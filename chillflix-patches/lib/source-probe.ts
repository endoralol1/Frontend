import {
    decodeCineproProxyPayload,
    fetchCineproProxyTarget,
    fetchUpstream,
    isAppPlaybackProxy,
} from "@/lib/upstream-fetch"
import { extractPlaybackToken } from "@/lib/playback-token"
import {
    hlsManifestHasMediaTags,
    isHtmlErrorBody,
    isValidHlsManifestBody,
    looksLikeHlsPlaylistUrl,
} from "@/lib/hls-manifest-utils"

import { SOURCE_PROBE_TIMEOUT_MS } from "@/lib/source-probe-constants"

const PROBE_TIMEOUT_MS = SOURCE_PROBE_TIMEOUT_MS
const PROBE_RANGE = "bytes=0-8191"
const MAX_HLS_PROBE_DEPTH = 2
const TRANSIENT_UPSTREAM_STATUSES = new Set([429, 502, 503])

function sleep(ms: number) {
    return new Promise<void>((resolve) => setTimeout(resolve, ms))
}

async function fetchProbeTargetWithRetry(
    fetchOnce: () => Promise<Response>,
    maxAttempts = 2
) {
    let lastResponse: Response | undefined

    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
        const response = await fetchOnce()
        lastResponse = response

        if (response.ok || !TRANSIENT_UPSTREAM_STATUSES.has(response.status)) {
            return response
        }

        if (attempt >= maxAttempts - 1) {
            return response
        }

        // Drain the body so the connection can be reused, then back off.
        await response.arrayBuffer().catch(() => undefined)
        const retryAfterHeader = response.headers.get("retry-after")
        const retryAfterSeconds = retryAfterHeader ? Number(retryAfterHeader) : NaN
        const waitMs = Number.isFinite(retryAfterSeconds)
            ? Math.min(3_000, Math.max(250, retryAfterSeconds * 1_000))
            : 400 * (attempt + 1)
        await sleep(waitMs)
    }

    return lastResponse!
}

export function looksPlayable(body: string, contentType: string | null, url: string) {
    const normalizedType = contentType?.toLowerCase() ?? ""
    const normalizedUrl = url.toLowerCase()
    const trimmed = body.trimStart()

    if (isHtmlErrorBody(body)) {
        return false
    }

    if (normalizedUrl.includes(".m3u8") || looksLikeHlsPlaylistUrl(normalizedUrl)) {
        return isValidHlsManifestBody(body)
    }

    if (trimmed.startsWith("#EXTM3U")) {
        return true
    }

    if (
        normalizedType.includes("mpegurl") ||
        normalizedType.includes("vnd.apple.mpegurl") ||
        normalizedType.includes("x-mpegurl")
    ) {
        return isValidHlsManifestBody(body)
    }

    if (
        normalizedType.includes("video/") ||
        normalizedType.includes("application/octet-stream")
    ) {
        return body.length > 0
    }

    if (normalizedUrl.includes(".mp4") || normalizedUrl.includes(".webm")) {
        return body.length > 0 && !isHtmlErrorBody(body)
    }

    return trimmed.length > 0 && !trimmed.startsWith("<!DOCTYPE")
}

function extractQuotedUri(line: string) {
    const match = line.match(/URI="([^"]+)"/i)
    return match?.[1]
}

function findFirstMediaUrl(manifest: string, manifestUrl: URL) {
    for (const line of manifest.split("\n")) {
        const trimmed = line.trim()
        if (!trimmed) continue

        if (trimmed.startsWith("#")) {
            const uri = extractQuotedUri(trimmed)
            if (uri) {
                try {
                    return new URL(uri, manifestUrl).toString()
                } catch {
                    continue
                }
            }
            continue
        }

        try {
            return new URL(trimmed, manifestUrl).toString()
        } catch {
            continue
        }
    }

    return undefined
}

function isHlsManifest(body: string, contentType: string | null) {
    if (isValidHlsManifestBody(body)) {
        return true
    }

    const normalizedType = contentType?.toLowerCase() ?? ""
    return (
        normalizedType.includes("mpegurl") ||
        normalizedType.includes("vnd.apple.mpegurl") ||
        normalizedType.includes("x-mpegurl")
    ) && isValidHlsManifestBody(body)
}

async function probeSegmentUrl(
    segmentUrl: string,
    requestOrigin: string,
    playbackToken?: string | null
) {
    let target = segmentUrl

    try {
        const parsed = new URL(segmentUrl, requestOrigin)
        if (playbackToken && isAppPlaybackProxy(parsed) && !extractPlaybackToken(parsed)?.trim()) {
            parsed.searchParams.set("pt", playbackToken)
            target = parsed.toString()
        }
    } catch {
        return false
    }

    const response = await fetchUpstream(target, requestOrigin, {
        method: "GET",
        range: PROBE_RANGE,
        timeoutMs: PROBE_TIMEOUT_MS,
    })

    if (response.status === 403 || response.status === 401) {
        return false
    }

    if (!response.ok) {
        return false
    }

    const contentType = response.headers.get("content-type")
    const body = await response.arrayBuffer()

    if (body.byteLength === 0) {
        return false
    }

    const bytes = new Uint8Array(body)
    if (bytes[0] === 0x47) {
        return true
    }

    const text = new TextDecoder().decode(body)
    return looksPlayable(text, contentType, segmentUrl)
}

async function evaluateManifestProbe(
    response: Response,
    probeUrl: string,
    requestOrigin: string,
    playbackToken?: string | null,
    depth = 0
) {
    const contentType = response.headers.get("content-type")

    if (response.status === 403 || response.status === 401) {
        return { ok: false, status: response.status }
    }

    const body = await response.text()

    if (looksLikeHlsPlaylistUrl(probeUrl) && !isValidHlsManifestBody(body)) {
        return { ok: false, status: response.status || 404 }
    }

    const manifestOk = response.ok && looksPlayable(body, contentType, probeUrl)

    if (!manifestOk) {
        return { ok: false, status: response.status }
    }

    if (!isHlsManifest(body, contentType)) {
        return { ok: true, status: response.status }
    }

    if (!hlsManifestHasMediaTags(body)) {
        return { ok: false, status: response.status }
    }

    const firstMediaUrl = findFirstMediaUrl(body, new URL(probeUrl, requestOrigin))
    if (!firstMediaUrl) {
        return { ok: false, status: response.status }
    }

    if (looksLikeHlsPlaylistUrl(firstMediaUrl) && depth < MAX_HLS_PROBE_DEPTH) {
        try {
            const nestedResponse = await fetchProbeTarget(
                new URL(firstMediaUrl, requestOrigin),
                requestOrigin,
                "GET",
                playbackToken
            )

            const nested = await evaluateManifestProbe(
                nestedResponse,
                firstMediaUrl,
                requestOrigin,
                playbackToken,
                depth + 1
            )

            if (nested.ok) {
                return nested
            }
        } catch {
            // fall through to segment probe / manifest-only acceptance
        }
    }

    const segmentOk = await probeSegmentUrl(firstMediaUrl, requestOrigin, playbackToken)
    if (segmentOk) {
        return { ok: true, status: response.status }
    }

    return { ok: false, status: response.status }
}

async function fetchProbeTarget(
    targetUrl: URL,
    requestOrigin: string,
    method: "GET" | "HEAD" = "GET",
    playbackToken?: string | null
) {
    let resolvedTarget = targetUrl

    if (
        playbackToken &&
        isAppPlaybackProxy(targetUrl) &&
        !extractPlaybackToken(targetUrl)?.trim()
    ) {
        const withToken = new URL(targetUrl.toString())
        withToken.searchParams.set("pt", playbackToken)
        resolvedTarget = withToken
    }

    return fetchProbeTargetWithRetry(() =>
        fetchUpstream(resolvedTarget, requestOrigin, {
            method,
            range: PROBE_RANGE,
            timeoutMs: PROBE_TIMEOUT_MS,
        })
    )
}

function buildProbeCandidates(parsed: URL) {
    const candidates: URL[] = []
    let proxyPayload: ReturnType<typeof decodeCineproProxyPayload> = undefined

    if (!isAppPlaybackProxy(parsed)) {
        return { candidates: [parsed], proxyPayload }
    }

    proxyPayload = decodeCineproProxyPayload(parsed)

    if (proxyPayload) {
        try {
            candidates.push(new URL(proxyPayload.url))
        } catch {
            // ignore invalid upstream
        }
    }

    candidates.push(parsed)

    const seen = new Set<string>()
    return {
        candidates: candidates.filter((candidate) => {
            const key = candidate.toString()
            if (seen.has(key)) return false
            seen.add(key)
            return true
        }),
        proxyPayload,
    }
}

export async function probeStreamUrl(rawUrl: string, requestOrigin: string) {
    const startedAt = Date.now()

    let parsed: URL
    try {
        parsed = new URL(rawUrl, requestOrigin)
    } catch {
        return {
            ok: false,
            status: 400,
            error: "Invalid url",
            latencyMs: Date.now() - startedAt,
        }
    }

    if (!["http:", "https:"].includes(parsed.protocol)) {
        return {
            ok: false,
            status: 400,
            error: "Unsupported protocol",
            latencyMs: Date.now() - startedAt,
        }
    }

    const playbackToken = extractPlaybackToken(parsed)

    if (isAppPlaybackProxy(parsed) && !playbackToken?.trim()) {
        return {
            ok: false,
            status: 403,
            error: "Missing playback token",
            latencyMs: Date.now() - startedAt,
            tokenPresent: false,
        }
    }

    const { candidates, proxyPayload } = buildProbeCandidates(parsed)

    let lastStatus: number | undefined
    let lastError: string | undefined

    for (const candidate of candidates) {
        if (isAppPlaybackProxy(candidate) && !playbackToken?.trim()) {
            continue
        }

        try {
            const response =
                proxyPayload &&
                candidate.toString() === proxyPayload.url &&
                !isAppPlaybackProxy(candidate)
                    ? await fetchProbeTargetWithRetry(() =>
                          fetchCineproProxyTarget(proxyPayload, {
                              method: "GET",
                              range: PROBE_RANGE,
                              timeoutMs: PROBE_TIMEOUT_MS,
                          })
                      )
                    : await fetchProbeTarget(candidate, requestOrigin, "GET", playbackToken)
            lastStatus = response.status

            const result = await evaluateManifestProbe(
                response,
                candidate.toString(),
                requestOrigin,
                playbackToken
            )

            if (result.ok) {
                return {
                    ok: true,
                    status: result.status,
                    latencyMs: Date.now() - startedAt,
                    via: candidate.pathname,
                    tokenPresent: Boolean(playbackToken),
                }
            }

            lastStatus = result.status
        } catch (error) {
            lastError = error instanceof Error ? error.message : "Probe failed"
        }
    }

    return {
        ok: false,
        status: lastStatus,
        error: lastError,
        latencyMs: Date.now() - startedAt,
        tokenPresent: Boolean(playbackToken),
    }
}
