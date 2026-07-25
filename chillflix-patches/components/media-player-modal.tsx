"use client"

import {
  Suspense,
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from "react"
import Image from "next/image"
import { wsrvImage } from "@/tmdb/utils"
import { useMediaQuery } from "@/hooks/use-media-query"
import { Calendar, ChevronLeft, ChevronRight, Clock } from "lucide-react"

import {
  pickBestFourKPlaybackOption,
  pickExplicitUserProviderPlaybackOption,
  pickHighestFourKPlaybackOption,
} from "@/lib/4khdhub/codec"
import { getClientUser } from "@/lib/auth-client"
import {
  readSessionAutoNext,
  writeSessionAutoNext,
} from "@/lib/auto-next-preference"
import { isLikelyTvBrowser } from "@/lib/device-profile"
import { useTranslations } from "@/lib/i18n/client"
import {
  createPlaybackRecoveryLimiter,
  isProxyTokenPlaybackError,
  isRecoverableStreamError,
  playbackPositionForRecovery,
} from "@/lib/playback-error-recovery"
import {
  notePlaybackUserGesture,
  unlockVideoElementForAutoplay,
} from "@/lib/playback-gesture"
import {
  getTitleUnavailableMessage,
  pickUserFacingPlaybackWarning,
  shouldShowPlaybackRetryButton,
} from "@/lib/playback-user-messages"
import { unlockScreenOrientation } from "@/lib/player-orientation"
import { resolveProviderUserLabel } from "@/lib/provider-display"
import { PRIMARY_PROVIDER_HEAD_START_MS } from "@/lib/source-probe-constants"
import { resolveNextTvEpisode } from "@/lib/tv-auto-next"
import { cn } from "@/lib/utils"
import { watchPartyPlayerUrl } from "@/lib/watch-party-session"
import type { WatchPartyRoom } from "@/lib/watch-party-types"
import {
  MIN_WATCH_SECONDS,
  flushWatchProgressCheckpoint,
  resolveResumeTime,
  type WatchProgressMeta,
} from "@/lib/watch-progress"
import { useAuth } from "@/hooks/use-auth"
import { usePlaybackAmbientPause } from "@/hooks/use-playback-ambient-pause"
import { useShowRealProviderNames } from "@/hooks/use-show-real-provider-names"
import { useWatchPartyPlayback } from "@/hooks/use-watch-party-playback"
import { useExternalSubtitles } from "@/hooks/useExternalSubtitles"
import {
  buildMediaSourcesCacheKey,
  prefetchMediaSources,
  useMediaSources,
} from "@/hooks/useMediaSources"
import {
  usePlaybackSourceFallback,
  type PlaybackErrorMeta,
} from "@/hooks/usePlaybackSourceFallback"
import { usePreferredExternalPlayer } from "@/hooks/usePreferredExternalPlayer"
import { useSourceHealth } from "@/hooks/useSourceHealth"
import { useStreamSourcesConfig } from "@/hooks/useStreamSourcesConfig"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Separator } from "@/components/ui/separator"
import { ExternalPlayerPanel } from "@/components/external-player-panel"
import { Icons } from "@/components/icons"
import { PlayerEngineDropdown } from "@/components/player-engine-dropdown"
import { MediaPlayer } from "@/components/player/MediaPlayer"
import { MobilePlayerLandscapeShell } from "@/components/player/MobilePlayerLandscapeShell"
import { PlaybackSourceRetryPanel } from "@/components/player/PlaybackSourceRetryPanel"
import { PlayerSettings } from "@/components/player/PlayerSettings"
import { SourceLoadingOverlay } from "@/components/player/SourceLoadingOverlay"
import { FullscreenPortalContext } from "@/components/player/fullscreen-portal-context"
import {
  buildPlaybackPickGate,
  canImmediatelyStartProviderOption,
  filterPlaybackOptionsByScanGate,
  getOrderedEnabledProviderIds,
  getProviderDisplayName,
  hasPendingSourceHealthProbe,
  isBackgroundSourcesPending,
  isFourKHlsPlaybackUrl,
  isProviderRequiringHealthProbe,
  isTrustedPlaybackSourceUrl,
  isVidLinkOnlySources,
  mapSourcesToPlaybackOptions,
  normalizeProviderName,
  parseStableSourceId,
  parseUnavailableProviders,
  pickAutoStartPlaybackSource,
  pickAutomaticProbeSources,
  pickForceStartPlaybackSource,
  pickPreferredPlaybackSource,
  shouldMarkAutoPickSourceReady,
  shouldPreferSourceUpgrade,
  shouldSkipPlaybackHealthProbe,
  shouldUpgradeToHigherRankedSource,
} from "@/components/player/utils/playback"
import { useSiteFeatures } from "@/components/site-features"
import { useWatchPartyState } from "@/components/watch-party-context"
import { WatchPartyDropdown } from "@/components/watch-party-dropdown"

interface Episode {
  id: number
  episode_number: number
  name: string
  overview?: string
  still_path?: string
  air_date?: string
  runtime?: number
  vote_average?: number
}

interface Season {
  id: number
  season_number: number
  name: string
  episode_count: number
  poster_path?: string
  overview?: string
}

type PlaybackOption = ReturnType<typeof mapSourcesToPlaybackOptions>[number]

interface MediaPlayerModalProps {
  isOpen: boolean
  onClose: () => void
  mediaId: string | number
  mediaType: "movie" | "tv"
  title: string
  description?: string
  currentSeason?: number
  currentEpisode?: number
  episodes?: Episode[]
  onEpisodeChange?: (season: number, episode: number) => void
  posterPath?: string
  initialResumeTime?: number
  /** Open from /4k browse — start 4K provider first. */
  prefer4k?: boolean
  runtimeMinutes?: number
  presentation?: "modal" | "inline"
}

export const MediaPlayerModal: React.FC<MediaPlayerModalProps> = ({
  isOpen,
  onClose,
  mediaId,
  mediaType,
  title,
  description,
  currentSeason = 1,
  currentEpisode = 1,
  episodes: initialEpisodes = [],
  onEpisodeChange,
  posterPath,
  initialResumeTime,
  prefer4k = false,
  runtimeMinutes: runtimeMinutesProp,
  presentation = "modal",
}) => {
  usePlaybackAmbientPause(isOpen)
  const { t } = useTranslations()
  const isTvDevice = useMemo(() => isLikelyTvBrowser(), [])
  const isMobile = useMediaQuery("(max-width: 768px)")
  const isTouchDevice = useMediaQuery("(hover: none) and (pointer: coarse)")
  const cinemaFullscreenRef = useRef<HTMLDivElement>(null)
  const [fullscreenPortal, setFullscreenPortal] = useState<HTMLElement | null>(
    null
  )
  const [cinemaFullscreen, setCinemaFullscreen] = useState(false)
  const [cinemaControlsVisible, setCinemaControlsVisible] = useState(true)
  const controlsWakeRef = useRef<(() => void) | null>(null)
  const wakeCinemaChrome = useCallback(() => {
    controlsWakeRef.current?.()
  }, [])
  const { user, updateProfile } = useAuth()
  const [season, setSeason] = useState(currentSeason)
  const [episode, setEpisode] = useState(currentEpisode)
  const [episodes, setEpisodes] = useState<Episode[]>(initialEpisodes)
  const [seasons, setSeasons] = useState<Season[]>([])
  const [mediaOverview, setMediaOverview] = useState<string | undefined>(
    description
  )
  const [selectedSourceId, setSelectedSourceId] = useState("")
  const [autoNext, setAutoNext] = useState(() => {
    const clientUser = getClientUser()
    if (clientUser) return clientUser.autoNextEnabled
    return readSessionAutoNext()
  })
  const [loading, setLoading] = useState(false)
  const [has4kCatalog, setHas4kCatalog] = useState(false)
  const prefer4kAttemptedRef = useRef(false)
  const unlockVideoRef = useRef<HTMLVideoElement>(null)

  useEffect(() => {
    if (!isOpen) {
      prefer4kAttemptedRef.current = false
      setHas4kCatalog(false)
      return
    }

    let cancelled = false

    void fetch(
      `/api/4k/check?tmdbId=${encodeURIComponent(
        String(mediaId)
      )}&type=${encodeURIComponent(mediaType)}`,
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
    }
  }, [isOpen, mediaId, mediaType])

  const hiddenProviderIds = useMemo(
    () => (has4kCatalog ? [] : ["4khdhub"]),
    [has4kCatalog]
  )

  useEffect(() => {
    if (!isOpen) {
      setCinemaFullscreen(false)
      setFullscreenPortal(null)
      unlockScreenOrientation()

      const unlockVideo = unlockVideoRef.current
      if (unlockVideo) {
        unlockVideo.pause()
        unlockVideo.removeAttribute("src")
        unlockVideo.load()
      }
      return
    }

    notePlaybackUserGesture()
  }, [isOpen])

  useLayoutEffect(() => {
    if (!isOpen) return
    const unlockVideo = unlockVideoRef.current
    if (!unlockVideo) return
    void unlockVideoElementForAutoplay(unlockVideo)
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return
    if (user) {
      setAutoNext(user.autoNextEnabled)
    } else {
      setAutoNext(readSessionAutoNext())
    }
  }, [isOpen, user?.id, user?.autoNextEnabled])

  const [seasonsLoading, setSeasonsLoading] = useState(false)
  const [runtimeError, setRuntimeError] = useState<string>()
  const providerResolveAttemptedRef = useRef(false)
  const playbackStableRef = useRef(false)
  const [playbackStable, setPlaybackStable] = useState(false)
  const livePlaybackTimeRef = useRef(0)
  const openingPlaybackRef = useRef<{
    sessionKey: string
    episode: { season: number; episode: number } | null
    startAt: number | undefined
  }>({ sessionKey: "", episode: null, startAt: undefined })
  const [upgradeResumeTime, setUpgradeResumeTime] = useState<
    number | undefined
  >()
  const sourceLoadingWatchdogRef = useRef(false)
  const forcePickWatchdogRef = useRef(false)
  const startupSourceLockedRef = useRef(false)
  const sourceUpgradeAttemptedRef = useRef(false)
  const playbackRecoveryLimiterRef = useRef(createPlaybackRecoveryLimiter())
  const [hasStartedPlaybackAttempt, setHasStartedPlaybackAttempt] =
    useState(false)
  const [playbackActive, setPlaybackActive] = useState(false)
  const [sourceSwitchNonce, setSourceSwitchNonce] = useState(0)
  const [dialogOpen, setDialogOpen] = useState(isOpen)
  const closingRef = useRef(false)
  useEffect(() => {
    if (isOpen) {
      closingRef.current = false
      setDialogOpen(true)
      return
    }

    setDialogOpen(false)
  }, [isOpen])
  useEffect(() => {
    if (!isOpen) {
      openingPlaybackRef.current = {
        sessionKey: "",
        episode: null,
        startAt: undefined,
      }
      setUpgradeResumeTime(undefined)
      livePlaybackTimeRef.current = 0
      return
    }

    const sessionKey = `${mediaType}:${mediaId}`
    if (openingPlaybackRef.current.sessionKey === sessionKey) return

    openingPlaybackRef.current = {
      sessionKey,
      episode:
        mediaType === "tv"
          ? { season: currentSeason, episode: currentEpisode }
          : null,
      startAt:
        typeof initialResumeTime === "number" && initialResumeTime > 0
          ? initialResumeTime
          : undefined,
    }
  }, [
    isOpen,
    mediaId,
    mediaType,
    currentSeason,
    currentEpisode,
    initialResumeTime,
  ])

  useEffect(() => {
    setUpgradeResumeTime(undefined)
    livePlaybackTimeRef.current = 0
  }, [mediaId, mediaType, season, episode])
  const {
    order: providerOrder,
    enabledIds,
    enabledPlayers,
    loading: streamSourcesLoading,
  } = useStreamSourcesConfig()
  const [activePlayerId, setActivePlayerId] = usePreferredExternalPlayer(
    enabledPlayers,
    streamSourcesLoading
  )
  const showRealProviderNames = useShowRealProviderNames()
  const { watchPartyEnabled } = useSiteFeatures()
  const [watchPartyOpen, setWatchPartyOpen] = useState(false)
  const { room: watchPartyRoom, setRoom: setWatchPartyRoom } =
    useWatchPartyState()
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

  const effectiveActivePlayerId = inWatchParty ? null : activePlayerId

  const handleJoinHostMedia = useCallback(
    (room: WatchPartyRoom) => {
      onClose()
      window.location.href = watchPartyPlayerUrl(room)
    },
    [onClose]
  )

  const {
    sources,
    subtitles,
    diagnostics,
    playbackToken,
    isLoading: sourceLoading,
    isLoadingMore: sourcesLoadingMore,
    error: sourceError,
    refetchSources,
    refetchSourcesAsync,
    requestProvider,
    scanningProviderId,
    scanFailedProviderIds,
    isScanningProviders,
    providerScanStartedAt,
    resolvedParamsKey,
  } = useMediaSources({
    id: mediaId,
    type: mediaType,
    season,
    episode,
    enabled: isOpen,
    playbackActive,
    embedFastStart: true,
  })

  useEffect(() => {
    if (!isOpen || mediaType !== "tv") return

    const nextEpisode = episode + 1
    if (episodes.length > 0 && nextEpisode > episodes.length) return

    void prefetchMediaSources({
      id: mediaId,
      type: "tv",
      season,
      episode: nextEpisode,
    })
  }, [episode, episodes.length, isOpen, mediaId, mediaType, season])

  // Fetch TV show data on open
  useEffect(() => {
    setMediaOverview(description)
  }, [description])

  useEffect(() => {
    if (!isOpen || mediaType !== "tv") {
      return
    }

    const fetchTvData = async () => {
      try {
        setSeasonsLoading(true)
        setLoading(true)

        // Fetch TV details to get seasons from our API route
        const tvResponse = await fetch(`/api/tv/${mediaId}`)
        if (!tvResponse.ok) {
          throw new Error("Failed to fetch TV details")
        }
        const tvDetails = await tvResponse.json()

        setMediaOverview(tvDetails.overview)

        if (tvDetails.seasons && tvDetails.seasons.length > 0) {
          setSeasons(tvDetails.seasons)

          // Immediately fetch episodes for current season from our API route
          try {
            const seasonResponse = await fetch(
              `/api/tv/${mediaId}/season/${season}`
            )
            if (!seasonResponse.ok) {
              throw new Error("Failed to fetch season details")
            }
            const seasonDetails = await seasonResponse.json()

            if (seasonDetails.episodes) {
              setEpisodes(seasonDetails.episodes)
            }
          } catch (episodeError) {
            console.error("Failed to fetch episodes:", episodeError)
          }
        }
      } catch (error) {
        console.error("Failed to fetch TV data:", error)
      } finally {
        setSeasonsLoading(false)
        setLoading(false)
      }
    }

    fetchTvData()
  }, [isOpen, mediaType, mediaId, season])

  const [mediaRuntimeMinutes, setMediaRuntimeMinutes] = useState<
    number | undefined
  >(runtimeMinutesProp)

  useEffect(() => {
    setMediaRuntimeMinutes(runtimeMinutesProp)
  }, [runtimeMinutesProp, mediaId])

  useEffect(() => {
    if (!isOpen || mediaType !== "movie" || mediaRuntimeMinutes) {
      return
    }

    let cancelled = false
    void fetch(`/api/movie/${mediaId}`, { cache: "force-cache" })
      .then((response) => (response.ok ? response.json() : null))
      .then((detail) => {
        if (cancelled) return
        const runtime = Number(detail?.runtime)
        if (Number.isFinite(runtime) && runtime > 0) {
          setMediaRuntimeMinutes(runtime)
        }
      })
      .catch(() => undefined)

    return () => {
      cancelled = true
    }
  }, [isOpen, mediaId, mediaRuntimeMinutes, mediaType])

  useEffect(() => {
    if (!isOpen || mediaType !== "movie" || description) {
      return
    }

    const fetchMovieOverview = async () => {
      try {
        const movieResponse = await fetch(`/api/movie/${mediaId}`)
        if (!movieResponse.ok) {
          throw new Error("Failed to fetch movie details")
        }

        const movieDetails = await movieResponse.json()
        setMediaOverview(movieDetails.overview)
        const runtime = Number(movieDetails.runtime)
        if (Number.isFinite(runtime) && runtime > 0) {
          setMediaRuntimeMinutes((current) => current ?? runtime)
        }
      } catch (error) {
        console.error("Failed to fetch movie overview:", error)
      }
    }

    void fetchMovieOverview()
  }, [description, isOpen, mediaId, mediaType])

  const orderedEnabledProviderIds = useMemo(
    () => getOrderedEnabledProviderIds(providerOrder, enabledIds),
    [enabledIds, providerOrder]
  )

  const cineproOptions: PlaybackOption[] = useMemo(
    () =>
      mapSourcesToPlaybackOptions(sources, {
        providerOrder: orderedEnabledProviderIds,
        showRealProviderNames,
      }),
    [orderedEnabledProviderIds, showRealProviderNames, sources]
  )

  const playbackOptions = cineproOptions

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
  const optionsForPlayback = hasPlayableBatch
    ? playbackOptions
    : gatedPlaybackOptions

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
      isTvDevice ||
      shouldSkipPlaybackHealthProbe({
        diagnostics,
        sources,
        sourcesLoadingMore,
        // Match embed: trust the first resolved batch and start playback immediately.
        embedFastStart: sources.length > 0,
        isScanningProviders,
        scanningProviderId,
      }),
    [
      diagnostics,
      isScanningProviders,
      isTvDevice,
      scanningProviderId,
      sources,
      sourcesLoadingMore,
    ]
  )

  const trustedProbeSourceIds = useMemo(() => {
    const options = hasPlayableBatch ? playbackOptions : gatedPlaybackOptions

    if (skipHealthProbe) {
      return options
        .filter(
          (option) =>
            !isProviderRequiringHealthProbe(option.providerId, option.src)
        )
        .map((option) => option.id)
    }

    return playbackOptions
      .filter(
        (option) =>
          !isProviderRequiringHealthProbe(option.providerId, option.src) &&
          !option.directClientPlayback &&
          isTrustedPlaybackSourceUrl(option.src)
      )
      .map((option) => option.id)
  }, [gatedPlaybackOptions, hasPlayableBatch, playbackOptions, skipHealthProbe])

  const playbackProbeContext = useMemo(
    () => ({
      type: mediaType,
      tmdbId: String(mediaId),
      ...(mediaType === "tv"
        ? {
            season: String(season),
            episode: String(episode),
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
    enabled:
      isOpen &&
      !streamSourcesLoading &&
      playbackOptions.length > 0 &&
      !playbackStable,
    sequential: true,
    stopOnFirstReady: true,
    skipProbe: skipHealthProbe,
    trustedSourceIds: trustedProbeSourceIds,
    playbackContext: playbackProbeContext,
    headStartMs: PRIMARY_PROVIDER_HEAD_START_MS,
    primarySourceIds: primaryProbeSourceIds,
  })

  const activeTestingProviderId = useMemo(() => {
    if (scanningProviderId) return scanningProviderId

    if (!activeProbeId) return undefined
    return playbackOptions.find((option) => option.id === activeProbeId)
      ?.providerId
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
        sourcesLoadingMore,
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
      orderedEnabledProviderIds,
      scanFailedProviderIds,
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
    prefer4kAttemptedRef.current = false
    startupSourceLockedRef.current = false
    sourceUpgradeAttemptedRef.current = false
    playbackRecoveryLimiterRef.current.reset()
    setHasStartedPlaybackAttempt(false)
    setPlaybackActive(false)
    setRuntimeError(undefined)
    setSelectedSourceId("")
    setSourceSwitchNonce(0)
    sourceLoadingWatchdogRef.current = false
    forcePickWatchdogRef.current = false
  }, [mediaId, mediaType, resetFallbackState])

  useEffect(() => {
    if (!isOpen) return
    resetFallbackState()
    providerResolveAttemptedRef.current = false
    playbackStableRef.current = false
    setPlaybackStable(false)
    startupSourceLockedRef.current = false
    sourceUpgradeAttemptedRef.current = false
    playbackRecoveryLimiterRef.current.reset()
    setRuntimeError(undefined)
    setSelectedSourceId("")
    setSourceSwitchNonce((nonce) => nonce + 1)
    sourceLoadingWatchdogRef.current = false
    forcePickWatchdogRef.current = false
  }, [isOpen, season, episode, resetFallbackState])

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
    if (streamSourcesLoading && playbackOptions.length === 0) return
    if (sourceLoading && playbackOptions.length === 0) return
    if (isScanningProviders && !hasPlayableBatch && !manualSelectionRef.current)
      return

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

    const current = playbackOptions.find(
      (option) => option.id === selectedSourceId
    )
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
    hasPlayableBatch,
    isCurrentProviderFailed,
    manualSelectionRef,
    markSourceReady,
    playbackPickGate,
    playbackOptions,
    orderedEnabledProviderIds,
    selectedProviderKeyRef,
    selectedSourceId,
    skipHealthProbe,
    sourceHealth,
    sourceLoading,
    sources,
    sourcesLoadingMore,
    streamSourcesLoading,
    syncSelectedSourceId,
    isScanningProviders,
    prefer4k,
    has4kCatalog,
    requestProvider,
    showRealProviderNames,
  ])

  useEffect(() => {
    if (
      !selectedSourceId ||
      manualSelectionRef.current ||
      playbackStableRef.current
    )
      return
    if (!runtimeError && !isCurrentProviderFailed()) return

    const current = playbackOptions.find(
      (option) => option.id === selectedSourceId
    )
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
  }, [
    attemptRecoveryFallback,
    isCurrentProviderFailed,
    playbackOptions,
    sourcesLoadingMore,
  ])

  const isResolvingMoreProviders =
    runtimeError === "Checking other providers…" ||
    runtimeError === "Resolving CinePro providers…" ||
    (isCurrentProviderFailed() &&
      isVidLinkOnlySources(sources) &&
      (sourceLoading || sourcesLoadingMore))

  const probeSourceIds = useMemo(
    () => probeSources.map((source) => source.id),
    [probeSources]
  )

  const hasPendingProbe = useMemo(
    () => hasPendingSourceHealthProbe(probeSourceIds, sourceHealth),
    [probeSourceIds, sourceHealth]
  )

  const expectedSourcesKey = useMemo(
    () =>
      buildMediaSourcesCacheKey({
        id: mediaId,
        type: mediaType,
        season: mediaType === "tv" ? season : undefined,
        episode: mediaType === "tv" ? episode : undefined,
      }),
    [mediaId, mediaType, season, episode]
  )

  const sourcesReadyForEpisode =
    mediaType === "movie" ||
    (resolvedParamsKey !== "" && resolvedParamsKey === expectedSourcesKey)

  const resolvedSourceId = useMemo(() => {
    if (!sourcesReadyForEpisode) return ""
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
    sourcesReadyForEpisode,
  ])

  useEffect(() => {
    if (!isOpen || !resolvedSourceId || !sourcesReadyForEpisode) return
    setHasStartedPlaybackAttempt(true)
    setPlaybackActive(true)
  }, [isOpen, resolvedSourceId, sourcesReadyForEpisode])

  useEffect(() => {
    if (
      manualSelectionRef.current ||
      (isScanningProviders && !hasPlayableBatch)
    )
      return
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

    const current = playbackOptions.find(
      (option) => option.id === selectedSourceId
    )
    const upgradeToPrimary = shouldUpgradeToHigherRankedSource({
      current,
      preferred,
      providerOrder: orderedEnabledProviderIds,
      sourceHealth,
      manualSelection: manualSelectionRef.current,
      currentPlaybackTime: livePlaybackTimeRef.current,
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

  const selectedPlayback = playbackOptions.find(
    (option) => option.id === resolvedSourceId
  )

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

  const isEpisodeSourceTransition =
    hasStartedPlaybackAttempt && !sourcesReadyForEpisode

  const showSourceLoading =
    isEpisodeSourceTransition ||
    (!hasStartedPlaybackAttempt &&
      (isAwaitingInitialPick ||
        (playbackOptions.length === 0 &&
          (sourceLoading ||
            sourcesLoadingMore ||
            (isScanningProviders && !playbackActive)))))

  useEffect(() => {
    if (!isOpen || !showSourceLoading) return

    const timer = window.setTimeout(() => {
      if (sourceLoadingWatchdogRef.current) return
      sourceLoadingWatchdogRef.current = true
      void refetchSources()
      const bootstrapProviders = orderedEnabledProviderIds.filter(
        (providerId) => normalizeProviderName(providerId) !== "vidlink"
      )
      for (
        let index = 0;
        index < Math.min(2, bootstrapProviders.length);
        index += 1
      ) {
        const providerId = bootstrapProviders[index]
        if (providerId) {
          void requestProvider(providerId)
        }
      }
    }, PRIMARY_PROVIDER_HEAD_START_MS)

    return () => window.clearTimeout(timer)
  }, [
    diagnostics,
    isOpen,
    mediaId,
    mediaType,
    providerOrder,
    refetchSources,
    requestProvider,
    season,
    episode,
    showSourceLoading,
    sources.length,
  ])

  useEffect(() => {
    if (!isOpen || playbackOptions.length === 0 || resolvedSourceId) return
    if (forcePickWatchdogRef.current || manualSelectionRef.current) return

    const timer = window.setTimeout(() => {
      if (
        forcePickWatchdogRef.current ||
        manualSelectionRef.current ||
        resolvedSourceId
      ) {
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
    isOpen,
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

  const sourceStatusMessage = useMemo(() => {
    if (runtimeError) return runtimeError
    if (sourcesLoadingMore) return undefined

    return (
      diagnostics.find((item) => item.code === "NO_ENABLED_PROVIDER_STREAMS")
        ?.message ??
      diagnostics.find((item) => item.code === "CINEPRO_OFFLINE")?.message ??
      diagnostics.find((item) => item.code === "CINEPRO_UNAVAILABLE")
        ?.message ??
      diagnostics.find((item) => item.code === "CINEPRO_NOT_CONFIGURED")
        ?.message ??
      diagnostics.find((item) => item.code === "CINEPRO_SELF_REFERENCE")
        ?.message
    )
  }, [diagnostics, runtimeError, sourcesLoadingMore])

  const warningMessage = useMemo(
    () =>
      pickUserFacingPlaybackWarning({
        runtimeError,
        fetchError: sourceError,
        diagnostics,
        hasPlayableSource: Boolean(playerSource) || hasReadySource,
        sourcesLoading: sourceLoading,
        sourcesLoadingMore,
        providerOrder: orderedEnabledProviderIds,
        showRealProviderNames,
      }),
    [
      diagnostics,
      hasReadySource,
      playerSource,
      providerOrder,
      runtimeError,
      showRealProviderNames,
      sourceError,
      sourceLoading,
      sourcesLoadingMore,
    ]
  )

  const watchMeta = useMemo<WatchProgressMeta>(() => {
    const episodeRuntime =
      mediaType === "tv" && episodes
        ? episodes.find((item) => item.episode_number === episode)?.runtime
        : undefined
    const movieRuntime =
      mediaType === "movie" && mediaRuntimeMinutes && mediaRuntimeMinutes > 0
        ? mediaRuntimeMinutes
        : undefined
    const runtimeMinutes = episodeRuntime ?? movieRuntime

    return {
      id: Number(mediaId),
      type: mediaType,
      title,
      poster: posterPath || "",
      ...(mediaType === "tv" ? { season, episode } : {}),
      ...(runtimeMinutes && runtimeMinutes > 0 ? { runtimeMinutes } : {}),
    }
  }, [
    mediaId,
    mediaType,
    title,
    posterPath,
    season,
    episode,
    episodes,
    mediaRuntimeMinutes,
  ])

  const nextTvEpisodeOnComplete = useMemo(
    () =>
      mediaType === "tv"
        ? resolveNextTvEpisode(season, episode, episodes, seasons)
        : null,
    [mediaType, season, episode, episodes, seasons]
  )

  const resumeTime = useMemo(() => {
    if (typeof upgradeResumeTime === "number" && upgradeResumeTime > 0) {
      return upgradeResumeTime
    }

    return resolveResumeTime(watchMeta, {
      openingEpisode: openingPlaybackRef.current.episode ?? undefined,
      openingResumeTime: openingPlaybackRef.current.startAt,
    })
  }, [watchMeta, season, episode, upgradeResumeTime])

  const handlePlaybackTime = useCallback((timeSec: number) => {
    livePlaybackTimeRef.current = timeSec
  }, [])

  const flushProgressBeforeLeave = useCallback(() => {
    const currentTime = livePlaybackTimeRef.current
    if (currentTime < MIN_WATCH_SECONDS) return

    let durationSec = 0
    if (mediaType === "tv") {
      const episodeRuntime = episodes.find(
        (item) => item.episode_number === episode
      )?.runtime
      if (episodeRuntime && episodeRuntime > 0) {
        durationSec = episodeRuntime * 60
      }
    } else if (mediaRuntimeMinutes && mediaRuntimeMinutes > 0) {
      durationSec = mediaRuntimeMinutes * 60
    }

    try {
      flushWatchProgressCheckpoint(watchMeta, currentTime, durationSec, {
        forceHistory: true,
      })
    } catch (error) {
      // Saving progress is best-effort and must not block Back or episode changes.
      console.warn("[watch-progress] checkpoint flush failed", error)
    }
  }, [episode, episodes, mediaRuntimeMinutes, mediaType, watchMeta])

  useEffect(() => {
    if (isOpen) return
    flushProgressBeforeLeave()
  }, [isOpen, flushProgressBeforeLeave])

  const requestClose = useCallback(() => {
    if (closingRef.current) return
    closingRef.current = true
    flushProgressBeforeLeave()
    setDialogOpen(false)
    onClose()
  }, [flushProgressBeforeLeave, onClose])

  const handleDialogOpenChange = useCallback(
    (open: boolean) => {
      if (open) {
        closingRef.current = false
        setDialogOpen(true)
        return
      }

      requestClose()
    },
    [requestClose]
  )

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

    const currentOption = playbackOptions.find(
      (item) => item.id === selectedSourceId
    )
    if (
      normalizeProviderName(currentOption?.providerId ?? "") !== providerKey
    ) {
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

      const diagnosticMessage = payload.diagnostics.find((item) =>
        [
          "VIDLINK_UNAVAILABLE",
          "VIDLINK_DISABLED",
          "PROVIDER_ERROR",
          "CLIENT_PROVIDER_UNAVAILABLE",
        ].includes(item.code ?? "")
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
    // Quiet stall recovery: keep timeline position, no "Reconnecting…" spam.
    // Attempt 1: remount same/admin-preferred source with fresh links.
    // Attempt 2+: try next provider in admin order.
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
        // Later stalls: move down the admin provider list.
        if (preferProviderId) {
          markSourceFailed(currentId)
        }
        const picked = pickSourceAfterRefetch(refreshed, {
          preferProviderId,
          sameProviderOnly: false,
        })
        nextId = picked?.nextSourceId
        // If still same id, explicitly pick first different admin-ordered option.
        if (nextId === currentId) {
          const alternate = refreshed.find((option) => option.id !== currentId)
          nextId = alternate?.id ?? nextId
        }
      }

      if (!nextId) return false

      quietStallAttemptRef.current = attempt + 1
      setRuntimeError(undefined)
      setUpgradeResumeTime(resumeAt)

      // Always remount so fresh proxy/stream URLs apply — same id still needs a remount.
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
      if (!bumpSourceSwitch()) return false

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

        // Keep position; avoid sticky "Reconnecting stream…" spam.
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
    setRuntimeError("Resolving CinePro providers…")

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
          diagnostics.find((item) => item.code === "CINEPRO_OFFLINE")
            ?.message ??
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
      if (isTransientStreamError(message)) {
        setRuntimeError(undefined)
        return
      }

      if (isProxyTokenPlaybackError(message) || isRecoverableStreamError(message)) {
        setRuntimeError("Buffering… reconnecting")
        void refetchAndResumeSource(true).then((ok) => {
          if (!ok) {
            setRuntimeError(
              `${message}. Tap Retry below or pick another source in player settings.`
            )
          }
        })
        return
      }

      setRuntimeError(
        `${message}. Tap Retry below or pick another source in player settings.`
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
      (sourceLoading || sourcesLoadingMore) && isVidLinkOnlySources(sources)
    const result = resolvePlaybackError(
      message,
      metaResolved,
      awaitingMoreSources
    )

    if (result.action === "fallback") {
      const resumeAt = playbackPositionForRecovery(livePlaybackTimeRef.current)
      if (resumeAt !== undefined) {
        setUpgradeResumeTime(resumeAt)
      }
      setRuntimeError("Buffering… reconnecting")
      bumpSourceSwitch()
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
      !sourceLoading &&
      !sourcesLoadingMore &&
      !manualSelectionRef.current
    ) {
      void resolveMoreProviders()
      return
    }

    setRuntimeError(result.errorMessage)
  }

  useEffect(() => {
    if (!isOpen || !isCurrentProviderFailed() || !isVidLinkOnlySources(sources))
      return
    if (
      sourceLoading ||
      sourcesLoadingMore ||
      providerResolveAttemptedRef.current
    )
      return

    void resolveMoreProviders()
  }, [
    isOpen,
    isCurrentProviderFailed,
    sourceLoading,
    sources,
    sourcesLoadingMore,
  ])

  const clearOpeningResumeForEpisode = useCallback(
    (nextSeason: number, nextEpisode: number) => {
      if (openingPlaybackRef.current.sessionKey) {
        openingPlaybackRef.current = {
          ...openingPlaybackRef.current,
          episode: { season: nextSeason, episode: nextEpisode },
          startAt: undefined,
        }
      }
    },
    []
  )

  const handleTvEpisodeSelect = useCallback(
    (nextSeason: number, nextEpisode: number) => {
      if (nextSeason === season && nextEpisode === episode) {
        return
      }

      flushProgressBeforeLeave()
      setUpgradeResumeTime(undefined)
      livePlaybackTimeRef.current = 0
      clearOpeningResumeForEpisode(nextSeason, nextEpisode)

      if (nextSeason !== season) {
        setSeason(nextSeason)
        setEpisode(nextEpisode)
        onEpisodeChange?.(nextSeason, nextEpisode)

        void fetch(`/api/tv/${mediaId}/season/${nextSeason}`)
          .then((response) => (response.ok ? response.json() : null))
          .then((data) => {
            if (Array.isArray(data?.episodes)) {
              setEpisodes(data.episodes)
            }
          })
          .catch(() => undefined)
        return
      }

      setEpisode(nextEpisode)
      onEpisodeChange?.(season, nextEpisode)
    },
    [
      clearOpeningResumeForEpisode,
      episode,
      flushProgressBeforeLeave,
      mediaId,
      onEpisodeChange,
      season,
    ]
  )

  const handlePrevEpisode = () => {
    if (episode > 1) {
      handleTvEpisodeSelect(season, episode - 1)
    }
  }

  const handleNextEpisode = () => {
    const next = resolveNextTvEpisode(season, episode, episodes, seasons)
    if (!next) return

    flushProgressBeforeLeave()

    if (next.season !== season) {
      void handleSeasonChange(next.season)
      return
    }

    handleTvEpisodeSelect(season, next.episode)
  }

  const handleSeasonChange = useCallback(
    async (newSeason: number) => {
      if (newSeason === season) return

      flushProgressBeforeLeave()

      setSeason(newSeason)
      setEpisode(1)
      onEpisodeChange?.(newSeason, 1)

      try {
        const response = await fetch(`/api/tv/${mediaId}/season/${newSeason}`)
        if (!response.ok) return

        const data = await response.json()
        if (Array.isArray(data.episodes)) {
          setEpisodes(data.episodes)
        }
      } catch {
        // keep previous episode list on failure
      }
    },
    [flushProgressBeforeLeave, mediaId, onEpisodeChange, season]
  )

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

  const handlePlaybackEnded = useCallback(async () => {
    if (!autoNext || mediaType !== "tv" || inWatchParty) return

    flushProgressBeforeLeave()

    const next = resolveNextTvEpisode(season, episode, episodes, seasons)
    if (!next) return

    if (next.season !== season) {
      await handleSeasonChange(next.season)
      return
    }

    handleTvEpisodeSelect(season, next.episode)
  }, [
    autoNext,
    episode,
    episodes,
    flushProgressBeforeLeave,
    handleSeasonChange,
    handleTvEpisodeSelect,
    inWatchParty,
    mediaType,
    season,
    seasons,
  ])

  const currentEpisodeData = episodes.find(
    (ep) => ep.episode_number === episode
  )

  const isBuiltinPlayer = effectiveActivePlayerId === null
  const useCinemaShell = isBuiltinPlayer || presentation === "inline"
  const showTvEpisodeSidebar =
    mediaType === "tv" && !isBuiltinPlayer && presentation !== "inline"

  const setCinemaShellRef = useCallback((node: HTMLDivElement | null) => {
    cinemaFullscreenRef.current = node
    setFullscreenPortal(node)
  }, [])

  const builtinPlayerStage =
    isOpen && dialogOpen ? (
      <ExternalPlayerPanel
        hidePlayerPicker
        mediaType={mediaType}
        mediaId={mediaId}
        season={season}
        episode={episode}
        activePlayerId={effectiveActivePlayerId}
        onSelectPlayer={setActivePlayerId}
        playerLayout="fill"
        builtinPlayer={
          <div className="relative h-full min-h-0 w-full">
            {playerSource && isOpen ? (
              <Suspense
                fallback={
                  <div className="flex h-full min-h-[240px] items-center justify-center bg-black text-sm text-white/60">
                    Loading player…
                  </div>
                }
              >
                <MediaPlayer
                  key={`${playerSource.id}:s${season}:e${episode}:n${sourceSwitchNonce}`}
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
                  hiddenProviderIds={hiddenProviderIds}
                  sourceHealth={sourceHealth}
                  activeTestingProviderId={activeTestingProviderId}
                  playbackToken={playbackToken}
                  watchMeta={watchMeta}
                  nextTvEpisodeOnComplete={nextTvEpisodeOnComplete}
                  resumeTime={
                    isWatchPartyGuest && guestResumeTime
                      ? guestResumeTime
                      : awaitingPartyRoom
                      ? 0
                      : resumeTime
                  }
                  onPlaybackSync={
                    isWatchPartyHost ? handlePlaybackSync : undefined
                  }
                  syncPlayback={isWatchPartyGuest ? syncPlayback : null}
                  watchPartyGuest={guestPlayback}
                  onPlaybackEnded={
                    inWatchParty ? undefined : handlePlaybackEnded
                  }
                  tvNavigation={
                    mediaType === "tv" && !inWatchParty
                      ? {
                          showId: mediaId,
                          showTitle: title,
                          currentSeason: season,
                          currentEpisode: episode,
                          onEpisodeSelect: handleTvEpisodeSelect,
                        }
                      : undefined
                  }
                  autoNextEnabled={autoNext}
                  onAutoNextChange={handleAutoNextChange}
                  showAutoNext={mediaType === "tv" && !inWatchParty}
                  statusInsetLeft={
                    isBuiltinPlayer && isTouchDevice ? "5.75rem" : undefined
                  }
                  mobileControlsTopInset={
                    isBuiltinPlayer && isTouchDevice ? "3.25rem" : undefined
                  }
                  fullscreenRootRef={
                    isBuiltinPlayer ? cinemaFullscreenRef : undefined
                  }
                  onFullscreenChange={
                    isBuiltinPlayer ? setCinemaFullscreen : undefined
                  }
                  onControlsOverlayVisibleChange={setCinemaControlsVisible}
                  controlsWakeRef={controlsWakeRef}
                />
              </Suspense>
            ) : showSourceLoading ? (
              <SourceLoadingOverlay
                active={showSourceLoading}
                sources={sources}
                diagnostics={diagnostics}
                cineproOnly={isVidLinkOnlySources(sources)}
                activeTestingProviderId={activeTestingProviderId}
                sourceHealth={sourceHealth}
                playbackOptions={playbackOptions}
                sourcesLoadingMore={sourcesLoadingMore}
                sourcesFetching={
                  sourceLoading || (isScanningProviders && sources.length === 0)
                }
                scanFailedProviderIds={scanFailedProviderIds}
                playbackStarted={Boolean(playerSource)}
                onRefetchSources={refetchSources}
                sourceStatusMessage={sourceStatusMessage}
                currentSourceId={selectedSourceId || resolvedSourceId}
                onSelectSource={handleSelectSource}
                onRequestProvider={handleRequestProvider}
                unavailableProviders={unavailableProviders}
                hiddenProviderIds={hiddenProviderIds}
                showRealProviderNames={showRealProviderNames}
              />
            ) : (
              <div className="relative flex h-full flex-col items-center justify-center gap-3 rounded-md border border-white/10 bg-black/70 px-6 py-8 text-center text-sm text-white/70">
                <div className="absolute left-3 top-14 z-30 sm:left-4 sm:top-16">
                  <PlayerSettings
                    playbackRate={1}
                    onPlaybackRateChange={() => undefined}
                    qualities={[]}
                    currentQuality={-1}
                    onQualityChange={() => undefined}
                    sources={playbackOptions.map((option) => ({
                      id: option.id,
                      label: option.label,
                      provider: option.provider,
                      providerId: option.providerId,
                      quality: option.quality,
                    }))}
                    currentSourceId={selectedSourceId || resolvedSourceId}
                    onSelectSource={handleSelectSource}
                    onRequestProvider={handleRequestProvider}
                    sourcesLoadingMore={sourcesLoadingMore}
                    sourceStatusMessage={
                      warningMessage ?? sourceStatusMessage
                    }
                    onRefetchSources={refetchSources}
                    unavailableProviders={unavailableProviders}
                    sourceHealth={sourceHealth}
                    activeTestingProviderId={activeTestingProviderId}
                    showRealProviderNames={showRealProviderNames}
                    hiddenProviderIds={hiddenProviderIds}
                  />
                </div>
                <p>{getTitleUnavailableMessage(t)}</p>
                <PlaybackSourceRetryPanel
                  message={warningMessage ?? sourceStatusMessage}
                  onRetry={refetchSources}
                  messageClassName="text-white/50"
                  showRetryButton={shouldShowPlaybackRetryButton(
                    warningMessage ?? sourceStatusMessage
                  )}
                />
                <p className="text-[11px] text-white/45">
                  {t("player.status.couldNotLoad")}
                </p>
              </div>
            )}
            {/* Episode/source transitions: never cover the player chrome — settings
                must stay tappable. MediaPlayer already shows its own loading UI. */}
            {showSourceLoading && playerSource && isOpen ? (
              <SourceLoadingOverlay
                active={showSourceLoading}
                sources={sources}
                diagnostics={diagnostics}
                cineproOnly={isVidLinkOnlySources(sources)}
                activeTestingProviderId={activeTestingProviderId}
                sourceHealth={sourceHealth}
                playbackOptions={playbackOptions}
                sourcesLoadingMore={sourcesLoadingMore}
                sourcesFetching={
                  sourceLoading ||
                  (isScanningProviders && sources.length === 0)
                }
                scanFailedProviderIds={scanFailedProviderIds}
                playbackStarted
                onRefetchSources={refetchSources}
                sourceStatusMessage={sourceStatusMessage}
                currentSourceId={selectedSourceId || resolvedSourceId}
                onSelectSource={handleSelectSource}
                onRequestProvider={handleRequestProvider}
                unavailableProviders={unavailableProviders}
                hiddenProviderIds={hiddenProviderIds}
                showRealProviderNames={showRealProviderNames}
              />
            ) : null}
          </div>
        }
      />
    ) : null

  return presentation === "inline" ? (
    isOpen ? (
      <div className="relative h-full w-full overflow-hidden bg-black">
        <video
          ref={unlockVideoRef}
          className="pointer-events-none absolute size-0 opacity-0"
          playsInline
          muted
          aria-hidden
        />
        <div className="relative flex h-full flex-col overflow-hidden bg-black">
          <FullscreenPortalContext.Provider value={fullscreenPortal}>
            <MobilePlayerLandscapeShell
              ref={setCinemaShellRef}
              className="min-h-0 flex-1 bg-black"
            >
              <div
                className={cn(
                  "absolute inset-x-0 top-0 z-[10003] px-3 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))] md:px-4 md:pb-3 md:pt-4",
                  "transition-opacity duration-300",
                  cinemaControlsVisible ? "opacity-100" : "opacity-0"
                )}
                onMouseMove={wakeCinemaChrome}
              >
                <div className="relative flex min-h-9 items-center">
                  <div className="relative z-10 shrink-0 pointer-events-auto">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={requestClose}
                      className="h-9 gap-1.5 rounded-full border border-white/10 bg-black/70 px-3 text-white hover:bg-white/10 hover:text-white"
                    >
                      <ChevronLeft className="size-4 shrink-0" />
                      <span className="text-sm font-medium">
                        {t("playerModal.back")}
                      </span>
                    </Button>
                  </div>
                  <div
                    key={`${title}-s${season}-e${episode}`}
                    className="pointer-events-none absolute left-1/2 top-1/2 hidden w-[min(28rem,calc(100%-26rem))] -translate-x-1/2 -translate-y-1/2 px-2 text-center md:block"
                  >
                    <p className="truncate text-sm font-semibold text-white md:animate-player-header-drop">
                      {title}
                    </p>
                    {mediaType === "tv" && currentEpisodeData ? (
                      <p className="truncate text-xs text-white/55 md:animate-player-header-drop-delay">
                        S{season} · E{episode} · {currentEpisodeData.name}
                      </p>
                    ) : null}
                  </div>
                  <div className="relative z-10 ml-auto flex shrink-0 items-center gap-2 pointer-events-auto">
                    {enabledPlayers.length > 0 ? (
                      <PlayerEngineDropdown
                        activePlayerId={effectiveActivePlayerId}
                        enabledPlayers={enabledPlayers}
                        onSelectPlayer={setActivePlayerId}
                        disabled={inWatchParty}
                        disabledReason={t("watchParty.requiresPlayer")}
                        className="h-9 w-auto rounded-full border border-white/10 bg-black/70 text-white hover:bg-white/10"
                      />
                    ) : null}
                    {watchPartyEnabled ? (
                      <WatchPartyDropdown
                        open={watchPartyOpen}
                        onOpenChange={setWatchPartyOpen}
                        mediaType={mediaType}
                        mediaId={mediaId}
                        mediaTitle={title}
                        season={season}
                        episode={episode}
                        room={watchPartyRoom}
                        onRoomChange={setWatchPartyRoom}
                        onJoinHostMedia={handleJoinHostMedia}
                        triggerClassName="h-9 rounded-full border border-white/10 bg-black/70 text-white hover:bg-white/10"
                      />
                    ) : null}
                  </div>
                </div>
              </div>
              <div className="flex min-h-0 flex-1 flex-col">
                {builtinPlayerStage}
              </div>
            </MobilePlayerLandscapeShell>
          </FullscreenPortalContext.Provider>
        </div>
      </div>
    ) : null
  ) : (
    <Dialog open={dialogOpen} onOpenChange={handleDialogOpenChange}>
      <DialogContent
        hideCloseButton
        onInteractOutside={(event) => event.preventDefault()}
        onFocusOutside={(event) => event.preventDefault()}
        onPointerDownOutside={(event) => event.preventDefault()}
        overlayClassName={useCinemaShell ? "bg-black" : "bg-[rgba(0,0,0,0.95)]"}
        className={cn(
          "overflow-hidden border-0 bg-transparent p-0 shadow-none",
          useCinemaShell
            ? "fixed inset-0 z-[10001] h-[100dvh] w-full max-h-[100dvh] max-w-none translate-x-0 translate-y-0 rounded-none"
            : "h-[min(80vh,calc(100vh-7rem))] w-[calc(100vw-2rem)] max-h-[min(80vh,calc(100vh-7rem))] max-w-[calc(100vw-2rem)] sm:h-[96vh] sm:w-[min(98vw,1700px)] sm:max-h-[96vh] sm:max-w-[98vw] sm:rounded-[28px]"
        )}
      >
        <DialogTitle className="sr-only">
          {mediaType === "tv" && currentEpisodeData
            ? `${title} — Season ${season}, Episode ${episode}: ${currentEpisodeData.name}`
            : title}
        </DialogTitle>
        <DialogDescription className="sr-only">
          {description ||
            currentEpisodeData?.overview ||
            mediaOverview ||
            "Media player"}
        </DialogDescription>
        <video
          ref={unlockVideoRef}
          className="pointer-events-none absolute size-0 opacity-0"
          playsInline
          muted
          aria-hidden
        />
        <div
          className={cn(
            "relative flex h-full overflow-hidden",
            useCinemaShell
              ? "flex-col bg-black"
              : "flex-col rounded-[24px] border border-white/6 bg-[linear-gradient(180deg,#121212_0%,#090909_100%)] shadow-[0_24px_90px_rgba(0,0,0,0.58)] lg:flex-row"
          )}
        >
          {useCinemaShell ? (
            <FullscreenPortalContext.Provider value={fullscreenPortal}>
              <MobilePlayerLandscapeShell
                ref={setCinemaShellRef}
                className="min-h-0 flex-1 bg-black"
              >
                <div
                  className={cn(
                    "absolute inset-x-0 top-0 z-[10003] px-3 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))] md:px-4 md:pb-3 md:pt-4",
                    "transition-opacity duration-300",
                    cinemaControlsVisible ? "opacity-100" : "opacity-0"
                  )}
                  onMouseMove={wakeCinemaChrome}
                >
                  <div className="relative flex min-h-9 items-center">
                    <div className="relative z-10 shrink-0 pointer-events-auto">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={requestClose}
                        className="h-9 gap-1.5 rounded-full border border-white/10 bg-black/70 px-3 text-white hover:bg-white/10 hover:text-white"
                      >
                        <ChevronLeft className="size-4 shrink-0" />
                        <span className="text-sm font-medium">
                          {t("playerModal.back")}
                        </span>
                      </Button>
                    </div>
                    <div
                      key={`${title}-s${season}-e${episode}`}
                      className="pointer-events-none absolute left-1/2 top-1/2 hidden w-[min(28rem,calc(100%-26rem))] -translate-x-1/2 -translate-y-1/2 px-2 text-center md:block"
                    >
                      <p className="truncate text-sm font-semibold text-white md:animate-player-header-drop">
                        {title}
                      </p>
                      {mediaType === "tv" && currentEpisodeData ? (
                        <p className="truncate text-xs text-white/55 md:animate-player-header-drop-delay">
                          S{season} · E{episode} · {currentEpisodeData.name}
                        </p>
                      ) : null}
                    </div>
                    <div className="relative z-10 ml-auto flex shrink-0 items-center gap-2 pointer-events-auto">
                      {enabledPlayers.length > 0 ? (
                        <PlayerEngineDropdown
                          activePlayerId={effectiveActivePlayerId}
                          enabledPlayers={enabledPlayers}
                          onSelectPlayer={setActivePlayerId}
                          disabled={inWatchParty}
                          disabledReason={t("watchParty.requiresPlayer")}
                          className="h-9 w-auto rounded-full border border-white/10 bg-black/70 text-white hover:bg-white/10"
                        />
                      ) : null}
                      {watchPartyEnabled ? (
                        <WatchPartyDropdown
                          open={watchPartyOpen}
                          onOpenChange={setWatchPartyOpen}
                          mediaType={mediaType}
                          mediaId={mediaId}
                          mediaTitle={title}
                          season={season}
                          episode={episode}
                          room={watchPartyRoom}
                          onRoomChange={setWatchPartyRoom}
                          onJoinHostMedia={handleJoinHostMedia}
                          triggerClassName="h-9 rounded-full border border-white/10 bg-black/70 text-white hover:bg-white/10"
                        />
                      ) : null}
                    </div>
                  </div>
                </div>
                <div className="flex min-h-0 flex-1 flex-col">
                  {builtinPlayerStage}
                </div>
              </MobilePlayerLandscapeShell>
            </FullscreenPortalContext.Provider>
          ) : (
            <>
              {/* Main Player Section */}
              <div
                className={cn(
                  "flex min-h-0 flex-col gap-2 px-3 pt-3 pb-2 md:gap-4 md:px-6 md:pt-6 md:pb-5 lg:flex-1",
                  mediaType === "tv"
                    ? "max-md:shrink-0 max-md:flex-none"
                    : "flex-1"
                )}
              >
                {/* Title Section with Watch Party */}
                <div className="relative flex flex-col gap-2 rounded-2xl border border-white/8 bg-white/[0.03] px-3 py-2 max-md:flex-row max-md:items-start max-md:justify-between max-md:gap-3 md:gap-3 md:px-4 md:py-3 md:flex-row md:items-start md:justify-between md:gap-4 md:pr-14">
                  <div className="min-w-0 flex-1 space-y-1">
                    <span className="inline-flex rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.24em] text-white/50">
                      {mediaType === "tv"
                        ? t("playerModal.seriesPlayback")
                        : t("playerModal.moviePlayback")}
                    </span>
                    <h2 className="line-clamp-1 text-lg font-bold text-white md:line-clamp-none md:text-3xl">
                      {title}
                    </h2>
                    {mediaType === "tv" && currentEpisodeData && (
                      <p className="text-xs font-medium text-white/60 md:text-base">
                        Season {season} · Episode {episode} ·{" "}
                        {currentEpisodeData.name}
                      </p>
                    )}
                  </div>
                  {watchPartyEnabled ? (
                    <WatchPartyDropdown
                      open={watchPartyOpen}
                      onOpenChange={setWatchPartyOpen}
                      mediaType={mediaType}
                      mediaId={mediaId}
                      mediaTitle={title}
                      season={season}
                      episode={episode}
                      room={watchPartyRoom}
                      onRoomChange={setWatchPartyRoom}
                      onJoinHostMedia={handleJoinHostMedia}
                      triggerClassName="shrink-0 max-md:static md:absolute md:top-3 md:right-3 md:right-auto md:top-auto"
                    />
                  ) : null}
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

                {builtinPlayerStage}

                {/* Description */}
                <div className="hidden md:block px-1">
                  <div className="rounded-2xl border border-white/8 bg-white/[0.03] p-4">
                    <div className="mb-2 flex items-center gap-2">
                      <span className="text-[10px] font-semibold uppercase tracking-[0.24em] text-white/40">
                        {t("playerModal.summary")}
                      </span>
                      <div className="h-px flex-1 bg-white/10" />
                    </div>
                    <p className="line-clamp-3 text-sm text-white/70">
                      {description ||
                        currentEpisodeData?.overview ||
                        mediaOverview ||
                        t("playerModal.noDescription")}
                    </p>
                  </div>
                </div>
              </div>

              {/* Episode Sidebar — external players only (builtin uses in-player picker) */}
              {showTvEpisodeSidebar && (
                <div className="flex min-h-0 w-full flex-1 flex-row border-t border-white/8 bg-[linear-gradient(180deg,rgba(255,255,255,0.025),rgba(255,255,255,0.015))] max-md:max-h-[34vh] md:flex-col lg:h-auto lg:max-h-none lg:w-[420px] lg:flex-col lg:border-t-0 lg:border-l">
                  {/* Season Selection — left column on mobile */}
                  <div className="flex min-h-0 w-[34%] min-w-0 flex-col border-r border-white/8 px-2 py-2 md:w-full md:space-y-3 md:border-r-0 md:p-4">
                    <div className="mb-1 flex flex-shrink-0 items-center justify-between md:mb-0">
                      <h3 className="text-[10px] font-bold uppercase tracking-[0.2em] text-white/55 md:text-sm md:tracking-[0.24em]">
                        {t("player.seasons")}
                      </h3>
                      <Badge
                        variant="outline"
                        className="rounded-full border-white/10 bg-white/[0.04] text-[10px] text-white/65"
                      >
                        {seasons.length}
                      </Badge>
                    </div>
                    <ScrollArea className="min-h-0 flex-1 md:h-32 md:flex-none">
                      <div className="flex flex-col gap-1.5 pr-2 md:grid md:grid-cols-3 md:gap-2 md:pr-4">
                        {seasonsLoading ? (
                          <div className="py-4 text-center text-sm text-white/50 md:col-span-3">
                            {t("player.loadingSeasons")}
                          </div>
                        ) : seasons.length > 0 ? (
                          seasons.map((s) => (
                            <Button
                              key={s.id}
                              variant="outline"
                              size="sm"
                              onClick={() =>
                                handleSeasonChange(s.season_number)
                              }
                              className={cn(
                                "h-auto rounded-xl border px-2.5 py-2 text-left font-semibold transition-all duration-200 md:min-h-16 md:flex-col md:items-start md:rounded-2xl md:px-3",
                                season === s.season_number
                                  ? "border-primary/60 bg-primary/18 text-white shadow-[0_10px_30px_rgba(245,158,11,0.16)]"
                                  : "border-white/10 bg-white/[0.04] text-white/88 hover:border-white/20 hover:bg-white/[0.08] hover:text-white"
                              )}
                            >
                              <span className="hidden text-[10px] font-medium uppercase tracking-[0.22em] text-white/45 md:inline">
                                {t("player.season")}
                              </span>
                              <span className="flex items-center justify-between gap-2 md:mt-1 md:block">
                                <span className="text-sm font-bold leading-none md:text-base">
                                  S{s.season_number}
                                </span>
                                <span className="text-[10px] font-medium text-white/55 md:mt-2 md:block md:text-[11px]">
                                  {t("player.episodesCount", {
                                    n: s.episode_count,
                                  })}
                                </span>
                              </span>
                            </Button>
                          ))
                        ) : (
                          <div className="py-4 text-center text-sm text-white/50 md:col-span-3">
                            {t("player.noSeasonsAvailable")}
                          </div>
                        )}
                      </div>
                    </ScrollArea>
                  </div>

                  <Separator className="hidden bg-white/10 md:my-1 md:block" />

                  {/* Episodes List — right column on mobile */}
                  <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                    <div className="mb-1 flex flex-shrink-0 items-center justify-between px-2 pt-2 md:mb-3 md:px-4 md:pt-4">
                      <h3 className="text-[10px] font-bold uppercase tracking-[0.2em] text-white/55 md:text-sm md:tracking-[0.24em]">
                        {t("player.episodes")}
                      </h3>
                      <Badge
                        variant="outline"
                        className="rounded-full border-white/10 bg-white/[0.04] text-xs text-white/70"
                      >
                        {episodes.length}
                      </Badge>
                    </div>

                    <div className="custom-scrollbar min-h-0 flex-1 overflow-y-auto px-2 pb-2 md:px-4 md:pb-4 lg:max-h-[calc(92vh-220px)]">
                      {loading ? (
                        <div className="text-center text-white/50 py-12 text-sm">
                          {t("player.loadingEpisodes")}
                        </div>
                      ) : episodes.length > 0 ? (
                        <div className="space-y-1 md:space-y-3">
                          {episodes.map((ep) => (
                            <Card
                              key={ep.id}
                              onMouseEnter={() => {
                                void prefetchMediaSources({
                                  id: mediaId,
                                  type: "tv",
                                  season,
                                  episode: ep.episode_number,
                                })
                              }}
                              onClick={() => {
                                handleTvEpisodeSelect(season, ep.episode_number)
                              }}
                              className={cn(
                                "cursor-pointer overflow-hidden rounded-xl border transition-all duration-200 md:rounded-2xl",
                                episode === ep.episode_number
                                  ? "border-primary/45 bg-white/[0.06] shadow-[0_16px_40px_rgba(245,158,11,0.12)]"
                                  : "border-white/10 bg-white/[0.03] hover:border-white/20 hover:bg-white/[0.06]"
                              )}
                            >
                              <div className="flex items-center gap-2 p-2 md:gap-3 md:p-3">
                                {/* Episode Thumbnail */}
                                <div className="relative hidden h-16 w-24 flex-shrink-0 overflow-hidden rounded-xl bg-white/10 md:block md:h-20 md:w-32">
                                  {ep.still_path ? (
                                    <Image
                                      src={wsrvImage.still(
                                        ep.still_path,
                                        "w300",
                                        200
                                      )}
                                      alt={ep.name}
                                      fill
                                      unoptimized
                                      className="object-cover"
                                      sizes="(max-width: 768px) 96px, 128px"
                                    />
                                  ) : (
                                    <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-white/10 to-white/[0.03]">
                                      <Icons.Logo className="w-8 h-8 md:w-12 md:h-12 text-primary opacity-60" />
                                    </div>
                                  )}
                                  {/* Episode number badge overlay */}
                                  <div className="absolute left-1 bottom-1 md:left-2 md:bottom-2">
                                    <Badge
                                      variant="secondary"
                                      className={cn(
                                        "border px-1.5 text-[10px] font-bold backdrop-blur-sm md:text-xs",
                                        episode === ep.episode_number
                                          ? "border-primary/40 bg-primary/55 text-white"
                                          : "border-white/15 bg-black/55 text-white/90"
                                      )}
                                    >
                                      {t("player.episodeShort", {
                                        n: ep.episode_number,
                                      })}
                                    </Badge>
                                  </div>
                                </div>

                                <Badge
                                  variant="secondary"
                                  className={cn(
                                    "shrink-0 border px-1.5 text-[10px] font-bold md:hidden",
                                    episode === ep.episode_number
                                      ? "border-primary/40 bg-primary/55 text-white"
                                      : "border-white/15 bg-black/55 text-white/90"
                                  )}
                                >
                                  {t("player.episodeShort", {
                                    n: ep.episode_number,
                                  })}
                                </Badge>

                                {/* Episode Info */}
                                <div className="min-w-0 flex-1 space-y-1 md:space-y-1.5">
                                  <CardTitle
                                    className={cn(
                                      "line-clamp-1 text-xs font-bold md:text-sm",
                                      episode === ep.episode_number
                                        ? "text-white"
                                        : "text-white/90"
                                    )}
                                  >
                                    {ep.name}
                                  </CardTitle>

                                  {ep.overview && (
                                    <CardDescription className="hidden line-clamp-2 text-[10px] text-white/58 md:block md:text-xs">
                                      {ep.overview}
                                    </CardDescription>
                                  )}

                                  {/* Episode Metadata */}
                                  <div className="hidden flex-wrap items-center gap-1.5 text-[10px] text-white/50 md:flex md:gap-2 md:text-xs">
                                    {ep.air_date && (
                                      <div className="flex items-center gap-1 rounded-full bg-white/[0.04] px-2 py-1">
                                        <Calendar className="size-2.5 md:size-3" />
                                        <span>
                                          {new Date(ep.air_date).getFullYear()}
                                        </span>
                                      </div>
                                    )}
                                    {ep.runtime && (
                                      <div className="flex items-center gap-1 rounded-full bg-white/[0.04] px-2 py-1">
                                        <Clock className="size-2.5 md:size-3" />
                                        <span>{ep.runtime}min</span>
                                      </div>
                                    )}
                                    {ep.vote_average && ep.vote_average > 0 && (
                                      <Badge
                                        variant="outline"
                                        className="h-6 rounded-full border-white/10 bg-white/[0.04] px-2 text-[10px] text-white md:text-xs"
                                      >
                                        ★ {ep.vote_average.toFixed(1)}
                                      </Badge>
                                    )}
                                  </div>
                                </div>
                              </div>
                            </Card>
                          ))}
                        </div>
                      ) : (
                        <div className="text-center text-white/50 py-12 text-sm">
                          {t("player.noEpisodesFound")}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
