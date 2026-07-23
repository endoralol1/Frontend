import {
    isLikelyBinarySegmentContentType,
    isLikelyHlsSegmentUrl,
    segmentContentTypeFromUrl,
} from "@/lib/hls-manifest-utils"
import { rewriteCineproManifestUrls } from "@/lib/cinepro/proxy"
import { applyPlaybackCorsHeaders, playbackCorsResponse } from "@/lib/playback-cors"
import { applyPlaybackNoStoreHeaders, playbackJsonResponse } from "@/lib/playback-response"
import { assertPlaybackProxyAccess } from "@/lib/playback-proxy-access"
import { extractPlaybackToken } from "@/lib/playback-token"
import { resolvePlaybackProxyOrigin } from "@/lib/playback-proxy-origin"
import {
    createAbortLinkedFetchSignal,
    createStreamWithPrefix,
    proxyStreamResponse,
    readAndRewriteHlsManifest,
    shouldTreatAsHlsManifest,
} from "@/lib/proxy-stream-response"
import { resolveRequestOrigin } from "@/lib/request-origin"
import { decodeCineproProxyPayload, fetchCineproProxyTarget } from "@/lib/upstream-fetch"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

const CINEPRO_SEGMENT_FETCH_TIMEOUT_MS = 30_000
const CINEPRO_MANIFEST_FETCH_TIMEOUT_MS = 60_000
const TRANSIENT_UPSTREAM_STATUSES = new Set([429, 502, 503])
const MAX_UPSTREAM_TRANSIENT_ATTEMPTS = 3

function sleep(ms: number) {
    return new Promise<void>((resolve) => setTimeout(resolve, ms))
}

function retryWaitMs(response: Response, attempt: number) {
    const retryAfterHeader = response.headers.get("retry-after")
    const retryAfterSeconds = retryAfterHeader ? Number(retryAfterHeader) : NaN
    if (Number.isFinite(retryAfterSeconds) && retryAfterSeconds >= 0) {
        return Math.min(4_000, Math.max(250, retryAfterSeconds * 1_000))
    }
    return Math.min(3_000, 350 * (attempt + 1))
}

async function fetchUpstreamWithTransientRetry(
    fetchOnce: () => Promise<Response>,
    maxAttempts = MAX_UPSTREAM_TRANSIENT_ATTEMPTS
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

        await response.arrayBuffer().catch(() => undefined)
        await sleep(retryWaitMs(response, attempt))
    }

    return lastResponse!
}

function getCineproBaseUrl() {
    const rawCineproUrl = process.env.CINEPRO_URL?.trim()

    if (!rawCineproUrl) {
        return undefined
    }

    return /^https?:\/\//i.test(rawCineproUrl)
        ? rawCineproUrl.replace(/\/$/, "")
        : `http://${rawCineproUrl.replace(/\/$/, "")}`
}

function rewriteManifest(
    manifest: string,
    upstreamUrl: string,
    requestOrigin: string,
    proxyHeaders: Record<string, string> | undefined,
    cineproBaseUrl: string,
    playbackToken: string | undefined
) {
    return rewriteCineproManifestUrls(
        manifest,
        upstreamUrl,
        requestOrigin,
        proxyHeaders,
        cineproBaseUrl,
        playbackToken
    )
}

function applySegmentContentType(headers: Headers, upstreamUrl: string) {
    const segmentType = segmentContentTypeFromUrl(upstreamUrl)
    if (segmentType) {
        headers.set("content-type", segmentType)
    }
}

async function handleProxy(request: Request) {
    const denied = await assertPlaybackProxyAccess(request, "cinepro")
    if (denied) return denied

    const requestUrl = new URL(request.url)
    const playbackToken = extractPlaybackToken(requestUrl)

    const cineproBaseUrl = getCineproBaseUrl()

    if (!cineproBaseUrl) {
        return playbackJsonResponse({ error: "CINEPRO_URL is not configured." }, { status: 500 })
    }

    const requestOrigin = resolvePlaybackProxyOrigin(resolveRequestOrigin(request))
    const proxyPayload = decodeCineproProxyPayload(requestUrl)

    if (!proxyPayload) {
        const legacyPid = requestUrl.searchParams.get("pid")
        const legacyPx = requestUrl.searchParams.get("px")
        const expired = Boolean(legacyPid || legacyPx)
        return playbackJsonResponse(
            {
                error: expired
                    ? "Proxy session expired. Refresh sources to continue playback."
                    : "Missing or invalid proxy data.",
                code: expired ? "PROXY_PID_EXPIRED" : "PROXY_DATA_INVALID",
            },
            { status: 400 }
        )
    }

    const upstreamUrl = proxyPayload.url
    const incomingRange = request.headers.get("range") ?? undefined
    const incomingAccept = request.headers.get("accept") ?? "*/*"
    const isStrictManifestRequest = shouldTreatAsHlsManifest(upstreamUrl, null)
    const rangeForUpstream = incomingRange && !isStrictManifestRequest ? incomingRange : undefined
    const upstreamMethod = request.method === "HEAD" ? "HEAD" : "GET"
    const fetchTimeoutMs = isLikelyHlsSegmentUrl(upstreamUrl) || incomingRange
        ? CINEPRO_SEGMENT_FETCH_TIMEOUT_MS
        : CINEPRO_MANIFEST_FETCH_TIMEOUT_MS

    const { signal, cleanup } = createAbortLinkedFetchSignal(request, fetchTimeoutMs)

    try {
        let response = await fetchUpstreamWithTransientRetry(() =>
            fetchCineproProxyTarget(proxyPayload, {
                method: upstreamMethod,
                accept: incomingAccept,
                signal,
                ...(rangeForUpstream ? { range: rangeForUpstream } : {}),
                timeoutMs: fetchTimeoutMs,
            })
        )

        if (response.status === 416 && rangeForUpstream) {
            response = await fetchUpstreamWithTransientRetry(() =>
                fetchCineproProxyTarget(proxyPayload, {
                    method: upstreamMethod,
                    accept: incomingAccept,
                    signal,
                    timeoutMs: fetchTimeoutMs,
                })
            )
        }

        if (!response.ok) {
            const errorBody = await response.text().catch(() => "")
            return playbackJsonResponse(
                {
                    error: `Upstream proxy failed (${response.status}).`,
                    detail: errorBody.slice(0, 500),
                },
                { status: response.status >= 400 && response.status < 600 ? response.status : 502 }
            )
        }

        const headers = new Headers(response.headers)
        applyPlaybackCorsHeaders(headers)
        applyPlaybackNoStoreHeaders(headers)

        if (upstreamMethod === "HEAD") {
            return new Response(null, {
                status: response.status,
                headers,
            })
        }

        const contentType = response.headers.get("content-type")
        const rewriteFn = (manifest: string) =>
            rewriteManifest(
                manifest,
                upstreamUrl,
                requestOrigin,
                proxyPayload.headers,
                cineproBaseUrl,
                playbackToken ?? undefined
            )

        if (
            isLikelyHlsSegmentUrl(upstreamUrl) ||
            isLikelyBinarySegmentContentType(contentType) ||
            incomingRange
        ) {
            applySegmentContentType(headers, upstreamUrl)
            return proxyStreamResponse(response, headers)
        }

        if (shouldTreatAsHlsManifest(upstreamUrl, contentType)) {
            const result = await readAndRewriteHlsManifest(response, rewriteFn)

            if (result && "manifest" in result) {
                headers.delete("content-encoding")
                headers.delete("content-length")
                headers.delete("transfer-encoding")
                headers.set("content-type", "application/vnd.apple.mpegurl")

                return new Response(result.manifest, {
                    status: response.status,
                    headers,
                })
            }

            return playbackJsonResponse(
                {
                    error: "Upstream returned an invalid HLS manifest.",
                    code: "INVALID_HLS_MANIFEST",
                },
                { status: 502 }
            )
        }

        const peekResult = await readAndRewriteHlsManifest(response, rewriteFn)

        if (peekResult && "manifest" in peekResult) {
            headers.delete("content-encoding")
            headers.delete("content-length")
            headers.delete("transfer-encoding")
            headers.set("content-type", "application/vnd.apple.mpegurl")

            return new Response(peekResult.manifest, {
                status: response.status,
                headers,
            })
        }

        if (peekResult && "firstChunk" in peekResult) {
            applySegmentContentType(headers, upstreamUrl)
            headers.delete("content-encoding")
            headers.delete("transfer-encoding")

            const stream = createStreamWithPrefix(peekResult.firstChunk, peekResult.reader)
            return new Response(stream, {
                status: response.status,
                headers,
            })
        }

        if (response.body) {
            return proxyStreamResponse(response, headers)
        }

        return playbackJsonResponse(
            { error: "Upstream returned an empty response." },
            { status: 502 }
        )
    } catch (error) {
        const message = error instanceof Error ? error.message : "Unknown proxy error"

        return playbackJsonResponse(
            { error: `Failed to proxy CinePro media: ${message}` },
            { status: 502 }
        )
    } finally {
        cleanup()
    }
}

export async function GET(request: Request) {
    return handleProxy(request)
}

export async function HEAD(request: Request) {
    return handleProxy(request)
}

export async function POST(request: Request) {
    return handleProxy(request)
}

export async function OPTIONS() {
    return playbackCorsResponse(null, { status: 204 })
}
