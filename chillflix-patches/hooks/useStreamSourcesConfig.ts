"use client"

import { useCallback, useEffect, useState } from "react"

import {
    DEFAULT_EXTERNAL_PLAYERS,
    DEFAULT_PLAYBACK_TIMINGS,
    DEFAULT_PROVIDER_CATALOG,
    normalizeProviderName,
    sanitizePlaybackTimings,
    type CustomPlayerEntry,
    type StreamPlaybackTimings,
    type StreamSourceEntry,
} from "@/lib/stream-sources-defaults"
import { STREAM_SOURCES_CONFIG_EVENT } from "@/lib/stream-sources-client"
import { setVidrockClientPlaybackTestRuntime } from "@/lib/vidrock-client-test"

type StreamSourcesState = {
    sources: StreamSourceEntry[]
    players: CustomPlayerEntry[]
    order: string[]
    enabledIds: string[]
    enabledPlayers: CustomPlayerEntry[]
    timings: StreamPlaybackTimings
    vidrockClientPlaybackTest?: boolean
}

function applyStreamSourcesRuntimeFeatures(state: StreamSourcesState) {
    if (typeof state.vidrockClientPlaybackTest === "boolean") {
        setVidrockClientPlaybackTestRuntime(state.vidrockClientPlaybackTest)
    }
}

let cachedConfig: StreamSourcesState | null = null
let inflight: Promise<StreamSourcesState> | null = null

/** UI placeholder while config is loading — never treat as a successful load. */
const PLACEHOLDER: StreamSourcesState = {
    sources: DEFAULT_PROVIDER_CATALOG.map((entry) => ({ ...entry, enabled: false })),
    players: DEFAULT_EXTERNAL_PLAYERS.map((entry) => ({ ...entry })),
    order: [],
    enabledIds: [],
    enabledPlayers: DEFAULT_EXTERNAL_PLAYERS.filter((entry) => entry.enabled),
    timings: { ...DEFAULT_PLAYBACK_TIMINGS },
}

const MAX_FETCH_ATTEMPTS = 3
const FETCH_RETRY_MS = 400

/** Wait until admin stream-source config has been loaded at least once. */
export function ensureStreamSourcesConfigReady(): Promise<StreamSourcesState> {
    return fetchStreamSourcesConfig()
}

function parseStreamSourcesPayload(data: {
    sources?: StreamSourceEntry[]
    players?: CustomPlayerEntry[]
    order?: string[]
    enabledIds?: string[]
    enabledPlayers?: CustomPlayerEntry[]
    timings?: StreamPlaybackTimings
    features?: { vidrockClientPlaybackTest?: boolean }
}): StreamSourcesState {
    const next: StreamSourcesState = {
        sources: data.sources ?? PLACEHOLDER.sources,
        players: data.players ?? PLACEHOLDER.players,
        order: data.order ?? PLACEHOLDER.order,
        enabledIds: data.enabledIds ?? PLACEHOLDER.enabledIds,
        enabledPlayers: data.enabledPlayers ?? PLACEHOLDER.enabledPlayers,
        timings: sanitizePlaybackTimings(data.timings ?? DEFAULT_PLAYBACK_TIMINGS),
        vidrockClientPlaybackTest: data.features?.vidrockClientPlaybackTest,
    }

    applyStreamSourcesRuntimeFeatures(next)
    return next
}

async function requestStreamSourcesConfig() {
    const response = await fetch("/api/stream-sources", { cache: "no-store" })
    const raw = await response.text()

    if (!raw.trim()) {
        throw new Error(`Stream sources response was empty (HTTP ${response.status}).`)
    }

    let data: {
        success?: boolean
        error?: string
        sources?: StreamSourceEntry[]
        players?: CustomPlayerEntry[]
        order?: string[]
        enabledIds?: string[]
        enabledPlayers?: CustomPlayerEntry[]
        timings?: StreamPlaybackTimings
        features?: { vidrockClientPlaybackTest?: boolean }
    }

    try {
        data = JSON.parse(raw) as typeof data
    } catch {
        throw new Error(`Stream sources returned invalid JSON (HTTP ${response.status}).`)
    }

    if (!response.ok || !data.success) {
        throw new Error(data.error ?? `Failed to load stream sources (HTTP ${response.status}).`)
    }

    return parseStreamSourcesPayload(data)
}

async function fetchStreamSourcesConfig(force = false): Promise<StreamSourcesState> {
    if (!force && cachedConfig) {
        applyStreamSourcesRuntimeFeatures(cachedConfig)
        return cachedConfig
    }
    if (inflight) return inflight

    inflight = (async () => {
        let lastError: Error | undefined

        for (let attempt = 0; attempt < MAX_FETCH_ATTEMPTS; attempt += 1) {
            try {
                const next = await requestStreamSourcesConfig()
                const isFirstLoad = cachedConfig === null
                cachedConfig = next

                if (isFirstLoad && typeof window !== "undefined") {
                    window.dispatchEvent(new Event(STREAM_SOURCES_CONFIG_EVENT))
                }

                return next
            } catch (error) {
                lastError =
                    error instanceof Error
                        ? error
                        : new Error("Failed to load stream sources")

                if (attempt < MAX_FETCH_ATTEMPTS - 1) {
                    await new Promise<void>((resolve) => {
                        window.setTimeout(resolve, FETCH_RETRY_MS * (attempt + 1))
                    })
                }
            }
        }

        throw lastError ?? new Error("Failed to load stream sources")
    })().finally(() => {
        inflight = null
    })

    return inflight
}

export function invalidateStreamSourcesConfigCache() {
    cachedConfig = null
}

export function getEnabledProviderIds() {
    return cachedConfig?.enabledIds ?? []
}

export function getPlaybackTimings(): StreamPlaybackTimings {
    return sanitizePlaybackTimings(
        cachedConfig?.timings ?? DEFAULT_PLAYBACK_TIMINGS
    )
}

export function getHeadStartMs(): number {
    return Math.round(getPlaybackTimings().headStartSeconds * 1000)
}

export function getLinkFetchTimeoutMs(): number {
    return getPlaybackTimings().linkFetchSeconds * 1000
}

export function getMetadataTimeoutMs(): number {
    return getPlaybackTimings().metadataSeconds * 1000
}

export function getFirstPlayTimeoutMs(): number {
    return getPlaybackTimings().firstPlaySeconds * 1000
}

/** Per-provider first-play override in seconds, or undefined for global. */
export function getProviderWaitSeconds(providerId: string): number | undefined {
    const key = normalizeProviderName(providerId)
    if (!key || !cachedConfig) return undefined
    const entry = cachedConfig.sources.find(
        (item) => normalizeProviderName(item.id) === key
    )
    return entry?.timeoutSeconds
}

/**
 * Effective first-play budget for a provider (admin absolute control).
 * Per-provider override wins; else global First play; else code fallback.
 */
export function getProviderWaitMs(providerId: string, fallbackMs: number): number {
    return getProviderFirstPlayTimeoutMs(providerId, fallbackMs)
}

export function getProviderFirstPlayTimeoutMs(
    providerId: string,
    fallbackMs: number
): number {
    const perProvider = getProviderWaitSeconds(providerId)
    if (perProvider != null) {
        return perProvider * 1000
    }
    if (cachedConfig?.timings) {
        return getFirstPlayTimeoutMs()
    }
    return fallbackMs
}

export function getProviderMetadataTimeoutMs(
    providerId: string,
    fallbackMs: number
): number {
    const perProvider = getProviderWaitSeconds(providerId)
    if (perProvider != null) {
        return perProvider * 1000
    }
    if (cachedConfig?.timings) {
        return getMetadataTimeoutMs()
    }
    return fallbackMs
}

/** Link-fetch race for a provider (per-provider first-play override also raises fetch). */
export function getProviderFetchTimeoutMs(
    providerId: string,
    fallbackMs: number
): number {
    const perProvider = getProviderWaitSeconds(providerId)
    if (perProvider != null) {
        return Math.max(perProvider * 1000, getLinkFetchTimeoutMs())
    }
    if (cachedConfig?.timings) {
        return getLinkFetchTimeoutMs()
    }
    return fallbackMs
}

export function getDefaultProviderWaitSeconds() {
    return getPlaybackTimings().firstPlaySeconds
}

export function isStreamSourcesConfigReady() {
    return cachedConfig !== null
}

export function useStreamSourcesConfig() {
    const [config, setConfig] = useState<StreamSourcesState | null>(cachedConfig)
    const [loading, setLoading] = useState(!cachedConfig)

    useEffect(() => {
        let cancelled = false

        const load = (force = false) => {
            setLoading(true)
            void fetchStreamSourcesConfig(force)
                .then((next) => {
                    if (cancelled) return
                    setConfig(next)
                })
                .catch(() => {
                    if (cancelled) return
                    if (!cachedConfig) {
                        setConfig(null)
                    }
                })
                .finally(() => {
                    if (!cancelled) {
                        setLoading(false)
                    }
                })
        }

        load(false)

        const onStorage = (event: StorageEvent) => {
            if (event.key === STREAM_SOURCES_CONFIG_EVENT) {
                invalidateStreamSourcesConfigCache()
                load(true)
            }
        }

        const onConfigEvent = () => {
            if (cachedConfig) {
                setConfig(cachedConfig)
                setLoading(false)
                return
            }
            load(true)
        }

        window.addEventListener("storage", onStorage)
        window.addEventListener(STREAM_SOURCES_CONFIG_EVENT, onConfigEvent)

        return () => {
            cancelled = true
            window.removeEventListener("storage", onStorage)
            window.removeEventListener(STREAM_SOURCES_CONFIG_EVENT, onConfigEvent)
        }
    }, [])

    const refresh = useCallback(async () => {
        invalidateStreamSourcesConfigCache()
        setLoading(true)
        try {
            const next = await fetchStreamSourcesConfig(true)
            setConfig(next)
            return next
        } catch (error) {
            if (!cachedConfig) {
                setConfig(null)
            }
            throw error
        } finally {
            setLoading(false)
        }
    }, [])

    const resolvedConfig = config ?? cachedConfig

    return {
        config: resolvedConfig ?? PLACEHOLDER,
        players: resolvedConfig?.players ?? PLACEHOLDER.players,
        enabledPlayers: resolvedConfig?.enabledPlayers ?? PLACEHOLDER.enabledPlayers,
        order: resolvedConfig?.order ?? PLACEHOLDER.order,
        enabledIds: resolvedConfig?.enabledIds ?? PLACEHOLDER.enabledIds,
        timings: resolvedConfig?.timings ?? PLACEHOLDER.timings,
        ready: resolvedConfig !== null,
        loading,
        refresh,
    }
}

if (typeof window !== "undefined") {
    void fetchStreamSourcesConfig()
}
