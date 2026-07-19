import { isClientResolveProvider } from "@/lib/client-resolve-providers"
import {
    AUTOMATIC_PROBE_PROVIDER_ORDER,
    DEFAULT_PROVIDER_LABELS,
    DEFAULT_PROVIDER_ORDER,
    MANUAL_ONLY_PROVIDER_IDS,
    normalizeProviderName,
} from "@/lib/stream-sources-defaults"
import { PRIMARY_PROVIDER_HEAD_START_MS, FOUR_K_HLS_STARTUP_TIMEOUT_MS, FOUR_K_HLS_TRANSCODE_STARTUP_TIMEOUT_MS } from "@/lib/source-probe-constants"
import { resolveProviderUserLabel } from "@/lib/provider-display"

interface SortableSource {
    url: string
    type: string
    quality: string
    provider: {
        name: string
    }
}

export const PROVIDER_ORDER = DEFAULT_PROVIDER_ORDER

export type ProviderOrder = readonly string[]

export const AUTOMATIC_PROVIDER_IDS = new Set<string>(AUTOMATIC_PROBE_PROVIDER_ORDER)

export { normalizeProviderName }

export function buildStableSourceId(
    providerId: string,
    quality: string,
    type: string
) {
    return `source-${normalizeProviderName(providerId)}-${quality}-${type}`
}

export function parseStableSourceId(sourceId: string): {
    providerId: string
    quality: string
    type: string
} | null {
    if (!sourceId.startsWith("source-")) return null

    const parts = sourceId.slice("source-".length).split("-")
    if (parts.length < 3) return null

    if (/^\d+$/.test(parts[parts.length - 1] ?? "") && parts.length >= 4) {
        parts.pop()
    }

    const type = parts.pop()
    const quality = parts.pop()
    const providerId = parts.join("-")

    if (!type || !quality || !providerId) return null

    return { providerId, quality, type }
}

export function playbackUrlWithoutToken(url: string) {
    try {
        const isAbsolute = /^https?:\/\//i.test(url)
        const parsed = new URL(url, isAbsolute ? undefined : "http://playback.local")
        parsed.searchParams.delete("pt")
        const serialized = isAbsolute
            ? parsed.toString()
            : `${parsed.pathname}${parsed.search}`
        return serialized.replace(/[?&]$/, "")
    } catch {
        return url.replace(/([?&])pt=[^&]*&?/g, "$1").replace(/[?&]$/, "")
    }
}

type RawMediaSource = SortableSource & {
    provider: { id: string; name: string }
    audioTracks?: Array<{ id: string; label: string }>
    url: string
    cinemacityPageUrl?: string
    directClientPlayback?: boolean
    clientPlaybackHeaders?: Record<string, string>
}

type MapPlaybackOptionsConfig = {
    providerOrder?: ProviderOrder
    showRealProviderNames?: boolean
}

export function mapSourcesToPlaybackOptions<
    T extends RawMediaSource & { url: string }
>(sources: T[], config?: ProviderOrder | MapPlaybackOptionsConfig) {
    const providerOrder =
        typeof config === "object" && config !== null && !Array.isArray(config)
            ? config.providerOrder
            : config
    const showRealProviderNames =
        typeof config === "object" && config !== null && !Array.isArray(config)
            ? Boolean(config.showRealProviderNames)
            : false
    const order = providerOrder ?? PROVIDER_ORDER

    return sortSourcesByPreference(sources, order).map((source) => {
        const providerKey = normalizeProviderName(source.provider.id || source.provider.name)

        const displayName = resolveProviderUserLabel(
            providerKey,
            source.provider.name,
            order,
            showRealProviderNames
        )

        return {
            id: buildStableSourceId(source.provider.id || source.provider.name, source.quality, source.type),
            label: `${displayName} (${source.quality})`,
            src: normalizeCinemacityCdnPlaybackUrl(source.url),
            sourceType: resolvePlaybackSourceType(source),
            provider: displayName,
            providerId: providerKey,
            quality: source.quality,
            audioTracks: (source.audioTracks ?? []).map((track) => ({
                id: track.id,
                label: track.label,
            })),
            cinemacityPageUrl: source.cinemacityPageUrl,
            directClientPlayback: source.directClientPlayback,
            clientPlaybackHeaders: source.clientPlaybackHeaders,
        }
    })
}

export const TERMINAL_SOURCE_DIAGNOSTIC_CODES = new Set([
    "NO_ENABLED_PROVIDER_STREAMS",
    "ENABLED_PROVIDER_UNAVAILABLE",
    "CINEPRO_UNAVAILABLE",
    "CINEPRO_OFFLINE",
])

export function isBackgroundSourcesPending(
    diagnostics: Array<{ code?: string }> = []
) {
    if (diagnostics.some((item) => TERMINAL_SOURCE_DIAGNOSTIC_CODES.has(item.code ?? ""))) {
        return false
    }

    return diagnostics.some((item) => item.code === "CINEPRO_BACKGROUND")
}

export function getProviderDisplayName(provider: string, providerOrder?: ProviderOrder) {
    const key = normalizeProviderName(provider) as (typeof PROVIDER_ORDER)[number]
    return DEFAULT_PROVIDER_LABELS[key] ?? provider
}

export function getAllKnownProviders(providerOrder?: ProviderOrder) {
    const order = providerOrder ?? PROVIDER_ORDER
    return order.map((id) => ({
        id,
        name: DEFAULT_PROVIDER_LABELS[id as (typeof PROVIDER_ORDER)[number]] ?? id,
    }))
}

export function getConfiguredProviders(
    entries: Array<{ id: string; name: string; enabled: boolean }>
) {
    return entries
        .filter((entry) => entry.enabled)
        .map((entry) => ({
            id: normalizeProviderName(entry.id),
            name: entry.name,
        }))
}

/** Admin drag order intersected with enabled providers (disabled are omitted). */
export function getOrderedEnabledProviderIds(
    order: readonly string[] | undefined,
    enabledIds: readonly string[]
): string[] {
    const enabledSet = new Set(enabledIds.map((id) => normalizeProviderName(id)))
    const seen = new Set<string>()
    const result: string[] = []

    if (order?.length) {
        for (const id of order) {
            const key = normalizeProviderName(id)
            if (!enabledSet.has(key) || seen.has(key)) continue
            seen.add(key)
            result.push(key)
        }
    }

    for (const id of enabledIds) {
        const key = normalizeProviderName(id)
        if (seen.has(key)) continue
        seen.add(key)
        result.push(key)
    }

    return result
}

export function compareProvidersForDisplay(
    a: string,
    b: string,
    providerOrder?: ProviderOrder
) {
    const rankA = providerDisplayRank(a, providerOrder)
    const rankB = providerDisplayRank(b, providerOrder)
    if (rankA !== rankB) return rankA - rankB
    return a.localeCompare(b)
}

export function providerDisplayRank(name: string, providerOrder?: ProviderOrder) {
    const key = normalizeProviderName(name)
    const order = providerOrder ?? PROVIDER_ORDER
    const index = order.indexOf(key)
    return index === -1 ? order.length : index
}

function providerKey(source: SortableSource) {
    return normalizeProviderName(source.provider.name)
}

function isVidLinkSource(source: SortableSource) {
    return providerKey(source) === "vidlink" || source.url.includes("/api/vidlink/")
}

function isCineSuSource(source: SortableSource) {
    return providerKey(source) === "cinesu"
}

function preferredStartRank(source: SortableSource, providerOrder?: ProviderOrder): number {
    return providerDisplayRank(source.provider.name, providerOrder)
}

function providerScore(source: SortableSource, providerOrder?: ProviderOrder): number {
    const order = providerOrder ?? PROVIDER_ORDER
    const rank = providerDisplayRank(source.provider.name, providerOrder)
    return rank === order.length ? 50 : order.length - rank
}

function decodeProxyTarget(url: string): string {
    try {
        const parsed = new URL(url)
        const data = parsed.searchParams.get("data")
        if (!data) return url

        const payload = JSON.parse(data) as { url?: unknown }
        return typeof payload.url === "string" ? payload.url : url
    } catch {
        return url
    }
}

function mediaScore(source: SortableSource): number {
    const target = decodeProxyTarget(source.url).toLowerCase()
    const declaredType = source.type.toLowerCase()

    if (declaredType === "mp4" || declaredType === "webm") {
        return 120
    }

    if (declaredType === "hls" || declaredType === "m3u8") {
        return 90
    }

    if (target.includes(".m3u8") || target.includes(".mp4") || target.includes(".webm")) {
        return 100
    }

    if (target.includes(".html")) {
        return -100
    }

    return 0
}

function sourceComparator(
    a: SortableSource,
    b: SortableSource,
    providerOrder?: ProviderOrder
): number {
    const preferredDelta = preferredStartRank(a, providerOrder) - preferredStartRank(b, providerOrder)
    if (preferredDelta !== 0) return preferredDelta

    const mediaDelta = mediaScore(b) - mediaScore(a)
    if (mediaDelta !== 0) return mediaDelta

    const providerDelta = providerScore(b, providerOrder) - providerScore(a, providerOrder)
    if (providerDelta !== 0) return providerDelta

    if (a.type === "hls" && b.type !== "hls") return -1
    if (a.type !== "hls" && b.type === "hls") return 1

    const qA = parseInt(a.quality, 10) || 0
    const qB = parseInt(b.quality, 10) || 0
    return qB - qA
}

export function sortSourcesByPreference<T extends SortableSource>(
    sources: T[],
    providerOrder?: ProviderOrder
): T[] {
    return [...sources].sort((a, b) => sourceComparator(a, b, providerOrder))
}

export function isDirectCinemacityCdnUrl(url: string) {
    return /^https?:\/\/[^/]*cccdn\.net/i.test(url)
}

/** Repair malformed Cinemacity CDN URLs (comma-separated playlist leaks, missing dot before urlset). */
export function normalizeCinemacityCdnPlaybackUrl(url: string) {
    if (!isDirectCinemacityCdnUrl(url)) return url

    const commaCount = (url.match(/,/g) ?? []).length
    if (commaCount >= 2) {
        const parts = url.split(",").map((part) => part.trim()).filter(Boolean)
        const base = parts[0]
        const hlsPart = parts.find((part) => /urlset\/master\.m3u8$/i.test(part))
        if (base && hlsPart && isDirectCinemacityCdnUrl(base)) {
            const relative = hlsPart.startsWith(".") ? hlsPart : `.${hlsPart}`
            return `${base.replace(/\/$/, "")}/${relative.replace(/^\//, "")}`
        }
    }

    return url
        .replace(/,urlset/gi, ".urlset")
        .replace(/public_files\/\/+\.urlset/gi, "public_files/.urlset")
        .replace(/public_files\/\.urlset\/\.urlset/gi, "public_files/.urlset")
}

export function isTrustedPlaybackSourceUrl(url: string) {
    return (
        url.includes("/api/cinepro/proxy") ||
        url.includes("/api/vidlink/stream") ||
        url.includes("/api/vidlink/proxy") ||
        url.includes("/api/huhu/stream") ||
        url.includes("/api/huhu/proxy") ||
        url.includes("/api/4k/hls/") ||
        isDirectCinemacityCdnUrl(url)
    )
}

export function isFourKHlsTranscodeUrl(url: string) {
    if (!url.includes("/api/4k/hls/")) return false
    try {
        return new URL(url, "https://chillflix.lol").searchParams.get("mode") === "transcode"
    } catch {
        return url.includes("mode=transcode")
    }
}

export function isFourKHlsPlaybackUrl(url: string) {
    return url.includes("/api/4k/hls/")
}

export function canImmediatelyStartProviderOption<
    T extends { id: string; providerId?: string; src?: string }
>(
    option: T,
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
): boolean {
    if (normalizeProviderName(option.providerId ?? "") === "4khdhub") {
        return isFourKHlsPlaybackUrl(option.src ?? "")
    }

    return sourceHealth?.[option.id] === "ready"
}

export function fourKHlsStartupTimeoutMs(url: string) {
    return isFourKHlsTranscodeUrl(url)
        ? FOUR_K_HLS_TRANSCODE_STARTUP_TIMEOUT_MS
        : FOUR_K_HLS_STARTUP_TIMEOUT_MS
}

/** Native Huhu stream URLs are resolver endpoints — must be probed before playback. */
export function isNativeHuhuResolverUrl(url: string) {
    return url.includes("/api/huhu/stream") || url.includes("/api/huhu/proxy")
}

/** Native FlixHQ stream URLs are resolver endpoints — must be probed before playback. */
export function isNativeFlixhqResolverUrl(url: string) {
    return url.includes("/api/flixhq/stream") || url.includes("/api/flixhq/proxy")
}

/** FlixHQ scrapers can resolve the wrong title — never trust without a health probe. */
export function isProviderRequiringHealthProbe(providerId?: string, url?: string) {
    const key = normalizeProviderName(providerId ?? "")
    if (key === "flixhq" || key === "flixhqz") {
        return true
    }

    return Boolean(url && isNativeFlixhqResolverUrl(url))
}

export function isSourceAutoStartEligible<
    T extends { id: string; providerId?: string; src?: string }
>(
    option: T,
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
): boolean {
    if (!isProviderRequiringHealthProbe(option.providerId, option.src)) {
        return true
    }

    if (!sourceHealth) {
        return false
    }

    return sourceHealth[option.id] === "ready"
}

export function isSourceProbePending(
    option: { id: string; providerId?: string; src?: string },
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
): boolean {
    if (!isProviderRequiringHealthProbe(option.providerId, option.src)) {
        return false
    }

    const status = sourceHealth?.[option.id]
    return !status || status === "idle" || status === "checking"
}

export function filterAutoStartEligibleOptions<
    T extends { id: string; providerId?: string; src?: string }
>(
    options: T[],
    args: {
        skipHealthProbe: boolean
        sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
    }
): T[] {
    if (args.skipHealthProbe || !args.sourceHealth) {
        return options
    }

    return options.filter((option) => isSourceAutoStartEligible(option, args.sourceHealth))
}

export function shouldMarkAutoPickSourceReady(
    option: { id: string; providerId?: string; src?: string },
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
): boolean {
    if (isProviderRequiringHealthProbe(option.providerId, option.src)) {
        return false
    }

    return sourceHealth?.[option.id] !== "ready"
}

export type ProviderScanGate = {
    isScanningProviders?: boolean
    scanningProviderId?: string
    scanFailedProviderIds?: readonly string[]
    /** When set, lower-ranked providers unlock after PRIMARY_PROVIDER_HEAD_START_MS. */
    scanStartedAt?: number
    /**
     * #1 provider returned at least one URL. Used for preference only — never a
     * hard lock: a resolve hit is not proof the stream will play.
     */
    primaryProviderResolved?: boolean
}

export function filterPlaybackOptionsByScanGate<
    T extends { providerId?: string }
>(
    options: T[],
    providerOrder: readonly string[] | undefined,
    gate: ProviderScanGate
): T[] {
    const isScanning = Boolean(gate.isScanningProviders || gate.scanningProviderId)
    if (!isScanning || !providerOrder?.length) {
        return options
    }

    const order = providerOrder
        .map((id) => normalizeProviderName(id))
        .filter((id) => id && id !== "vidlink")
    const failed = new Set(
        (gate.scanFailedProviderIds ?? []).map((id) => normalizeProviderName(id))
    )
    const activeId = gate.scanningProviderId
        ? normalizeProviderName(gate.scanningProviderId)
        : ""
    const headStartExpired =
        typeof gate.scanStartedAt === "number" &&
        Date.now() - gate.scanStartedAt >= PRIMARY_PROVIDER_HEAD_START_MS
    const primaryId = order[0] ?? ""

    return options.filter((option) => {
        const providerId = normalizeProviderName(option.providerId ?? "")
        if (!providerId) return false

        const rank = order.indexOf(providerId)
        if (rank === -1) {
            return false
        }

        if (activeId && providerId === activeId) {
            return true
        }

        for (let index = 0; index < rank; index += 1) {
            const higherId = order[index] ?? ""
            if (failed.has(higherId)) {
                continue
            }
            // After the head-start window, do not block on primary merely because
            // it returned a URL — let secondaries compete / be auto-tried.
            if (headStartExpired && higherId === primaryId) {
                continue
            }
            return false
        }

        return true
    })
}

export function shouldSkipPlaybackHealthProbe(args: {
    diagnostics?: Array<{ code?: string }>
    sources: Array<{
        url: string
        provider?: { id?: string; name?: string }
        directClientPlayback?: boolean
    }>
    sourcesLoadingMore?: boolean
    /** Embed iframe: trust first resolved batch and start playback immediately. */
    embedFastStart?: boolean
    /** While walking enabled providers in admin order, keep probing. */
    isScanningProviders?: boolean
    scanningProviderId?: string
}) {
    if (!args.embedFastStart || args.sources.length === 0) {
        return false
    }

    const needsProbe = args.sources.some((source) =>
        isProviderRequiringHealthProbe(
            source.provider?.id ?? source.provider?.name,
            source.url
        )
    )

    return !needsProbe
}

/** After the startup watchdog fires: prefer probed-ready streams, never retry health-failed ones. */
export function pickForceStartPlaybackSource<
    T extends { id: string; providerId?: string }
>(
    options: T[],
    args: {
        sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
        firstReadySourceId?: string
        providerOrder?: ProviderOrder
    }
): T | undefined {
    if (options.length === 0) return undefined

    const order = args.providerOrder ?? [...PROVIDER_ORDER]
    const health = args.sourceHealth ?? {}
    const withoutFailed = options.filter((option) => health[option.id] !== "failed")
    const pool = withoutFailed.length > 0 ? withoutFailed : options

    const eligible = filterAutoStartEligibleOptions(pool, {
        skipHealthProbe: false,
        sourceHealth: health,
    })

    return (
        pickAutoStartPlaybackSource(eligible.length > 0 ? eligible : pool, {
            skipHealthProbe: false,
            sourceHealth: health,
            firstReadySourceId: args.firstReadySourceId,
            providerOrder: order,
        }) ??
        eligible[0] ??
        pool[0] ??
        options[0]
    )
}

/** Pick a stream for automatic playback (mirrors manual source selection). */
export function pickAutoStartPlaybackSource<
    T extends { id: string; providerId?: string; src?: string }
>(
    options: T[],
    args: {
        skipHealthProbe: boolean
        sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
        firstReadySourceId?: string
        providerOrder?: ProviderOrder
        pickGate?: PlaybackPickGate
    }
): T | undefined {
    if (options.length === 0) return undefined

    const order = args.providerOrder ?? [...PROVIDER_ORDER]

    const eligibleOptions = filterAutoStartEligibleOptions(options, {
        skipHealthProbe: args.skipHealthProbe,
        sourceHealth: args.sourceHealth,
    })

    if (!args.skipHealthProbe && args.sourceHealth && eligibleOptions.length === 0) {
        const hasPendingProbe = options.some((option) =>
            isSourceProbePending(option, args.sourceHealth)
        )
        if (hasPendingProbe) {
            return undefined
        }
    }

    const pickPool = eligibleOptions.length > 0 ? eligibleOptions : options

    if (!args.skipHealthProbe && args.sourceHealth) {
        const probeReady = pickPool.filter(
            (option) => args.sourceHealth![option.id] === "ready"
        )
        if (probeReady.length > 0) {
            return sortEligibleByProviderRank(probeReady, order)[0]
        }
    }

    const pickGate = args.skipHealthProbe
        ? args.pickGate
        : { ...args.pickGate, sourceHealth: args.sourceHealth }

    const preferred = pickPreferredPlaybackSource(pickPool, new Set(), order, pickGate)
    if (preferred) return preferred

    if (!args.skipHealthProbe && args.sourceHealth) {
        return undefined
    }

    return (
        sortEligibleByProviderRank(
            pickPool.filter((option) => option.providerId),
            order
        )[0] ?? pickPool[0]
    )
}

export function hasPendingSourceHealthProbe(
    sourceIds: string[],
    sourceHealth: Record<string, "idle" | "checking" | "ready" | "failed"> = {}
) {
    if (sourceIds.length === 0) return false

    return sourceIds.some((id) => {
        const status = sourceHealth[id]
        return !status || status === "idle" || status === "checking"
    })
}

export function buildTrustedSourceHealth(sourceIds: string[]) {
    const health: Record<string, "ready"> = {}
    for (const id of sourceIds) {
        health[id] = "ready"
    }
    return health
}

export function isVidLinkOnlySources(
    sources: Array<{ provider?: { id?: string; name?: string } }>
) {
    if (sources.length === 0) return false

    return sources.every((source) => {
        const providerId = (source.provider?.id ?? source.provider?.name ?? "").toLowerCase()
        return providerId === "vidlink" || providerId.includes("vidlink")
    })
}

export function findCineSuPlaybackOption<T extends { id: string; providerId?: string }>(
    options: T[]
) {
    return options.find((option) => option.providerId === "cinesu")
}

export function hasCineSuPlaybackOption<T extends { providerId?: string }>(options: T[]) {
    return options.some((option) => option.providerId === "cinesu")
}

export type PlaybackPickGate = {
    sourcesLoadingMore?: boolean
    unavailableProviderIds?: ReadonlySet<string>
    healthFailedProviderIds?: ReadonlySet<string>
    scanFailedProviderIds?: ReadonlySet<string>
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
    primaryProviderId?: string
}

export function buildPlaybackPickGate(args: {
    sourcesLoadingMore?: boolean
    unavailableProviderIds?: ReadonlySet<string>
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
    playbackOptions?: Array<{ id: string; providerId?: string }>
    primaryProviderId?: string
    scanFailedProviderIds?: ReadonlySet<string>
}): PlaybackPickGate {
    return {
        sourcesLoadingMore: args.sourcesLoadingMore,
        unavailableProviderIds: args.unavailableProviderIds,
        sourceHealth: args.sourceHealth,
        primaryProviderId: args.primaryProviderId,
        scanFailedProviderIds: args.scanFailedProviderIds,
        healthFailedProviderIds:
            args.sourceHealth && args.playbackOptions
                ? getHealthFailedProviderIds(args.playbackOptions, args.sourceHealth)
                : undefined,
    }
}

export function getHealthFailedProviderIds<
    T extends { id: string; providerId?: string }
>(
    options: T[],
    sourceHealth: Record<string, "idle" | "checking" | "ready" | "failed"> = {}
): Set<string> {
    const byProvider = new Map<string, Array<"idle" | "checking" | "ready" | "failed">>()

    for (const option of options) {
        if (!option.providerId) continue
        const statuses = byProvider.get(option.providerId) ?? []
        statuses.push(sourceHealth[option.id] ?? "idle")
        byProvider.set(option.providerId, statuses)
    }

    const failed = new Set<string>()
    for (const [providerId, statuses] of byProvider.entries()) {
        const hasReady = statuses.some((status) => status === "ready")
        const hasFailed = statuses.some((status) => status === "failed")
        if (hasFailed && !hasReady) {
            failed.add(providerId)
        }
    }

    return failed
}

export function isProviderResolvedForPlayback(
    providerId: string,
    gate?: PlaybackPickGate
): boolean {
    if (!gate) return true

    return (
        gate.unavailableProviderIds?.has(providerId) ||
        gate.healthFailedProviderIds?.has(providerId) ||
        gate.scanFailedProviderIds?.has(providerId) ||
        !gate.sourcesLoadingMore
    )
}

/** Probe every ranked stream per provider (HLS first, then MP4 qualities). */
export function pickProbeSourcesPerProvider<
    T extends { id: string; providerId?: string; sourceType?: string }
>(options: T[], providerOrder?: ProviderOrder, limitToOrderOnly = false) {
    const order = providerOrder?.length ? providerOrder : [...PROVIDER_ORDER]
    const seen = new Set<string>()
    const picked: T[] = []

    const appendProviderSources = (providerId: string) => {
        if (seen.has(providerId)) return
        const providerOptions = options.filter((option) => option.providerId === providerId)
        if (providerOptions.length === 0) return
        seen.add(providerId)
        picked.push(...providerOptions)
    }

    for (const providerId of order) {
        appendProviderSources(providerId)
    }

    if (!limitToOrderOnly) {
        for (const option of options) {
            if (!option.providerId || seen.has(option.providerId)) continue
            appendProviderSources(option.providerId)
        }
    }

    return picked
}

/** Automatic mode: first stream per provider in admin order (top = probed first). */
export function pickAutomaticProbeSources<T extends { id: string; providerId?: string }>(
    options: T[],
    providerOrder?: ProviderOrder,
    orderedEnabledIds?: readonly string[]
) {
    const order = orderedEnabledIds?.length
        ? [...orderedEnabledIds]
        : providerOrder?.length
          ? getOrderedEnabledProviderIds(providerOrder, providerOrder)
          : []

    if (order.length === 0) {
        return []
    }

    const probeOrder = order.filter(
        (id) => !MANUAL_ONLY_PROVIDER_IDS.has(normalizeProviderName(id))
    )

    return pickProbeSourcesPerProvider(options, probeOrder, true)
}

export function isAutomaticProviderId(providerId?: string, providerOrder?: ProviderOrder) {
    if (!providerId) return false

    const key = normalizeProviderName(providerId)

    if (MANUAL_ONLY_PROVIDER_IDS.has(key)) {
        return false
    }

    if (providerOrder?.length) {
        return providerOrder.some((id) => normalizeProviderName(id) === key)
    }

    return AUTOMATIC_PROVIDER_IDS.has(key)
}

export function shouldDeferPlaybackPick<T extends { id: string; providerId?: string }>(
    options: T[],
    providerOrder?: ProviderOrder,
    gate?: PlaybackPickGate
): boolean {
    if (!gate?.sourcesLoadingMore || options.length === 0) {
        return false
    }

    if (gate.sourceHealth) {
        const hasReadyOption = options.some(
            (option) => gate.sourceHealth![option.id] === "ready"
        )
        if (hasReadyOption) {
            return false
        }
    }

    const order = providerOrder?.length ? providerOrder : [...PROVIDER_ORDER]
    const primaryId = gate.primaryProviderId ?? order[0]
    if (!primaryId || primaryId === "vidlink") {
        return false
    }

    const hasPrimarySource = options.some((option) => option.providerId === primaryId)
    if (hasPrimarySource) {
        return false
    }

    return isVidLinkOnlySources(
        options.map((option) => ({
            provider: { id: option.providerId, name: option.providerId },
        }))
    )
}

function sortEligibleByProviderRank<T extends { providerId?: string }>(
    options: T[],
    order: ProviderOrder
) {
    return [...options].sort(
        (a, b) =>
            providerDisplayRank(a.providerId ?? "", order) -
            providerDisplayRank(b.providerId ?? "", order)
    )
}

export function pickPreferredPlaybackSource<T extends { id: string; providerId?: string }>(
    options: T[],
    skipProviderIds: ReadonlySet<string> = new Set(),
    providerOrder?: ProviderOrder,
    gate?: PlaybackPickGate,
    automaticOnly = true
) {
    if (options.length === 0) return undefined

    const order = providerOrder?.length ? providerOrder : [...PROVIDER_ORDER]

    const eligible = options.filter((option) => {
        if (!option.providerId || skipProviderIds.has(option.providerId)) {
            return false
        }

        if (automaticOnly && !isAutomaticProviderId(option.providerId, order)) {
            return false
        }

        return true
    })

    if (eligible.length === 0) {
        const ranked = sortEligibleByProviderRank(
            options.filter((option) => option.providerId),
            order
        )
        return ranked[0] ?? options[0]
    }

    if (shouldDeferPlaybackPick(eligible, order, gate)) {
        return undefined
    }

    if (gate?.sourceHealth) {
        const readyOptions = eligible.filter(
            (option) => gate.sourceHealth![option.id] === "ready"
        )

        if (readyOptions.length > 0) {
            return sortEligibleByProviderRank(readyOptions, order)[0]
        }

        const stillProbing = eligible.some((option) => {
            const status = gate.sourceHealth![option.id]
            return !status || status === "idle" || status === "checking"
        })

        if (stillProbing) {
            return undefined
        }

        const withoutFailedProviders = eligible.filter(
            (option) =>
                option.providerId &&
                !gate.healthFailedProviderIds?.has(option.providerId)
        )
        if (withoutFailedProviders.length > 0) {
            return sortEligibleByProviderRank(withoutFailedProviders, order)[0]
        }

        return undefined
    }

    for (const providerId of order) {
        if (skipProviderIds.has(providerId)) continue

        const providerOptions = eligible.filter((option) => option.providerId === providerId)

        if (providerOptions.length > 0) {
            const sorted = sortEligibleByProviderRank(providerOptions, order)
            return sorted[0]
        }

        const hasSourceForProvider = options.some(
            (option) => option.providerId === providerId
        )
        if (!hasSourceForProvider) {
            continue
        }

        if (!isProviderResolvedForPlayback(providerId, gate)) {
            return undefined
        }
    }

    return sortEligibleByProviderRank(eligible, order)[0]
}

export function findNextAutoFallbackSource<T extends { id: string; providerId?: string }>(
    options: T[],
    attemptedIds: ReadonlySet<string>,
    currentId?: string,
    automaticOnly = true,
    providerOrder?: ProviderOrder,
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
) {
    const fallbackOrder =
        providerOrder?.length ? providerOrder : [...AUTOMATIC_PROBE_PROVIDER_ORDER]

    const pool = automaticOnly
        ? options.filter((option) => isAutomaticProviderId(option.providerId, fallbackOrder))
        : options

    const attemptedProviderIds = new Set<string>()

    for (const option of pool) {
        if (!option.providerId || !attemptedIds.has(option.id)) continue
        attemptedProviderIds.add(option.providerId)
    }

    const current = pool.find((option) => option.id === currentId)
    if (current?.providerId) {
        attemptedProviderIds.add(current.providerId)
    }

    const pickFromProvider = (providerId: string) => {
        const providerOptions = pool.filter(
            (option) =>
                option.providerId === providerId &&
                option.id !== currentId &&
                !attemptedIds.has(option.id)
        )

        if (providerOptions.length === 0) {
            return undefined
        }

        if (sourceHealth) {
            const ready = sortEligibleByProviderRank(providerOptions, fallbackOrder).find(
                (option) => sourceHealth[option.id] === "ready"
            )
            if (ready) return ready

            const allFailed = providerOptions.every(
                (option) => sourceHealth[option.id] === "failed"
            )
            if (allFailed) {
                return undefined
            }

            return sortEligibleByProviderRank(providerOptions, fallbackOrder)[0]
        }

        return sortEligibleByProviderRank(providerOptions, fallbackOrder)[0]
    }

    for (const providerId of fallbackOrder) {
        if (attemptedProviderIds.has(providerId)) continue

        const nextProvider = pickFromProvider(providerId)
        if (nextProvider) return nextProvider
    }

    for (const option of pool) {
        if (option.id === currentId) continue
        if (attemptedIds.has(option.id)) continue
        if (sourceHealth && sourceHealth[option.id] !== "ready") continue
        return option
    }

    return undefined
}

export function shouldPreferSourceUpgrade(args: {
    current?: Pick<SortableSource, "url" | "provider">
    preferred?: Pick<SortableSource, "url" | "provider">
    manualSelection: boolean
    hasRuntimeError: boolean
}) {
    if (args.manualSelection || args.hasRuntimeError || !args.current || !args.preferred) {
        return false
    }

    if (isVidLinkSource(args.current) && isCineSuSource(args.preferred)) {
        return true
    }

    return false
}

/** Auto-switch from a lower-ranked playing source when a higher-ranked one becomes ready. */
export function shouldUpgradeToHigherRankedSource<
    T extends { id: string; providerId?: string }
>(args: {
    current?: T
    preferred?: T
    providerOrder?: ProviderOrder
    sourceHealth?: Record<string, "idle" | "checking" | "ready" | "failed">
    manualSelection: boolean
    /** Seconds of real playback on the current source (live clock). */
    currentPlaybackTime?: number
}): boolean {
    if (args.manualSelection || !args.current || !args.preferred) {
        return false
    }

    if (args.current.id === args.preferred.id) {
        return false
    }

    // Probe "ready" ≠ playable. Never yank away during cold start — that was
    // stranding users on a flaky primary (e.g. VAPlayer) after a working secondary.
    const played = args.currentPlaybackTime ?? 0
    if (played < 1.5) {
        return false
    }

    const order = args.providerOrder ?? [...PROVIDER_ORDER]
    const currentRank = providerDisplayRank(args.current.providerId ?? "", order)
    const preferredRank = providerDisplayRank(args.preferred.providerId ?? "", order)

    if (preferredRank >= currentRank) {
        return false
    }

    if (args.sourceHealth && args.sourceHealth[args.preferred.id] !== "ready") {
        return false
    }

    return true
}

export function parseUnavailableProviders(
    diagnostics: Array<{ code?: string; message?: string }>,
    activeProviderNames: string[] = []
) {
    const active = new Set(activeProviderNames.map((name) => name.toLowerCase()))
    const unavailable: string[] = []
    const seen = new Set<string>()

    for (const diagnostic of diagnostics) {
        if (diagnostic.code !== "PROVIDER_ERROR") continue

        const match = diagnostic.message?.match(/^([^:]+):/)
        if (!match) continue

        const name = match[1].trim()
        const key = name.toLowerCase()
        if (!name || seen.has(key) || active.has(key)) continue
        if (isClientResolveProvider(name)) continue

        seen.add(key)
        unavailable.push(name)
    }

    return unavailable.sort(compareProvidersForDisplay)
}

export function compareSourcesForHealthDisplay<
    T extends { id: string; provider?: string; providerId?: string }
>(
    a: T,
    b: T,
    health: Record<string, "idle" | "checking" | "ready" | "failed" | undefined>,
    providerOrder?: ProviderOrder
) {
    const rank = (source: T) => {
        const status = health[source.id]
        if (status === "ready") return 0
        if (status === "checking") return 1
        if (!status || status === "idle") return 2
        if (status === "failed") return 4
        return 3
    }

    const rankDelta = rank(a) - rank(b)
    if (rankDelta !== 0) return rankDelta

    const providerA = a.providerId ?? a.provider ?? ""
    const providerB = b.providerId ?? b.provider ?? ""
    return compareProvidersForDisplay(providerA, providerB, providerOrder)
}

export function resolvePlaybackSourceType(source: {
    type: string
    url: string
}): "hls" | "file" {
    const declaredType = source.type.toLowerCase()

    if (declaredType === "hls" || declaredType === "m3u8") {
        return "hls"
    }

    const target = decodeProxyTarget(source.url).toLowerCase()

    if (
        target.includes(".m3u8") ||
        source.url.includes("/api/vidlink/") ||
        source.url.includes("/api/4k/hls/")
    ) {
        return "hls"
    }

    return "file"
}
