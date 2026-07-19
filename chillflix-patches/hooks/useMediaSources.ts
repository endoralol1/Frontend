"use client"

import { useCallback, useEffect, useRef, useState } from "react"

import {
    getOrderedEnabledProviderIds,
    isBackgroundSourcesPending,
    isVidLinkOnlySources,
    normalizeCinemacityCdnPlaybackUrl,
    parseUnavailableProviders,
    playbackUrlWithoutToken,
    TERMINAL_SOURCE_DIAGNOSTIC_CODES,
} from "@/components/player/utils/playback"
import {
  isClientResolveProvider,
  resolveClientProviderSources,
  stripClientProviderServerDiagnostics,
} from "@/lib/client-resolve-providers"
import { buildEmergencyVidlinkPayload } from "@/lib/emergency-vidlink-source"
import { stripNativeFlixhqSources } from "@/lib/native-flixhq"
import { stripNativeHuhuSources } from "@/lib/native-huhu"
import { isNativeChillflixProvider } from "@/lib/native-chillflix-providers"
import { stripNativeVidlinkSources } from "@/lib/native-vidlink"
import {
  PLAYBACK_MAX_AGE_SEC,
  requiresPlaybackTokenUrl,
  withPlaybackToken,
} from "@/lib/playback-token"
import {
    ensureStreamSourcesConfigReady,
    getEnabledProviderIds,
    isStreamSourcesConfigReady,
} from "@/hooks/useStreamSourcesConfig"
import {
    filterSourcesByEnabledIds,
    STREAM_SOURCES_CONFIG_EVENT,
} from "@/lib/stream-sources-client"
import { resolveTitleUnavailableMessage } from "@/lib/playback-user-messages"
import {
  INITIAL_SOURCES_FETCH_TIMEOUT_MS,
  PRIMARY_PROVIDER_HEAD_START_MS,
  PRIMARY_PROVIDER_RETRY_MS,
  PROVIDER_SOURCE_FETCH_TIMEOUT_MS,
} from "@/lib/source-probe-constants"
import { MANUAL_ONLY_PROVIDER_IDS, normalizeProviderName } from "@/lib/stream-sources-defaults"
import { stripVidrockServerSourcesWhenClientTest } from "@/lib/vidrock-client-test"
import {
  buildMediaSourcesCacheKey,
  prefetchMediaSources,
  schedulePrefetchMediaSources,
  type PrefetchMediaSourcesParams,
} from "@/lib/prefetch-media-sources"

export {
  buildMediaSourcesCacheKey,
  prefetchMediaSources,
  schedulePrefetchMediaSources,
}
export type { UseMediaSourcesParams }

function stripNativePlaybackSources<T extends { url: string }>(
  sources: T[],
  options?: { manual?: boolean; provider?: string }
) {
  const manualNativeProvider = Boolean(
    options?.provider && isNativeChillflixProvider(options.provider)
  )

  return stripNativeHuhuSources(
    stripNativeFlixhqSources(stripNativeVidlinkSources(sources, options), options),
    { manual: options?.manual || manualNativeProvider }
  )
}

interface MediaSource {
  url: string
  type: string
  quality: string
  provider: { id: string; name: string }
  audioTracks: Array<{ id: string; language: string; label: string }>
  cinemacityPageUrl?: string
  directClientPlayback?: boolean
  clientPlaybackHeaders?: Record<string, string>
}

interface MediaSubtitle {
  id: string
  label: string
  language: string
  kind: string
  src: string
}

interface MediaDiagnostic {
  code: string
  message: string
  severity: string
}

type UseMediaSourcesParams = PrefetchMediaSourcesParams & {
  enabled?: boolean
  /** When true, skip background source polling/scans — playback is already running. */
  playbackActive?: boolean
  /** Embed / third-party iframe: start on first CinePro batch, defer full provider walk. */
  embedFastStart?: boolean
}

interface SourcesPayload {
  sources: MediaSource[]
  subtitles: MediaSubtitle[]
  diagnostics: MediaDiagnostic[]
}

interface UseMediaSourcesResult {
  sources: MediaSource[]
  subtitles: MediaSubtitle[]
  diagnostics: MediaDiagnostic[]
  playbackToken?: string
  isLoading: boolean
  isLoadingMore: boolean
  error: string | undefined
  refetchSources: () => void
  refetchSourcesAsync: () => Promise<SourcesPayload>
  requestProvider: (providerId: string) => Promise<SourcesPayload>
  scanningProviderId?: string
  scanFailedProviderIds: string[]
  isScanningProviders: boolean
  providerScanStartedAt?: number
  resolvedParamsKey: string
}

const FETCH_TIMEOUT_MS = 65_000
const PROVIDER_FETCH_TIMEOUT_MS = PROVIDER_SOURCE_FETCH_TIMEOUT_MS
const OFFLINE_RETRY_MS = 10_000
const BACKGROUND_POLL_MS = 8_000
const BACKGROUND_POLL_FIRST_MS = 2_000
const BACKGROUND_PENDING_CAP_MS = 8_000
const INITIAL_LOADING_CAP_MS = 12_000
const PROVIDER_BOOTSTRAP_DELAY_MS = 300
const BACKGROUND_MERGE_KICK_MS = 1_500
const SOURCE_FETCH_ATTEMPTS = 4
const RETRYABLE_HTTP_STATUSES = new Set([408, 500, 502, 503, 504])
const TOKEN_REFRESH_LEAD_MS = 30 * 60 * 1000
const TOKEN_REFRESH_INTERVAL_MS = PLAYBACK_MAX_AGE_SEC * 1000 - TOKEN_REFRESH_LEAD_MS

function sleep(ms: number) {
  return new Promise<void>((resolve) => window.setTimeout(resolve, ms))
}

function fetchSourcesWithTimeout(
  fetchSources: (
    options?: {
      retry?: boolean
      fresh?: boolean
      merge?: boolean
      provider?: string
      preserveNativeVidlink?: boolean
    }
  ) => Promise<SourcesPayload>,
  options?: {
    retry?: boolean
    fresh?: boolean
    merge?: boolean
    provider?: string
    preserveNativeVidlink?: boolean
  }
) {
  return Promise.race([
    fetchSources(options),
    sleep(PROVIDER_FETCH_TIMEOUT_MS).then(() => {
      throw new Error("Provider scan timed out")
    }),
  ])
}

function purgeMalformedCinemacitySourceCaches() {
  if (typeof window === "undefined") return

  try {
    for (let index = localStorage.length - 1; index >= 0; index -= 1) {
      const key = localStorage.key(index)
      if (!key?.startsWith("media-sources:")) continue

      const raw = localStorage.getItem(key)
      if (!raw) continue

      if (raw.includes(",urlset") || raw.includes("public_files/,")) {
        localStorage.removeItem(key)
        continue
      }

      try {
        const parsed = JSON.parse(raw) as { sources?: Array<{ url?: string }> }
        const hasMalformedUrl = (parsed.sources ?? []).some((source) =>
          source.url?.includes(",urlset")
        )
        if (hasMalformedUrl) {
          localStorage.removeItem(key)
        }
      } catch {
        localStorage.removeItem(key)
      }
    }
  } catch {
    // ignore
  }
}

if (typeof window !== "undefined") {
  purgeMalformedCinemacitySourceCaches()
}

const buildCacheKey = buildMediaSourcesCacheKey
const sourcesFetchInflight = new Map<string, Promise<SourcesPayload>>()

function buildSourcesFetchDedupeKey(
  params: URLSearchParams,
  options?: { provider?: string }
) {
  if (options?.provider) {
    return params.toString()
  }

  return [
    params.get("type") ?? "",
    params.get("tmdbId") ?? "",
    params.get("season") ?? "",
    params.get("episode") ?? "",
  ].join("|")
}

function shouldAutoRetry(diagnostics: MediaDiagnostic[]) {
  return diagnostics.some((item) => item.code === "CINEPRO_OFFLINE")
}

function refreshSourcePlaybackToken(url: string, token: string) {
  if (!requiresPlaybackTokenUrl(url)) {
    return url
  }

  return withPlaybackToken(url, token)
}

function sourcesHavePlaybackTokens(sources: unknown) {
  if (!Array.isArray(sources)) return false

  return sources.every((source) => {
    if (!source || typeof source !== "object") return false
    const url = (source as MediaSource).url
    if (typeof url !== "string" || !url) return false
    if (!requiresPlaybackTokenUrl(url)) return true
    return url.includes("pt=")
  })
}

function sourcesIncludeProvider(sources: MediaSource[], providerId: string) {
  const key = normalizeProviderName(providerId)
  return sources.some((source) => normalizeProviderName(source.provider.id) === key)
}

function withoutManualOnlyProviders(providerIds: string[]) {
  return providerIds.filter(
    (id) => !MANUAL_ONLY_PROVIDER_IDS.has(normalizeProviderName(id))
  )
}

function filterSourcesForEnabledProviders(sources: MediaSource[]) {
  if (!isStreamSourcesConfigReady()) {
    return sources
  }

  const enabledIds = getEnabledProviderIds()
  if (enabledIds.length === 0) {
    return []
  }

  return filterSourcesByEnabledIds(sources, enabledIds)
}

function sourceMergeKey(source: MediaSource) {
  return `${normalizeProviderName(source.provider.id)}:${source.quality}:${source.type}`
}

function mergeSources(existing: MediaSource[], incoming: MediaSource[], token?: string) {
  const byKey = new Map<string, MediaSource>()
  const seenUrls = new Set<string>()

  const sealUrl = (url: string) => (token ? refreshSourcePlaybackToken(url, token) : url)

  for (const source of existing) {
    const key = sourceMergeKey(source)
    const urlKey = playbackUrlWithoutToken(source.url)
    byKey.set(key, { ...source, url: sealUrl(source.url) })
    seenUrls.add(urlKey)
  }

  for (const source of incoming) {
    const key = sourceMergeKey(source)
    const urlKey = playbackUrlWithoutToken(source.url)
    const previous = byKey.get(key)

    if (previous) {
      byKey.set(key, {
        ...previous,
        ...source,
        url: sealUrl(previous.url),
      })
      continue
    }

    if (seenUrls.has(urlKey)) {
      continue
    }

    byKey.set(key, { ...source, url: sealUrl(source.url) })
    seenUrls.add(urlKey)
  }

  return Array.from(byKey.values())
}

export function useMediaSources({
  id,
  type,
  season,
  episode,
  enabled = true,
  playbackActive = false,
  embedFastStart = false,
}: UseMediaSourcesParams): UseMediaSourcesResult {
  const [sources, setSources] = useState<MediaSource[]>([])
  const [subtitles, setSubtitles] = useState<MediaSubtitle[]>([])
  const [diagnostics, setDiagnostics] = useState<MediaDiagnostic[]>([])
  const [playbackToken, setPlaybackToken] = useState<string>()
  const [isLoading, setIsLoading] = useState(true)
  const [isRefreshing, setIsRefreshing] = useState(false)
  const [scanningProviderId, setScanningProviderId] = useState<string | undefined>()
  const [providerScanStartedAt, setProviderScanStartedAt] = useState<number | undefined>()
  const [scanFailedProviderIds, setScanFailedProviderIds] = useState<string[]>([])
  const [isScanningProviders, setIsScanningProviders] = useState(false)
  const [error, setError] = useState<string>()
  const [resolvedParamsKey, setResolvedParamsKey] = useState("")
  const [streamSourcesReady, setStreamSourcesReady] = useState(isStreamSourcesConfigReady())
  const paramsKey = `${id}|${type}|${season ?? ""}|${episode ?? ""}`
  const sourcesRef = useRef(sources)
  const playbackTokenRef = useRef(playbackToken)
  const playbackActiveRef = useRef(playbackActive)
  const embedFastStartRef = useRef(embedFastStart)
  const diagnosticsRef = useRef(diagnostics)
  const providerScanAttemptedRef = useRef(false)
  const providerBootstrapAttemptedRef = useRef(false)
  const scanFailedRef = useRef<Set<string>>(new Set())
  const fetchGenerationRef = useRef(0)
  const [backgroundPendingCapReached, setBackgroundPendingCapReached] = useState(false)

  useEffect(() => {
    if (!enabled) {
      providerScanAttemptedRef.current = false
      scanFailedRef.current = new Set()
      setScanFailedProviderIds([])
      setScanningProviderId(undefined)
      setProviderScanStartedAt(undefined)
      setIsScanningProviders(false)
    }
  }, [enabled])

  useEffect(() => {
    sourcesRef.current = sources
  }, [sources])

  useEffect(() => {
    playbackTokenRef.current = playbackToken
  }, [playbackToken])

  useEffect(() => {
    playbackActiveRef.current = playbackActive
  }, [playbackActive])

  useEffect(() => {
    embedFastStartRef.current = embedFastStart
  }, [embedFastStart])

  useEffect(() => {
    diagnosticsRef.current = diagnostics
  }, [diagnostics])

  const buildSearchParams = useCallback(
    (options?: { retry?: boolean; fresh?: boolean; provider?: string }) => {
      const params = new URLSearchParams({
        type,
        tmdbId: String(id),
        ...(type === "tv" && season !== undefined ? { season: String(season) } : {}),
        ...(type === "tv" && episode !== undefined ? { episode: String(episode) } : {}),
      })

      if (options?.retry) params.set("retry", "1")
      if (options?.fresh) params.set("fresh", "1")
      if (options?.provider) params.set("provider", options.provider)

      return params
    },
    [episode, id, season, type]
  )

  const applyPayload = useCallback(
    (
      data: {
        sources?: MediaSource[]
        subtitles?: MediaSubtitle[]
        diagnostics?: MediaDiagnostic[]
      },
      options?: { merge?: boolean; preserveNativeVidlink?: boolean; provider?: string }
    ): SourcesPayload => {
      const cacheKey = buildCacheKey({ id, type, season, episode })
      let incomingSources = stripVidrockServerSourcesWhenClientTest(
        filterSourcesForEnabledProviders(
          stripNativePlaybackSources(data.sources ?? [], {
            manual: options?.preserveNativeVidlink,
            provider: options?.provider,
          }).map((source) => ({
            ...source,
            url: normalizeCinemacityCdnPlaybackUrl(source.url),
          }))
        )
      )
      const nextSubtitles = data.subtitles ?? []
      const nextDiagnostics = stripClientProviderServerDiagnostics(data.diagnostics ?? [])
      const nextPlaybackToken =
        data && typeof data === "object" && "playbackToken" in data
          ? String((data as { playbackToken?: string }).playbackToken ?? "")
          : ""
      const existingToken = playbackTokenRef.current
      const mergeToken = options?.merge && existingToken ? existingToken : nextPlaybackToken || undefined

      if (options?.merge && existingToken) {
        incomingSources = incomingSources.map((source) => ({
          ...source,
          url: refreshSourcePlaybackToken(source.url, existingToken),
        }))
      } else if (nextPlaybackToken) {
        setPlaybackToken(nextPlaybackToken)
      }

      let resolvedSources = incomingSources
      setSources((previous) => {
        resolvedSources = options?.merge
          ? mergeSources(previous, incomingSources, mergeToken)
          : incomingSources
        return resolvedSources
      })
      setSubtitles((previous) => (nextSubtitles.length > 0 ? nextSubtitles : previous))
      setDiagnostics((previous) => {
        const stripBackgroundWhenReady = (
          items: MediaDiagnostic[],
          sourceCount: number
        ) =>
          sourceCount > 0
            ? items.filter((item) => item.code !== "CINEPRO_BACKGROUND")
            : items

        if (!options?.merge) {
          return stripClientProviderServerDiagnostics(
            stripBackgroundWhenReady(nextDiagnostics, resolvedSources.length)
          )
        }

        const previousTerminal = previous.some((item) =>
          TERMINAL_SOURCE_DIAGNOSTIC_CODES.has(item.code ?? "")
        )
        const filteredIncoming = previousTerminal
          ? nextDiagnostics.filter((item) => item.code !== "CINEPRO_BACKGROUND")
          : nextDiagnostics

        const seen = new Set(
          filteredIncoming.map((item) => `${item.code}:${item.message}`)
        )
        const merged = [...filteredIncoming]

        for (const item of previous) {
          const key = `${item.code}:${item.message}`
          if (seen.has(key)) continue
          seen.add(key)
          merged.push(item)
        }

        return stripClientProviderServerDiagnostics(
          stripBackgroundWhenReady(merged, resolvedSources.length)
        )
      })

      setError(
        resolvedSources.length === 0
          ? resolveTitleUnavailableMessage(nextDiagnostics)
          : undefined
      )

      if (resolvedSources.length > 0) {
        setIsLoading(false)
        setIsRefreshing(false)
        setResolvedParamsKey(cacheKey)
        try {
          localStorage.setItem(
            cacheKey,
            JSON.stringify({
              sources: resolvedSources,
              subtitles: nextSubtitles,
              diagnostics: nextDiagnostics,
              playbackToken:
                options?.merge && existingToken
                  ? existingToken
                  : nextPlaybackToken || playbackTokenRef.current,
              expiresAt: Date.now() + 5 * 60 * 1000,
            })
          )
        } catch {
          // ignore
        }
      }

      return {
        sources: resolvedSources,
        subtitles: nextSubtitles,
        diagnostics: nextDiagnostics,
      }
    },
    [episode, id, season, type]
  )

  const applyEmergencyFallback = useCallback(
    async (reason: string, options?: { merge?: boolean }) => {
      const payload = buildEmergencyVidlinkPayload(
        { id, type, season, episode },
        reason
      )

      try {
        const mintParams = new URLSearchParams({
          type,
          tmdbId: String(id),
        })

        if (type === "tv") {
          mintParams.set("season", String(season ?? ""))
          mintParams.set("episode", String(episode ?? ""))
        }

        const mintResponse = await fetch(`/api/playback/mint?${mintParams}`)
        const mintData = await mintResponse.json()

        if (mintResponse.ok && mintData.playbackToken) {
          const token = String(mintData.playbackToken)
          setPlaybackToken(token)
          payload.sources = payload.sources.map((source) => ({
            ...source,
            url: refreshSourcePlaybackToken(source.url, token),
          }))
        }
      } catch {
        // Fall back to payload without a token if mint fails.
      }

      return applyPayload(payload, options)
    },
    [applyPayload, episode, id, season, type]
  )

  const fetchClientProvider = useCallback(
    async (providerId: string, playbackToken?: string) => {
      let token = playbackToken

      if (!token) {
        try {
          const mintParams = new URLSearchParams({
            type,
            tmdbId: String(id),
          })

          if (type === "tv") {
            mintParams.set("season", String(season ?? ""))
            mintParams.set("episode", String(episode ?? ""))
          }

          const mintResponse = await fetch(`/api/playback/mint?${mintParams}`)
          const mintData = await mintResponse.json()

          if (mintResponse.ok && mintData.playbackToken) {
            token = String(mintData.playbackToken)
            setPlaybackToken(token)
          }
        } catch {
          // continue without token; proxy may still work for some paths
        }
      }

      const result = await resolveClientProviderSources(providerId, {
        type,
        tmdbId: String(id),
        // Movies must not inherit leftover season/episode from prior TV watches.
        season: type === "tv" && season !== undefined ? String(season) : undefined,
        episode: type === "tv" && episode !== undefined ? String(episode) : undefined,
        playbackToken: token,
      })

      if (!result.sources.length) {
        return {
          sources: [] as MediaSource[],
          subtitles: [] as MediaSubtitle[],
          diagnostics: result.failureReason
            ? [
                {
                  code: "CLIENT_PROVIDER_UNAVAILABLE",
                  message: result.failureReason,
                  severity: "warning",
                },
              ]
            : [],
        }
      }

      return applyPayload(
        {
          sources: result.sources,
          subtitles: [],
          diagnostics: [],
        },
        { merge: true }
      )
    },
    [applyPayload, episode, id, season, type]
  )

  const fetchSources = useCallback(
    async (options?: {
      retry?: boolean
      fresh?: boolean
      merge?: boolean
      provider?: string
      preserveNativeVidlink?: boolean
    }) => {
      const searchParams = buildSearchParams({
        retry: options?.retry,
        fresh: options?.fresh,
        provider: options?.provider,
      })
      const dedupeKey = buildSourcesFetchDedupeKey(searchParams, options)
      const inflight = sourcesFetchInflight.get(dedupeKey)
      if (inflight) {
        const payload = await inflight
        if (options?.merge) {
          return applyPayload(payload, {
            merge: true,
            preserveNativeVidlink: options?.preserveNativeVidlink,
          })
        }
        return payload
      }

      const task = (async (): Promise<SourcesPayload> => {
      let lastError: Error | undefined

      const fetchTimeoutMs = options?.provider
        ? PROVIDER_FETCH_TIMEOUT_MS
        : options?.retry
          ? FETCH_TIMEOUT_MS
          : INITIAL_SOURCES_FETCH_TIMEOUT_MS
      const maxAttempts = options?.provider ? 1 : SOURCE_FETCH_ATTEMPTS

      for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const controller = new AbortController()
        const timeout = window.setTimeout(() => controller.abort(), fetchTimeoutMs)

        try {
          const response = await fetch(`/api/cinepro/sources?${searchParams}`, {
              signal: controller.signal,
              cache: "no-store",
            })

          if (!response.ok) {
            if (response.status === 429) {
              throw new Error(
                "Too many source requests. Wait a minute, then tap Retry below."
              )
            }

            const retryable =
              RETRYABLE_HTTP_STATUSES.has(response.status) &&
              attempt < SOURCE_FETCH_ATTEMPTS - 1
            if (retryable) {
              await sleep(400 * (attempt + 1))
              continue
            }

            throw new Error(`HTTP ${response.status}`)
          }

          const data = await response.json()
          const payload = applyPayload(data, {
            merge: options?.merge,
            preserveNativeVidlink: options?.preserveNativeVidlink,
            provider: options?.provider,
          })

          if (
            payload.sources.length === 0 &&
            attempt < SOURCE_FETCH_ATTEMPTS - 1 &&
            !options?.provider
          ) {
            const backgroundPending = isBackgroundSourcesPending(payload.diagnostics)
            if (backgroundPending) {
              // Do not block the UI for minutes — background poll + bootstrap handle merge.
              return payload
            }

            await sleep(400 * (attempt + 1))
            continue
          }

          return payload
        } catch (err) {
          lastError = err instanceof Error ? err : new Error("Failed to load sources")
          const retryable =
            attempt < SOURCE_FETCH_ATTEMPTS - 1 &&
            (lastError.name === "AbortError" ||
              lastError.message.startsWith("HTTP 5") ||
              lastError.message.includes("Failed to fetch"))

          if (retryable) {
            await sleep(400 * (attempt + 1))
            continue
          }

          throw lastError
        } finally {
          window.clearTimeout(timeout)
        }
      }

      throw lastError ?? new Error("Failed to load sources")
      })()

      sourcesFetchInflight.set(dedupeKey, task)

      try {
        return await task
      } finally {
        sourcesFetchInflight.delete(dedupeKey)
      }
    },
    [applyPayload, buildSearchParams]
  )

  const fetchSourcesRef = useRef(fetchSources)
  const fetchClientProviderRef = useRef(fetchClientProvider)

  useEffect(() => {
    fetchSourcesRef.current = fetchSources
  }, [fetchSources])

  useEffect(() => {
    fetchClientProviderRef.current = fetchClientProvider
  }, [fetchClientProvider])

  const refetchSourcesAsync = useCallback(async () => {
    try {
      localStorage.removeItem(buildCacheKey({ id, type, season, episode }))
    } catch {
      // ignore
    }

    setIsRefreshing(true)
    setError(undefined)

    try {
      return await fetchSources({ retry: true, fresh: true, merge: false })
    } catch (err) {
      const message =
        err instanceof Error && err.name === "AbortError"
          ? "Source lookup timed out"
          : err instanceof Error
            ? err.message
            : "Failed to load sources"

      return applyEmergencyFallback(message)
    } finally {
      setIsRefreshing(false)
    }
  }, [applyEmergencyFallback, episode, fetchSources, id, season, type])

  const refetchSources = useCallback(() => {
    void refetchSourcesAsync().catch(() => undefined)
  }, [refetchSourcesAsync])

  const requestProvider = useCallback(
    async (providerId: string) => {
      const providerKey = normalizeProviderName(providerId)
      const isManualVidlink = providerKey === "vidlink"

      if (!isManualVidlink) {
        try {
          localStorage.removeItem(buildCacheKey({ id, type, season, episode }))
        } catch {
          // ignore
        }
      }

      setIsRefreshing(true)
      setError(undefined)

      try {
        if (isClientResolveProvider(providerKey)) {
          return await fetchClientProvider(providerKey, playbackTokenRef.current)
        }

        return await fetchSources(
          isManualVidlink
            ? {
                provider: "vidlink",
                merge: true,
                preserveNativeVidlink: true,
              }
            : {
                provider: providerKey,
                retry: true,
                fresh: true,
                merge: true,
              }
        )
      } catch (err) {
        const message =
          err instanceof Error && err.name === "AbortError"
            ? "Source lookup timed out"
            : err instanceof Error
              ? err.message
              : "Failed to load sources"

        return applyEmergencyFallback(message, { merge: true })
      } finally {
        setIsRefreshing(false)
      }
    },
    [applyEmergencyFallback, episode, fetchClientProvider, fetchSources, id, season, type]
  )

  useEffect(() => {
    if (!enabled || !id) {
      setIsLoading(false)
      setIsRefreshing(false)
      setIsScanningProviders(false)
      setScanningProviderId(undefined)
      return
    }

    const generation = ++fetchGenerationRef.current
    const cacheKey = buildCacheKey({ id, type, season, episode })
    const isStale = () => fetchGenerationRef.current !== generation

    providerScanAttemptedRef.current = false
    providerBootstrapAttemptedRef.current = false
    scanFailedRef.current = new Set()
    setScanFailedProviderIds([])
    setScanningProviderId(undefined)
    setIsScanningProviders(false)
    setSources([])
    setSubtitles([])
    setDiagnostics([])
    setPlaybackToken(undefined)
    setError(undefined)
    setResolvedParamsKey("")
    setIsLoading(true)
    setIsRefreshing(false)
    setBackgroundPendingCapReached(false)

    setBackgroundPendingCapReached(false)

    let retryTimer: number | undefined
    let loadingCapTimer: number | undefined

    const refreshInBackground = async () => {
      if (isStale()) return
      setIsRefreshing(true)
      setError(undefined)

      try {
        const result = await fetchSources({ merge: true })
        if (isStale()) return

        if (shouldAutoRetry(result.diagnostics)) {
          retryTimer = window.setTimeout(() => {
            if (!isStale()) {
              void fetchSources({ retry: true, fresh: true, merge: true }).catch(() => undefined)
            }
          }, OFFLINE_RETRY_MS)
        }
      } catch {
        // keep cached playback when background refresh fails
      } finally {
        if (!isStale()) {
          setIsRefreshing(false)
        }
      }
    }

    const run = async () => {
      const config = await ensureStreamSourcesConfigReady()
      if (isStale()) return

      let cacheHit = false

      try {
        const cached = localStorage.getItem(cacheKey)
        if (cached) {
          const parsed = JSON.parse(cached)
          if (
            parsed.expiresAt > Date.now() &&
            Array.isArray(parsed.sources) &&
            parsed.sources.length > 0 &&
            sourcesHavePlaybackTokens(parsed.sources)
          ) {
            if (
              parsed.playbackToken &&
              typeof parsed.playbackToken === "string" &&
              parsed.playbackToken.length > 0
            ) {
              setPlaybackToken(parsed.playbackToken)
            }

            const nextDiagnostics = applyPayload(parsed)
            if (isStale()) return

            setResolvedParamsKey(cacheKey)
            setIsLoading(false)
            cacheHit = true

            const hasFullProviderSet = !isVidLinkOnlySources(parsed.sources)
            const needsRefresh =
              !hasFullProviderSet ||
              shouldAutoRetry(nextDiagnostics.diagnostics) ||
              isBackgroundSourcesPending(nextDiagnostics.diagnostics)

            if (!needsRefresh) {
              return
            }

            void refreshInBackground()
            return
          }

          localStorage.removeItem(cacheKey)
        }
      } catch {
        // ignore
      }

      if (!cacheHit && !isStale()) {
        setIsLoading(true)
        setError(undefined)
      }

      if (cacheHit) return

      loadingCapTimer = window.setTimeout(() => {
        if (!isStale()) {
          setIsLoading(false)
        }
      }, INITIAL_LOADING_CAP_MS)

      try {
        if (!cacheHit && !isStale()) {
          const scanIds = withoutManualOnlyProviders(
            getOrderedEnabledProviderIds(config.order, config.enabledIds)
          )
          const primaryId = scanIds[0]
          if (
            primaryId &&
            primaryId !== "vidlink" &&
            !isClientResolveProvider(primaryId)
          ) {
            void fetchSources({
              provider: primaryId,
              merge: true,
              retry: true,
              fresh: true,
            }).catch(() => undefined)
          }
        }

        const result = await fetchSources()
        if (isStale()) return

        if (
          result.sources.length === 0 &&
          isBackgroundSourcesPending(result.diagnostics)
        ) {
          window.setTimeout(() => {
            if (!isStale() && sourcesRef.current.length === 0) {
              void fetchSources({ fresh: true, merge: true }).catch(() => undefined)
            }
          }, BACKGROUND_MERGE_KICK_MS)
        }

        if (shouldAutoRetry(result.diagnostics)) {
          retryTimer = window.setTimeout(() => {
            if (!isStale()) {
              void fetchSources({ retry: true, fresh: true, merge: true }).catch(() => undefined)
            }
          }, OFFLINE_RETRY_MS)
        }
      } catch (err) {
        if (isStale()) return

        const isTimeout =
          err instanceof Error &&
          (err.name === "AbortError" || err.message === "Provider scan timed out")

        if (isTimeout) {
          setError(undefined)
          retryTimer = window.setTimeout(() => {
            if (!isStale()) {
              void fetchSources({ retry: true, fresh: true, merge: true }).catch(() => undefined)
            }
          }, OFFLINE_RETRY_MS)
        } else {
          const message =
            err instanceof Error ? err.message : "Failed to load sources"

          const fallback = await applyEmergencyFallback(message)
          if (isStale()) return

          void fetchSources({ merge: true }).catch(() => undefined)

          if (fallback.sources.length === 0) {
            setError(message)
          }
        }
      } finally {
        if (loadingCapTimer) window.clearTimeout(loadingCapTimer)
        if (!isStale()) {
          setResolvedParamsKey(cacheKey)
          setIsLoading(false)
          setIsRefreshing(false)
        }
      }
    }

    void run()

    return () => {
      if (retryTimer) window.clearTimeout(retryTimer)
      if (loadingCapTimer) window.clearTimeout(loadingCapTimer)
      setIsRefreshing(false)
      setIsScanningProviders(false)
      setScanningProviderId(undefined)
    }
  }, [applyEmergencyFallback, applyPayload, enabled, fetchSources, id, paramsKey, season, type])

  useEffect(() => {
    setBackgroundPendingCapReached(false)
  }, [paramsKey])

  const backgroundPendingActive =
    enabled &&
    sources.length === 0 &&
    isBackgroundSourcesPending(diagnostics)

  useEffect(() => {
    if (!backgroundPendingActive || backgroundPendingCapReached) {
      return
    }

    const timer = window.setTimeout(() => {
      setBackgroundPendingCapReached(true)
    }, BACKGROUND_PENDING_CAP_MS)

    return () => {
      window.clearTimeout(timer)
    }
  }, [backgroundPendingActive, backgroundPendingCapReached, paramsKey])

  useEffect(() => {
    let cancelled = false

    const syncReady = () => {
      if (!cancelled) {
        setStreamSourcesReady(isStreamSourcesConfigReady())
      }
    }

    syncReady()
    void ensureStreamSourcesConfigReady().finally(syncReady)
    window.addEventListener(STREAM_SOURCES_CONFIG_EVENT, syncReady)

    return () => {
      cancelled = true
      window.removeEventListener(STREAM_SOURCES_CONFIG_EVENT, syncReady)
    }
  }, [])

  useEffect(() => {
    if (!enabled) return
    if (!isStreamSourcesConfigReady()) return
    if (providerScanAttemptedRef.current) return

    const cacheKey = buildCacheKey({ id, type, season, episode })
    if (!resolvedParamsKey || resolvedParamsKey !== cacheKey) return

    const enabledIds = getEnabledProviderIds()
    if (enabledIds.length === 0) return

    let cancelled = false
    let scanFinished = false

    const runProviderScan = async () => {
      // Keep scanning even after playback starts so player settings can fill in
      // every enabled provider (prefer4k / fast primary must not abort the rest).
      const config = await ensureStreamSourcesConfigReady()
      if (cancelled) return

      const scanIds = withoutManualOnlyProviders(
        getOrderedEnabledProviderIds(config.order, config.enabledIds)
      )

      if (scanIds.length === 0) return

      providerScanAttemptedRef.current = true
      setIsScanningProviders(true)
      setIsRefreshing(true)
      setProviderScanStartedAt(Date.now())

      if (scanFailedRef.current.size > 0) {
        setScanFailedProviderIds([...scanFailedRef.current])
      }

      const markProviderFailed = (providerId: string) => {
        scanFailedRef.current.add(providerId)
        setScanFailedProviderIds([...scanFailedRef.current])
      }

      const clearProviderFailed = (providerId: string) => {
        if (!scanFailedRef.current.delete(providerId)) return
        setScanFailedProviderIds([...scanFailedRef.current])
      }

      const tryProvider = async (
        providerId: string,
        options: {
          useFetchTimeout: boolean
          markFailedOnMiss: boolean
          /** Match manual provider pick — bypass cache and re-scrape CinePro. */
          forceFresh?: boolean
        }
      ): Promise<boolean> => {
        if (cancelled) {
          return false
        }

        if (
          !options.forceFresh &&
          sourcesIncludeProvider(sourcesRef.current, providerId)
        ) {
          clearProviderFailed(providerId)
          return true
        }

        if (
          !options.forceFresh &&
          scanFailedRef.current.has(providerId) &&
          options.markFailedOnMiss
        ) {
          return false
        }

        setScanningProviderId(providerId)

        try {
          if (isClientResolveProvider(providerId)) {
            const payload = options.useFetchTimeout
              ? await fetchSourcesWithTimeout(
                  () =>
                    fetchClientProviderRef.current(
                      providerId,
                      playbackTokenRef.current
                    ),
                  {}
                )
              : await fetchClientProviderRef.current(
                    providerId,
                    playbackTokenRef.current
                )
            const found = sourcesIncludeProvider(payload.sources, providerId)
            if (found) {
              clearProviderFailed(providerId)
              setDiagnostics((previous) =>
                previous.filter((item) => item.code !== "CINEPRO_BACKGROUND")
              )
            } else if (options.markFailedOnMiss) {
              markProviderFailed(providerId)
            }
            return found
          }

          const fetchOptions = options.forceFresh
            ? ({
                provider: providerId,
                merge: true,
                retry: true,
                fresh: true,
              } as const)
            : ({
                provider: providerId,
                merge: true,
              } as const)

          if (options.useFetchTimeout) {
            await fetchSourcesWithTimeout(
              (requestOptions) => fetchSourcesRef.current(requestOptions),
              fetchOptions
            )
          } else {
            await fetchSourcesRef.current(fetchOptions)
          }

          const found = sourcesIncludeProvider(sourcesRef.current, providerId)
          if (found) {
            clearProviderFailed(providerId)
            setDiagnostics((previous) =>
              previous.filter((item) => item.code !== "CINEPRO_BACKGROUND")
            )
          } else if (options.markFailedOnMiss) {
            markProviderFailed(providerId)
          }
          return found
        } catch (err) {
          const message = err instanceof Error ? err.message : ""
          if (message.includes("Too many source requests")) {
            setError(message)
            throw err
          }
          if (options.markFailedOnMiss) {
            markProviderFailed(providerId)
          }
          return false
        }
      }

      const primaryProviderId = scanIds[0]
      const secondaryProviderIds = scanIds.slice(1)

      const startPrimaryRetryLoop = (providerId: string) => {
        const retry = async () => {
          while (!cancelled) {
            await sleep(PRIMARY_PROVIDER_RETRY_MS)
            if (cancelled) return

            const found = await tryProvider(providerId, {
              useFetchTimeout: true,
              markFailedOnMiss: false,
              forceFresh: true,
            })
            if (found) {
              clearProviderFailed(providerId)
              return
            }
          }
        }

        void retry().catch(() => undefined)
      }

      const hasSecondaryProviderSource = () =>
        secondaryProviderIds.some((providerId) =>
          sourcesIncludeProvider(sourcesRef.current, providerId)
        )

      try {
        if (primaryProviderId) {
          setScanningProviderId(primaryProviderId)
          const primaryPromise = tryProvider(primaryProviderId, {
            useFetchTimeout: false,
            markFailedOnMiss: false,
            forceFresh: true,
          })

          const headStartDeadline = Date.now() + PRIMARY_PROVIDER_HEAD_START_MS
          while (Date.now() < headStartDeadline) {
            if (cancelled) return
            if (
              sourcesIncludeProvider(sourcesRef.current, primaryProviderId) &&
              hasSecondaryProviderSource()
            ) {
              break
            }
            await sleep(150)
          }

          if (cancelled) return

          // Always probe every missing enabled provider — having one secondary
          // already (e.g. 4K / bulk hit) must not skip the rest of the list.
          const missingSecondaries = secondaryProviderIds.filter(
            (providerId) => !sourcesIncludeProvider(sourcesRef.current, providerId)
          )

          if (missingSecondaries.length > 0) {
            await Promise.allSettled(
              missingSecondaries.map((providerId) =>
                tryProvider(providerId, {
                  useFetchTimeout: true,
                  markFailedOnMiss: true,
                })
              )
            )
          }

          if (primaryPromise) {
            const primaryFound = await primaryPromise
            if (primaryFound || sourcesIncludeProvider(sourcesRef.current, primaryProviderId)) {
              clearProviderFailed(primaryProviderId)
            } else if (
              !sourcesIncludeProvider(sourcesRef.current, primaryProviderId)
            ) {
              markProviderFailed(primaryProviderId)
              startPrimaryRetryLoop(primaryProviderId)
            }
          }
        } else if (secondaryProviderIds.length > 0) {
          for (const providerId of secondaryProviderIds) {
            if (cancelled) break
            await tryProvider(providerId, {
              useFetchTimeout: true,
              markFailedOnMiss: true,
            })
          }
        }
      } catch {
        if (cancelled) return
      }

      if (!cancelled) {
        setScanningProviderId(undefined)
        setIsRefreshing(false)
        setDiagnostics((previous) => {
          const withoutBackground = previous.filter(
            (item) => item.code !== "CINEPRO_BACKGROUND"
          )

          const hasAutomaticSource = getEnabledProviderIds()
            .filter((providerId) => providerId !== "vidlink")
            .some((providerId) => sourcesIncludeProvider(sourcesRef.current, providerId))

          if (hasAutomaticSource) {
            return withoutBackground
          }

          return [
            ...withoutBackground,
            {
              code: "ENABLED_PROVIDER_UNAVAILABLE",
              message: "No enabled provider returned a stream for this title.",
              severity: "warning",
            },
          ]
        })
      }
    }

    void runProviderScan()
      .catch(() => undefined)
      .finally(() => {
        scanFinished = true
        if (!cancelled) {
          setIsScanningProviders(false)
          setIsRefreshing(false)
          setProviderScanStartedAt(undefined)
        }
      })

    return () => {
      cancelled = true
      if (!scanFinished) {
        providerScanAttemptedRef.current = false
      }
      setScanningProviderId(undefined)
      setProviderScanStartedAt(undefined)
      setIsScanningProviders(false)
      setIsRefreshing(false)
    }
  }, [enabled, episode, id, resolvedParamsKey, season, type])

  const bootstrapEmptySources = useCallback(async () => {
    if (sourcesRef.current.length > 0) return
    if (providerScanAttemptedRef.current) return

    try {
      const merged = await fetchSources({ fresh: true, merge: true, retry: true })
      if (merged.sources.length > 0 || sourcesRef.current.length > 0) return

      const enabledIds = getEnabledProviderIds().filter(
        (providerId) => providerId !== "vidlink"
      )

      for (const providerId of enabledIds) {
        if (sourcesRef.current.length > 0) return
        await fetchSources({
          provider: providerId,
          merge: true,
          retry: true,
        })
      }
    } catch {
      // bootstrap is best-effort; manual provider pick still works
    }
  }, [fetchSources])

  useEffect(() => {
    if (!enabled || !id) return

    const cacheKey = buildCacheKey({ id, type, season, episode })
    if (resolvedParamsKey !== cacheKey) return
    if (sources.length > 0) return
    if (providerBootstrapAttemptedRef.current) return
    if (!isStreamSourcesConfigReady()) return

    providerBootstrapAttemptedRef.current = true
    let cancelled = false

    const bootstrap = async () => {
      await sleep(PROVIDER_BOOTSTRAP_DELAY_MS)
      if (cancelled) return
      await bootstrapEmptySources()
    }

    void bootstrap()

    return () => {
      cancelled = true
    }
  }, [
    bootstrapEmptySources,
    enabled,
    episode,
    id,
    resolvedParamsKey,
    season,
    sources.length,
    type,
  ])

  useEffect(() => {
    if (!enabled || !id) return
    if (sources.length > 0) return

    let cancelled = false
    const timer = window.setTimeout(() => {
      if (cancelled || sourcesRef.current.length > 0) return
      if (providerBootstrapAttemptedRef.current) return
      if (providerScanAttemptedRef.current) return
      providerBootstrapAttemptedRef.current = true
      void bootstrapEmptySources()
    }, INITIAL_LOADING_CAP_MS - 2_000)

    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [bootstrapEmptySources, enabled, episode, id, paramsKey, season, sources.length, type])

  useEffect(() => {
    if (
      !enabled ||
      playbackActive ||
      !isBackgroundSourcesPending(diagnostics)
    ) {
      return
    }

    let cancelled = false
    let timer: number | undefined
    let firstPollTimer: number | undefined

    const poll = () => {
      if (document.visibilityState === "hidden") return

      void fetchSources({ fresh: true, merge: true }).catch((err) => {
        if (cancelled) return

        const message =
          err instanceof Error ? err.message : "Failed to load sources"
        if (message.includes("Too many source requests")) {
          cancelled = true
          if (timer) window.clearInterval(timer)
          setError(message)
        }
      })
    }

    const onVisibilityChange = () => {
      if (document.visibilityState === "visible") {
        poll()
      }
    }

    firstPollTimer = window.setTimeout(poll, BACKGROUND_POLL_FIRST_MS)
    timer = window.setInterval(poll, BACKGROUND_POLL_MS)
    document.addEventListener("visibilitychange", onVisibilityChange)

    return () => {
      cancelled = true
      if (firstPollTimer) window.clearTimeout(firstPollTimer)
      if (timer) window.clearInterval(timer)
      document.removeEventListener("visibilitychange", onVisibilityChange)
    }
  }, [diagnostics, enabled, fetchSources, playbackActive, scanningProviderId])

  useEffect(() => {
    if (!enabled || !playbackToken || TOKEN_REFRESH_INTERVAL_MS <= 0) {
      return
    }

    const refreshPlaybackToken = async () => {
      try {
        const mintParams = new URLSearchParams({
          type,
          tmdbId: String(id),
        })

        if (type === "tv") {
          mintParams.set("season", String(season ?? ""))
          mintParams.set("episode", String(episode ?? ""))
        }

        const response = await fetch(`/api/playback/mint?${mintParams}`, {
          cache: "no-store",
        })
        const data = await response.json()

        if (!response.ok || !data.playbackToken) {
          return
        }

        const nextToken = String(data.playbackToken)
        setPlaybackToken(nextToken)
        setSources((previous) =>
          previous.map((source) => ({
            ...source,
            url: refreshSourcePlaybackToken(source.url, nextToken),
          }))
        )
      } catch {
        // ignore refresh failures; existing token may still be valid
      }
    }

    const timer = window.setInterval(refreshPlaybackToken, TOKEN_REFRESH_INTERVAL_MS)
    return () => window.clearInterval(timer)
  }, [enabled, episode, id, playbackToken, season, type])

  useEffect(() => {
    if (!enabled || !id) return

    const refreshForConfigChange = () => {
      setSources((previous) => filterSourcesForEnabledProviders(previous))
      try {
        localStorage.removeItem(buildCacheKey({ id, type, season, episode }))
      } catch {
        // ignore
      }
      providerScanAttemptedRef.current = false
      void fetchSources({ fresh: true, merge: false }).catch(() => undefined)
    }

    window.addEventListener(STREAM_SOURCES_CONFIG_EVENT, refreshForConfigChange)
    return () => window.removeEventListener(STREAM_SOURCES_CONFIG_EVENT, refreshForConfigChange)
  }, [enabled, episode, fetchSources, id, season, type])

  return {
    sources,
    subtitles,
    diagnostics,
    playbackToken,
    isLoading: !enabled ? false : isLoading,
    isLoadingMore:
      (isScanningProviders && sources.length === 0) ||
      (isRefreshing && sources.length === 0) ||
      (sources.length === 0 &&
        isBackgroundSourcesPending(diagnostics) &&
        !isScanningProviders &&
        !backgroundPendingCapReached),
    error,
    refetchSources,
    refetchSourcesAsync,
    requestProvider,
    scanningProviderId,
    scanFailedProviderIds,
    isScanningProviders,
    providerScanStartedAt,
    resolvedParamsKey,
  }
}
