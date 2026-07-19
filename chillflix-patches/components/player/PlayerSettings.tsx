"use client"

import { useEffect, useMemo, useRef, useState } from "react"
import { Check, Clapperboard, ChevronDown, HardDrive, Loader2, Settings, Captions, Volume2, Gauge } from "lucide-react"

import { Button } from "@/components/ui/button"
import { PlaybackSourceRetryPanel } from "@/components/player/PlaybackSourceRetryPanel"
import { Badge } from "@/components/ui/badge"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Switch } from "@/components/ui/switch"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
    compareProvidersForDisplay,
    compareSourcesForHealthDisplay,
    getConfiguredProviders,
    getOrderedEnabledProviderIds,
    getProviderDisplayName,
    normalizeProviderName,
} from "@/components/player/utils/playback"
import type { SourceHealthStatus } from "@/hooks/useSourceHealth"
import { useSourceLoadingProgress } from "@/hooks/useSourceLoadingProgress"
import { useShowRealProviderNames } from "@/hooks/use-show-real-provider-names"
import { useStreamSourcesConfig } from "@/hooks/useStreamSourcesConfig"
import {
    isTransientPlaybackStatusMessage,
    maskPlaybackDiagnosticMessageWithT,
    shouldShowPlaybackRetryButton,
} from "@/lib/playback-user-messages"
import { resolveProviderUserLabel } from "@/lib/provider-display"
import { getProviderLanguageFlag } from "@/lib/stream-sources-defaults"
import {
    groupSubtitlesForDisplay,
    type SubtitleListItem,
} from "@/components/player/utils/subtitle-display"
import { useTranslations } from "@/lib/i18n/client"
import {
    documentLostFocus,
    shouldSuppressPlayerOverlayDismissEvent,
} from "@/lib/player-overlay-dismiss"
import { cn } from "@/lib/utils"

interface PlayerSettingsProps {
    playbackRate: number
    onPlaybackRateChange: (rate: number) => void
    qualities: {
        index: number
        height: number
        label: string
    }[]
    currentQuality: number
    onQualityChange: (level: number) => void
    sources?: {
        id: string
        label: string
        provider?: string
        providerId?: string
        quality?: string
    }[]
    currentSourceId?: string
    onSelectSource?: (id: string) => void | Promise<void>
    onRequestProvider?: (providerId: string) => void | Promise<void>
    sourcesLoadingMore?: boolean
    sourceStatusMessage?: string
    onRefetchSources?: () => void
    unavailableProviders?: string[]
    sourceHealth?: Record<string, SourceHealthStatus>
    activeTestingProviderId?: string
    subtitles?: SubtitleListItem[]
    currentSubtitleId?: string
    onSelectSubtitle?: (id?: string) => void
    audioTracks?: {
        id: string
        label: string
    }[]
    currentAudioTrackId?: string
    onSelectAudioTrack?: (id: string) => void
    onOpenChange?: (open: boolean) => void
    externalSubtitlesLoading?: boolean
    autoplayEnabled?: boolean
    onAutoplayChange?: (enabled: boolean) => void
    autoNextEnabled?: boolean
    onAutoNextChange?: (enabled: boolean) => void
    showAutoNext?: boolean
    /** When false, show Alpha/Beta/etc. instead of scraper names. */
    showRealProviderNames?: boolean
    /** Providers hidden from the source picker (e.g. 4K when not in catalog). */
    hiddenProviderIds?: string[]
}

const PLAYBACK_RATES = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2]

const SETTINGS_SCROLL =
    "max-h-[min(52vh,17.5rem)] overflow-y-auto overscroll-contain [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,0.2)_transparent] max-md:max-h-[min(60vh,28rem)] max-md:[scrollbar-width:none] max-md:[&::-webkit-scrollbar]:hidden"

const SETTINGS_PANEL = "rounded-lg border border-white/10 bg-white/[0.04]"

const SETTINGS_OPTION =
    "flex w-full min-h-9 items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors"

function shouldSuppressSettingsDismiss(isOpen: boolean) {
    return isOpen && shouldSuppressPlayerOverlayDismissEvent()
}

function settingsOptionClass(active: boolean) {
    return cn(
        SETTINGS_OPTION,
        active ? "bg-primary/20 text-primary" : "text-zinc-200 hover:bg-white/10"
    )
}

function isSourceReadyForPicker(
    sourceId: string,
    sourceHealth: Record<string, SourceHealthStatus> = {}
) {
    return sourceHealth[sourceId] === "ready"
}

function isSourceStillProbing(
    sourceId: string,
    sourceHealth: Record<string, SourceHealthStatus> = {}
) {
    const status = sourceHealth[sourceId]
    return !status || status === "idle" || status === "checking"
}


function ProviderNameWithFlag({
    providerId,
    name,
}: {
    providerId: string
    name: string
}) {
    const flag = getProviderLanguageFlag(providerId)
    return (
        <span className="flex min-w-0 items-center gap-1.5">
            {flag ? (
                <span className="shrink-0 text-base leading-none" aria-hidden="true">
                    {flag}
                </span>
            ) : null}
            <span className="truncate text-sm font-medium">{name}</span>
        </span>
    )
}

export function PlayerSettings({
    playbackRate,
    onPlaybackRateChange,
    qualities,
    currentQuality,
    onQualityChange,
    sources = [],
    currentSourceId,
    onSelectSource,
    onRequestProvider,
    sourcesLoadingMore = false,
    sourceStatusMessage,
    onRefetchSources,
    unavailableProviders = [],
    sourceHealth = {},
    activeTestingProviderId,
    subtitles = [],
    currentSubtitleId,
    onSelectSubtitle,
    audioTracks = [],
    currentAudioTrackId,
    onSelectAudioTrack,
    onOpenChange,
    externalSubtitlesLoading = false,
    autoplayEnabled,
    onAutoplayChange,
    autoNextEnabled,
    onAutoNextChange,
    showAutoNext = false,
    showRealProviderNames: showRealProviderNamesProp,
    hiddenProviderIds = [],
}: PlayerSettingsProps) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [activeTab, setActiveTab] = useState("source")
    const [selectingSourceId, setSelectingSourceId] = useState<string | null>(null)
    const restoreOpenOnVisibleRef = useRef(false)
    const openRef = useRef(false)
    const { config, order: providerOrder, enabledIds, ready: streamSourcesReady } =
        useStreamSourcesConfig()
    const hookShowRealProviderNames = useShowRealProviderNames()
    const showRealProviderNames = showRealProviderNamesProp ?? hookShowRealProviderNames

    const enabledSources = useMemo(() => {
        if (!streamSourcesReady) {
            return sources
        }

        if (enabledIds.length === 0) {
            return []
        }

        return sources.filter((source) => {
            const providerKey = normalizeProviderName(
                source.providerId ??
                    (typeof source.provider === "string"
                        ? source.provider
                        : source.label || "other")
            )
            return enabledIds.includes(providerKey)
        })
    }, [enabledIds, sources, streamSourcesReady])

    const probeDiagnostics = useMemo(
        () =>
            unavailableProviders.map((name) => ({
                code: "PROVIDER_ERROR",
                message: `${name}: no stream for this title`,
            })),
        [unavailableProviders]
    )

    const probeSources = useMemo(
        () =>
            enabledSources.map((source) => ({
                provider: {
                    id: source.provider,
                    name: source.provider ?? source.label,
                },
            })),
        [enabledSources]
    )

    const groupedSubtitles = useMemo(() => groupSubtitlesForDisplay(subtitles), [subtitles])
    const [openSubtitleGroup, setOpenSubtitleGroup] = useState<string | undefined>()

    const activeSource = useMemo(
        () => (currentSourceId ? sources.find((source) => source.id === currentSourceId) : undefined),
        [currentSourceId, sources]
    )

    const activeProviderKey = useMemo(() => {
        if (!activeSource) return undefined
        return normalizeProviderName(
            activeSource.providerId ??
                (typeof activeSource.provider === "string" ? activeSource.provider : "")
        )
    }, [activeSource])

    useEffect(() => {
        if (!currentSubtitleId) return
        const activeGroup = groupedSubtitles.find((group) =>
            group.items.some((item) => item.id === currentSubtitleId)
        )
        if (activeGroup) setOpenSubtitleGroup(activeGroup.code)
    }, [currentSubtitleId, groupedSubtitles])

    const playbackOptions = useMemo(
        () =>
            enabledSources.map((source) => ({
                id: source.id,
                providerId: normalizeProviderName(
                    source.providerId ??
                        (typeof source.provider === "string"
                            ? source.provider
                            : source.label.split(" (")[0] ?? "")
                ),
            })),
        [enabledSources]
    )

    const { rows: probeRows, currentTestingProvider, headline: probeHeadline } =
        useSourceLoadingProgress({
            active: sourcesLoadingMore,
            sources: probeSources,
            diagnostics: probeDiagnostics,
            configuredSources: config.sources,
            activeTestingProviderId,
            sourceHealth,
            playbackOptions,
            sourcesLoadingMore,
            showRealProviderNames,
            providerOrder,
        })

    const probeStatusById = useMemo(
        () => new Map(probeRows.map((row) => [row.id, row.status])),
        [probeRows]
    )

    const probeDisplayNameById = useMemo(
        () => new Map(probeRows.map((row) => [row.id, row.displayName])),
        [probeRows]
    )

    const handleOpenChange = (nextOpen: boolean) => {
        if (shouldSuppressSettingsDismiss(openRef.current) && !nextOpen) {
            restoreOpenOnVisibleRef.current = true
            return
        }

        openRef.current = nextOpen
        if (nextOpen) {
            restoreOpenOnVisibleRef.current = false
        }
        setOpen(nextOpen)
        onOpenChange?.(nextOpen)
    }

    const handleOutsideDismiss = (event: Event) => {
        if (!shouldSuppressSettingsDismiss(openRef.current)) return
        event.preventDefault()
        if (openRef.current) {
            restoreOpenOnVisibleRef.current = true
        }
    }

    useEffect(() => {
        openRef.current = open
    }, [open])

    useEffect(() => {
        const markRestore = () => {
            if (openRef.current) {
                restoreOpenOnVisibleRef.current = true
            }
        }

        const tryRestore = () => {
            if (!restoreOpenOnVisibleRef.current || documentLostFocus()) return
            restoreOpenOnVisibleRef.current = false
            openRef.current = true
            setOpen(true)
            onOpenChange?.(true)
        }

        const onWindowBlur = () => {
            markRestore()
        }

        const onVisibilityChange = () => {
            if (document.visibilityState === "hidden") {
                markRestore()
                return
            }
            tryRestore()
        }

        window.addEventListener("blur", onWindowBlur)
        window.addEventListener("focus", tryRestore)
        document.addEventListener("visibilitychange", onVisibilityChange)
        return () => {
            window.removeEventListener("blur", onWindowBlur)
            window.removeEventListener("focus", tryRestore)
            document.removeEventListener("visibilitychange", onVisibilityChange)
        }
    }, [onOpenChange])

    const providerRows = useMemo(() => {
        const grouped = new Map<string, typeof enabledSources>()

        for (const source of enabledSources) {
            const providerKey = normalizeProviderName(
                source.providerId ??
                    (typeof source.provider === "string"
                        ? source.provider
                        : source.label || "other")
            )
            const existing = grouped.get(providerKey) || []
            grouped.set(providerKey, [...existing, source])
        }

        const unavailableSet = new Set(unavailableProviders.map((name) => normalizeProviderName(name)))
        const hiddenSet = new Set(hiddenProviderIds.map((id) => normalizeProviderName(id)))
        const knownIds = new Set(config.sources.map((entry) => entry.id))

        // Always list every enabled provider (admin order), not only ones that
        // already returned streams — empty rows stay tappable ("tap to check").
        const configured = streamSourcesReady
            ? getConfiguredProviders(config.sources)
            : []
        const configuredById = new Map(configured.map((entry) => [entry.id, entry]))
        const orderedEnabled = streamSourcesReady
            ? getOrderedEnabledProviderIds(providerOrder, enabledIds)
            : Array.from(grouped.keys())

        const rows = orderedEnabled.map((providerId) => {
            const providerSources = grouped.get(providerId) || []
            const configuredEntry = configuredById.get(providerId)
            return {
                id: providerId,
                name: resolveProviderUserLabel(
                    providerId,
                    configuredEntry?.name ??
                        getProviderDisplayName(
                            typeof providerSources[0]?.provider === "string"
                                ? providerSources[0].provider
                                : providerSources[0]?.label || providerId
                        ),
                    providerOrder,
                    showRealProviderNames
                ),
                providerSources,
                unavailable:
                    providerSources.length === 0 &&
                    !sourcesLoadingMore &&
                    unavailableSet.has(providerId),
                loading: providerSources.length === 0 && sourcesLoadingMore,
            }
        })

        const enabledSet = new Set(enabledIds.map((id) => normalizeProviderName(id)))

        if (streamSourcesReady) {
            for (const [id, providerSources] of grouped.entries()) {
                if (knownIds.has(id) || !enabledSet.has(id)) continue
                rows.push({
                id,
                name: resolveProviderUserLabel(
                    id,
                    getProviderDisplayName(
                        typeof providerSources[0]?.provider === "string"
                            ? providerSources[0].provider
                            : providerSources[0]?.label || id
                    ),
                    providerOrder,
                    showRealProviderNames
                ),
                providerSources: [...providerSources].sort((a, b) =>
                    compareSourcesForHealthDisplay(
                        { id: a.id, provider: id },
                        { id: b.id, provider: id },
                        sourceHealth,
                        providerOrder
                    )
                ),
                unavailable: false,
                loading: false,
            })
            }
        }

        return rows
            .filter((row) => !hiddenSet.has(row.id))
            .map((row) => {
                const allSources = [...row.providerSources].sort((a, b) =>
                    compareSourcesForHealthDisplay(
                        { id: a.id, provider: row.id },
                        { id: b.id, provider: row.id },
                        sourceHealth,
                        providerOrder
                    )
                )
                const readySources = allSources.filter((source) =>
                    isSourceReadyForPicker(source.id, sourceHealth)
                )
                const probing =
                    allSources.length > 0 &&
                    readySources.length === 0 &&
                    allSources.some((source) => isSourceStillProbing(source.id, sourceHealth))

                return {
                    ...row,
                    allSources,
                    readySources,
                    providerSources: (() => {
                        let list = allSources.length > 0 ? allSources : readySources
                        if (row.id === activeProviderKey && activeSource) {
                            if (!list.some((source) => source.id === activeSource.id)) {
                                list = [activeSource, ...list]
                            }
                        }
                        return list
                    })(),
                    loading:
                        row.loading ||
                        (probing && readySources.length === 0 && allSources.length === 0),
                    isActiveProvider: row.id === activeProviderKey,
                }
            })
            .sort((a, b) => {
                const rank = (row: { providerSources: typeof enabledSources; loading: boolean; unavailable: boolean }) => {
                    if (row.providerSources.length > 0) return 0

                    if (row.loading) return 2
                    if (row.unavailable) return 4
                    return 3
                }

                const rankDelta = rank(a) - rank(b)
                if (rankDelta !== 0) return rankDelta

                return compareProvidersForDisplay(a.id, b.id, providerOrder)
            })
    }, [
        config.sources,
        streamSourcesReady,
        enabledIds,
        enabledSources,
        providerOrder,
        showRealProviderNames,
        unavailableProviders,
        hiddenProviderIds,
        sourcesLoadingMore,
        sourceHealth,
        activeProviderKey,
        activeSource,
    ])

    const loadedProviderCount = providerRows.filter(
        (row) => row.providerSources.length > 0
    ).length

    const displayStatusMessage = sourceStatusMessage
        ? maskPlaybackDiagnosticMessageWithT(
              t,
              sourceStatusMessage,
              providerOrder,
              showRealProviderNames
          )
        : undefined

    const handleSourceClick = async (sourceId: string) => {
        if (!onSelectSource || selectingSourceId) return

        setSelectingSourceId(sourceId)
        try {
            await onSelectSource(sourceId)
        } finally {
            setSelectingSourceId(null)
        }
    }

    const handleProviderClick = async (providerId: string) => {
        if (selectingSourceId) return

        const providerKey = normalizeProviderName(providerId)

        if (providerKey === "4khdhub" && onRequestProvider) {
            setSelectingSourceId(`provider:${providerId}`)
            try {
                await onRequestProvider(providerId)
            } finally {
                setSelectingSourceId(null)
            }
            return
        }

        const readySource = enabledSources.find((source) => {
            const sourceProviderKey = normalizeProviderName(
                source.providerId ??
                    (typeof source.provider === "string" ? source.provider : source.label || "")
            )
            return (
                sourceProviderKey === providerKey &&
                isSourceReadyForPicker(source.id, sourceHealth)
            )
        })

        if (readySource && onSelectSource) {
            setSelectingSourceId(readySource.id)
            try {
                await onSelectSource(readySource.id)
            } finally {
                setSelectingSourceId(null)
            }
            return
        }

        if (!onRequestProvider) return

        setSelectingSourceId(`provider:${providerId}`)
        try {
            await onRequestProvider(providerId)
        } finally {
            setSelectingSourceId(null)
        }
    }

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button variant="ghost" size="icon" className="text-white hover:bg-white/20" title={t("player.playerSettings")}>
                    <Settings className="h-5 w-5" />
                </Button>
            </PopoverTrigger>

            <PopoverContent
                className="pointer-events-auto z-[10002] w-[min(calc(100vw-1.25rem),22rem)] overflow-hidden rounded-xl border border-white/10 bg-zinc-950/95 p-0 text-white shadow-2xl shadow-black/50 backdrop-blur-xl sm:w-[24rem]"
                side="top"
                align="end"
                sideOffset={10}
                collisionPadding={12}
                onOpenAutoFocus={(event) => event.preventDefault()}
                onFocusOutside={handleOutsideDismiss}
                onPointerDownOutside={handleOutsideDismiss}
                onInteractOutside={handleOutsideDismiss}
            >
                <div className="flex items-start justify-between gap-3 border-b border-white/10 px-3 py-2.5">
                    <div className="min-w-0">
                        <p className="text-sm font-semibold tracking-tight text-white">{t("player.playerSettings")}</p>
                        <p className="text-[11px] text-zinc-500">{t("player.playerSettingsDesc")}</p>
                    </div>
                    {(onAutoplayChange || (showAutoNext && onAutoNextChange)) && (
                        <div className="flex shrink-0 flex-col gap-2">
                            {onAutoplayChange ? (
                                <label className="flex items-center justify-end gap-2">
                                    <span className="text-[10px] font-medium text-zinc-400">{t("player.autoplay")}</span>
                                    <Switch
                                        checked={autoplayEnabled ?? false}
                                        onCheckedChange={onAutoplayChange}
                                        className="h-5 w-9 data-[state=checked]:bg-primary data-[state=unchecked]:bg-zinc-700"
                                    />
                                </label>
                            ) : null}
                            {showAutoNext && onAutoNextChange ? (
                                <label className="flex items-center justify-end gap-2">
                                    <span className="text-[10px] font-medium text-zinc-400">{t("player.autoNext")}</span>
                                    <Switch
                                        checked={autoNextEnabled ?? false}
                                        onCheckedChange={onAutoNextChange}
                                        className="h-5 w-9 data-[state=checked]:bg-primary data-[state=unchecked]:bg-zinc-700"
                                    />
                                </label>
                            ) : null}
                        </div>
                    )}
                </div>

                <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full p-2 pt-1">
                    <TabsList className="grid h-auto w-full grid-cols-5 gap-0.5 rounded-lg bg-white/5 p-1">
                        <TabsTrigger
                            value="source"
                            className="flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[10px] font-medium text-zinc-400 data-[state=active]:bg-white/12 data-[state=active]:text-white sm:flex-row sm:gap-1 sm:px-2 sm:py-2 sm:text-xs"
                        >
                            <HardDrive className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span>{t("player.source")}</span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="subtitles"
                            className="flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[10px] font-medium text-zinc-400 data-[state=active]:bg-white/12 data-[state=active]:text-white sm:flex-row sm:gap-1 sm:px-2 sm:py-2 sm:text-xs"
                        >
                            <Captions className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span>{t("player.subs")}</span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="audio"
                            className="flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[10px] font-medium text-zinc-400 data-[state=active]:bg-white/12 data-[state=active]:text-white sm:flex-row sm:gap-1 sm:px-2 sm:py-2 sm:text-xs"
                        >
                            <Volume2 className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span>{t("player.audio")}</span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="quality"
                            className="flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[10px] font-medium text-zinc-400 data-[state=active]:bg-white/12 data-[state=active]:text-white sm:flex-row sm:gap-1 sm:px-2 sm:py-2 sm:text-xs"
                        >
                            <Clapperboard className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span>{t("player.quality")}</span>
                        </TabsTrigger>
                        <TabsTrigger
                            value="speed"
                            className="flex flex-col items-center gap-0.5 rounded-md px-1 py-1.5 text-[10px] font-medium text-zinc-400 data-[state=active]:bg-white/12 data-[state=active]:text-white sm:flex-row sm:gap-1 sm:px-2 sm:py-2 sm:text-xs"
                        >
                            <Gauge className="h-3.5 w-3.5 shrink-0 sm:h-4 sm:w-4" />
                            <span>{t("player.speed")}</span>
                        </TabsTrigger>
                    </TabsList>

                    <div className={cn(SETTINGS_SCROLL, "mt-2 pr-0.5")}>
                        <TabsContent value="source" className="m-0 px-1 pb-1">
                            <div className="mb-2 flex items-center justify-between gap-2 px-1">
                                <div className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
                                    {sourcesLoadingMore
                                        ? currentTestingProvider
                                            ? t("player.testing", { name: currentTestingProvider.displayName })
                                            : probeHeadline
                                        : t("player.providersReady", { count: loadedProviderCount })}
                                </div>
                                <div className="flex items-center gap-2">
                                    {sourcesLoadingMore ? (
                                        <span className="inline-flex items-center gap-1 text-[10px] text-zinc-400">
                                            <Loader2 className="size-3 animate-spin" />
                                            {t("player.scanning")}
                                        </span>
                                    ) : null}
                                    {onRefetchSources ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="h-6 border-zinc-700 px-2 text-[10px] text-zinc-300"
                                            onClick={onRefetchSources}
                                        >
                                            {t("player.retry")}
                                        </Button>
                                    ) : null}
                                </div>
                            </div>

                            <div className="space-y-2">
                                {providerRows.map(({ id, name, providerSources, allSources, readySources, unavailable, loading, isActiveProvider }) => {
                                    const displayName =
                                        sourcesLoadingMore && probeDisplayNameById.has(id)
                                            ? probeDisplayNameById.get(id)!
                                            : name

                                    return (
                                    <div key={id} className={cn(SETTINGS_PANEL, "p-1", isActiveProvider && "border-emerald-500/25 bg-emerald-500/5")}>
                                        {unavailable ? (
                                            <button
                                                type="button"
                                                disabled={Boolean(selectingSourceId) || !onRequestProvider}
                                                onClick={() => void handleProviderClick(id)}
                                                className="flex w-full min-h-9 items-center justify-between rounded-md px-2 py-2 text-zinc-400 transition-colors hover:bg-white/10 hover:text-zinc-200 disabled:opacity-60"
                                            >
                                                <ProviderNameWithFlag providerId={id} name={displayName} />
                                                {selectingSourceId === `provider:${id}` ? (
                                                    <Loader2 className="size-3.5 shrink-0 animate-spin max-md:size-4" />
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="shrink-0 border-zinc-700 py-0 text-[10px] text-zinc-500 max-md:text-xs"
                                                    >
                                                        {t("player.tapToCheck")}
                                                    </Badge>
                                                )}
                                            </button>
                                        ) : loading && !isActiveProvider && allSources.length === 0 ? (
                                            <button
                                                type="button"
                                                disabled={Boolean(selectingSourceId)}
                                                onClick={() => void handleProviderClick(id)}
                                                className="flex w-full min-h-9 items-center justify-between rounded-md px-2 py-2 text-zinc-300 transition-colors hover:bg-white/10 disabled:opacity-60"
                                            >
                                                <ProviderNameWithFlag providerId={id} name={displayName} />
                                                <Badge
                                                    variant="outline"
                                                    className="shrink-0 border-zinc-700 py-0 text-[10px] text-zinc-400"
                                                >
                                                    {probeStatusById.get(id) === "testing" ? (
                                                        <>
                                                            <Loader2 className="mr-1 inline size-3 animate-spin" />
                                                            {t("player.testingLabel")}
                                                        </>
                                                    ) : (
                                                        t("player.queued")
                                                    )}
                                                </Badge>
                                            </button>
                                        ) : providerSources.length === 0 ? (
                                            <button
                                                type="button"
                                                disabled={Boolean(selectingSourceId) || !onRequestProvider}
                                                onClick={() => void handleProviderClick(id)}
                                                className="flex w-full min-h-9 items-center justify-between rounded-md px-2 py-2 text-zinc-400 transition-colors hover:bg-white/10 hover:text-zinc-200 disabled:opacity-60"
                                            >
                                                <ProviderNameWithFlag providerId={id} name={displayName} />
                                                {selectingSourceId === `provider:${id}` ? (
                                                    <Loader2 className="size-3.5 shrink-0 animate-spin max-md:size-4" />
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="shrink-0 border-zinc-700 py-0 text-[10px] text-zinc-500 max-md:text-xs"
                                                    >
                                                        {t("player.tapToCheck")}
                                                    </Badge>
                                                )}
                                            </button>
                                        ) : (
                                            <div className="space-y-0.5">
                                                {readySources.length === 0 && allSources.length > 0 ? (
                                                    <button
                                                        type="button"
                                                        disabled={Boolean(selectingSourceId) || !onRequestProvider}
                                                        onClick={() => void handleProviderClick(id)}
                                                        className="flex w-full min-h-9 items-center justify-between rounded-md px-2 py-2 text-zinc-300 transition-colors hover:bg-white/10 disabled:opacity-60"
                                                    >
                                                        <ProviderNameWithFlag providerId={id} name={displayName} />
                                                        {selectingSourceId === `provider:${id}` ? (
                                                            <Loader2 className="size-3.5 shrink-0 animate-spin max-md:size-4" />
                                                        ) : (
                                                            <Badge
                                                                variant="outline"
                                                                className="shrink-0 border-zinc-700 py-0 text-[10px] text-zinc-400 max-md:text-xs"
                                                            >
                                                                {t("player.tapToCheck")}
                                                            </Badge>
                                                        )}
                                                    </button>
                                                ) : null}
                                                {providerSources.map((source, idx) => {
                                                    const isSelected = source.id === currentSourceId
                                                    const isSelecting = selectingSourceId === source.id
                                                    const streamLabel =
                                                        providerSources.length > 1
                                                            ? `${displayName} · ${t("player.streamN", { n: idx + 1 })}`
                                                            : displayName

                                                    return (
                                                        <button
                                                            key={source.id}
                                                            type="button"
                                                            disabled={Boolean(selectingSourceId)}
                                                            onClick={() => void handleSourceClick(source.id)}
                                                            className={cn(
                                                                "flex w-full min-h-9 items-center justify-between rounded-md px-2 py-2 transition-colors",
                                                                isSelected
                                                                    ? "bg-primary/20 text-primary"
                                                                    : "text-zinc-200 hover:bg-white/10",
                                                                isSelecting && "opacity-70"
                                                            )}
                                                        >
                                                            <div className="flex min-w-0 items-center gap-2">
                                                                <ProviderNameWithFlag providerId={id} name={streamLabel} />
                                                                {source.quality ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="shrink-0 border-zinc-700 py-0 text-[10px]"
                                                                    >
                                                                        {source.quality}
                                                                    </Badge>
                                                                ) : null}
                                                            </div>
                                                            <div className="flex shrink-0 items-center gap-1.5">
                                                                {isActiveProvider && isSelected ? (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="border-emerald-500/30 bg-emerald-500/10 py-0 text-[10px] text-emerald-200"
                                                                    >
                                                                        {t("player.playing")}
                                                                    </Badge>
                                                                ) : null}
                                                                {isSelected ? (
                                                                    <Check className="h-3.5 w-3.5 shrink-0" />
                                                                ) : isSelecting ? (
                                                                    <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
                                                                ) : null}
                                                            </div>
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        )}
                                    </div>
                                    )
                                })}
                            </div>

                            {displayStatusMessage ? (
                                <div className="mt-3 space-y-2 rounded-md border border-amber-500/20 bg-amber-500/10 px-3 py-2">
                                    <p className="text-xs text-amber-100">{displayStatusMessage}</p>
                                    {isTransientPlaybackStatusMessage(sourceStatusMessage) ? (
                                        <p className="text-[11px] text-amber-100/70">
                                            {t("player.playerSettingsDesc")}
                                        </p>
                                    ) : onRefetchSources &&
                                      shouldShowPlaybackRetryButton(sourceStatusMessage) ? (
                                        <PlaybackSourceRetryPanel
                                            onRetry={onRefetchSources}
                                            align="start"
                                            className="items-stretch"
                                            showRetryButton
                                        />
                                    ) : null}
                                </div>
                            ) : sourcesLoadingMore ? (
                                <p className="mt-3 px-2 text-center text-xs text-zinc-500">
                                    {t("player.streamsBackgroundHint")}
                                </p>
                            ) : null}
                        </TabsContent>

                        <TabsContent value="subtitles" className="m-0 space-y-2 px-1 pb-1">
                            <div className={cn(SETTINGS_PANEL, "p-1")}>
                                <button
                                    type="button"
                                    onClick={() => onSelectSubtitle?.(undefined)}
                                    className={settingsOptionClass(!currentSubtitleId)}
                                >
                                    <span>{t("player.off")}</span>
                                    {!currentSubtitleId ? <Check className="h-3.5 w-3.5 shrink-0" /> : null}
                                </button>
                            </div>

                            {groupedSubtitles.length > 0 ? (
                                <div className="space-y-1.5">
                                    {groupedSubtitles.map((group) => {
                                        const isOpen = openSubtitleGroup === group.code
                                        const hasActiveTrack = group.items.some(
                                            (item) => item.id === currentSubtitleId
                                        )

                                        return (
                                            <div
                                                key={group.code}
                                                className={cn(
                                                    SETTINGS_PANEL,
                                                    hasActiveTrack && "border-primary/30 bg-primary/5"
                                                )}
                                            >
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setOpenSubtitleGroup(isOpen ? undefined : group.code)
                                                    }
                                                    className="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-zinc-200 transition-colors hover:bg-white/10"
                                                >
                                                    {group.flag ? (
                                                        <span className="text-base leading-none" aria-hidden="true">
                                                            {group.flag}
                                                        </span>
                                                    ) : null}
                                                    <span className="text-sm font-medium">{group.name}</span>
                                                    <span className="rounded-full bg-white/10 px-1.5 py-0.5 text-[10px] text-zinc-400">
                                                        {group.items.length}
                                                    </span>
                                                    <ChevronDown
                                                        className={cn(
                                                            "ml-auto h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200",
                                                            isOpen && "rotate-180"
                                                        )}
                                                    />
                                                </button>

                                                {isOpen ? (
                                                    <div className="space-y-0.5 border-t border-white/10 p-1">
                                                        {group.items.map((subtitle) => (
                                                            <button
                                                                key={subtitle.id}
                                                                type="button"
                                                                onClick={() =>
                                                                    onSelectSubtitle?.(subtitle.id)
                                                                }
                                                                className={settingsOptionClass(
                                                                    currentSubtitleId === subtitle.id
                                                                )}
                                                            >
                                                                <span className="flex items-center gap-2">
                                                                    {group.flag ? (
                                                                        <span
                                                                            className="text-sm leading-none"
                                                                            aria-hidden="true"
                                                                        >
                                                                            {group.flag}
                                                                        </span>
                                                                    ) : null}
                                                                    <span>{subtitle.trackLabel}</span>
                                                                </span>
                                                                {currentSubtitleId === subtitle.id ? (
                                                                    <Check className="h-3.5 w-3.5 shrink-0" />
                                                                ) : null}
                                                            </button>
                                                        ))}
                                                    </div>
                                                ) : null}
                                            </div>
                                        )
                                    })}
                                </div>
                            ) : null}

                            {externalSubtitlesLoading ? (
                                <div className="flex items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] py-4 text-xs text-zinc-500">
                                    <Loader2 className="size-3.5 animate-spin" />
                                    {t("player.loadingExternalSubs")}
                                </div>
                            ) : null}

                            {subtitles.length === 0 && !externalSubtitlesLoading ? (
                                <div className="rounded-lg border border-dashed border-white/10 py-6 text-center text-xs text-zinc-500">
                                    {t("player.noSubtitles")}
                                </div>
                            ) : null}
                        </TabsContent>

                        <TabsContent value="audio" className="m-0 px-1 pb-1">
                            {audioTracks.length > 0 ? (
                                <div className={cn(SETTINGS_PANEL, "space-y-0.5 p-1")}>
                                    {audioTracks.map((track) => (
                                        <button
                                            key={track.id}
                                            type="button"
                                            onClick={() => onSelectAudioTrack?.(track.id)}
                                            className={settingsOptionClass(
                                                currentAudioTrackId === track.id
                                            )}
                                        >
                                            <span>{track.label}</span>
                                            {currentAudioTrackId === track.id ? (
                                                <Check className="h-3.5 w-3.5 shrink-0" />
                                            ) : null}
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed border-white/10 py-6 text-center text-xs text-zinc-500">
                                    {t("player.noAudioTracks")}
                                </div>
                            )}
                        </TabsContent>

                        <TabsContent value="quality" className="m-0 px-1 pb-1">
                            <div className={cn(SETTINGS_PANEL, "space-y-0.5 p-1")}>
                                <button
                                    type="button"
                                    onClick={() => onQualityChange(-1)}
                                    className={settingsOptionClass(currentQuality === -1)}
                                >
                                    <span>{t("player.auto")}</span>
                                    {currentQuality === -1 ? <Check className="h-3.5 w-3.5 shrink-0" /> : null}
                                </button>

                                {qualities.map((quality) => (
                                    <button
                                        key={quality.index}
                                        type="button"
                                        onClick={() => onQualityChange(quality.index)}
                                        className={settingsOptionClass(currentQuality === quality.index)}
                                    >
                                        <span>{quality.label}</span>
                                        {currentQuality === quality.index ? (
                                            <Check className="h-3.5 w-3.5 shrink-0" />
                                        ) : null}
                                    </button>
                                ))}
                            </div>

                            {qualities.length === 0 ? (
                                <div className="mt-2 rounded-lg border border-dashed border-white/10 py-6 text-center text-xs text-zinc-500">
                                    {t("player.noAlternateQualities")}
                                </div>
                            ) : null}
                        </TabsContent>

                        <TabsContent value="speed" className="m-0 px-1 pb-1">
                            <div className={cn(SETTINGS_PANEL, "grid grid-cols-2 gap-1 p-1 sm:grid-cols-3")}>
                                {PLAYBACK_RATES.map((speed) => (
                                    <button
                                        key={speed}
                                        type="button"
                                        onClick={() => onPlaybackRateChange(speed)}
                                        className={cn(
                                            "flex min-h-9 items-center justify-center gap-1 rounded-md px-2 py-2 text-sm font-medium transition-colors",
                                            playbackRate === speed
                                                ? "bg-primary/20 text-primary"
                                                : "text-zinc-200 hover:bg-white/10"
                                        )}
                                    >
                                        <span>{speed}x</span>
                                        {playbackRate === speed ? (
                                            <Check className="h-3.5 w-3.5 shrink-0" />
                                        ) : null}
                                    </button>
                                ))}
                            </div>
                        </TabsContent>
                    </div>
                </Tabs>
            </PopoverContent>
        </Popover>
    )
}
