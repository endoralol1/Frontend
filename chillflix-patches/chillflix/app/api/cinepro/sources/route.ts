import { NextResponse } from "next/server"

import { getCurrentUser } from "@/lib/auth"
import type { UserRole } from "@/lib/permissions"

import { compactCineproProxyHref } from "@/lib/cinepro/proxy"
import { isNativeFlixhqEnabled } from "@/lib/native-flixhq"
import { isNativeHuhuEnabled } from "@/lib/native-huhu"
import {
    isNativeVidlinkEnabled,
    stripNativeVidlinkSources,
} from "@/lib/native-vidlink"
import {
    buildFlixhqStreamUrl,
    isFlixhqKnownUnavailable,
} from "@/lib/providers/flixhq"
import {
    buildHuhuStreamUrl,
} from "@/lib/providers/huhu"
import { isVidLinkKnownUnavailable } from "@/lib/providers/vidlink"
import { assertPlaybackRateLimit } from "@/lib/rate-limit"
import { assertSitePlaybackClient } from "@/lib/playback-guard"
import { recordSecurityEvent } from "@/lib/security-stats"
import {
    type CineproPlaybackMeta,
    sealCineproPlaybackPayload,
} from "@/lib/playback-token"
import { resolvePlaybackProxyOrigin } from "@/lib/playback-proxy-origin"
import { resolveRequestOrigin } from "@/lib/request-origin"
import { decodeCineproProxyPayload } from "@/lib/upstream-fetch"
import {
    isClientResolveProvider,
    stripClientProviderServerDiagnostics,
} from "@/lib/client-resolve-providers"
import {
    DEFAULT_PROVIDER_LABELS,
    ON_DEMAND_CINEPRO_PROVIDER_IDS,
    normalizeProviderName,
} from "@/lib/stream-sources-defaults"
import {
    clearCineproSourceCache,
    deleteCineproSourceCache,
    getCineproSourceCache,
    setCineproSourceCache,
} from "@/lib/cinepro-sources-cache"
import { normalizeCinemacityCdnPlaybackUrl } from "@/components/player/utils/playback"
import {
    getCinemacityCcdnUpstreamProxy,
    isCinemacityCcdnUrl,
} from "@/lib/cinemacity-cccdn-proxy"
import {
    applyStreamSourcesConfig,
    filterNativeSourcesForBulkResponse,
    getPrimaryEnabledProviderId,
    getStreamSourcesConfig,
    isProviderEnabledInConfig,
    shouldOfferNativeImmediateFallback,
    type StreamSourcesConfig,
} from "@/lib/stream-sources-config"
import { playbackJsonResponse } from "@/lib/playback-response"
import {
  CINEPRO_BULK_FETCH_TIMEOUT_MS,
  CINEPRO_SINGLE_PROVIDER_FETCH_TIMEOUT_MS,
  CINEPRO_SOURCES_CACHE_TTL_MS,
  CINEMACITY_CINEPRO_FETCH_TIMEOUT_MS,
  MERGED_SOURCES_BACKGROUND_WAIT_MS,
} from "@/lib/source-probe-constants"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

type SourceItem = {
  url: string
  type?: string
  quality?: string
  provider?: {
    id?: string
    name?: string
  }
  audioTracks?: Array<{
    language?: string
    label?: string
  }>
  cinemacityPageUrl?: string
  directClientPlayback?: boolean
  clientPlaybackHeaders?: Record<string, string>
}

type SubtitleItem = {
  id?: string
  label?: string
  lang?: string
  language?: string
  url?: string
  file?: string
  src?: string
  kind?: string
}

const CACHE_TTL_MS = CINEPRO_SOURCES_CACHE_TTL_MS
/** Cinemacity CDN signed URLs expire quickly — keep cache short. */
const CINEMACITY_CACHE_TTL_MS = 90 * 1000
const CINEPRO_FETCH_TIMEOUT_MS = CINEPRO_BULK_FETCH_TIMEOUT_MS
const VIDLINK_ENABLED = process.env.VIDLINK_ENABLED !== "false"
const FLIXHQ_ENABLED = process.env.FLIXHQ_ENABLED !== "false"
const HUHU_ENABLED = process.env.HUHU_ENABLED !== "false"
const backgroundCineproFetches = new Map<string, Promise<void>>()
const MERGED_SOURCES_WAIT_MS = MERGED_SOURCES_BACKGROUND_WAIT_MS
const CINEPRO_OFFLINE_CACHE_MS = 15_000

async function waitForMergedSources(cacheKey: string, timeoutMs = MERGED_SOURCES_WAIT_MS) {
  const inFlight = backgroundCineproFetches.get(cacheKey)
  if (inFlight) {
    await Promise.race([
      inFlight,
      new Promise<void>((resolve) => setTimeout(resolve, timeoutMs)),
    ])
  }

  return getCached(cacheKey)
}

type DiagnosticItem = {
  code: string
  message: string
  severity: "info" | "warning"
}

function clearBackgroundDiagnostic(payload: unknown) {
  if (!payload || typeof payload !== "object") {
    return payload
  }

  const record = payload as {
    diagnostics?: Array<{ code?: string }>
  }

  if (!Array.isArray(record.diagnostics)) {
    return payload
  }

  return {
    ...record,
    diagnostics: record.diagnostics.filter((item) => item.code !== "CINEPRO_BACKGROUND"),
  }
}

function isCineproConnectionFailure(result?: {
  payload?: unknown
  lastStatus?: number
  lastError?: string
}) {
  if (result?.payload) return false
  if (result?.lastStatus) return false

  const error = result?.lastError?.toLowerCase() ?? ""
  return (
    error.includes("fetch failed") ||
    error.includes("econnrefused") ||
    error.includes("connect") ||
    error.includes("network") ||
    error.includes("abort")
  )
}

function buildCineproOfflineDiagnostic(cineproBaseUrl: string): DiagnosticItem {
  return {
    code: "CINEPRO_OFFLINE",
    message: `CinePro Core is not reachable at ${cineproBaseUrl}. Run "npm run dev:cinepro" in another terminal, then tap Retry below.`,
    severity: "warning",
  }
}

function buildCineproUnavailableDiagnostic(cineproBaseUrl: string): DiagnosticItem {
  return {
    code: "CINEPRO_UNAVAILABLE",
    message: `CinePro did not return extra sources in time (providers can take up to ${Math.round(CINEPRO_BULK_FETCH_TIMEOUT_MS / 1000)}s). Tap Retry below, or use VidLink.`,
    severity: "warning",
  }
}

function finalizeBackgroundCache(args: {
  cacheKey: string
  payload: unknown
  cineproBaseUrl: string
}) {
  const cleared = clearBackgroundDiagnostic(args.payload)
  if (!cleared || typeof cleared !== "object") {
    return cleared
  }

  const record = cleared as { diagnostics?: DiagnosticItem[] }
  const diagnostics = Array.isArray(record.diagnostics) ? record.diagnostics : []

  if (diagnostics.some((item) => item.code === "CINEPRO_UNAVAILABLE")) {
    return cleared
  }

  return {
    ...record,
    diagnostics: [...diagnostics, buildCineproUnavailableDiagnostic(args.cineproBaseUrl)],
  }
}

function resolveCachedPayload(cacheKey: string, payload: unknown, cineproBaseUrl?: string) {
  if (!payload || typeof payload !== "object") {
    return payload
  }

  const record = payload as { diagnostics?: Array<{ code?: string }> }
  const waitingForBackground = record.diagnostics?.some(
    (item) => item.code === "CINEPRO_BACKGROUND"
  )

  if (!waitingForBackground || backgroundCineproFetches.has(cacheKey)) {
    return payload
  }

  if (!cineproBaseUrl) {
    return clearBackgroundDiagnostic(payload)
  }

  const finalized = finalizeBackgroundCache({
    cacheKey,
    payload,
    cineproBaseUrl,
  })
  setCached(cacheKey, finalized)
  return finalized
}

function buildCacheKey(params: URLSearchParams) {
  return [
    "v3",
    params.get("type") ?? "",
    params.get("tmdbId") ?? "",
    params.get("season") ?? "",
    params.get("episode") ?? "",
  ].join(":")
}

function normalizeCineproBaseUrl(rawCineproUrl: string) {
  const withProtocol = /^https?:\/\//i.test(rawCineproUrl)
    ? rawCineproUrl.replace(/\/$/, "")
    : `http://${rawCineproUrl.replace(/\/$/, "")}`

  // Avoid IPv6 localhost resolution issues in server-side fetch.
  return withProtocol.replace("://localhost", "://127.0.0.1")
}

function isTerminalEmptySourcesPayload(payload: unknown) {
  if (!payload || typeof payload !== "object") return false

  const diagnostics = (payload as { diagnostics?: Array<{ code?: string }> }).diagnostics
  if (!Array.isArray(diagnostics)) return false

  return diagnostics.some(
    (item) =>
      item.code === "NO_ENABLED_PROVIDER_STREAMS" ||
      item.code === "ENABLED_PROVIDER_UNAVAILABLE" ||
      item.code === "CINEPRO_UNAVAILABLE" ||
      item.code === "CINEPRO_OFFLINE"
  )
}

function isCinemacityOnlyConfig(config: StreamSourcesConfig) {
  const enabled = config.sources
    .filter((entry) => entry.enabled)
    .map((entry) => normalizeProviderName(entry.id))

  return enabled.length === 1 && enabled[0] === "cinemacity"
}

function cachedPayloadMissingEnabledProviders(
  payload: unknown,
  streamSourcesConfig: StreamSourcesConfig,
  viewerRole?: UserRole
) {
  const refiltered = refilterPayloadWithConfig(payload, streamSourcesConfig, viewerRole) as {
    sources?: unknown[]
    diagnostics?: Array<{ code?: string }>
  }

  if (!Array.isArray(refiltered.sources) || refiltered.sources.length > 0) {
    return false
  }

  return (
    Array.isArray(refiltered.diagnostics) &&
    refiltered.diagnostics.some((item) => item.code === "NO_ENABLED_PROVIDER_STREAMS")
  )
}

function getCached(key: string) {
  const cached = getCineproSourceCache(key)

  if (!cached) return null

  const sources = (cached as { sources?: unknown[] } | undefined)?.sources
  if (!Array.isArray(sources) || sources.length === 0) {
    if (isTerminalEmptySourcesPayload(cached)) {
      return cached
    }

    deleteCineproSourceCache(key)
    return null
  }

  return cached
}

function stripNativePlaybackSources<T extends { url: string }>(
  sources: T[],
  options?: { manual?: boolean }
) {
  return stripNativeVidlinkSources(sources, options)
}

function refilterPayloadWithConfig(
  payload: unknown,
  streamSourcesConfig: StreamSourcesConfig,
  viewerRole?: UserRole
) {
  if (!payload || typeof payload !== "object") {
    return payload
  }

  const record = payload as { sources?: ResolvedSource[]; total?: number }
  if (!Array.isArray(record.sources)) {
    return payload
  }

  const sources = filterNativeSourcesForBulkResponse(
    streamSourcesConfig,
    stripNativePlaybackSources(
      applyStreamSourcesConfig(record.sources, streamSourcesConfig, { viewerRole })
    )
  )

  return {
    ...record,
    sources,
    total: sources.length,
  }
}

async function fetchCineproPayload(
  endpoints: string[],
  timeoutMs: number
): Promise<
  | {
      payload?: {
        sources?: SourceItem[]
        subtitles?: unknown[]
        diagnostics?: unknown[]
      }
      lastStatus?: number
      lastError?: string
    }
  | undefined
> {
  let lastStatus: number | undefined
  let lastError: string | undefined

  for (const endpoint of endpoints) {
    try {
      const controller = new AbortController()
      const timeout = setTimeout(() => controller.abort(), timeoutMs)

      const response = await fetch(endpoint, {
        method: "GET",
        headers: {
          Accept: "application/json",
        },
        signal: controller.signal,
        cache: "no-store",
      }).finally(() => clearTimeout(timeout))

      lastStatus = response.status

      if (!response.ok) {
        continue
      }

      return {
        payload: (await response.json()) as {
          sources?: SourceItem[]
          subtitles?: unknown[]
          diagnostics?: unknown[]
        },
        lastStatus,
      }
    } catch (error) {
      lastError = error instanceof Error ? error.message : "Unknown fetch error"
    }
  }

  return { lastStatus, lastError }
}

type ResolvedSource = {
  url: string
  type: string
  quality: string
  provider: {
    id: string
    name: string
  }
  audioTracks: Array<{ id: string; language: string; label: string }>
}

function providerDisplayLabel(providerId: string) {
  const key = normalizeProviderName(providerId) as keyof typeof DEFAULT_PROVIDER_LABELS
  return DEFAULT_PROVIDER_LABELS[key] ?? providerId
}

function buildSingleProviderMovieEndpoint(
  cineproBaseUrl: string,
  tmdbId: string,
  providerId: string,
  fresh = false
) {
  const base = `${cineproBaseUrl}/v1/movies/${encodeURIComponent(tmdbId)}/provider/${encodeURIComponent(providerId)}`
  const params = new URLSearchParams()
  if (fresh) params.set("fresh", "true")
  // On-demand scrapers are intentionally off the bulk allowlist — probe loads them anyway.
  if (ON_DEMAND_CINEPRO_PROVIDER_IDS.has(normalizeProviderName(providerId))) {
    params.set("probe", "true")
  }
  const q = params.toString()
  return q ? `${base}?${q}` : base
}

function buildSingleProviderTvEndpoint(
  cineproBaseUrl: string,
  tmdbId: string,
  season: string,
  episode: string,
  providerId: string,
  fresh = false
) {
  const base = `${cineproBaseUrl}/v1/tv/${encodeURIComponent(tmdbId)}/seasons/${encodeURIComponent(season)}/episodes/${encodeURIComponent(episode)}/provider/${encodeURIComponent(providerId)}`
  const params = new URLSearchParams()
  if (fresh) params.set("fresh", "true")
  if (ON_DEMAND_CINEPRO_PROVIDER_IDS.has(normalizeProviderName(providerId))) {
    params.set("probe", "true")
  }
  const q = params.toString()
  return q ? `${base}?${q}` : base
}

function buildSingleProviderEndpoints(
  type: "movie" | "tv",
  cineproBaseUrl: string,
  tmdbId: string,
  providerId: string,
  season?: string,
  episode?: string,
  fresh = false
) {
  if (type === "movie") {
    return [buildSingleProviderMovieEndpoint(cineproBaseUrl, tmdbId, providerId, fresh)]
  }

  if (!season || !episode) {
    return []
  }

  return [
    buildSingleProviderTvEndpoint(
      cineproBaseUrl,
      tmdbId,
      season,
      episode,
      providerId,
      fresh
    ),
  ]
}

/** CinePro Core often lacks per-provider TV routes; bulk TV still returns all providers. */
function buildBulkTvEndpoints(
  cineproBaseUrl: string,
  tmdbId: string,
  season: string,
  episode: string,
  fresh = false
) {
  const suffix = fresh ? "?fresh=true" : ""
  const encId = encodeURIComponent(tmdbId)
  const encSeason = encodeURIComponent(season)
  const encEpisode = encodeURIComponent(episode)

  return [
    `${cineproBaseUrl}/v1/tv/${encId}/seasons/${encSeason}/episodes/${encEpisode}${suffix}`,
    `${cineproBaseUrl}/stream/tv/${encId}/${encSeason}/${encEpisode}${suffix}`,
    `${cineproBaseUrl}/api/stream/tv/${encId}/${encSeason}/${encEpisode}${suffix}`,
  ]
}

function filterCineproPayloadByProvider(
  payload:
    | {
        sources?: SourceItem[]
        subtitles?: unknown[]
        diagnostics?: unknown[]
        responseId?: string
      }
    | undefined,
  providerId: string
) {
  if (!payload || !Array.isArray(payload.sources)) {
    return undefined
  }

  const key = normalizeProviderName(providerId)
  const sources = payload.sources.filter((source) => {
    const id = source.provider?.id ?? source.provider?.name ?? ""
    return normalizeProviderName(id) === key
  })

  if (sources.length === 0) {
    return undefined
  }

  return {
    ...payload,
    sources,
  }
}

function payloadHasPlayableSources(payload: unknown) {
  if (!payload || typeof payload !== "object") {
    return false
  }

  const sources = (payload as { sources?: Array<{ url?: string }> }).sources
  if (!Array.isArray(sources)) {
    return false
  }

  return sources.some((source) => Boolean(source?.url))
}

function payloadHasProviderSources(payload: unknown, providerId: string) {
  if (!payload || typeof payload !== "object" || !providerId) {
    return false
  }

  const key = normalizeProviderName(providerId)
  const sources = (payload as { sources?: Array<{ provider?: { id?: string; name?: string } }> })
    .sources

  if (!Array.isArray(sources)) {
    return false
  }

  return sources.some((source) => {
    const id = source.provider?.id ?? source.provider?.name ?? ""
    return normalizeProviderName(id) === key
  })
}

function getCineproResponseId(payload: unknown): string | undefined {
  if (!payload || typeof payload !== "object") return undefined

  const responseId = (payload as { responseId?: unknown }).responseId
  return typeof responseId === "string" ? responseId : undefined
}

async function refreshCineproSourceCache(
  cineproBaseUrl: string,
  responseId?: string
) {
  if (!responseId || !/^[a-zA-Z0-9_-]{1,128}$/.test(responseId)) {
    return false
  }

  try {
    const response = await fetch(`${cineproBaseUrl}/v1/refresh/${responseId}`, {
      method: "GET",
      cache: "no-store",
    })

    return response.ok
  } catch {
    return false
  }
}

function finalizeSourcePayload<T extends { sources: ResolvedSource[]; total?: number; diagnostics?: unknown[] }>(
  payload: T,
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>,
  viewerRole?: UserRole,
  options?: { requestedProvider?: string; immediatePlayback?: boolean }
): T {
  const sources = filterNativeSourcesForBulkResponse(
    streamSourcesConfig,
    stripNativePlaybackSources(
      applyStreamSourcesConfig(payload.sources, streamSourcesConfig, { viewerRole })
    ).filter((source) => !isClientResolveProvider(source.provider.id)),
    options?.requestedProvider,
    options?.immediatePlayback ? { allowNativeFallbackWhenEmpty: true } : undefined
  )

  const diagnostics = Array.isArray(payload.diagnostics)
    ? stripClientProviderServerDiagnostics(
        payload.diagnostics.filter((item) => {
          if (!item || typeof item !== "object") return true
          return true
        }) as Array<{ code?: string; message?: string }>
      )
    : payload.diagnostics

  return {
    ...payload,
    sources,
    diagnostics,
    total: sources.length,
  }
}

async function respondSources(
  request: Request,
  meta: CineproPlaybackMeta,
  payload: unknown
) {
  const denied = assertSitePlaybackClient(request)
  if (denied) return denied

  recordSecurityEvent("sourcesAllowed")
  return playbackJsonResponse(await sealCineproPlaybackPayload(payload, meta))
}

function collectNativeSources(args: {
  vidlinkSource: ResolvedSource | null
  flixhqSource: ResolvedSource | null
  huhuSource: ResolvedSource | null
}) {
  return [args.vidlinkSource, args.flixhqSource, args.huhuSource].filter(
    (source): source is ResolvedSource => Boolean(source)
  )
}

function buildMergedResult(args: {
  vidlinkSource: ResolvedSource | null
  flixhqSource: ResolvedSource | null
  huhuSource: ResolvedSource | null
  payload?: {
    sources?: SourceItem[]
    subtitles?: unknown[]
    diagnostics?: unknown[]
    responseId?: string
  }
  cineproBaseUrl: string
  requestOrigin: string
  vidlinkDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  flixhqDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  huhuDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  extraDiagnostics?: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>
  viewerRole?: UserRole
  requestedProvider?: string
}) {
  const normalizedSources = normalizeSources(
    args.payload?.sources,
    args.cineproBaseUrl,
    args.requestOrigin
  )
  const nativeSources = args.requestedProvider
    ? []
    : [args.vidlinkSource, args.flixhqSource, args.huhuSource].filter(
        (source): source is ResolvedSource => Boolean(source)
      )
  const mergedSources = [
    ...normalizedSources,
    ...nativeSources,
  ].filter((source, index, list) => {
    const key = `${source.provider.id}:${source.url}`
    return list.findIndex((item) => `${item.provider.id}:${item.url}` === key) === index
  })

  const filteredSources = filterNativeSourcesForBulkResponse(
    args.streamSourcesConfig,
    stripNativePlaybackSources(
      applyStreamSourcesConfig(mergedSources, args.streamSourcesConfig, {
        viewerRole: args.viewerRole,
      })
    ).filter((source) => !isClientResolveProvider(source.provider.id)),
    args.requestedProvider
  )

  const normalizedSubtitles = normalizeSubtitles(
    args.payload?.subtitles,
    args.cineproBaseUrl,
    args.requestOrigin
  )
  const payloadDiagnostics = Array.isArray(args.payload?.diagnostics)
    ? args.payload.diagnostics.filter((item) => {
        if (!item || typeof item !== "object") return true
        const diagnostic = item as { code?: string; message?: string }
        if (diagnostic.code !== "PROVIDER_ERROR") return true
        const match = diagnostic.message?.match(/^([^:]+):/)
        if (!match) return true
        return !isClientResolveProvider(match[1].trim())
      })
    : args.payload?.diagnostics

  const cinemacityProxyDiagnostic =
    filteredSources.some(
      (source) =>
        source.provider.id === "cinemacity" ||
        isCinemacityCcdnUrl(source.url) ||
        source.url.includes("cccdn.net")
    ) && !getCinemacityCcdnUpstreamProxy()
      ? {
          code: "CINEMACITY_CLIENT_PLAYBACK",
          message:
            "Cinemacity HLS plays from your browser (cccdn.net blocks the VPS). Server relay needs CINEMACITY_CCCDN_PROXY.",
          severity: "info" as const,
        }
      : null

  const result = {
    sources: filteredSources,
    subtitles: normalizedSubtitles,
    diagnostics: [
      ...(Array.isArray(payloadDiagnostics) ? payloadDiagnostics : []),
      ...(args.extraDiagnostics ?? []),
      ...(cinemacityProxyDiagnostic ? [cinemacityProxyDiagnostic] : []),
      ...args.vidlinkDiagnostics,
      ...args.flixhqDiagnostics,
      ...args.huhuDiagnostics,
    ],
    total: filteredSources.length,
    fetchedAt: new Date().toISOString(),
    cineproResponseId: getCineproResponseId(args.payload),
  }

  if (filteredSources.length > 0 || mergedSources.length === 0) {
    if (
      filteredSources.length === 0 &&
      mergedSources.length === 0 &&
      !result.diagnostics.some((item) =>
        item.code === "CINEPRO_BACKGROUND" ||
        item.code === "ENABLED_PROVIDER_UNAVAILABLE" ||
        item.code === "NO_ENABLED_PROVIDER_STREAMS"
      )
    ) {
      const enabledAutomaticProviders = args.streamSourcesConfig.sources.filter(
        (entry) => {
          const normalized = normalizeProviderName(entry.id)
          return entry.enabled && normalized !== "vidlink"
        }
      )

      if (enabledAutomaticProviders.length > 0) {
        return {
          ...result,
          diagnostics: [
            ...result.diagnostics,
            {
              code: "ENABLED_PROVIDER_UNAVAILABLE",
              message: "No enabled provider returned a stream for this title.",
              severity: "warning" as const,
            },
          ],
        }
      }
    }

    return result
  }

  const enabledNames = args.streamSourcesConfig.sources
    .filter((entry) => entry.enabled)
    .map((entry) => entry.name)
  const enabledLabel =
    enabledNames.length > 0 ? enabledNames.join(", ") : "no providers"

  return {
    ...result,
    diagnostics: [
      ...result.diagnostics,
      {
        code: "NO_ENABLED_PROVIDER_STREAMS",
        message: `CinePro found ${mergedSources.length} stream(s), but none match your enabled providers (${enabledLabel}). Open Admin → Stream Sources and enable the matching provider (e.g. Cinemacity).`,
        severity: "warning" as const,
      },
    ],
  }
}

function scheduleBackgroundCineproFetch(args: {
  cacheKey: string
  endpoints: string[]
  vidlinkSource: ResolvedSource | null
  flixhqSource: ResolvedSource | null
  huhuSource: ResolvedSource | null
  cineproBaseUrl: string
  requestOrigin: string
  vidlinkDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  flixhqDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  huhuDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>
  viewerRole?: UserRole
}) {
  if (backgroundCineproFetches.has(args.cacheKey)) {
    return
  }

  const job = fetchCineproPayload([args.endpoints[0]!], CINEPRO_FETCH_TIMEOUT_MS)
    .then((cineproResult) => {
      if (!cineproResult?.payload) {
        const cached = getCached(args.cacheKey)
        if (cached) {
          setCached(
            args.cacheKey,
            finalizeBackgroundCache({
              cacheKey: args.cacheKey,
              payload: cached,
              cineproBaseUrl: args.cineproBaseUrl,
            })
          )
        }
        return
      }

      const result = buildMergedResult({
        vidlinkSource: args.vidlinkSource,
        flixhqSource: args.flixhqSource,
        huhuSource: args.huhuSource,
        payload: cineproResult.payload,
        cineproBaseUrl: args.cineproBaseUrl,
        requestOrigin: args.requestOrigin,
        vidlinkDiagnostics: args.vidlinkDiagnostics,
        flixhqDiagnostics: args.flixhqDiagnostics,
        huhuDiagnostics: args.huhuDiagnostics,
        streamSourcesConfig: args.streamSourcesConfig,
        viewerRole: args.viewerRole,
      })

      setCached(args.cacheKey, result)
    })
    .catch(() => {
      const cached = getCached(args.cacheKey)
      if (cached) {
        setCached(
          args.cacheKey,
          finalizeBackgroundCache({
            cacheKey: args.cacheKey,
            payload: cached,
            cineproBaseUrl: args.cineproBaseUrl,
          })
        )
      }
    })
    .finally(() => {
      backgroundCineproFetches.delete(args.cacheKey)
    })

  backgroundCineproFetches.set(args.cacheKey, job)
}

function payloadCacheTtl(payload: unknown, fallbackMs = CACHE_TTL_MS) {
  const sources = (payload as { sources?: SourceItem[] } | undefined)?.sources
  if (
    Array.isArray(sources) &&
    sources.some(
      (source) =>
        isCinemacityCcdnUrl(source.url) ||
        source.provider?.id?.toLowerCase() === "cinemacity"
    )
  ) {
    return CINEMACITY_CACHE_TTL_MS
  }

  return fallbackMs
}

function setCached(key: string, payload: unknown, ttlMs = CACHE_TTL_MS) {
  const effectiveTtl = payloadCacheTtl(payload, ttlMs)
  const sources = (payload as { sources?: unknown[] } | undefined)?.sources
  if (!Array.isArray(sources) || sources.length === 0) {
    if (isTerminalEmptySourcesPayload(payload)) {
      setCineproSourceCache(key, payload, effectiveTtl)
    }
    return
  }

  setCineproSourceCache(key, payload, effectiveTtl)
}

function normalizeLoopbackHost(url: string) {
  return url.replace(/:\/\/(localhost|127\.0\.0\.1)/gi, "://127.0.0.1")
}

function extractCinemacityPageUrlFromProxyUrl(url: string) {
  if (!url.includes("/api/cinepro/proxy") && !url.includes("/v1/proxy")) return undefined

  try {
    const query = url.includes("?") ? url.slice(url.indexOf("?") + 1) : ""
    const payload = decodeCineproProxyPayload(new URL(`http://proxy.local/?${query}`))
    if (!payload?.url || !isCinemacityCcdnUrl(payload.url)) {
      return undefined
    }

    return payload.headers?.Referer
  } catch {
    return undefined
  }
}

function applyCinemacityClientPlayback<T extends SourceItem & { provider: { id: string; name: string } }>(
  source: T,
  playbackProxyUrl: string
): T {
  if (source.provider.id !== "cinemacity" || getCinemacityCcdnUpstreamProxy()) {
    return source
  }

  let payload
  try {
    payload = decodeCineproProxyPayload(
      new URL(playbackProxyUrl, "https://chillflix.lol")
    )
  } catch {
    return source
  }

  if (!payload?.url || !isCinemacityCcdnUrl(payload.url)) {
    return source
  }

  const referer =
    payload.headers?.Referer?.trim() ||
    source.cinemacityPageUrl?.trim() ||
    "https://cinemacity.cc/"

  return {
    ...source,
    url: normalizeCinemacityCdnPlaybackUrl(payload.url),
    cinemacityPageUrl: referer,
    directClientPlayback: true,
    clientPlaybackHeaders: {
      Referer: referer,
    },
  }
}

function rewriteCineproProxyUrl(url: string, cineproBaseUrl: string, origin: string) {
  const proxyOrigin = resolvePlaybackProxyOrigin(origin)
  const normalizedBaseUrl = normalizeLoopbackHost(cineproBaseUrl.replace(/\/$/, ""))
  const normalizedUrl = normalizeLoopbackHost(url)

  let rewritten = url

  if (normalizedUrl.startsWith(`${normalizedBaseUrl}/v1/proxy?`)) {
    rewritten = `${proxyOrigin}/api/cinepro/proxy${normalizedUrl.slice(`${normalizedBaseUrl}/v1/proxy`.length)}`
  } else if (normalizedUrl.startsWith(`${normalizedBaseUrl}/proxy?`)) {
    rewritten = `${proxyOrigin}/api/cinepro/proxy${normalizedUrl.slice(`${normalizedBaseUrl}/proxy`.length)}`
  }

  return compactCineproProxyHref(rewritten, origin) ?? rewritten
}

function normalizeSources(rawSources: SourceItem[] = [], cineproBaseUrl: string, origin: string) {
  const seen = new Set<string>()

  return rawSources
    .filter((source) => {
      if (!source?.url) return false

      const providerName = source.provider?.name?.toLowerCase() ?? ""
      const providerId = source.provider?.id?.toLowerCase() ?? ""

      if (!VIDLINK_ENABLED && (providerName === "vidlink" || providerId === "vidlink")) return false
      if (!FLIXHQ_ENABLED && (providerName === "flixhq" || providerId === "flixhq")) return false
      if (!HUHU_ENABLED && (providerName === "huhu" || providerId === "huhu")) return false

      return true
    })
    .map((source) => {
      const rewritten = rewriteCineproProxyUrl(source.url, cineproBaseUrl, origin)
      const playbackUrl = normalizeCinemacityCdnPlaybackUrl(rewritten).replace(/,urlset/gi, ".urlset")

      const normalized = {
      url: playbackUrl,
      type: source.type ?? "unknown",
      quality: source.quality ?? "Auto",
      cinemacityPageUrl: extractCinemacityPageUrlFromProxyUrl(rewritten),
      provider: {
        id: normalizeProviderName(
          source.provider?.id ?? source.provider?.name ?? "unknown"
        ),
        name: source.provider?.name ?? "Unknown Provider",
      },
      audioTracks: Array.isArray(source.audioTracks)
        ? source.audioTracks
            .map((track, index) => {
              const label = track.label?.trim() || track.language?.trim()
              if (!label) return null

              return {
                id: `${source.provider?.id ?? "unknown"}-${index}`,
                language: track.language?.trim() || "und",
                label,
              }
            })
            .filter((track): track is NonNullable<typeof track> => Boolean(track))
        : [],
    }

      return applyCinemacityClientPlayback(normalized, playbackUrl)
    })
    .filter((source) => {
      const key = `${source.provider.id}:${source.quality}:${source.url}`
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
}

function normalizeSubtitles(rawSubtitles: unknown[] = [], cineproBaseUrl: string, origin: string) {
  const seen = new Set<string>()

  return rawSubtitles
    .map((item, index) => {
      if (!item || typeof item !== "object") return null
      const subtitle = item as SubtitleItem

      const src = subtitle.url ?? subtitle.file ?? subtitle.src
      if (!src) return null

      const language = subtitle.lang ?? subtitle.language ?? "und"
      const label = subtitle.label ?? language.toUpperCase()
      const id = subtitle.id ?? `${language}-${index}`
      const kind = subtitle.kind ?? "subtitles"

      return {
        id,
        label,
        language,
        kind,
        src: rewriteCineproProxyUrl(src, cineproBaseUrl, origin),
      }
    })
    .filter((item): item is NonNullable<typeof item> => Boolean(item))
    .filter((item) => {
      const key = `${item.id}:${item.src}`
      if (seen.has(key)) return false
      seen.add(key)
      return true
    })
}

function buildVidLinkStreamUrl(args: {
  requestOrigin: string
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
}) {
  const params = new URLSearchParams({
    type: args.type,
    tmdbId: args.tmdbId,
  })

  if (args.type === "tv") {
    params.set("season", args.season ?? "")
    params.set("episode", args.episode ?? "")
  }

  return `${args.requestOrigin}/api/vidlink/stream?${params.toString()}`
}

function buildFastVidlinkResponse(args: {
  vidlinkSource: ResolvedSource
  vidlinkDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  extraDiagnostics?: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
}) {
  return {
    sources: [args.vidlinkSource],
    subtitles: [],
    diagnostics: [
      {
        code: "CINEPRO_BACKGROUND",
        message:
          "VidLink returned immediately. Additional CinePro sources load in the background.",
        severity: "info" as const,
      },
      ...(args.extraDiagnostics ?? []),
      ...args.vidlinkDiagnostics,
    ],
    total: 1,
    fetchedAt: new Date().toISOString(),
  }
}

function buildNativeSourcesLoadingResponse(args: {
  vidlinkSource: ResolvedSource | null
  flixhqSource: ResolvedSource | null
  huhuSource: ResolvedSource | null
  vidlinkDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  flixhqDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  huhuDiagnostics: Array<{
    code: string
    message: string
    severity: "info" | "warning"
  }>
  streamSourcesConfig: StreamSourcesConfig
}) {
  const nativeSources = filterNativeSourcesForBulkResponse(
    args.streamSourcesConfig,
    collectNativeSources({
      vidlinkSource: args.vidlinkSource,
      flixhqSource: args.flixhqSource,
      huhuSource: args.huhuSource,
    }),
    undefined,
    { allowNativeFallbackWhenEmpty: true }
  )

  return {
    sources: nativeSources,
    subtitles: [],
    diagnostics: [
      {
        code: "CINEPRO_BACKGROUND",
        message:
          nativeSources.length > 0
            ? "Native sources returned immediately. Additional CinePro sources load in the background."
            : "Scanning providers. Playback starts when the next source responds.",
        severity: "info" as const,
      },
      ...args.vidlinkDiagnostics,
      ...args.flixhqDiagnostics,
      ...args.huhuDiagnostics,
    ],
    total: nativeSources.length,
    fetchedAt: new Date().toISOString(),
  }
}

function buildCineproScanningResponse(
  streamSourcesConfig: StreamSourcesConfig,
  viewerRole?: UserRole
) {
  return finalizeSourcePayload(
    {
      sources: [],
      subtitles: [],
      diagnostics: [
        {
          code: "CINEPRO_BACKGROUND",
          message:
            "Scanning providers. Playback starts when the next source responds.",
          severity: "info" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    },
    streamSourcesConfig,
    viewerRole
  )
}

function buildVidLinkFallback(args: {
  requestOrigin: string
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
}) {
  const vidlinkSource = {
    url: buildVidLinkStreamUrl(args),
    type: "hls",
    quality: "Auto",
    provider: {
      id: "vidlink",
      name: "VidLink",
    },
    audioTracks: [] as Array<{ id: string; language: string; label: string }>,
  }

  return {
    sources: [vidlinkSource],
    subtitles: [],
    diagnostics: [
      {
        code: "CINEPRO_ROUTE_ERROR",
        message: "Source resolver fell back to VidLink after an unexpected error.",
        severity: "warning" as const,
      },
      {
        code: "VIDLINK_SOURCE_ADDED",
        message: "VidLink source is always available in source settings for manual testing.",
        severity: "info" as const,
      },
    ],
    total: 1,
    fetchedAt: new Date().toISOString(),
  }
}

async function respondManualVidlinkRequest(args: {
  request: Request
  playbackMeta: CineproPlaybackMeta
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>
  requestOrigin: string
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
  nativeVidlinkEnabled: boolean
  viewerRole?: UserRole
}) {
  if (!VIDLINK_ENABLED || !isProviderEnabledInConfig(args.streamSourcesConfig, "vidlink", args.viewerRole)) {
    return respondSources(args.request, args.playbackMeta, {
      sources: [] as ResolvedSource[],
      subtitles: [],
      diagnostics: [
        {
          code: "VIDLINK_DISABLED",
          message: "VidLink is disabled in stream source settings.",
          severity: "warning" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    })
  }

  if (
    isVidLinkKnownUnavailable({
      tmdbId: args.tmdbId,
      type: args.type,
      season: args.season,
      episode: args.episode,
    })
  ) {
    return respondSources(args.request, args.playbackMeta, {
      sources: [] as ResolvedSource[],
      subtitles: [],
      diagnostics: [
        {
          code: "VIDLINK_UNAVAILABLE",
          message: "VidLink has no stream for this title.",
          severity: "warning" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    })
  }

  const vidlinkSource: ResolvedSource = {
    url: buildVidLinkStreamUrl({
      requestOrigin: args.requestOrigin,
      type: args.type,
      tmdbId: args.tmdbId,
      season: args.season,
      episode: args.episode,
    }),
    type: "hls",
    quality: "Auto",
    provider: {
      id: "vidlink",
      name: "VidLink",
    },
    audioTracks: [],
  }

  const configuredSources = applyStreamSourcesConfig([vidlinkSource], args.streamSourcesConfig, { viewerRole: args.viewerRole })

  return respondSources(args.request, args.playbackMeta, {
    sources: configuredSources,
    subtitles: [],
    diagnostics: [
      {
        code: "VIDLINK_MANUAL",
        message: args.nativeVidlinkEnabled
          ? "VidLink source loaded."
          : "VidLink added for manual attempt (native auto-load is disabled on server).",
        severity: "info" as const,
      },
    ],
    total: configuredSources.length,
    fetchedAt: new Date().toISOString(),
  })
}

async function respondNativeFlixhqRequest(args: {
  request: Request
  playbackMeta: CineproPlaybackMeta
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>
  requestOrigin: string
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
  nativeFlixhqEnabled: boolean
  viewerRole?: UserRole
}) {
  if (
    !FLIXHQ_ENABLED ||
    !isProviderEnabledInConfig(args.streamSourcesConfig, "flixhq", args.viewerRole)
  ) {
    return respondSources(args.request, args.playbackMeta, {
      sources: [] as ResolvedSource[],
      subtitles: [],
      diagnostics: [
        {
          code: "FLIXHQ_DISABLED",
          message: "FlixHQ is disabled in stream source settings.",
          severity: "warning" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    })
  }

  if (
    isFlixhqKnownUnavailable({
      type: args.type,
      tmdbId: args.tmdbId,
      season: args.season,
      episode: args.episode,
    })
  ) {
    return respondSources(args.request, args.playbackMeta, {
      sources: [] as ResolvedSource[],
      subtitles: [],
      diagnostics: [
        {
          code: "FLIXHQ_UNAVAILABLE",
          message: "FlixHQ: no stream for this title",
          severity: "warning" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    })
  }

  const flixhqSource: ResolvedSource = {
    url: buildFlixhqStreamUrl({
      requestOrigin: args.requestOrigin,
      type: args.type,
      tmdbId: args.tmdbId,
      season: args.season,
      episode: args.episode,
    }),
    type: "hls",
    quality: "Auto",
    provider: {
      id: "flixhq",
      name: "FlixHQ",
    },
    audioTracks: [],
  }

  const configuredSources = applyStreamSourcesConfig(
    [flixhqSource],
    args.streamSourcesConfig,
    { viewerRole: args.viewerRole }
  )

  return respondSources(args.request, args.playbackMeta, {
    sources: configuredSources,
    subtitles: [],
    diagnostics: [
      {
        code: "FLIXHQ_SOURCE_ADDED",
        message: args.nativeFlixhqEnabled
          ? "Native FlixHQ source added for playback."
          : "FlixHQ added for manual attempt (native auto-load is disabled on server).",
        severity: "info" as const,
      },
    ],
    total: configuredSources.length,
    fetchedAt: new Date().toISOString(),
  })
}

async function respondNativeHuhuRequest(args: {
  request: Request
  playbackMeta: CineproPlaybackMeta
  streamSourcesConfig: Awaited<ReturnType<typeof getStreamSourcesConfig>>
  requestOrigin: string
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
  nativeHuhuEnabled: boolean
  viewerRole?: UserRole
}) {
  if (
    !HUHU_ENABLED ||
    !isProviderEnabledInConfig(args.streamSourcesConfig, "huhu", args.viewerRole)
  ) {
    return respondSources(args.request, args.playbackMeta, {
      sources: [] as ResolvedSource[],
      subtitles: [],
      diagnostics: [
        {
          code: "HUHU_DISABLED",
          message: "Huhu is disabled in stream source settings.",
          severity: "warning" as const,
        },
      ],
      total: 0,
      fetchedAt: new Date().toISOString(),
    })
  }

  const huhuSource: ResolvedSource = {
    url: buildHuhuStreamUrl({
      requestOrigin: args.requestOrigin,
      type: args.type,
      tmdbId: args.tmdbId,
      season: args.season,
      episode: args.episode,
    }),
    type: "hls",
    quality: "Auto",
    provider: {
      id: "huhu",
      name: "Huhu",
    },
    audioTracks: [],
  }

  const configuredSources = applyStreamSourcesConfig(
    [huhuSource],
    args.streamSourcesConfig,
    { viewerRole: args.viewerRole }
  )

  return respondSources(args.request, args.playbackMeta, {
    sources: configuredSources,
    subtitles: [],
    diagnostics: [
      {
        code: "HUHU_SOURCE_ADDED",
        message: args.nativeHuhuEnabled
          ? "Native Huhu source added for playback."
          : "Huhu added for manual attempt (native auto-load is disabled on server).",
        severity: "info" as const,
      },
    ],
    total: configuredSources.length,
    fetchedAt: new Date().toISOString(),
  })
}


export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const wantsFresh = searchParams.get("fresh") === "1"
  const wantsRetry = searchParams.get("retry") === "1"
  const requestedProvider = normalizeProviderName(searchParams.get("provider") ?? "")
  const isBackgroundPoll = wantsFresh && !wantsRetry

  // Per-provider scans and background polls are one discovery flow — use the higher poll budget.
  const rateLimited = assertPlaybackRateLimit(
    request,
    requestedProvider || isBackgroundPoll ? "sourcesPoll" : "sources"
  )
  if (rateLimited) return rateLimited
  const sessionUser = await getCurrentUser(request)
  const viewerRole: UserRole = sessionUser?.role ?? "user"
  const requestOrigin = resolveRequestOrigin(request)
  const type = searchParams.get("type")
  const tmdbId = searchParams.get("tmdbId")
  const season = searchParams.get("season")
  const episode = searchParams.get("episode")

  try {
    if (!type || (type !== "movie" && type !== "tv")) {
      return playbackJsonResponse(
        { error: "Invalid 'type'. Expected 'movie' or 'tv'." },
        { status: 400 }
      )
    }

    if (!tmdbId) {
      return playbackJsonResponse(
        { error: "Missing 'tmdbId' query parameter." },
        { status: 400 }
      )
    }

    if (type === "tv" && (!season || !episode)) {
      return playbackJsonResponse(
        { error: "Missing 'season' or 'episode' for tv sources." },
        { status: 400 }
      )
    }

    const playbackMeta: CineproPlaybackMeta = {
      type,
      tmdbId,
      season: season ?? undefined,
      episode: episode ?? undefined,
    }

    const streamSourcesConfig = await getStreamSourcesConfig()
    const primaryProviderId = getPrimaryEnabledProviderId(streamSourcesConfig)
    const preferVidLinkFastPath = primaryProviderId === "vidlink"
    const vidlinkEnabled =
      VIDLINK_ENABLED && isProviderEnabledInConfig(streamSourcesConfig, "vidlink", viewerRole)
    const flixhqEnabled =
      FLIXHQ_ENABLED && isProviderEnabledInConfig(streamSourcesConfig, "flixhq", viewerRole)
    const nativeVidlinkEnabled = isNativeVidlinkEnabled()
    const nativeFlixhqEnabled = isNativeFlixhqEnabled()
    const flixhqSource =
      nativeFlixhqEnabled && flixhqEnabled
        ? {
            url: buildFlixhqStreamUrl({
              requestOrigin,
              type,
              tmdbId,
              season: season ?? undefined,
              episode: episode ?? undefined,
            }),
            type: "hls",
            quality: "Auto",
            provider: {
              id: "flixhq",
              name: "FlixHQ",
            },
            audioTracks: [] as Array<{ id: string; language: string; label: string }>,
          }
        : null
    const flixhqDiagnostics: Array<{
      code: string
      message: string
      severity: "info" | "warning"
    }> =
      nativeFlixhqEnabled && flixhqEnabled
        ? [
            {
              code: "FLIXHQ_SOURCE_ADDED",
              message: "Native FlixHQ source added for manual testing.",
              severity: "info",
            },
          ]
        : []
    const huhuEnabled =
      HUHU_ENABLED && isProviderEnabledInConfig(streamSourcesConfig, "huhu", viewerRole)
    const nativeHuhuEnabled = isNativeHuhuEnabled()
    const huhuSource =
      nativeHuhuEnabled && huhuEnabled
        ? {
            url: buildHuhuStreamUrl({
              requestOrigin,
              type,
              tmdbId,
              season: season ?? undefined,
              episode: episode ?? undefined,
            }),
            type: "hls",
            quality: "Auto",
            provider: {
              id: "huhu",
              name: "Huhu",
            },
            audioTracks: [] as Array<{ id: string; language: string; label: string }>,
          }
        : null
    const huhuDiagnostics: Array<{
      code: string
      message: string
      severity: "info" | "warning"
    }> =
      nativeHuhuEnabled && huhuEnabled
        ? [
            {
              code: "HUHU_SOURCE_ADDED",
              message: "Native Huhu source added for manual testing.",
              severity: "info",
            },
          ]
        : []

    if (requestedProvider === "vidlink") {
      return respondManualVidlinkRequest({
        request,
        playbackMeta,
        streamSourcesConfig,
        requestOrigin,
        type,
        tmdbId,
        season: season ?? undefined,
        episode: episode ?? undefined,
        nativeVidlinkEnabled,
        viewerRole,
      })
    }

    if (requestedProvider === "flixhq") {
      return respondNativeFlixhqRequest({
        request,
        playbackMeta,
        streamSourcesConfig,
        requestOrigin,
        type,
        tmdbId,
        season: season ?? undefined,
        episode: episode ?? undefined,
        nativeFlixhqEnabled,
        viewerRole,
      })
    }

    if (requestedProvider === "huhu") {
      return respondNativeHuhuRequest({
        request,
        playbackMeta,
        streamSourcesConfig,
        requestOrigin,
        type,
        tmdbId,
        season: season ?? undefined,
        episode: episode ?? undefined,
        nativeHuhuEnabled,
        viewerRole,
      })
    }

    const vidlinkSource =
      nativeVidlinkEnabled && vidlinkEnabled
      ? {
      url: buildVidLinkStreamUrl({
        requestOrigin,
        type,
        tmdbId,
        season: season ?? undefined,
        episode: episode ?? undefined,
      }),
      type: "hls",
      quality: "Auto",
      provider: {
        id: "vidlink",
        name: "VidLink",
      },
      audioTracks: [] as Array<{ id: string; language: string; label: string }>,
    }
      : null

    const vidlinkDiagnostics: Array<{
      code: string
      message: string
      severity: "info" | "warning"
    }> =
      nativeVidlinkEnabled && vidlinkEnabled
        ? [
      {
        code: "VIDLINK_SOURCE_ADDED",
              message: "Native VidLink source added for manual testing.",
        severity: "info",
      },
    ]
        : []

    const cacheKey = buildCacheKey(searchParams)

    if (wantsFresh && !requestedProvider) {
      const merged = await waitForMergedSources(
        cacheKey,
        backgroundCineproFetches.has(cacheKey) ? 5_000 : 0
      )
      if (merged) {
        return respondSources(
          request,
          playbackMeta,
          refilterPayloadWithConfig(
            resolveCachedPayload(cacheKey, merged, undefined),
            streamSourcesConfig,
            viewerRole
          )
        )
      }
    }

    const rawCineproUrl = process.env.CINEPRO_URL?.trim()
    const cineproBaseUrl = rawCineproUrl ? normalizeCineproBaseUrl(rawCineproUrl) : undefined

    if (wantsRetry) {
      const stale = getCached(cacheKey)
      if (cineproBaseUrl && stale) {
        await refreshCineproSourceCache(
          cineproBaseUrl,
          (stale as { cineproResponseId?: string }).cineproResponseId
        )
      }
      deleteCineproSourceCache(cacheKey)
    }

    const cached = getCached(cacheKey)
    if (cached && !requestedProvider) {
      if (
        cineproBaseUrl &&
        cachedPayloadMissingEnabledProviders(cached, streamSourcesConfig, viewerRole)
      ) {
        await refreshCineproSourceCache(
          cineproBaseUrl,
          getCineproResponseId(cached)
        )
        deleteCineproSourceCache(cacheKey)
      } else {
        return respondSources(
          request,
          playbackMeta,
          refilterPayloadWithConfig(
            resolveCachedPayload(cacheKey, cached, cineproBaseUrl),
            streamSourcesConfig,
            viewerRole
          )
        )
      }
    }

    if (!cineproBaseUrl) {
      const nativeSources = collectNativeSources({ vidlinkSource, flixhqSource, huhuSource })
      const result = finalizeSourcePayload(
        {
          sources: nativeSources,
        subtitles: [],
        diagnostics: [
          {
            code: "CINEPRO_NOT_CONFIGURED",
              message: vidlinkEnabled || flixhqEnabled || huhuEnabled
                ? "CINEPRO_URL is not set. Using fallback embed providers."
                : "CINEPRO_URL is not set. Configure CinePro Core to enable playback.",
            severity: "warning",
          },
          ...vidlinkDiagnostics,
          ...flixhqDiagnostics,
        ...huhuDiagnostics,
        ],
          total: nativeSources.length,
        fetchedAt: new Date().toISOString(),
        }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })

      setCached(cacheKey, result)
      return respondSources(request, playbackMeta, result)
    }

    if (
      requestedProvider &&
      isProviderEnabledInConfig(streamSourcesConfig, requestedProvider, viewerRole)
    ) {
      if (isClientResolveProvider(requestedProvider)) {
        const providerLabel = providerDisplayLabel(requestedProvider)
        const clientPending = finalizeSourcePayload(
          {
            sources: [],
            subtitles: [],
            diagnostics: [
              {
                code: "CLIENT_PROVIDER_PENDING",
                message: `${providerLabel} resolves in your browser (not on the server).`,
                severity: "info",
              },
            ],
            total: 0,
            fetchedAt: new Date().toISOString(),
          },
          streamSourcesConfig,
          viewerRole,
          { requestedProvider }
        )

        return respondSources(request, playbackMeta, clientPending)
      }

      const providerEndpoints = buildSingleProviderEndpoints(
        type,
        cineproBaseUrl,
        tmdbId,
        requestedProvider,
        season ?? undefined,
        episode ?? undefined,
        wantsFresh
      )

      if (providerEndpoints.length === 0) {
        return playbackJsonResponse(
          { error: "Missing season or episode for tv provider lookup." },
          { status: 400 }
        )
      }

      const providerLabel = providerDisplayLabel(requestedProvider)
      const providerResult = await fetchCineproPayload(
        providerEndpoints,
        CINEPRO_SINGLE_PROVIDER_FETCH_TIMEOUT_MS
      )
      let providerPayload = providerResult?.payload

      const providerPayloadHasStreams = Boolean(
        providerPayload &&
          (payloadHasProviderSources(providerPayload, requestedProvider) ||
            payloadHasPlayableSources(providerPayload))
      )

      if (!providerPayloadHasStreams && type === "tv" && season && episode) {
        const bulkResult = await fetchCineproPayload(
          buildBulkTvEndpoints(
            cineproBaseUrl,
            tmdbId,
            season,
            episode,
            wantsFresh
          ),
          CINEPRO_SINGLE_PROVIDER_FETCH_TIMEOUT_MS
        )
        providerPayload = filterCineproPayloadByProvider(
          bulkResult?.payload,
          requestedProvider
        )
      }

      if (
        providerPayload &&
        (payloadHasProviderSources(providerPayload, requestedProvider) ||
          payloadHasPlayableSources(providerPayload))
      ) {
        const result = buildMergedResult({
          vidlinkSource,
          flixhqSource,
          huhuSource,
          payload: providerPayload,
          cineproBaseUrl,
          requestOrigin,
          vidlinkDiagnostics,
          flixhqDiagnostics,
          huhuDiagnostics,
          streamSourcesConfig,
          requestedProvider,
          extraDiagnostics: [
            {
              code: "CINEPRO_PROVIDER",
              message: `Loaded ${providerLabel}.`,
              severity: "info",
            },
          ],
          viewerRole,
        })

        return respondSources(request, playbackMeta, result)
      }

      const emptyResult = finalizeSourcePayload(
        {
          sources: [],
          subtitles: [],
          diagnostics: [
            {
              code: "PROVIDER_ERROR",
              message: `${providerLabel}: no stream for this title`,
              severity: "warning",
            },
          ],
          total: 0,
          fetchedAt: new Date().toISOString(),
        }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })

      return respondSources(request, playbackMeta, emptyResult)
    }

    if (cineproBaseUrl === requestOrigin) {
      const nativeSources = collectNativeSources({ vidlinkSource, flixhqSource, huhuSource })
      const result = finalizeSourcePayload(
        {
          sources: nativeSources,
        subtitles: [],
        diagnostics: [
          {
            code: "CINEPRO_SELF_REFERENCE",
            message:
              "CINEPRO_URL points to the current app origin. Set it to your CinePro Core server URL.",
            severity: "warning",
          },
          ...vidlinkDiagnostics,
          ...flixhqDiagnostics,
        ...huhuDiagnostics,
        ],
          total: nativeSources.length,
        fetchedAt: new Date().toISOString(),
        }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })

      setCached(cacheKey, result)
      return respondSources(request, playbackMeta, result)
    }

    const endpoints =
      type === "movie"
        ? [
            `${cineproBaseUrl}/v1/movies/${tmdbId}${wantsFresh ? "?fresh=true" : ""}`,
            `${cineproBaseUrl}/stream/movie/${tmdbId}${wantsFresh ? "?fresh=true" : ""}`,
            `${cineproBaseUrl}/api/stream/movie/${tmdbId}${wantsFresh ? "?fresh=true" : ""}`,
          ]
        : buildBulkTvEndpoints(cineproBaseUrl, tmdbId, season!, episode!, wantsFresh)

    if (vidlinkSource || flixhqSource || huhuSource) {
      const vidlinkKnownUnavailable = isVidLinkKnownUnavailable({
        tmdbId,
        type,
        season: season ?? undefined,
        episode: episode ?? undefined,
      })

      if (!vidlinkKnownUnavailable && wantsFresh && !requestedProvider) {
        const merged = await waitForMergedSources(
          cacheKey,
          backgroundCineproFetches.has(cacheKey) ? 8_000 : 0
        )
        if (merged) {
          return respondSources(
            request,
            playbackMeta,
            refilterPayloadWithConfig(
              resolveCachedPayload(cacheKey, merged, cineproBaseUrl),
              streamSourcesConfig,
              viewerRole
            )
          )
        }

        const pendingFast = getCached(cacheKey)
        if (pendingFast) {
          return respondSources(
            request,
            playbackMeta,
            refilterPayloadWithConfig(
              resolveCachedPayload(cacheKey, pendingFast, cineproBaseUrl),
              streamSourcesConfig,
              viewerRole
            )
          )
        }
      }

      if (!wantsRetry) {
        if (vidlinkSource && !vidlinkKnownUnavailable && preferVidLinkFastPath) {
          const fastResponse = finalizeSourcePayload(
            buildFastVidlinkResponse({
              vidlinkSource,
              vidlinkDiagnostics,
              extraDiagnostics: flixhqDiagnostics,
            }), streamSourcesConfig, viewerRole)

          setCached(cacheKey, fastResponse)
          scheduleBackgroundCineproFetch({
            cacheKey,
            endpoints,
            vidlinkSource,
            flixhqSource,
            huhuSource,
            cineproBaseUrl,
            requestOrigin,
            vidlinkDiagnostics,
            flixhqDiagnostics,
            huhuDiagnostics,
            streamSourcesConfig,
          viewerRole,
          })

          return respondSources(request, playbackMeta, fastResponse)
        }

        scheduleBackgroundCineproFetch({
          cacheKey,
          endpoints,
          vidlinkSource,
          flixhqSource,
          huhuSource,
          cineproBaseUrl,
          requestOrigin,
          vidlinkDiagnostics,
          flixhqDiagnostics,
          huhuDiagnostics,
          streamSourcesConfig,
          viewerRole,
        })

        if (!shouldOfferNativeImmediateFallback(streamSourcesConfig)) {
          return respondSources(
            request,
            playbackMeta,
            buildCineproScanningResponse(streamSourcesConfig, viewerRole)
          )
        }

        const loadingResponse = finalizeSourcePayload(
          buildNativeSourcesLoadingResponse({
            vidlinkSource,
            flixhqSource,
            huhuSource,
            vidlinkDiagnostics,
            flixhqDiagnostics,
            huhuDiagnostics,
            streamSourcesConfig,
          }),
          streamSourcesConfig,
          viewerRole,
          { immediatePlayback: true }
        )

        return respondSources(request, playbackMeta, loadingResponse)
      }

      if (!vidlinkKnownUnavailable) {
        const cineproResult = await fetchCineproPayload(
          endpoints,
          CINEPRO_FETCH_TIMEOUT_MS
        )

        if (cineproResult?.payload) {
          const result = buildMergedResult({
            vidlinkSource,
            flixhqSource,
            huhuSource,
            payload: cineproResult.payload,
            cineproBaseUrl,
            requestOrigin,
            vidlinkDiagnostics,
            flixhqDiagnostics,
            huhuDiagnostics,
            streamSourcesConfig,
          viewerRole,
          })

          setCached(cacheKey, result)
          return respondSources(request, playbackMeta, result)
        }

        const offline = isCineproConnectionFailure(cineproResult)
        const nativeSources = collectNativeSources({ vidlinkSource, flixhqSource, huhuSource })
        const fallbackResult = finalizeSourcePayload(
          {
            sources: nativeSources,
            subtitles: [],
            diagnostics: [
              offline
                ? buildCineproOfflineDiagnostic(cineproBaseUrl)
                : buildCineproUnavailableDiagnostic(cineproBaseUrl),
              ...vidlinkDiagnostics,
              ...flixhqDiagnostics,
            ...huhuDiagnostics,
            ],
            total: nativeSources.length,
            fetchedAt: new Date().toISOString(),
          }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })

        setCached(
          cacheKey,
          fallbackResult,
          offline ? CINEPRO_OFFLINE_CACHE_MS : CACHE_TTL_MS
        )
        return respondSources(request, playbackMeta, fallbackResult)
      }

      scheduleBackgroundCineproFetch({
        cacheKey,
        endpoints,
        vidlinkSource: null,
        flixhqSource,
        huhuSource,
        cineproBaseUrl,
        requestOrigin,
        vidlinkDiagnostics: [],
        flixhqDiagnostics,
        huhuDiagnostics,
        streamSourcesConfig,
        viewerRole,
      })

      const unavailableDiagnostics = [
        {
          code: "CINEPRO_BACKGROUND",
          message:
            "Scanning providers. Playback starts when the next source responds.",
          severity: "info" as const,
        },
      ]

      if (vidlinkSource) {
        unavailableDiagnostics.push({
          code: "VIDLINK_UNAVAILABLE",
          message: "VidLink has no stream for this title.",
          severity: "warning" as const,
        })
      }

      const loadingResponse = shouldOfferNativeImmediateFallback(streamSourcesConfig)
        ? finalizeSourcePayload(
            buildNativeSourcesLoadingResponse({
              vidlinkSource: null,
              flixhqSource,
              huhuSource,
              vidlinkDiagnostics: [],
              flixhqDiagnostics,
              huhuDiagnostics,
              streamSourcesConfig,
            }),
            streamSourcesConfig,
            viewerRole,
            { immediatePlayback: true }
          )
        : buildCineproScanningResponse(streamSourcesConfig, viewerRole)

      return respondSources(request, playbackMeta, {
        ...loadingResponse,
        diagnostics: [...unavailableDiagnostics, ...(loadingResponse.diagnostics ?? [])],
      })
    }

    const cinemacityOnly = isCinemacityOnlyConfig(streamSourcesConfig)
    const cineproFetchTimeoutMs = cinemacityOnly
      ? CINEMACITY_CINEPRO_FETCH_TIMEOUT_MS
      : CINEPRO_FETCH_TIMEOUT_MS

    if (!wantsRetry && !wantsFresh) {
      scheduleBackgroundCineproFetch({
        cacheKey,
        endpoints,
        vidlinkSource,
        flixhqSource,
        huhuSource,
        cineproBaseUrl,
        requestOrigin,
        vidlinkDiagnostics,
        flixhqDiagnostics,
        huhuDiagnostics,
        streamSourcesConfig,
        viewerRole,
      })

      const loadingResponse = finalizeSourcePayload(
        {
          sources: [],
          subtitles: [],
          diagnostics: [
            {
              code: "CINEPRO_BACKGROUND",
              message:
                "Scanning providers. Playback starts when the next source responds.",
              severity: "info",
            },
          ],
          total: 0,
          fetchedAt: new Date().toISOString(),
        }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })

      return respondSources(request, playbackMeta, loadingResponse)
    }

    const cineproResult = await fetchCineproPayload(
      [endpoints[0]],
      cineproFetchTimeoutMs
    )
    const payload = cineproResult?.payload
    const lastStatus = cineproResult?.lastStatus
    const lastError = cineproResult?.lastError

    if (!payload) {
      const fallbackSources = collectNativeSources({ vidlinkSource, flixhqSource, huhuSource })
      const result = finalizeSourcePayload(
        {
        sources: fallbackSources,
        subtitles: [],
        diagnostics: [
          {
            code: "CINEPRO_UNAVAILABLE",
              message: `CinePro did not return sources (last status: ${lastStatus ?? "unknown"}${lastError ? `, error: ${lastError}` : ""}). Make sure CinePro Core is running at ${cineproBaseUrl}.`,
            severity: "warning",
          },
          ...vidlinkDiagnostics,
          ...flixhqDiagnostics,
        ...huhuDiagnostics,
        ],
        total: fallbackSources.length,
        fetchedAt: new Date().toISOString(),
        }, streamSourcesConfig, viewerRole, { requestedProvider: requestedProvider || undefined })
      return respondSources(request, playbackMeta, result)
    }

    const result = buildMergedResult({
      vidlinkSource,
      flixhqSource,
      huhuSource,
      payload,
      cineproBaseUrl,
      requestOrigin,
      vidlinkDiagnostics,
      flixhqDiagnostics,
      huhuDiagnostics,
      streamSourcesConfig,
    viewerRole,
    })

    setCached(cacheKey, result)

    return respondSources(request, playbackMeta, result)
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unknown error"

    if (
      tmdbId &&
      type &&
      (type === "movie" || (type === "tv" && season && episode))
    ) {
      const fallbackConfig = await getStreamSourcesConfig()
      const fallbackVidlinkEnabled =
        isNativeVidlinkEnabled() &&
        VIDLINK_ENABLED &&
        isProviderEnabledInConfig(fallbackConfig, "vidlink", viewerRole)

      if (fallbackVidlinkEnabled) {
        const fallback = finalizeSourcePayload(
          buildVidLinkFallback({
        requestOrigin,
        type,
        tmdbId,
        season: season ?? undefined,
        episode: episode ?? undefined,
          }), fallbackConfig, viewerRole)

      fallback.diagnostics[0].message = `Source resolver fell back to VidLink: ${message}`

        const fallbackPlaybackMeta: CineproPlaybackMeta = {
          type,
          tmdbId,
          season: season ?? undefined,
          episode: episode ?? undefined,
        }

        return respondSources(request, fallbackPlaybackMeta, fallback)
      }
    }

    return playbackJsonResponse(
      {
        error: `Source resolver failed: ${message}`,
      },
      { status: 500 }
    )
  }
}
