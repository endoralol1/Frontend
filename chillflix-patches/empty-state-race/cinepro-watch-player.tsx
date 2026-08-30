"use client"

import { useCallback, useEffect, useMemo, useRef, useState, Suspense } from "react"
import { useMediaQuery } from "@/hooks/use-media-query"
import { ExternalPlayerPanel } from "@/components/external-player-panel"
import { PlayerEngineDropdown } from "@/components/player-engine-dropdown"
import { FullscreenPortalContext } from "@/components/player/fullscreen-portal-context"
import { MediaPlayer } from "@/components/player/MediaPlayer"
import { Button } from "@/components/ui/button"
import { useWatchPartyState } from "@/components/watch-party-context"
import { WatchPartyDropdown } from "@/components/watch-party-dropdown"
import { PlaybackSourceRetryPanel } from "@/components/player/PlaybackSourceRetryPanel"
import { SourceLoadingOverlay } from "@/components/player/SourceLoadingOverlay"
import {
    buildPlaybackPickGate,
    getHealthFailedProviderIds,
    filterPlaybackOptionsByScanGate,
    hasPendingSourceHealthProbe,
    pickAutomaticProbeSources,
    pickAutoStartPlaybackSource,
    pickForceStartPlaybackSource,
    shouldSkipPlaybackHealthProbe,
    getProviderDisplayName,
    isVidLinkOnlySources,
    mapSourcesToPlaybackOptions,
    normalizeProviderName,
    parseStableSourceId,
    parseUnavailableProviders,
    pickPreferredPlaybackSource,
    shouldPreferSourceUpgrade,
    shouldUpgradeToHigherRankedSource,
    canImmediatelyStartProviderOption,
    isTrustedPlaybackSourceUrl,
    isFourKHlsPlaybackUrl,
    isProviderRequiringHealthProbe,
    isBackgroundSourcesPending,
    shouldMarkAutoPickSourceReady,
    getOrderedEnabledProviderIds,
} from "@/components/player/utils/playback"
import {
    type PlaybackErrorMeta,
    usePlaybackSourceFallback,
} from "@/hooks/usePlaybackSourceFallback"
import { useExternalSubtitles } from "@/hooks/useExternalSubtitles"
import { prefetchMediaSources, useMediaSources } from "@/hooks/useMediaSources"
import { useSourceHealth } from "@/hooks/useSourceHealth"
import { useClientSearchParams } from "@/hooks/useClientSearchParams"
import { usePreferredExternalPlayer } from "@/hooks/usePreferredExternalPlayer"
import { useStreamSourcesConfig } from "@/hooks/useStreamSourcesConfig"
import { useWatchPartyPlayback } from "@/hooks/use-watch-party-playback"
import { useAuth } from "@/hooks/use-auth"
import { usePlaybackAmbientPause } from "@/hooks/use-playback-ambient-pause"
import { useShowRealProviderNames } from "@/hooks/use-show-real-provider-names"
import { pickBestFourKPlaybackOption, pickExplicitUserProviderPlaybackOption, pickHighestFourKPlaybackOption } from "@/lib/4khdhub/codec"
import { resolveProviderUserLabel } from "@/lib/provider-display"
import { PRIMARY_PROVIDER_HEAD_START_MS } from "@/lib/source-probe-constants"
import {
    getTitleUnavailableMessage,
    pickUserFacingPlaybackWarning,
    sanitizePublicPlaybackStatusWithT,
} from "@/lib/playback-user-messages"
import { useTranslations } from "@/lib/i18n/client"
import { resolveResumeTime, type WatchProgressMeta } from "@/lib/watch-progress"
import {
    createPlaybackRecoveryLimiter,
    isProxyTokenPlaybackError,
    isRecoverableStreamError,
    playbackPositionForRecovery,
} from "@/lib/playback-error-recovery"
import { getClientUser } from "@/lib/auth-client"
import { readSessionAutoNext, writeSessionAutoNext } from "@/lib/auto-next-preference"
import { watchPartyPlayerUrl } from "@/lib/watch-party-session"
import type { WatchPartyRoom } from "@/lib/watch-party-types"
import { postEmbedMessage } from "@/lib/embed-player"
import { EMBED_MESSAGE_CONTROLS_VISIBLE } from "@/lib/embed-ui-sync"
import { cn } from "@/lib/utils"

type MediaType = "movie" | "tv"

type SourceType = "hls" | "file"

type PlaybackOption = ReturnType<typeof mapSourcesToPlaybackOptions>[number]

interface CineProWatchPlayerProps {
    mediaType: MediaType
    mediaId: string | number
    season?: string | number
    episode?: string | number
    watchMeta?: WatchProgressMeta
    showToolbar?: boolean
    onPlaybackEnded?: () => void
    onTvEpisodeChange?: (season: number, episode: number) => void
    embedMode?: boolean
    embedOptions?: {
        autoplay?: boolean
        autoNext?: boolean
        startAt?: number
        showTitle?: boolean
        showWatchParty?: boolean
    }
}

export function CineProWatchPlayer({
    mediaType,
    mediaId,
    season,
    episode,
    watchMeta,
    showToolbar = true,
    onPlaybackEnded,
    onTvEpisodeChange,
    embedMode = false,
    embedOptions,
}: CineProWatchPlayerProps) {
    usePlaybackAmbientPause(true)
    const { t } = useTranslations()
    const isMobile = useMediaQuery("(max-width: 768px)")
    const searchParams = useClientSearchParams()
    const prefer4k =
        searchParams.get("prefer4k") === "1" || Boolean(embedOptions?.prefer4k)
    const [has4kCatalog, setHas4kCatalog] = useState(false)
    const prefer4kAttemptedRef = useRef(false)
    const { user, updateProfile } = useAuth()
    const [autoNext, setAutoNext] = useState(() => {
        if (embedMode && embedOptions?.autoNext) return true
        const clientUser = getClientUser()
        if (clientUser) return clientUser.autoNextEnabled
        return readSessionAutoNext()
    })
    const autoNextRef = useRef(autoNext)
    const embedShellRef = useRef<HTMLDivElement>(null)
    const [embedFullscreenPortal, setEmbedFullscreenPortal] = useState<HTMLElement | null>(null)
    const setEmbedShellRef = useCallback((node: HTMLDivElement | null) => {
        embedShellRef.current = node
        setEmbedFullscreenPortal(node)
    }, [])
    const onPlaybackEndedRef = useRef(onPlaybackEnded)
    const [watchPartyOpen, setWatchPartyOpen] = useState(false)
    const { room: watchPartyRoom, setRoom: setWatchPartyRoom } = useWatchPartyState()
    const {
        isHost: isWatchPartyHost,
        isGuest: isWatchPartyGuest,
        guestPlayback,
        awaitingPartyRoom,
        inParty: inWatchParty,
        handlePlaybackSync,
        syncPlayback,
        guestResumeTime,
    } = useWatchPartyPlayback()
    const mediaTitle = watchMeta?.title ?? "Playback"
    const numericSeason = season !== undefined ? Number(season) : undefined
    const numericEpisode = episode !== undefined ? Number(episode) : undefined

    autoNextRef.current = autoNext
    onPlaybackEndedRef.current = onPlaybackEnded

    useEffect(() => {
        if (user) {
            setAutoNext(user.autoNextEnabled)
        } else {
            setAutoNext(readSessionAutoNext())
        }
    }, [user?.id, user?.autoNextEnabled])

    const handleAutoNextChange = useCallback(
        (enabled: boolean) => {
            setAutoNext(enabled)
            if (user) {
                void updateProfile({ autoNextEnabled: enabled }).then((error) => {
                    if (error) setAutoNext(!enabled)
                })
            } else {
                writeSessionAutoNext(enabled)
            }
        },
        [updateProfile, user]
    )

    const handlePlaybackEnded = useCallback(() => {
        if (inWatchParty) return
        if (!autoNextRef.current) return
        onPlaybackEndedRef.current?.()
    }, [inWatchParty])

    useEffect(() => {
        if (watchPartyRoom && searchParams.get("party")) {
            setWatchPartyOpen(true)
        }
    }, [searchParams, watchPartyRoom])

    const handleJoinHostMedia = useCallback((room: WatchPartyRoom) => {
        if (embedMode) {
            const params = new URLSearchParams(window.location.search)
            params.set("party", room.id)
            window.location.search = params.toString()
            return
        }

        const path = watchPartyPlayerUrl(room)
        window.location.href = path
    }, [embedMode])
    const openingPlaybackRef = useRef({
        mediaKey: "",
        episode: null as { season: number; episode: number } | null,
        startAt: undefined as number | undefined,
    })

    const mediaKey = `${mediaType}:${mediaId}`
    if (openingPlaybackRef.current.mediaKey !== mediaKey) {
        const rawStart =
            embedOptions?.startAt ??
            searchParams.get("startAt") ??
            searchParams.get("continue")
        const parsedStart = rawStart != null ? Number(rawStart) : NaN

        openingPlaybackRef.current = {
            mediaKey,
            episode:
                mediaType === "tv" && numericSeason != null && numericEpisode != null
                    ? { season: numericSeason, episode: numericEpisode }
                    : null,
            startAt:
                Number.isFinite(parsedStart) && parsedStart > 0 ? parsedStart : undefined,
        }
    }

    const stableWatchMeta = useMemo((): WatchProgressMeta | undefined => {
        const runtimeMinutes =
            embedOptions?.runtimeMinutes && embedOptions.runtimeMinutes > 0
                ? embedOptions.runtimeMinutes
                : watchMeta?.runtimeMinutes

        if (!watchMeta) {
            return undefined
        }

        return {
            ...watchMeta,
            ...(runtimeMinutes && runtimeMinutes > 0 ? { runtimeMinutes } : {}),
        }
    }, [
        embedOptions?.runtimeMinutes,
        watchMeta,
        watchMeta?.id,
        watchMeta?.type,
        watchMeta?.title,
        watchMeta?.poster,
        watchMeta?.season,
        watchMeta?.episode,
        watchMeta?.runtimeMinutes,
    ])
    const effectiveShowToolbar = showToolbar && !embedMode
    const embedShowToolbar =
        embedMode &&
        (embedOptions?.showTitle !== false || embedOptions?.showWatchParty !== false)

    const livePlaybackTimeRef = useRef(0)
    const [upgradeResumeTime, setUpgradeResumeTime] = useState<number | undefined>()

    const resumeTime = useMemo(() => {
        if (typeof upgradeResumeTime === "number" && upgradeResumeTime > 0) {
            return upgradeResumeTime
        }

        return resolveResumeTime(stableWatchMeta, {
            openingEpisode: openingPlaybackRef.current.episode ?? undefined,
            openingResumeTime: openingPlaybackRef.current.startAt,
        })
    }, [stableWatchMeta, numericSeason, numericEpisode, upgradeResumeTime])

    useEffect(() => {
        setUpgradeResumeTime(undefined)
        livePlaybackTimeRef.current = 0
    }, [mediaId, mediaType, season, episode])

    const handlePlaybackTime = useCallback((timeSec: number) => {
        livePlaybackTimeRef.current = timeSec
    }, [])
    const [playbackActive, setPlaybackActive] = useState(false)
    const hasReadySourceRef = useRef(false)
    const healthFailedProviderIdsRef = useRef<string[]>([])
    const {
        sources,
        subtitles,
        diagnostics,
        playbackToken,
        isLoading,
        isLoadingMore: sourcesLoadingMore,
        error,
        refetchSources,
        refetchSourcesAsync,
        requestProvider,
        scanningProviderId,
        scanFailedProviderIds,
        isScanningProviders,
        providerScanStartedAt,
    } = useMediaSources({
        id: mediaId,
        type: mediaType,
        season,
        episode,
        playbackActive,
        hasReadySourceRef,
        healthFailedProviderIdsRef,
        embedFastStart: true,
    })

    useEffect(() => {
        let cancelled = false

        void fetch(
            `/api/4k/check?tmdbId=${encodeURIComponent(String(mediaId))}&type=${encodeURIComponent(mediaType)}`,
            { cache: "no-store" }
        )
            .then((response) => (response.ok ? response.json() : null))
            .then((payload) => {
                if (!cancelled) {
                    setHas4kCatalog(Boolean(payload?.has4k))
                }
            })
            .catch(() => {
                if (!cancelled) setHas4kCatalog(false)
            })

        return () => {
            cancelled = true
            prefer4kAttemptedRef.current = false
            setHas4kCatalog(false)
        }
    }, [mediaId, mediaType, season, episode])

    const hiddenProviderIds = useMemo(
        () => (has4kCatalog ? [] : ["4khdhub"]),
        [has4kCatalog]
    )

    const [selectedSourceId, setSelectedSourceId] = useState("")
    const [runtimeError, setRuntimeError] = useState<string>()
    const providerResolveAttemptedRef = useRef(false)
    const playbackStableRef = useRef(false)
    const [playbackStable, setPlaybackStable] = useState(false)
    const sourceLoadingWatchdogRef = useRef(false)
    const forcePickWatchdogRef = useRef(false)
    const startupSourceLockedRef = useRef(false)
    const sourceUpgradeAttemptedRef = useRef(false)
    const playbackRecoveryLimiterRef = useRef(createPlaybackRecoveryLimiter())
    const [hasStartedPlaybackAttempt, setHasStartedPlaybackAttempt] = useState(false)
    const [sourceSwitchNonce, setSourceSwitchNonce] = useState(0)
    const {
        order: providerOrder,
        enabledIds,
        enabledPlayers,
        loading: streamSourcesLoading,
    } = useStreamSourcesConfig()
    const adminCanSeeRealProviderNames = useShowRealProviderNames()
    const showRealProviderNames = embedMode ? false : adminCanSeeRealProviderNames
    const [activePlayerId, setActivePlayerId] = usePreferredExternalPlayer(
        enabledPlayers,
        streamSourcesLoading
    )
    const effectiveActivePlayerId = inWatchParty ? null : activePlayerId

    const orderedEnabledProviderIds = useMemo(
        () => getOrderedEnabledProviderIds(providerOrder, enabledIds),
        [enabledIds, providerOrder]
    )

    useEffect(() => {
        if (!prefer4k || !has4kCatalog || prefer4kAttemptedRef.current) {
            return
        }

        if (streamSourcesLoading || isLoading) {
            return
        }

        prefer4kAttemptedRef.current = true
        void requestProvider("4khdhub").catch(() => undefined)
    }, [
        has4kCatalog,
        isLoading,
        prefer4k,
        requestProvider,
        streamSourcesLoading,
    ])

    const playbackOptions: PlaybackOption[] = useMemo(
        () =>
            mapSourcesToPlaybackOptions(sources, {
                providerOrder: orderedEnabledProviderIds,
                showRealProviderNames,
            }),
        [orderedEnabledProviderIds, showRealProviderNames, sources]
    )

    const primaryProviderResolved = useMemo(() => {
        const primaryId = orderedEnabledProviderIds[0]
        if (!primaryId) return false
        return sources.some(
            (source) => normalizeProviderName(source.provider.id) === primaryId
        )
    }, [orderedEnabledProviderIds, sources])

    const providerScanGate = useMemo(
        () => ({
            isScanningProviders,
            scanningProviderId,
            scanFailedProviderIds,
            scanStartedAt: providerScanStartedAt,
            primaryProviderResolved,
        }),
        [
            isScanningProviders,
            primaryProviderResolved,
            providerScanStartedAt,
            scanFailedProviderIds,
            scanningProviderId,
        ]
    )

    const gatedPlaybackOptions = useMemo(
        () =>
            filterPlaybackOptionsByScanGate(
                playbackOptions,
                orderedEnabledProviderIds,
                providerScanGate
            ),
        [orderedEnabledProviderIds, playbackOptions, providerScanGate]
    )

    const hasPlayableBatch = sources.length > 0
    const optionsForPlayback = hasPlayableBatch ? playbackOptions : gatedPlaybackOptions

    const probeSources = useMemo(
        () =>
            pickAutomaticProbeSources(
                playbackOptions,
                providerOrder,
                orderedEnabledProviderIds
            ).map((option) => ({
                id: option.id,
                url: option.src,
                type: option.sourceType,
            })),
        [orderedEnabledProviderIds, playbackOptions, providerOrder]
    )

    const primaryProbeSourceIds = useMemo(() => {
        const primaryId = orderedEnabledProviderIds[0]
        if (!primaryId) return [] as string[]
        return probeSources
            .filter((source) => {
                const option = playbackOptions.find((item) => item.id === source.id)
                return option?.providerId === primaryId
            })
            .map((source) => source.id)
    }, [orderedEnabledProviderIds, playbackOptions, probeSources])

    const skipHealthProbe = useMemo(
        () =>
            shouldSkipPlaybackHealthProbe({
                diagnostics,
                sources,
                sourcesLoadingMore,
                // Only skip probes in embed fast-start. On the watch page,
                // always health-test sources in admin order so a dead #1
                // does not auto-start (and freeze discovery) before #2.
                embedFastStart: embedMode,
                isScanningProviders,
                scanningProviderId,
            }),
        [
            diagnostics,
            embedMode,
            isScanningProviders,
            scanningProviderId,
            sources,
            sourcesLoadingMore,
        ]
    )

    const trustedProbeSourceIds = useMemo(() => {
        const options = hasPlayableBatch ? playbackOptions : gatedPlaybackOptions

        // Embed fast-start may trust the first batch. On the watch page we probe
        // every source in admin order — pre-trusting MovieBox/proxy URLs used to
        // mark them ready and abort Flixhqz/Huhu probing.
        if (!skipHealthProbe) {
            return [] as string[]
        }

        return options
            .filter(
                (option) =>
                    !isProviderRequiringHealthProbe(option.providerId, option.src)
            )
            .map((option) => option.id)
    }, [gatedPlaybackOptions, hasPlayableBatch, playbackOptions, skipHealthProbe])

    const playbackProbeContext = useMemo(
        () => ({
            type: mediaType,
            tmdbId: String(mediaId),
            ...(mediaType === "tv"
                ? {
                      season: String(season ?? ""),
                      episode: String(episode ?? ""),
                  }
                : {}),
        }),
        [episode, mediaId, mediaType, season]
    )

    const {
        health: sourceHealth,
        ensureProbed,
        activeProbeId,
        hasReadySource,
        isProbing,
        firstReadySourceId,
        markSourceReady,
        markSourceFailed,
    } = useSourceHealth(probeSources, {
        enabled: !streamSourcesLoading && playbackOptions.length > 0 && !playbackStable,
        sequential: true,
        stopOnFirstReady: true,
        skipProbe: skipHealthProbe,
        trustedSourceIds: trustedProbeSourceIds,
        playbackContext: playbackProbeContext,
        headStartMs: PRIMARY_PROVIDER_HEAD_START_MS,
        primarySourceIds: primaryProbeSourceIds,
    })

    useEffect(() => {
        hasReadySourceRef.current = hasReadySource
    }, [hasReadySource])

    useEffect(() => {
        healthFailedProviderIdsRef.current = Array.from(
            getHealthFailedProviderIds(playbackOptions, sourceHealth)
        )
    }, [playbackOptions, sourceHealth])


    const activeTestingProviderId = useMemo(() => {
        if (scanningProviderId) return scanningProviderId

        if (!activeProbeId) return undefined
        return playbackOptions.find((option) => option.id === activeProbeId)?.providerId
    }, [activeProbeId, playbackOptions, scanningProviderId])

    const unavailableProviders = useMemo(
        () =>
            parseUnavailableProviders(
                diagnostics,
                playbackOptions.map((option) => option.provider)
            ),
        [diagnostics, playbackOptions]
    )

    const playbackPickGate = useMemo(
        () =>
            buildPlaybackPickGate({
                sourcesLoadingMore:
                    sourcesLoadingMore ||
                    isScanningProviders ||
                    Boolean(scanningProviderId),
                unavailableProviderIds: new Set(
                    unavailableProviders.map((name) => normalizeProviderName(name))
                ),
                sourceHealth,
                playbackOptions: gatedPlaybackOptions,
                primaryProviderId: orderedEnabledProviderIds[0],
                scanFailedProviderIds: new Set(
                    scanFailedProviderIds.map((id) => normalizeProviderName(id))
                ),
            }),
        [
            gatedPlaybackOptions,
            isScanningProviders,
            orderedEnabledProviderIds,
            scanFailedProviderIds,
            scanningProviderId,
            sourceHealth,
            sourcesLoadingMore,
            unavailableProviders,
        ]
    )

    const {
        manualSelectionRef,
        selectedProviderKeyRef,
        selectedSourceIdRef,
        resetFallbackState,
        syncSelectedSourceId,
        markManualSelection,
        markPendingProviderSelection,
        clearFailedProvider,
        pickInitialSource,
        resolvePlaybackError,
        attemptRecoveryFallback,
        attemptRecoveryFromOptions,
        pickSourceAfterRefetch,
        hasStablePlayback,
        isCurrentProviderFailed,
    } = usePlaybackSourceFallback(
        optionsForPlayback,
        orderedEnabledProviderIds,
        playbackPickGate,
        playbackStableRef
    )

    useEffect(() => {
        resetFallbackState()
        providerResolveAttemptedRef.current = false
        playbackStableRef.current = false
        setPlaybackStable(false)
        startupSourceLockedRef.current = false
        sourceUpgradeAttemptedRef.current = false
        playbackRecoveryLimiterRef.current.reset()
        setHasStartedPlaybackAttempt(false)
        setPlaybackActive(false)
        // Must clear these or the next title's provider scan exits immediately
        // (stale hasReadySourceRef makes shouldStopDiscovering() true).
        hasReadySourceRef.current = false
        healthFailedProviderIdsRef.current = []
        setRuntimeError(undefined)
        setSelectedSourceId("")
        setSourceSwitchNonce(0)
        sourceLoadingWatchdogRef.current = false
        forcePickWatchdogRef.current = false
    }, [mediaId, mediaType, season, episode, resetFallbackState])

    const lockStartupSource = useCallback(() => {
        startupSourceLockedRef.current = true
    }, [])

    const bumpSourceSwitch = useCallback(() => {
        if (!playbackRecoveryLimiterRef.current.canSwitch()) {
            return false
        }
        setSourceSwitchNonce((nonce) => nonce + 1)
        return true
    }, [])

    useEffect(() => {
        if (mediaType !== "tv" || season === undefined || episode === undefined) return

        const nextEpisode = Number(episode) + 1
        if (!Number.isFinite(nextEpisode)) return

        void prefetchMediaSources({
            id: mediaId,
            type: "tv",
            season,
            episode: nextEpisode,
        })
    }, [episode, mediaId, mediaType, season])

    useEffect(() => {
        if (streamSourcesLoading && playbackOptions.length === 0) return
        if (isLoading && playbackOptions.length === 0) return
        if (isScanningProviders && !hasPlayableBatch && !manualSelectionRef.current) return

        if (!playbackOptions.length) {
            if (prefer4k && has4kCatalog && !prefer4kAttemptedRef.current) {
                prefer4kAttemptedRef.current = true
                void requestProvider("4khdhub")
                    .then((payload) => {
                        const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                            providerOrder: orderedEnabledProviderIds,
                            showRealProviderNames,
                        })
                        const fourK = pickBestFourKPlaybackOption(refreshed)
                        if (!fourK) return
                        syncSelectedSourceId(fourK.id)
                        setSelectedSourceId(fourK.id)
                        markSourceReady(fourK.id)
                        setSourceSwitchNonce((nonce) => nonce + 1)
                    })
                    .catch(() => undefined)
            } else {
                setSelectedSourceId("")
            }
            return
        }

        if (!selectedSourceId) {
            if (manualSelectionRef.current) {
                const providerKey = selectedProviderKeyRef.current
                if (providerKey) {
                    const pending = pickExplicitUserProviderPlaybackOption(
                        playbackOptions,
                        providerKey
                    )
                    if (pending) {
                        lockStartupSource()
                        syncSelectedSourceId(pending.id)
                        setSelectedSourceId(pending.id)
                        if (shouldMarkAutoPickSourceReady(pending, sourceHealth)) {
                            markSourceReady(pending.id)
                        }
                    }
                }
                return
            }

            if (prefer4k && has4kCatalog) {
                const fourKOption = pickBestFourKPlaybackOption(playbackOptions)

                if (
                    fourKOption &&
                    (skipHealthProbe ||
                        sourceHealth[fourKOption.id] === "ready" ||
                        isTrustedPlaybackSourceUrl(fourKOption.src))
                ) {
                    lockStartupSource()
                    syncSelectedSourceId(fourKOption.id)
                    setSelectedSourceId(fourKOption.id)
                    if (shouldMarkAutoPickSourceReady(fourKOption, sourceHealth)) {
                        markSourceReady(fourKOption.id)
                    }
                    return
                }

                if (!prefer4kAttemptedRef.current) {
                    prefer4kAttemptedRef.current = true
                    void requestProvider("4khdhub")
                        .then((payload) => {
                            const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                                providerOrder: orderedEnabledProviderIds,
                                showRealProviderNames,
                            })
                            const fourK = pickBestFourKPlaybackOption(refreshed)
                            if (!fourK) return
                            syncSelectedSourceId(fourK.id)
                            setSelectedSourceId(fourK.id)
                            markSourceReady(fourK.id)
                            setSourceSwitchNonce((nonce) => nonce + 1)
                        })
                        .catch(() => undefined)
                    return
                }
            }

            const preferred = pickAutoStartPlaybackSource(playbackOptions, {
                skipHealthProbe,
                sourceHealth,
                firstReadySourceId,
                providerOrder: orderedEnabledProviderIds,
                pickGate: playbackPickGate,
            })
            if (!preferred) return
            lockStartupSource()
            syncSelectedSourceId(preferred.id)
            setSelectedSourceId(preferred.id)
            if (shouldMarkAutoPickSourceReady(preferred, sourceHealth)) {
                markSourceReady(preferred.id)
            }
            return
        }

        const current = playbackOptions.find((option) => option.id === selectedSourceId)
        if (current) {
            if (
                !manualSelectionRef.current &&
                !playbackStableRef.current &&
                isCurrentProviderFailed()
            ) {
                const recovery = attemptRecoveryFallback()
                if (recovery) {
                    if (!bumpSourceSwitch()) return
                    setRuntimeError(recovery.statusMessage)
                    setSelectedSourceId(recovery.nextSourceId)
                    return
                }
            }

            syncSelectedSourceId(selectedSourceId)
            return
        }

        if (playbackStableRef.current && selectedSourceId) {
            const parsed = parseStableSourceId(selectedSourceId)
            const stableMatch = parsed
                ? playbackOptions.find((option) => {
                      const optionParsed = parseStableSourceId(option.id)
                      return (
                          optionParsed &&
                          optionParsed.providerId === parsed.providerId &&
                          optionParsed.quality === parsed.quality &&
                          optionParsed.type === parsed.type
                      )
                  })
                : undefined

            if (stableMatch) {
                syncSelectedSourceId(stableMatch.id)
                setSelectedSourceId(stableMatch.id)
                return
            }

            return
        }

        const matched = selectedProviderKeyRef.current
            ? pickExplicitUserProviderPlaybackOption(
                  playbackOptions,
                  selectedProviderKeyRef.current
              )
            : undefined

        if (matched && !playbackStableRef.current) {
            syncSelectedSourceId(matched.id)
            setSelectedSourceId(matched.id)
            return
        }

        if (playbackStableRef.current) {
            return
        }

        if (!manualSelectionRef.current && !playbackStableRef.current) {
            if (livePlaybackTimeRef.current >= 1.5) return
            if (startupSourceLockedRef.current && selectedSourceId) {
                const stillExists = playbackOptions.some(
                    (option) => option.id === selectedSourceId
                )
                if (stillExists && !isCurrentProviderFailed()) return
            }

            const preferred = pickAutoStartPlaybackSource(playbackOptions, {
                skipHealthProbe,
                sourceHealth,
                firstReadySourceId,
                providerOrder: orderedEnabledProviderIds,
                pickGate: playbackPickGate,
            })
            if (!preferred) return
            lockStartupSource()
            syncSelectedSourceId(preferred.id)
            setSelectedSourceId(preferred.id)
            if (shouldMarkAutoPickSourceReady(preferred, sourceHealth)) {
                markSourceReady(preferred.id)
            }
        }
    }, [
        attemptRecoveryFallback,
        firstReadySourceId,
        has4kCatalog,
        hasPlayableBatch,
        isCurrentProviderFailed,
        manualSelectionRef,
        markSourceReady,
        orderedEnabledProviderIds,
        playbackPickGate,
        playbackOptions,
        prefer4k,
        providerOrder,
        requestProvider,
        selectedProviderKeyRef,
        selectedSourceId,
        showRealProviderNames,
        skipHealthProbe,
        isLoading,
        sourceHealth,
        sources,
        sourcesLoadingMore,
        streamSourcesLoading,
        syncSelectedSourceId,
        isScanningProviders,
    ])

    useEffect(() => {
        if (!selectedSourceId || manualSelectionRef.current || playbackStableRef.current) return
        if (!runtimeError && !isCurrentProviderFailed()) return

        const current = playbackOptions.find((option) => option.id === selectedSourceId)
        const preferred = pickInitialSource()
        if (!current || !preferred || preferred.id === selectedSourceId) return

        if (
            shouldPreferSourceUpgrade({
                current: {
                    url: current.src,
                    provider: { name: current.provider },
                },
                preferred: {
                    url: preferred.src,
                    provider: { name: preferred.provider },
                },
                manualSelection: false,
                hasRuntimeError: Boolean(runtimeError),
            })
        ) {
            syncSelectedSourceId(preferred.id)
            setSelectedSourceId(preferred.id)
            setRuntimeError(undefined)
        }
    }, [
        isCurrentProviderFailed,
        manualSelectionRef,
        pickInitialSource,
        playbackOptions,
        runtimeError,
        selectedSourceId,
        syncSelectedSourceId,
    ])

    useEffect(() => {
        if (!isCurrentProviderFailed() || playbackStableRef.current) return

        const recovery = attemptRecoveryFallback()
        if (!recovery) return
        if (!bumpSourceSwitch()) return

        setRuntimeError(recovery.statusMessage)
        setSelectedSourceId(recovery.nextSourceId)
    }, [attemptRecoveryFallback, bumpSourceSwitch, isCurrentProviderFailed, playbackOptions, sourcesLoadingMore])

    const isResolvingMoreProviders =
        runtimeError === t("player.status.checkingOther") ||
        runtimeError === t("player.status.findingStream") ||
        runtimeError === "Checking other providers…" ||
        runtimeError === "Resolving CinePro providers…" ||
        (isCurrentProviderFailed() &&
            isVidLinkOnlySources(sources) &&
            (isLoading || sourcesLoadingMore))

    const probeSourceIds = useMemo(() => probeSources.map((source) => source.id), [probeSources])

    const hasPendingProbe = useMemo(
        () => hasPendingSourceHealthProbe(probeSourceIds, sourceHealth),
        [probeSourceIds, sourceHealth]
    )

    const resolvedSourceId = useMemo(() => {
        if (selectedSourceId) return selectedSourceId
        if (manualSelectionRef.current) return ""
        if (isScanningProviders && !hasPlayableBatch) return ""

        const autoStart = pickAutoStartPlaybackSource(playbackOptions, {
            skipHealthProbe,
            sourceHealth,
            firstReadySourceId,
            providerOrder: orderedEnabledProviderIds,
            pickGate: playbackPickGate,
        })
        return autoStart?.id ?? ""
    }, [
        firstReadySourceId,
        hasPlayableBatch,
        isScanningProviders,
        playbackOptions,
        playbackPickGate,
        orderedEnabledProviderIds,
        selectedSourceId,
        skipHealthProbe,
        sourceHealth,
    ])

    useEffect(() => {
        if (!resolvedSourceId) return
        // Mark that we tried to start — but do NOT set playbackActive yet.
        // playbackActive stops the provider scan; only real playback-ready
        // should do that (handlePlaybackReady). A dead first pick must not
        // freeze discovery while other sources could still return.
        setHasStartedPlaybackAttempt(true)
    }, [resolvedSourceId])

    useEffect(() => {
        if (playbackOptions.length === 0 || resolvedSourceId) return
        if (forcePickWatchdogRef.current || manualSelectionRef.current) return

        const timer = window.setTimeout(() => {
            if (forcePickWatchdogRef.current || manualSelectionRef.current || resolvedSourceId) {
                return
            }

            const candidate = pickForceStartPlaybackSource(playbackOptions, {
                sourceHealth,
                firstReadySourceId,
                providerOrder: orderedEnabledProviderIds,
            })
            if (!candidate) return
            forcePickWatchdogRef.current = true
            if (shouldMarkAutoPickSourceReady(candidate, sourceHealth)) {
                markSourceReady(candidate.id)
            }
            lockStartupSource()
            syncSelectedSourceId(candidate.id)
            setSelectedSourceId(candidate.id)
        }, PRIMARY_PROVIDER_HEAD_START_MS)

        return () => window.clearTimeout(timer)
    }, [
        manualSelectionRef,
        markSourceReady,
        sourceHealth,
        mediaId,
        mediaType,
        playbackOptions.length,
        resolvedSourceId,
        season,
        episode,
        syncSelectedSourceId,
    ])

    useEffect(() => {
        if (manualSelectionRef.current || (isScanningProviders && !hasPlayableBatch)) return
        if (playbackStableRef.current) return
        if (sourceUpgradeAttemptedRef.current) return
        if (livePlaybackTimeRef.current >= 1.5) return
        if (!selectedSourceId) return

        const preferred = pickAutoStartPlaybackSource(playbackOptions, {
            skipHealthProbe,
            sourceHealth,
            providerOrder: orderedEnabledProviderIds,
            pickGate: playbackPickGate,
        })
        if (!preferred || preferred.id === selectedSourceId) return

        const current = playbackOptions.find((option) => option.id === selectedSourceId)
        const upgradeToPrimary = shouldUpgradeToHigherRankedSource({
            current,
            preferred,
            providerOrder: orderedEnabledProviderIds,
            sourceHealth,
            manualSelection: manualSelectionRef.current,
        })

        if (!upgradeToPrimary) return

        sourceUpgradeAttemptedRef.current = true

        if (preferred.providerId) {
            clearFailedProvider(preferred.providerId)
        }

        setRuntimeError(undefined)
        syncSelectedSourceId(preferred.id)
        setSelectedSourceId(preferred.id)
        if (livePlaybackTimeRef.current >= 0.5) {
            setUpgradeResumeTime(livePlaybackTimeRef.current)
        }
        bumpSourceSwitch()
    }, [
        clearFailedProvider,
        hasPlayableBatch,
        manualSelectionRef,
        orderedEnabledProviderIds,
        playbackOptions,
        playbackPickGate,
        selectedSourceId,
        skipHealthProbe,
        sourceHealth,
        syncSelectedSourceId,
        isScanningProviders,
        bumpSourceSwitch,
    ])

    const selectedPlayback = playbackOptions.find((option) => option.id === resolvedSourceId)

    const playerSource = useMemo(
        () =>
            selectedPlayback
                ? {
                      id: selectedPlayback.id,
                      url: selectedPlayback.src,
                      type: selectedPlayback.sourceType,
                      cinemacityPageUrl: selectedPlayback.cinemacityPageUrl,
                      directClientPlayback: selectedPlayback.directClientPlayback,
                      clientPlaybackHeaders: selectedPlayback.clientPlaybackHeaders,
                  }
                : null,
        [
            selectedPlayback?.id,
            selectedPlayback?.sourceType,
            selectedPlayback?.src,
            selectedPlayback?.cinemacityPageUrl,
            selectedPlayback?.directClientPlayback,
            selectedPlayback?.clientPlaybackHeaders,
        ]
    )

    const { externalSubtitles, loadingExternalSubtitles } = useExternalSubtitles({
        enabled: Boolean(playerSource),
        type: mediaType,
        tmdbId: mediaId,
        season,
        episode,
    })

    const mergedSubtitles = useMemo(() => {
        const seen = new Set<string>()
        const merged: Array<{
            id: string
            label: string
            src: string
            language?: string
            kind?: string
        }> = []

        for (const subtitle of subtitles) {
            if (seen.has(subtitle.id)) continue
            seen.add(subtitle.id)
            merged.push({
                id: subtitle.id,
                label: subtitle.label,
                src: subtitle.src,
                language: subtitle.language,
                kind: subtitle.kind,
            })
        }

        for (const subtitle of externalSubtitles) {
            if (seen.has(subtitle.id)) continue
            seen.add(subtitle.id)
            merged.push({
                id: subtitle.id,
                label: subtitle.label,
                src: subtitle.src,
                language: subtitle.language,
                kind: subtitle.kind,
            })
        }

        return merged
    }, [externalSubtitles, subtitles])

    const isAwaitingInitialPick =
        playbackOptions.length > 0 &&
        !resolvedSourceId &&
        !manualSelectionRef.current &&
        ((isScanningProviders && !hasPlayableBatch) ||
            (!hasPlayableBatch &&
                (isProbing ||
                    (!hasReadySource && (sourcesLoadingMore || hasPendingProbe)))))

    const showSourceLoading =
        !playerSource &&
        (isScanningProviders ||
            isAwaitingInitialPick ||
            isLoading ||
            sourcesLoadingMore ||
            (playbackOptions.length === 0 &&
                (isLoading || sourcesLoadingMore || isScanningProviders)))

    useEffect(() => {
        if (!showSourceLoading) return

        const timer = window.setTimeout(() => {
            if (sourceLoadingWatchdogRef.current) return
            sourceLoadingWatchdogRef.current = true
            void refetchSources()
            const bootstrapProviders = orderedEnabledProviderIds.filter(
                (providerId) => normalizeProviderName(providerId) !== "vidlink"
            )
            for (let index = 0; index < Math.min(2, bootstrapProviders.length); index += 1) {
                const providerId = bootstrapProviders[index]
                if (providerId) {
                    void requestProvider(providerId)
                }
            }
        }, PRIMARY_PROVIDER_HEAD_START_MS)

        return () => window.clearTimeout(timer)
    }, [
        diagnostics,
        mediaId,
        mediaType,
        season,
        episode,
        providerOrder,
        refetchSources,
        requestProvider,
        showSourceLoading,
        sources.length,
    ])

    const handleSelectSource = (id: string) => {
        setRuntimeError(undefined)
        playbackStableRef.current = false
        setPlaybackStable(false)

        const option = playbackOptions.find((item) => item.id === id)
        if (option) {
            markManualSelection(id, option.providerId)
        }

        markSourceReady(id)
        setSourceSwitchNonce((nonce) => nonce + 1)
        setSelectedSourceId(id)
        void ensureProbed(id, true)
    }

    const handleRequestProvider = async (providerId: string) => {
        const providerKey = normalizeProviderName(providerId)
        const isExplicitFourK = providerKey === "4khdhub"

        markPendingProviderSelection(providerKey)
        playbackStableRef.current = false
        setPlaybackStable(false)
        setRuntimeError(undefined)

        const currentOption = playbackOptions.find((item) => item.id === selectedSourceId)
        if (normalizeProviderName(currentOption?.providerId ?? "") !== providerKey) {
            setSelectedSourceId("")
        }

        const pickForRequest = (options: PlaybackOption[]) =>
            isExplicitFourK
                ? pickHighestFourKPlaybackOption(options)
                : options.find((option) => option.providerId === providerKey)

        const existing = pickForRequest(playbackOptions)
        if (existing && canImmediatelyStartProviderOption(existing, sourceHealth)) {
            handleSelectSource(existing.id)
            return
        }

        try {
            if (isExplicitFourK) {
                setRuntimeError(t("player.fourKStarting"))
            }

            const payload = await requestProvider(providerId)
            const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                providerOrder: orderedEnabledProviderIds,
                showRealProviderNames,
            })
            const matching = pickForRequest(refreshed)

            if (matching) {
                handleSelectSource(matching.id)
                return
            }

            const diagnosticMessage =
                payload.diagnostics.find((item) =>
                    ["VIDLINK_UNAVAILABLE", "VIDLINK_DISABLED", "PROVIDER_ERROR", "CLIENT_PROVIDER_UNAVAILABLE"].includes(
                        item.code ?? ""
                    )
                )?.message

            setRuntimeError(
                diagnosticMessage ??
                    `No stream from ${resolveProviderUserLabel(
                        providerId,
                        getProviderDisplayName(providerId),
                        providerOrder,
                        showRealProviderNames
                    )} for this title.`
            )
        } catch {
            setRuntimeError(
                "Could not load sources. Start CinePro Core on port 3001, then tap the provider again."
            )
        }
    }

    const sourceStatusMessage = useMemo(() => {
        if (runtimeError) {
            return sanitizePublicPlaybackStatusWithT(t, runtimeError, showRealProviderNames)
        }
        if (sourcesLoadingMore) return undefined

        const hasPlayable = Boolean(playerSource) || hasReadySource
        const infra =
            diagnostics.find((item) => item.code === "CINEPRO_OFFLINE")?.message ??
            (hasPlayable
                ? undefined
                : diagnostics.find((item) => item.code === "CINEPRO_UNAVAILABLE")
                      ?.message) ??
            diagnostics.find((item) => item.code === "CINEPRO_NOT_CONFIGURED")?.message ??
            diagnostics.find((item) => item.code === "CINEPRO_SELF_REFERENCE")?.message

        return infra
            ? sanitizePublicPlaybackStatusWithT(t, infra, showRealProviderNames)
            : undefined
    }, [
        diagnostics,
        hasReadySource,
        playerSource,
        runtimeError,
        showRealProviderNames,
        sourcesLoadingMore,
        t,
    ])

    const warningMessage = useMemo(
        () => {
            if (showSourceLoading || embedMode) return undefined

            return pickUserFacingPlaybackWarning({
                runtimeError,
                fetchError: error,
                diagnostics,
                hasPlayableSource: Boolean(playerSource) || hasReadySource,
                sourcesLoading: isLoading,
                sourcesLoadingMore: sourcesLoadingMore || isScanningProviders,
                providerOrder: orderedEnabledProviderIds,
                showRealProviderNames,
            })
        },
        [
            diagnostics,
            embedMode,
            error,
            hasReadySource,
            isLoading,
            isScanningProviders,
            playerSource,
            providerOrder,
            runtimeError,
            showRealProviderNames,
            showSourceLoading,
            sourcesLoadingMore,
        ]
    )

    const handlePlaybackReady = (meta: PlaybackErrorMeta) => {
        if (hasStablePlayback(meta)) {
            playbackStableRef.current = true
            setPlaybackStable(true)
            setPlaybackActive(true)
            setRuntimeError(undefined)
            quietStallAttemptRef.current = 0
            if (resolvedSourceId) {
                markSourceReady(resolvedSourceId)
            }
        }
    }


const quietStallAttemptRef = useRef(0)

    const quietBackgroundStallCheck = useCallback(async () => {
        const resumeAt = playbackPositionForRecovery(livePlaybackTimeRef.current)
        if (resumeAt === undefined) return false

        setUpgradeResumeTime(resumeAt)
        livePlaybackTimeRef.current = resumeAt

        const preferProviderId =
            selectedProviderKeyRef.current ||
            playbackOptions.find((option) => option.id === selectedSourceIdRef.current)
                ?.providerId
        const currentId = selectedSourceIdRef.current
        const attempt = quietStallAttemptRef.current
        if (attempt >= 3) return false // stop endless source walking

        try {
            const payload = await refetchSourcesAsync()
            const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                providerOrder: orderedEnabledProviderIds,
                showRealProviderNames,
            })

            let nextId: string | undefined

            if (attempt === 0) {
                const picked = pickSourceAfterRefetch(refreshed, {
                    preferProviderId,
                    sameProviderOnly: false,
                })
                nextId = picked?.nextSourceId
            } else {
                if (preferProviderId) {
                    markSourceFailed(currentId)
                }
                const picked = pickSourceAfterRefetch(refreshed, {
                    preferProviderId,
                    sameProviderOnly: false,
                })
                nextId = picked?.nextSourceId
                if (nextId === currentId) {
                    const alternate = refreshed.find((option) => option.id !== currentId)
                    nextId = alternate?.id ?? nextId
                }
            }

            if (!nextId) return false

            quietStallAttemptRef.current = attempt + 1
            setRuntimeError(undefined)
            setUpgradeResumeTime(resumeAt)
            if (!bumpSourceSwitch()) return false
            syncSelectedSourceId(nextId)
            setSelectedSourceId(nextId)
            return true
        } catch {
            return false
        }
    }, [
        bumpSourceSwitch,
        markSourceFailed,
        orderedEnabledProviderIds,
        pickSourceAfterRefetch,
        playbackOptions,
        refetchSourcesAsync,
        showRealProviderNames,
        syncSelectedSourceId,
    ])

    const refetchAndResumeSource = useCallback(
        async (sameProviderOnly: boolean) => {
            const resumeAt = playbackPositionForRecovery(livePlaybackTimeRef.current)
            if (resumeAt !== undefined) {
                setUpgradeResumeTime(resumeAt)
                livePlaybackTimeRef.current = resumeAt
            }
            if (!bumpSourceSwitch()) {
                return false
            }

            const preferProviderId =
                selectedProviderKeyRef.current ||
                playbackOptions.find((option) => option.id === selectedSourceIdRef.current)
                    ?.providerId

            try {
                const payload = await refetchSourcesAsync()
                const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                    providerOrder: orderedEnabledProviderIds,
                    showRealProviderNames,
                })
                const picked = pickSourceAfterRefetch(refreshed, {
                    preferProviderId,
                    sameProviderOnly,
                })

                if (!picked) {
                    setRuntimeError(
                        "Could not reconnect this stream. Open player settings and pick another source."
                    )
                    return false
                }

                setRuntimeError(undefined)
                setUpgradeResumeTime(
                    playbackPositionForRecovery(livePlaybackTimeRef.current) ??
                        resumeAt
                )
                syncSelectedSourceId(picked.nextSourceId)
                setSelectedSourceId(picked.nextSourceId)
                return true
            } catch {
                setRuntimeError(
                    "Could not refresh stream links. Open player settings and pick another source."
                )
                return false
            }
        },
        [
            bumpSourceSwitch,
            orderedEnabledProviderIds,
            pickSourceAfterRefetch,
            playbackOptions,
            refetchSourcesAsync,
            showRealProviderNames,
            syncSelectedSourceId,
        ]
    )

    const resolveMoreProviders = async () => {
        if (providerResolveAttemptedRef.current) return
        providerResolveAttemptedRef.current = true
        setRuntimeError(t("player.status.findingStream"))

        try {
            const payload = await refetchSourcesAsync()
            const refreshed = mapSourcesToPlaybackOptions(payload.sources, {
                providerOrder: orderedEnabledProviderIds,
                showRealProviderNames,
            })
            const preferred = pickSourceAfterRefetch(refreshed)

            if (preferred) {
                setRuntimeError(preferred.statusMessage)
                setSelectedSourceId(preferred.nextSourceId)
                return
            }

            const recovery = attemptRecoveryFromOptions(refreshed)
            if (recovery) {
                setRuntimeError(recovery.statusMessage)
                setSelectedSourceId(recovery.nextSourceId)
                return
            }

            setRuntimeError(
                sourceStatusMessage ??
                    diagnostics.find((item) => item.code === "CINEPRO_OFFLINE")?.message ??
                    "No other providers returned a stream. Start CinePro Core on port 3001, then tap Retry below."
            )
        } catch {
            setRuntimeError(
                "Could not reach CinePro Core. Start it on port 3001, then tap Retry below."
            )
        }
    }

    const handlePlaybackError = (message: string, meta?: PlaybackErrorMeta) => {
        const metaResolved = meta ?? { currentTime: 0, bufferedSeconds: 0 }
        const isStable =
            playbackStableRef.current || hasStablePlayback(metaResolved)

        if (isStable) {
            if (isRecoverableStreamError(message)) {
                void refetchAndResumeSource(true)
                return
            }

            setRuntimeError(
                `${message}. Open player settings and pick another source if playback does not resume.`
            )
            return
        }

        if (resolvedSourceId) {
            markSourceFailed(resolvedSourceId)
        }

        if (isProxyTokenPlaybackError(message) && !sourcesLoadingMore) {
            void refetchAndResumeSource(false)
            return
        }

        const awaitingMoreSources =
            (isLoading || sourcesLoadingMore) && isVidLinkOnlySources(sources)
        const result = resolvePlaybackError(message, metaResolved, awaitingMoreSources)

        if (result.action === "fallback") {
            if (!bumpSourceSwitch()) {
                setRuntimeError(
                    "Buffering… reconnecting"
                )
                return
            }

            const resumeAt = playbackPositionForRecovery(livePlaybackTimeRef.current)
            if (resumeAt !== undefined) {
                setUpgradeResumeTime(resumeAt)
            }
            setRuntimeError(result.statusMessage)
            syncSelectedSourceId(result.nextSourceId)
            setSelectedSourceId(result.nextSourceId)
            return
        }

        if (result.action === "pending") {
            void resolveMoreProviders()
            return
        }

        if (
            isVidLinkOnlySources(sources) &&
            !isLoading &&
            !sourcesLoadingMore &&
            !manualSelectionRef.current
        ) {
            void resolveMoreProviders()
            return
        }

        setRuntimeError(result.errorMessage)
    }

    useEffect(() => {
        if (!isCurrentProviderFailed() || !isVidLinkOnlySources(sources)) return
        if (isLoading || sourcesLoadingMore || providerResolveAttemptedRef.current) return

        void resolveMoreProviders()
    }, [isCurrentProviderFailed, isLoading, sources, sourcesLoadingMore])

    const hostedOnParent = Boolean(embedOptions?.hostedOnParent)

    const handleEmbedControlsVisible = useCallback((visible: boolean) => {
        postEmbedMessage(EMBED_MESSAGE_CONTROLS_VISIBLE, { visible })
    }, [])

    const builtinPlayer = showSourceLoading ? (
        <SourceLoadingOverlay
            active={showSourceLoading}
            sources={sources}
            diagnostics={diagnostics}
            cineproOnly={isVidLinkOnlySources(sources)}
            activeTestingProviderId={activeTestingProviderId}
            sourceHealth={sourceHealth}
            playbackOptions={playbackOptions}
            sourcesLoadingMore={sourcesLoadingMore}
            sourcesFetching={isLoading || (isScanningProviders && sources.length === 0)}
            scanFailedProviderIds={scanFailedProviderIds}
            playbackStarted={Boolean(playerSource)}
            showRealProviderNames={showRealProviderNames}
            onRefetchSources={refetchSources}
            sourceStatusMessage={sourceStatusMessage}
        />
    ) : playerSource ? (
        <Suspense
            fallback={
                <div className="flex h-full min-h-[240px] items-center justify-center bg-black text-sm text-white/60">
                    Loading player…
                </div>
            }
        >
            <MediaPlayer
            key={`${playerSource.id}:s${season ?? ""}:e${episode ?? ""}:n${sourceSwitchNonce}`}
            source={playerSource}
            sources={playbackOptions.map((option) => ({
                id: option.id,
                label: option.label,
                provider: option.provider,
                providerId: option.providerId,
                quality: option.quality,
            }))}
            subtitles={mergedSubtitles}
            externalSubtitlesLoading={loadingExternalSubtitles}
            audioTracks={selectedPlayback?.audioTracks ?? []}
            onSelectSource={handleSelectSource}
            onRequestProvider={handleRequestProvider}
            onPlaybackError={handlePlaybackError}
            onPlaybackReady={handlePlaybackReady}
            onPlaybackTime={handlePlaybackTime}
            sourcesLoadingMore={sourcesLoadingMore}
            sourceStatusMessage={sourceStatusMessage}
            onRefetchSources={refetchSources}
            onBufferStall={() => {
                void quietBackgroundStallCheck()
            }}
            unavailableProviders={unavailableProviders}
            sourceHealth={sourceHealth}
            activeTestingProviderId={activeTestingProviderId}
            playbackToken={playbackToken}
            watchMeta={stableWatchMeta}
            resumeTime={
                isWatchPartyGuest && guestResumeTime
                    ? guestResumeTime
                    : awaitingPartyRoom
                      ? 0
                      : resumeTime
            }
            onPlaybackSync={isWatchPartyHost ? handlePlaybackSync : undefined}
            syncPlayback={isWatchPartyGuest ? syncPlayback : null}
            watchPartyGuest={guestPlayback}
            onPlaybackEnded={inWatchParty ? undefined : handlePlaybackEnded}
            tvNavigation={
                mediaType === "tv" && onTvEpisodeChange && !inWatchParty
                    ? {
                          showId: mediaId,
                          showTitle: mediaTitle,
                          currentSeason: numericSeason ?? 1,
                          currentEpisode: numericEpisode ?? 1,
                          onEpisodeSelect: onTvEpisodeChange,
                      }
                    : undefined
            }
            autoNextEnabled={autoNext}
            onAutoNextChange={handleAutoNextChange}
            showAutoNext={mediaType === "tv" && !inWatchParty}
            embedReportingEnabled={embedMode}
            embedMediaType={mediaType}
            embedSeason={numericSeason}
            embedEpisode={numericEpisode}
            forceAutoplay={Boolean(embedOptions?.autoplay)}
            showRealProviderNames={showRealProviderNames}
            parentHostedEmbed={embedMode && hostedOnParent}
            fullscreenRootRef={
                embedMode && !hostedOnParent ? embedShellRef : undefined
            }
            onControlsOverlayVisibleChange={
                embedMode && hostedOnParent ? handleEmbedControlsVisible : undefined
            }
            hiddenProviderIds={hiddenProviderIds}
        />
        </Suspense>
    ) : (
        <div className="flex h-full flex-col items-center justify-center gap-3 rounded-md border border-white/10 bg-black/70 px-6 py-8 text-center text-sm text-white/70">
            {embedMode ? (
                <p>{getTitleUnavailableMessage(t)}</p>
            ) : (
                <>
                    <p>{getTitleUnavailableMessage(t)}</p>
                    <PlaybackSourceRetryPanel
                        message={warningMessage ?? sourceStatusMessage}
                        onRetry={refetchSources}
                        messageClassName="text-white/50"
                    />
                </>
            )}
        </div>
    )

    const playerLayout = (
        <div className={cn("space-y-3", embedMode && "flex h-full min-h-0 flex-col space-y-0")}>
            {effectiveShowToolbar ? (
                <div className="space-y-3 px-3 pt-3 md:px-4">
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm font-semibold text-foreground md:text-base">
                            {mediaTitle}
                        </p>
                        <div className="flex items-center gap-2">
                            <WatchPartyDropdown
                            open={watchPartyOpen}
                            onOpenChange={setWatchPartyOpen}
                            mediaType={mediaType}
                            mediaId={mediaId}
                            mediaTitle={mediaTitle}
                            season={numericSeason}
                            episode={numericEpisode}
                            room={watchPartyRoom}
                            onRoomChange={setWatchPartyRoom}
                            onJoinHostMedia={handleJoinHostMedia}
                            triggerClassName="border-border/60 bg-background/80 text-foreground hover:bg-muted"
                        />
                        </div>
                    </div>

                    {!isMobile && enabledPlayers.length > 0 ? (
                        <PlayerEngineDropdown
                            activePlayerId={effectiveActivePlayerId}
                            enabledPlayers={enabledPlayers}
                            onSelectPlayer={setActivePlayerId}
                            disabled={inWatchParty}
                            disabledReason={t("watchParty.requiresPlayer")}
                        />
                    ) : null}
                </div>
            ) : embedShowToolbar ? (
                <div className="relative flex items-center justify-between gap-3 border-b border-white/10 px-3 py-2">
                    {embedOptions?.showTitle !== false ? (
                        <p
                            className="pointer-events-none absolute left-1/2 max-w-[min(70%,20rem)] -translate-x-1/2 truncate text-center text-sm font-semibold text-white"
                            title={mediaTitle}
                        >
                            {mediaTitle}
                        </p>
                    ) : null}
                    <span className="min-w-[4.5rem] shrink-0" aria-hidden="true" />
                    {embedOptions?.showWatchParty !== false && !embedOptions?.hostedOnParent ? (
                        <WatchPartyDropdown
                            open={watchPartyOpen}
                            onOpenChange={setWatchPartyOpen}
                            mediaType={mediaType}
                            mediaId={mediaId}
                            mediaTitle={mediaTitle}
                            season={numericSeason}
                            episode={numericEpisode}
                            room={watchPartyRoom}
                            onRoomChange={setWatchPartyRoom}
                            onJoinHostMedia={handleJoinHostMedia}
                            triggerClassName="border-white/10 bg-white/[0.05] text-white hover:bg-white/10"
                        />
                    ) : null}
                </div>
            ) : null}

            <ExternalPlayerPanel
                mediaType={mediaType}
                mediaId={mediaId}
                season={season}
                episode={episode}
                activePlayerId={embedMode ? null : effectiveActivePlayerId}
                onSelectPlayer={embedMode ? () => undefined : setActivePlayerId}
                builtinPlayer={
                    awaitingPartyRoom ? (
                        <div className="flex h-full min-h-[240px] items-center justify-center rounded-md border border-white/10 bg-black/70 px-6 text-sm text-white/70">
                            {t("watchParty.joining")}
                        </div>
                    ) : (
                        builtinPlayer
                    )
                }
                hidePlayerPicker={effectiveShowToolbar || embedMode}
                playerLayout={embedMode ? "fill" : "aspect"}
            />
        </div>
    )

    if (embedMode && !hostedOnParent) {
        return (
            <FullscreenPortalContext.Provider value={embedFullscreenPortal}>
                <div ref={setEmbedShellRef} className="flex h-full min-h-0 flex-col">
                    {playerLayout}
                </div>
            </FullscreenPortalContext.Provider>
        )
    }

    return playerLayout
}
