"use client"

import React, {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type RefObject,
} from "react"
import Hls from "hls.js"

import { getClientUser } from "@/lib/auth-client"
import {
  decodeCineproProxyPayloadFromHref,
  resolveProxiedMediaRequestUrl,
} from "@/lib/cinepro/proxy"
import { getEmbedAuthParentOrigins } from "@/lib/embed-auth-handoff"
import { postEmbedMessage } from "@/lib/embed-player"
import {
  EMBED_MESSAGE_FULLSCREEN_STATE,
  EMBED_MESSAGE_TOGGLE_FULLSCREEN,
  EMBED_MESSAGE_WAKE_CHROME,
  type EmbedFullscreenStatePayload,
} from "@/lib/embed-ui-sync"
import {
  addDocumentFullscreenChangeListener,
  addIosVideoFullscreenListeners,
  exitPlayerFullscreen,
  isElementFullscreen,
  isFullscreenActive,
  requestPlayerFullscreen,
} from "@/lib/fullscreen"
import { useTranslations } from "@/lib/i18n/client"
import {
  isProxyRefetchHttpStatus,
  isTransientProxyHttpStatus,
} from "@/lib/playback-error-recovery"
import {
  attemptAutoplayUnmute,
  canAttemptAutoplayWithSound,
  markVideoAutoplayUnlocked,
  scheduleAutoplayUnmuteAttempts,
  unlockVideoElementForAutoplay,
} from "@/lib/playback-gesture"
import { resolveClientPlaybackProxyOrigin } from "@/lib/playback-proxy-origin"
import {
  extractPlaybackToken,
  requiresPlaybackTokenUrl,
} from "@/lib/playback-token"
import {
  tryLockLandscapeOrientation,
  unlockScreenOrientation,
} from "@/lib/player-orientation"
import {
  findSubtitleForLanguage,
  getSubtitlePreferences,
} from "@/lib/player-subtitle-prefs"
import { readSafeAreaInsets, type SafeAreaInsets } from "@/lib/safe-area-insets"
import {
  SOURCE_METADATA_TIMEOUT_MS,
  STARTUP_PLAYBACK_FAIL_MS,
} from "@/lib/source-probe-constants"
import {
  isTransientPlaybackStatusMessage,
  shouldShowPlaybackRetryButton,
} from "@/lib/playback-user-messages"
import type { TvEpisodeRef } from "@/lib/tv-auto-next"
import type { WatchProgressMeta } from "@/lib/watch-progress"
import { useAuth } from "@/hooks/use-auth"
import { useEmbedPlayerBridge } from "@/hooks/use-embed-player-bridge"
import { useIntroSkip } from "@/hooks/use-intro-skip"
import { usePlayerAnalytics } from "@/hooks/use-player-analytics"
import { useWatchPartyGuestVideoSync } from "@/hooks/use-watch-party-guest-video-sync"
import { useWatchProgressSaver } from "@/hooks/use-watch-progress-saver"
import { useClientSearchParams } from "@/hooks/useClientSearchParams"
import { canAutoFallbackPlayback } from "@/hooks/usePlaybackSourceFallback"
import type { SourceHealthStatus } from "@/hooks/useSourceHealth"

import { CustomSubtitles } from "./CustomSubtitles"
import { PlayerControls } from "./PlayerControls"
import type { TvEpisodeNavigationProps } from "./PlayerEpisodes"
import { PlaybackSourceRetryPanel } from "./PlaybackSourceRetryPanel"
import { SourceStatusOverlay } from "./SourceStatusOverlay"
import {
  fourKHlsStartupTimeoutMs,
  isFourKHlsPlaybackUrl,
  isFourKHlsTranscodeUrl,
  normalizeCinemacityCdnPlaybackUrl,
  playbackUrlWithoutToken,
} from "./utils/playback"

const EMPTY_AUDIO_TRACKS: Array<{ id: string; label: string }> = []
/** Ignore bogus HLS fragment durations; real episodes are always longer. */
const MIN_TRUSTED_DURATION_SEC = 45
const MIN_PLAYBACK_BEFORE_END_CHECK_SEC = 15

function parseChillflixSourceDurationTag(manifest: string) {
  const match = manifest.match(/#EXT-X-CHILLFLIX-SOURCE-DURATION:([0-9.]+)/)
  if (!match) return undefined
  const seconds = Number(match[1])
  return Number.isFinite(seconds) && seconds > 0 ? seconds : undefined
}

function parseTmdbIdFromFourKUrl(url: string) {
  try {
    const parsed = new URL(url, "https://chillflix.invalid")
    if (!parsed.pathname.includes("/api/4k/hls/")) return undefined
    const tmdbId = Number(parsed.searchParams.get("tmdbId"))
    return Number.isFinite(tmdbId) && tmdbId > 0 ? tmdbId : undefined
  } catch {
    return undefined
  }
}

function resolvePlayerDuration(args: {
  expectedSec: number
  streamSec: number
  videoSec: number
  currentTime: number
  progressiveHls: boolean
}) {
  const trusted = [args.streamSec, args.videoSec].filter(
    (value) => Number.isFinite(value) && value >= MIN_TRUSTED_DURATION_SEC
  )
  const trustedMax = trusted.length > 0 ? Math.max(...trusted) : 0

  if (args.expectedSec >= MIN_TRUSTED_DURATION_SEC) {
    let total = Math.max(trustedMax, args.expectedSec)
    if (args.currentTime > 0 && total < args.currentTime) {
      total = args.currentTime + 1
    }
    return total
  }

  if (args.progressiveHls) {
    return trustedMax > 0 ? trustedMax : 0
  }

  const partialMax = Math.max(
    Number.isFinite(args.streamSec) && args.streamSec > 0 ? args.streamSec : 0,
    Number.isFinite(args.videoSec) && args.videoSec > 0 ? args.videoSec : 0
  )
  let total = Math.max(trustedMax, partialMax)

  if (args.currentTime > 0 && total > 0 && total < args.currentTime) {
    total = args.currentTime + 1
  }

  return total
}
const VIDEO_ZOOM_MIN = 1
const VIDEO_ZOOM_MAX = 5
const VIDEO_PAN_THRESHOLD_PX = 8
const VIDEO_DOUBLE_TAP_MS = 300
const VIDEO_DOUBLE_TAP_DISTANCE_PX = 40

function clampVideoScale(scale: number) {
  return Math.max(VIDEO_ZOOM_MIN, Math.min(VIDEO_ZOOM_MAX, scale))
}

function getTouchDistance(touchA: Touch, touchB: Touch) {
  const dx = touchA.clientX - touchB.clientX
  const dy = touchA.clientY - touchB.clientY
  return Math.hypot(dx, dy)
}

/** Minimum pan bleed when the browser reports zero insets (common on some Android builds). */
const MOBILE_FALLBACK_SAFE_AREA: SafeAreaInsets = {
  top: 52,
  right: 28,
  bottom: 28,
  left: 28,
}

function resolvePanSafeAreaInsets(insets: SafeAreaInsets): SafeAreaInsets {
  if (insets.top + insets.right + insets.bottom + insets.left > 0) {
    return insets
  }

  if (typeof window === "undefined" || !("ontouchstart" in window)) {
    return insets
  }

  return MOBILE_FALLBACK_SAFE_AREA
}

function clampVideoTranslate(
  x: number,
  y: number,
  scale: number,
  containerWidth: number,
  containerHeight: number,
  safe: SafeAreaInsets = { top: 0, right: 0, bottom: 0, left: 0 }
) {
  if (scale <= VIDEO_ZOOM_MIN) {
    return { x: 0, y: 0 }
  }

  // Extra pan range into status bar / camera cutout / gesture areas (viewport-fit=cover).
  const bleedX = safe.left + safe.right
  const bleedY = safe.top + safe.bottom
  const maxX = (containerWidth * (scale - 1)) / 2 + bleedX
  const maxY = (containerHeight * (scale - 1)) / 2 + bleedY

  return {
    x: Math.max(-maxX, Math.min(maxX, x)),
    y: Math.max(-maxY, Math.min(maxY, y)),
  }
}

function isEnglishAudioLabel(label: string) {
  const normalized = label.trim().toLowerCase()
  return (
    normalized === "en" ||
    normalized.startsWith("en-") ||
    normalized === "eng" ||
    normalized.includes("english")
  )
}

function pickPreferredAudioTrackFromList(
  tracks: Array<{ id: string; label: string }>
) {
  const english = tracks.find((track) => isEnglishAudioLabel(track.label))
  return english?.id ?? tracks[0]?.id
}

function pickPreferredHlsAudioTrackId(
  hlsInstance: Hls,
  safeTracks: Array<{ id: string; label: string }>
) {
  const hlsTracks = hlsInstance.audioTracks
  for (let i = 0; i < hlsTracks.length; i++) {
    const lang = (hlsTracks[i].lang || "").toLowerCase()
    const name = (hlsTracks[i].name || "").toLowerCase()
    if (isEnglishAudioLabel(lang) || isEnglishAudioLabel(name)) {
      return String(i)
    }
  }

  const fromLabels = pickPreferredAudioTrackFromList(safeTracks)
  if (
    fromLabels &&
    isEnglishAudioLabel(
      safeTracks.find((t) => t.id === fromLabels)?.label ?? ""
    )
  ) {
    return fromLabels
  }

  if (hlsInstance.audioTrack >= 0) {
    return String(hlsInstance.audioTrack)
  }

  return fromLabels
}

export interface MediaPlayerSource {
  id: string
  url: string
  type: "hls" | "file"
  cinemacityPageUrl?: string
  directClientPlayback?: boolean
  clientPlaybackHeaders?: Record<string, string>
}

interface MediaPlayerProps {
  source: MediaPlayerSource
  sources?: Array<{
    id: string
    label: string
    provider?: string
    providerId?: string
    quality?: string
  }>
  onSelectSource?: (id: string) => void | Promise<void>
  onRequestProvider?: (providerId: string) => void | Promise<void>
  subtitles?: Array<{
    id: string
    label: string
    src: string
    language?: string
    kind?: string
  }>
  audioTracks?: Array<{
    id: string
    label: string
  }>
  onPlaybackError?: (
    message: string,
    meta?: { currentTime: number; bufferedSeconds: number }
  ) => void
  onPlaybackReady?: (meta: {
    currentTime: number
    bufferedSeconds: number
  }) => void
  onPlaybackTime?: (currentTime: number) => void
  onPlaybackEnded?: () => void
  sourcesLoadingMore?: boolean
  sourceStatusMessage?: string
  onRefetchSources?: () => void
  /** Called when playback waits too long after starting — parent should retest/refetch and resume. */
  onBufferStall?: () => void
  unavailableProviders?: string[]
  sourceHealth?: Record<string, SourceHealthStatus>
  activeTestingProviderId?: string
  watchMeta?: WatchProgressMeta
  nextTvEpisodeOnComplete?: TvEpisodeRef | null
  resumeTime?: number
  playbackToken?: string
  onPlaybackSync?: (state: { currentTime: number; isPlaying: boolean }) => void
  syncPlayback?: {
    currentTime: number
    isPlaying: boolean
    updatedAt?: number
    anchorTime?: number
  } | null
  watchPartyGuest?: boolean
  externalSubtitlesLoading?: boolean
  tvNavigation?: TvEpisodeNavigationProps
  autoNextEnabled?: boolean
  onAutoNextChange?: (enabled: boolean) => void
  showAutoNext?: boolean
  /** When true, posts watch progress + player events to parent iframe via postMessage. */
  embedReportingEnabled?: boolean
  embedMediaType?: "movie" | "tv"
  embedSeason?: number
  embedEpisode?: number
  /** Embed query param: start playback automatically. */
  forceAutoplay?: boolean
  /** Left inset for source status badge (cinema header clearance). */
  statusInsetLeft?: string
  /** Fullscreen the cinema shell (header + player) instead of only the video box. */
  fullscreenRootRef?: RefObject<HTMLElement | null>
  /** Parent page hosts Back / Watch Party; fullscreen + popovers are coordinated via postMessage. */
  parentHostedEmbed?: boolean
  onControlsOverlayVisibleChange?: (visible: boolean) => void
  onFullscreenChange?: (isFullscreen: boolean) => void
  /** Parent cinema chrome can call this ref to wake in-player controls (e.g. header hover). */
  controlsWakeRef?: React.MutableRefObject<(() => void) | null>
  /** When false, show Alpha/Beta/etc. instead of scraper names. */
  showRealProviderNames?: boolean
  hiddenProviderIds?: string[]
  /** Clearance below a parent cinema header (Back / Watch Party) on mobile. */
  mobileControlsTopInset?: string
}

export function MediaPlayer({
  source,
  sources,
  onSelectSource,
  onRequestProvider,
  subtitles = [],
  audioTracks,
  onPlaybackError,
  onPlaybackReady,
  onPlaybackTime,
  onPlaybackEnded,
  sourcesLoadingMore = false,
  sourceStatusMessage,
  onRefetchSources,
  onBufferStall,
  unavailableProviders = [],
  sourceHealth,
  activeTestingProviderId,
  watchMeta,
  nextTvEpisodeOnComplete,
  resumeTime,
  playbackToken,
  onPlaybackSync,
  syncPlayback,
  watchPartyGuest = false,
  externalSubtitlesLoading = false,
  tvNavigation,
  autoNextEnabled,
  onAutoNextChange,
  showAutoNext = false,
  embedReportingEnabled = false,
  embedMediaType,
  embedSeason,
  embedEpisode,
  forceAutoplay = false,
  statusInsetLeft,
  fullscreenRootRef,
  parentHostedEmbed = false,
  onControlsOverlayVisibleChange,
  onFullscreenChange,
  controlsWakeRef,
  showRealProviderNames,
  hiddenProviderIds = [],
  mobileControlsTopInset,
}: MediaPlayerProps) {
  const { t } = useTranslations()
  const { user, updateProfile } = useAuth()
  const defaultAudioTrack = useMemo(
    () => ({ id: "default", label: t("player.defaultAudio") }),
    [t]
  )
  const ensureAudioTracks = useCallback(
    (tracks: Array<{ id: string; label: string }>) =>
      tracks.length > 0 ? tracks : [defaultAudioTrack],
    [defaultAudioTrack]
  )
  const trackLabel = useCallback(
    (index: number, name?: string, lang?: string) =>
      name || lang || t("player.trackN", { n: index + 1 }),
    [t]
  )

  const searchParams = useClientSearchParams()
  const videoRef = useRef<HTMLVideoElement>(null)
  const containerRef = useRef<HTMLDivElement>(null)
  const videoTransformRef = useRef<HTMLDivElement>(null)
  const [videoScale, setVideoScale] = useState(VIDEO_ZOOM_MIN)
  const [videoTranslate, setVideoTranslate] = useState({ x: 0, y: 0 })
  const [zoomGestureActive, setZoomGestureActive] = useState(false)
  const videoScaleRef = useRef(VIDEO_ZOOM_MIN)
  const videoTranslateRef = useRef({ x: 0, y: 0 })
  const zoomGestureRef = useRef({
    mode: "idle" as "idle" | "pinch" | "pan",
    startDistance: 0,
    startScale: VIDEO_ZOOM_MIN,
    startTranslateX: 0,
    startTranslateY: 0,
    lastPanX: 0,
    lastPanY: 0,
    moved: false,
  })
  const suppressVideoTapRef = useRef(false)
  const suppressDoubleClickRef = useRef(false)
  const lastTapRef = useRef({ time: 0, x: 0, y: 0 })
  const safeAreaInsetsRef = useRef<SafeAreaInsets>({
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
  })
  const hlsRef = useRef<Hls | null>(null)
  const controlsTimeoutRef = useRef<number | null>(null)
  const sourceTimeoutRef = useRef<number | null>(null)
  const startupProgressTimeoutRef = useRef<number | null>(null)

  const playbackErrorRef = useRef(onPlaybackError)
  const playbackReadyRef = useRef(onPlaybackReady)
  const onPlaybackTimeRef = useRef(onPlaybackTime)
  const playbackEndedRef = useRef(onPlaybackEnded)
  const playbackSyncRef = useRef(onPlaybackSync)
  const hasNotifiedPlaybackStartedRef = useRef(false)
  const hasNotifiedPlaybackEndedRef = useRef(false)
  const streamDurationRef = useRef(0)
  const expectedDurationRef = useRef(0)
  const notifyPlaybackEndedRef = useRef<() => void>(() => undefined)
  const watchPartyGuestRef = useRef(watchPartyGuest)

  useEffect(() => {
    playbackEndedRef.current = onPlaybackEnded
  }, [onPlaybackEnded])

  useEffect(() => {
    playbackSyncRef.current = onPlaybackSync
  }, [onPlaybackSync])

  useEffect(() => {
    watchPartyGuestRef.current = watchPartyGuest
  }, [watchPartyGuest])

  const applyExpectedDuration = useCallback((seconds: number) => {
    if (!Number.isFinite(seconds) || seconds < MIN_TRUSTED_DURATION_SEC) return
    expectedDurationRef.current = Math.max(expectedDurationRef.current, seconds)
    setDuration((current) => Math.max(current, expectedDurationRef.current))
  }, [])

  useEffect(() => {
    const runtimeMinutes = watchMeta?.runtimeMinutes
    if (typeof runtimeMinutes === "number" && runtimeMinutes > 0) {
      applyExpectedDuration(runtimeMinutes * 60)
    }
  }, [watchMeta?.runtimeMinutes, applyExpectedDuration])

  useEffect(() => {
    if (expectedDurationRef.current >= MIN_TRUSTED_DURATION_SEC) return
    if (!watchMeta?.id || watchMeta.type !== "movie") return

    let cancelled = false
    void fetch(`/api/movie/${watchMeta.id}`, { cache: "force-cache" })
      .then((response) => (response.ok ? response.json() : null))
      .then((detail) => {
        if (cancelled) return
        const runtime = Number(detail?.runtime)
        if (Number.isFinite(runtime) && runtime > 0) {
          applyExpectedDuration(runtime * 60)
        }
      })
      .catch(() => undefined)

    return () => {
      cancelled = true
    }
  }, [watchMeta?.id, watchMeta?.type, applyExpectedDuration])

  const applyResolvedDuration = useCallback(
    (
      streamSec: number,
      videoSec: number,
      currentTime: number,
      progressiveHls: boolean
    ) => {
      const next = resolvePlayerDuration({
        expectedSec: expectedDurationRef.current,
        streamSec,
        videoSec,
        currentTime,
        progressiveHls,
      })

      if (!Number.isFinite(next) || next <= 0) return

      streamDurationRef.current = Math.max(streamDurationRef.current, next)
      setDuration((current) => (next > current ? next : current))
    },
    []
  )

  const reportPlaybackSync = useCallback(() => {
    if (watchPartyGuest) return
    const video = videoRef.current
    if (!video) return

    playbackSyncRef.current?.({
      currentTime: video.currentTime,
      isPlaying: !video.paused && !video.ended,
    })
  }, [watchPartyGuest])

  const getPlaybackMeta = (video: HTMLVideoElement) => {
    let bufferedSeconds = 0

    if (video.buffered.length > 0) {
      bufferedSeconds = video.buffered.end(video.buffered.length - 1)
    }

    return {
      currentTime: video.currentTime,
      bufferedSeconds,
    }
  }

  const reportPlaybackError = (message: string) => {
    const video = videoRef.current
    playbackErrorRef.current?.(
      message,
      video ? getPlaybackMeta(video) : { currentTime: 0, bufferedSeconds: 0 }
    )
  }
  const activeSourceKeyRef = useRef<string | null>(null)
  const activeStreamBaseRef = useRef<string>("")
  const sourcePropsRef = useRef(source)
  sourcePropsRef.current = source
  const playbackTokenRef = useRef(playbackToken)
  const proxyHeadersRef = useRef<Record<string, string>>({
    Referer: "https://peachify.top/",
    Origin: "https://peachify.top",
  })
  const audioTracksRef = useRef(audioTracks)
  const userPickedAudioTrackRef = useRef(false)
  const audioTracksReadyForSourceRef = useRef(false)

  useEffect(() => {
    playbackTokenRef.current = playbackToken
  }, [playbackToken])
  const [isPlaying, setIsPlaying] = useState(false)
  const notifyPlaybackEnded = useCallback(() => {
    if (hasNotifiedPlaybackEndedRef.current) return
    hasNotifiedPlaybackEndedRef.current = true
    setIsPlaying(false)
    playbackEndedRef.current?.()
  }, [])
  notifyPlaybackEndedRef.current = notifyPlaybackEnded
  const [isLoading, setIsLoading] = useState(true)
  const [hasPlayedOnce, setHasPlayedOnce] = useState(false)
  // BUFFERING_OVERLAY_DELAY: don't flash "Buffering…" on every timeline click.
  // Only show the label after the player has been waiting this long.
  const [showBufferingLabel, setShowBufferingLabel] = useState(false)
  const bufferingLabelTimerRef = useRef<number | null>(null)

  const clearBufferingLabelTimer = useCallback(() => {
    if (bufferingLabelTimerRef.current != null) {
      window.clearTimeout(bufferingLabelTimerRef.current)
      bufferingLabelTimerRef.current = null
    }
  }, [])

  const scheduleBufferingLabel = useCallback(() => {
    if (bufferingLabelTimerRef.current != null) return
    bufferingLabelTimerRef.current = window.setTimeout(() => {
      bufferingLabelTimerRef.current = null
      setShowBufferingLabel(true)
    }, 900)
  }, [])

  const hideBufferingLabel = useCallback(() => {
    clearBufferingLabelTimer()
    setShowBufferingLabel(false)
  }, [clearBufferingLabelTimer])

  const clearLoadingWhenPlaybackFlowing = useCallback((media: HTMLVideoElement) => {
    if (
      !media.paused &&
      !media.seeking &&
      media.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA
    ) {
      setIsLoading(false)
      hideBufferingLabel()
    }
  }, [hideBufferingLabel])

  // RESUME_TIME_INIT_CLOCK: avoid 00:00 flash while resume seek applies after remount.
  const [currentTime, setCurrentTime] = useState(() =>
    typeof resumeTime === "number" && resumeTime > 0.5 ? resumeTime : 0
  )
  const skipPlaybackTimeRef = useRef(
    typeof resumeTime === "number" && resumeTime > 0.5 ? resumeTime : 0
  )
  const needsPlaybackTimeUiRef = useRef(true)
  const [duration, setDuration] = useState(0)
  const [volume, setVolume] = useState(1)
  const volumeRef = useRef(volume)
  const [isMuted, setIsMuted] = useState(false)
  const [pendingAutoplayUnmute, setPendingAutoplayUnmute] = useState(false)
  const pendingAutoplayUnmuteRef = useRef(false)
  const [autoplayBlocked, setAutoplayBlocked] = useState(false)
  const [showControls, setShowControls] = useState(true)
  const [isFullscreen, setIsFullscreen] = useState(false)
  const [isPiP, setIsPiP] = useState(false)
  const [playbackRate, setPlaybackRate] = useState(1)
  const [qualities, setQualities] = useState<
    Array<{ index: number; height: number; label: string }>
  >([])
  const [currentQuality, setCurrentQuality] = useState(-1)
  const [autoplayEnabled, setAutoplayEnabled] = useState(
    () => forceAutoplay || (getClientUser()?.autoplayEnabled ?? true)
  )
  const forceAutoplayRef = useRef(forceAutoplay)
  const autoplayEnabledRef = useRef(autoplayEnabled)
  const tryAutoplayRef = useRef<(() => Promise<void>) | null>(null)
  const autoplayUnmuteCleanupRef = useRef<(() => void) | null>(null)

  const clearAutoplayUnmuteSchedule = useCallback(() => {
    autoplayUnmuteCleanupRef.current?.()
    autoplayUnmuteCleanupRef.current = null
  }, [])

  const teardownPlayback = useCallback(() => {
    clearAutoplayUnmuteSchedule()

    if (sourceTimeoutRef.current) {
      window.clearTimeout(sourceTimeoutRef.current)
      sourceTimeoutRef.current = null
    }

    if (controlsTimeoutRef.current) {
      window.clearTimeout(controlsTimeoutRef.current)
      controlsTimeoutRef.current = null
    }

    if (hlsRef.current) {
      const hls = hlsRef.current
      hlsRef.current = null
      try {
        hls.stopLoad()
        hls.detachMedia()
        hls.destroy()
      } catch {
        // ignore teardown races during unmount
      }
    }

    const video = videoRef.current
    if (video) {
      if (document.pictureInPictureElement === video) {
        void document.exitPictureInPicture().catch(() => undefined)
      }

      video.pause()
      video.removeAttribute("src")
      video.load()
    }

    activeSourceKeyRef.current = null
    activeStreamBaseRef.current = ""
  }, [clearAutoplayUnmuteSchedule])

  useEffect(() => () => teardownPlayback(), [teardownPlayback])

  useEffect(() => {
    const stopPlayback = () => teardownPlayback()
    window.addEventListener("pagehide", stopPlayback)
    return () => window.removeEventListener("pagehide", stopPlayback)
  }, [teardownPlayback])

  const applyAutoplayUnmute = useCallback(() => {
    const video = videoRef.current
    const targetVolume = volumeRef.current > 0 ? volumeRef.current : 1

    if (video) {
      video.muted = false
      video.volume = targetVolume
    }

    setIsMuted(false)
    setPendingAutoplayUnmute(false)
    clearAutoplayUnmuteSchedule()
  }, [clearAutoplayUnmuteSchedule])

  const queueAutoplayUnmute = useCallback(
    (video: HTMLVideoElement) => {
      clearAutoplayUnmuteSchedule()
      const targetVolume = volumeRef.current > 0 ? volumeRef.current : 1
      autoplayUnmuteCleanupRef.current = scheduleAutoplayUnmuteAttempts(
        video,
        applyAutoplayUnmute,
        undefined,
        targetVolume
      )
    },
    [applyAutoplayUnmute, clearAutoplayUnmuteSchedule]
  )

  useEffect(
    () => () => clearAutoplayUnmuteSchedule(),
    [clearAutoplayUnmuteSchedule]
  )

  const queueAutoplayUnmuteRef = useRef(queueAutoplayUnmute)
  const applyAutoplayUnmuteRef = useRef(applyAutoplayUnmute)

  useEffect(() => {
    queueAutoplayUnmuteRef.current = queueAutoplayUnmute
  }, [queueAutoplayUnmute])

  useEffect(() => {
    applyAutoplayUnmuteRef.current = applyAutoplayUnmute
  }, [applyAutoplayUnmute])

  useEffect(() => {
    forceAutoplayRef.current = forceAutoplay
  }, [forceAutoplay])

  useEffect(() => {
    autoplayEnabledRef.current = autoplayEnabled
  }, [autoplayEnabled])

  useEffect(() => {
    pendingAutoplayUnmuteRef.current = pendingAutoplayUnmute
  }, [pendingAutoplayUnmute])

  const tryAutoplay = useCallback(async () => {
    if (watchPartyGuestRef.current) return
    if (!forceAutoplayRef.current && !autoplayEnabledRef.current) return

    const video = videoRef.current
    if (!video || !video.paused) return
    if (video.readyState < HTMLMediaElement.HAVE_METADATA) return

    setAutoplayBlocked(false)

    const finishMutedAutoplay = async () => {
      if (!video.muted) {
        video.muted = true
        setIsMuted(true)
      }
      await video.play()
      markVideoAutoplayUnlocked()
      setPendingAutoplayUnmute(true)
      queueAutoplayUnmute(video)
      const unmuted = await attemptAutoplayUnmute(video, {
        allowWithoutGesture: true,
        volume: volumeRef.current > 0 ? volumeRef.current : 1,
      })
      if (unmuted) {
        applyAutoplayUnmute()
      }
    }

    if (canAttemptAutoplayWithSound()) {
      try {
        video.muted = false
        setIsMuted(false)
        setPendingAutoplayUnmute(false)
        await video.play()
        return
      } catch {
        // fall through to muted autoplay
      }
    }

    try {
      await finishMutedAutoplay()
    } catch {
      try {
        video.muted = false
        setIsMuted(false)
        setPendingAutoplayUnmute(false)
        await video.play()
      } catch {
        setAutoplayBlocked(true)
        setPendingAutoplayUnmute(false)
      }
    }
  }, [applyAutoplayUnmute, queueAutoplayUnmute])

  useEffect(() => {
    tryAutoplayRef.current = tryAutoplay
  }, [tryAutoplay])

  const handleAutoplayChange = useCallback(
    (enabled: boolean) => {
      setAutoplayEnabled(enabled)
      if (user) {
        void updateProfile({ autoplayEnabled: enabled }).then((error) => {
          if (error) setAutoplayEnabled(!enabled)
        })
      }
    },
    [updateProfile, user]
  )

  useEffect(() => {
    if (forceAutoplay) {
      setAutoplayEnabled(true)
      return
    }
    if (user) {
      setAutoplayEnabled(user.autoplayEnabled)
    }
  }, [user?.id, user?.autoplayEnabled, forceAutoplay])

  useLayoutEffect(() => {
    if (watchPartyGuest) return
    if (!forceAutoplay && !autoplayEnabled) return

    const video = videoRef.current
    if (!video) return

    void unlockVideoElementForAutoplay(video)
  }, [autoplayEnabled, forceAutoplay, watchPartyGuest])

  useEffect(() => {
    setAutoplayBlocked(false)
  }, [source.id])
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [episodesOpen, setEpisodesOpen] = useState(false)
  const menuOpenRef = useRef(false)
  const [selectedSubtitleId, setSelectedSubtitleId] = useState<
    string | undefined
  >(undefined)
  const initialAudioTracks = ensureAudioTracks(
    audioTracks ?? EMPTY_AUDIO_TRACKS
  )
  const [selectedAudioTrackId, setSelectedAudioTrackId] = useState<
    string | undefined
  >(
    () =>
      pickPreferredAudioTrackFromList(initialAudioTracks) ??
      initialAudioTracks[0]?.id
  )
  const [availableAudioTracks, setAvailableAudioTracks] =
    useState<Array<{ id: string; label: string }>>(initialAudioTracks)

  const handleSelectAudioTrack = useCallback((trackId: string) => {
    userPickedAudioTrackRef.current = true
    setSelectedAudioTrackId(trackId)
  }, [])

  const hasAppliedContinueRef = useRef(false)
  /** Opening resume/URL continue may only apply once per media+episode; remounts use live position. */
  const continueAppliedSessionKeyRef = useRef("")
  const onRefetchSourcesRef = useRef(onRefetchSources)
  const onBufferStallRef = useRef(onBufferStall)
  // STALL_AUTO_RECOVERY
  const stallRecoveryAtRef = useRef(0)
  const stallRecoveryTimerRef = useRef<number | null>(null)
  const proxyRecoveryAtRef = useRef(0)
  const [proxyRecoveryResumeTime, setProxyRecoveryResumeTime] = useState<
    number | undefined
  >()

  useEffect(() => {
    onRefetchSourcesRef.current = onRefetchSources
  }, [onRefetchSources])

  useEffect(() => {
    onBufferStallRef.current = onBufferStall
  }, [onBufferStall])

  useEffect(() => {
    // RESUME_TIME_PROP_SYNC
    if (typeof resumeTime === "number" && resumeTime > 0.5) {
      if (skipPlaybackTimeRef.current < 0.5 || Math.abs(skipPlaybackTimeRef.current - resumeTime) > 1) {
        skipPlaybackTimeRef.current = resumeTime
        setCurrentTime(resumeTime)
      }
    }
  }, [resumeTime])

  const continueSessionKey = useMemo(() => {
    if (!watchMeta?.id) return ""
    if (watchMeta.type === "tv") {
      return `tv:${watchMeta.id}:${watchMeta.season ?? 0}:${
        watchMeta.episode ?? 0
      }`
    }
    return `movie:${watchMeta.id}`
  }, [watchMeta?.id, watchMeta?.type, watchMeta?.season, watchMeta?.episode])

  const continueTime = useMemo(() => {
    if (
      typeof proxyRecoveryResumeTime === "number" &&
      proxyRecoveryResumeTime > 0
    ) {
      return proxyRecoveryResumeTime
    }

    if (watchPartyGuest) {
      const anchor = syncPlayback?.anchorTime ?? syncPlayback?.currentTime ?? 0
      if (anchor > 0) {
        return anchor
      }
      if (typeof resumeTime === "number" && resumeTime > 0) {
        return resumeTime
      }
      return 0
    }

    // Opening resume already applied for this episode — remounts must not seek back to it.
    if (
      continueSessionKey &&
      continueAppliedSessionKeyRef.current === continueSessionKey
    ) {
      const preserved = skipPlaybackTimeRef.current
      return preserved > 0.5 ? preserved : 0
    }

    if (typeof resumeTime === "number" && resumeTime > 0) {
      return resumeTime
    }

    const urlContinue = Number(
      searchParams.get("startAt") ?? searchParams.get("continue") ?? "0"
    )
    if (Number.isFinite(urlContinue) && urlContinue > 0) {
      return urlContinue
    }
    return 0
  }, [
    watchPartyGuest,
    syncPlayback?.anchorTime,
    syncPlayback?.currentTime,
    resumeTime,
    searchParams,
    proxyRecoveryResumeTime,
    continueSessionKey,
  ])

  useWatchPartyGuestVideoSync({
    videoRef,
    enabled: watchPartyGuest && Boolean(syncPlayback),
    syncPlayback,
    onCurrentTime: setCurrentTime,
    onIsPlaying: setIsPlaying,
  })

  const playbackProxyOrigin = useMemo(
    () => resolveClientPlaybackProxyOrigin(),
    []
  )

  const selectedSubtitle = useMemo(
    () => subtitles.find((subtitle) => subtitle.id === selectedSubtitleId),
    [selectedSubtitleId, subtitles]
  )

  const handleSubtitleLoadError = useCallback(
    (failedUrl: string) => {
      const currentIndex = subtitles.findIndex(
        (subtitle) => subtitle.src === failedUrl
      )
      const fallback = subtitles.find((_, idx) => idx > currentIndex)
      if (fallback) {
        setSelectedSubtitleId(fallback.id)
      } else {
        setSelectedSubtitleId(undefined)
      }
    },
    [subtitles]
  )

  useEffect(() => {
    menuOpenRef.current = settingsOpen || episodesOpen
    if (settingsOpen || episodesOpen) {
      setShowControls(true)
      if (controlsTimeoutRef.current) {
        window.clearTimeout(controlsTimeoutRef.current)
        controlsTimeoutRef.current = null
      }
    }
  }, [settingsOpen, episodesOpen])

  useEffect(() => {
    playbackErrorRef.current = onPlaybackError
  }, [onPlaybackError])

  useEffect(() => {
    playbackReadyRef.current = onPlaybackReady
  }, [onPlaybackReady])

  useEffect(() => {
    onPlaybackTimeRef.current = onPlaybackTime
  }, [onPlaybackTime])

  useEffect(() => {
    audioTracksRef.current = audioTracks
  }, [audioTracks])

  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    const playbackUrlForGate = normalizeCinemacityCdnPlaybackUrl(source.url)
    const needsPlaybackToken = requiresPlaybackTokenUrl(playbackUrlForGate)

    const resolveRequestToken = (href: string) => {
      if (playbackTokenRef.current) {
        return playbackTokenRef.current
      }

      try {
        return (
          extractPlaybackToken(new URL(href, window.location.origin)) ??
          undefined
        )
      } catch {
        return undefined
      }
    }

    const initialPlaybackToken = resolveRequestToken(playbackUrlForGate)

    if (
      needsPlaybackToken &&
      !initialPlaybackToken &&
      !source.directClientPlayback
    ) {
      return
    }

    const sourceKey = `${source.id}|${source.type}`
    const streamBase = playbackUrlWithoutToken(playbackUrlForGate)
    const isSameStream =
      activeSourceKeyRef.current === sourceKey &&
      activeStreamBaseRef.current === streamBase

    if (
      isSameStream &&
      (hlsRef.current || video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA)
    ) {
      if (playbackToken) {
        playbackTokenRef.current = playbackToken
      }
      return
    }

    userPickedAudioTrackRef.current = false
    audioTracksReadyForSourceRef.current = false

    const bootstrapTracks = ensureAudioTracks(
      audioTracksRef.current ?? EMPTY_AUDIO_TRACKS
    )
    setAvailableAudioTracks(bootstrapTracks)
    setSelectedAudioTrackId(
      pickPreferredAudioTrackFromList(bootstrapTracks) ?? bootstrapTracks[0]?.id
    )

    activeSourceKeyRef.current = sourceKey
    activeStreamBaseRef.current = streamBase
    hasAppliedContinueRef.current = false
    hasNotifiedPlaybackStartedRef.current = false
    hasNotifiedPlaybackEndedRef.current = false
    streamDurationRef.current = 0
    if (!(watchMeta?.runtimeMinutes && watchMeta.runtimeMinutes > 0)) {
      expectedDurationRef.current = 0
    }

    if (sourceTimeoutRef.current) {
      window.clearTimeout(sourceTimeoutRef.current)
      sourceTimeoutRef.current = null
    }
    if (startupProgressTimeoutRef.current) {
      window.clearTimeout(startupProgressTimeoutRef.current)
      startupProgressTimeoutRef.current = null
    }

    video.pause()
    video.removeAttribute("src")
    video.load()

    const preservedTime = Math.max(
      skipPlaybackTimeRef.current,
      video.currentTime || 0
    )

    setIsLoading(true)
    setIsPlaying(false)
    setCurrentTime((previous) =>
      preservedTime > 0.5 ? preservedTime : previous > 0.5 ? previous : 0
    )
    skipPlaybackTimeRef.current =
      preservedTime > 0.5 ? preservedTime : skipPlaybackTimeRef.current > 0.5
        ? skipPlaybackTimeRef.current
        : 0
    if (expectedDurationRef.current >= MIN_TRUSTED_DURATION_SEC) {
      setDuration(expectedDurationRef.current)
    } else {
      setDuration(0)
    }
    clearAutoplayUnmuteSchedule()
    setPendingAutoplayUnmute(false)

    const playbackUrl = normalizeCinemacityCdnPlaybackUrl(source.url)
    const isDirectClientPlayback = Boolean(source.directClientPlayback)
    const directHeaders = source.clientPlaybackHeaders ?? {}
    const decodedProxy = isDirectClientPlayback
      ? null
      : decodeCineproProxyPayloadFromHref(playbackUrl)
    if (isDirectClientPlayback && Object.keys(directHeaders).length > 0) {
      proxyHeadersRef.current = directHeaders
    } else if (decodedProxy?.headers) {
      proxyHeadersRef.current = decodedProxy.headers
    }
    const isVidLinkSource = playbackUrl.includes("/api/vidlink/")
    const isCinemacityCdn = playbackUrl.includes("cccdn.net")
    const cinemacityReferer =
      source.cinemacityPageUrl?.trim() || "https://cinemacity.cc/"
    const isFourKHls = playbackUrl.includes("/api/4k/hls/")
    const isFourKHlsTranscode = isFourKHlsTranscodeUrl(playbackUrl)
    const fourKStartupTimeoutMs = fourKHlsStartupTimeoutMs(playbackUrl)
    const isProxiedCinepro = playbackUrl.includes("/api/cinepro/proxy")
    const sourceLoadTimeoutMs = isFourKHls
      ? fourKStartupTimeoutMs
      : isVidLinkSource
      ? 20_000
      : isProxiedCinepro
      ? Math.max(SOURCE_METADATA_TIMEOUT_MS, 14_000)
      : SOURCE_METADATA_TIMEOUT_MS
    const startupFailMs = isVidLinkSource
      ? 20_000
      : isProxiedCinepro
      ? Math.max(STARTUP_PLAYBACK_FAIL_MS, 18_000)
      : STARTUP_PLAYBACK_FAIL_MS

    sourceTimeoutRef.current = window.setTimeout(() => {
      if (video.readyState >= HTMLMediaElement.HAVE_METADATA) {
        return
      }

      reportPlaybackError(
        isFourKHlsTranscode
          ? t("player.errors.fourKTranscodeTimedOut")
          : t("player.errors.sourceTimedOut")
      )
    }, sourceLoadTimeoutMs)

    // Separate from metadata probe: a dead HLS manifest can clear the probe
    // above while the first segment never starts. Give proxied CDNs (VAPlayer)
    // enough time for 429 backoff + first fragment before auto-fallback.
    if (!isFourKHls && preservedTime < 0.5) {
      const failColdStartIfStuck = (allowGrace: boolean) => {
        const videoEl = videoRef.current
        if (!videoEl) return
        if (skipPlaybackTimeRef.current >= 0.5 || videoEl.currentTime >= 0.25) {
          return
        }
        if (
          videoEl.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA &&
          !videoEl.paused
        ) {
          return
        }
        // Still downloading / already buffered some media — one grace window.
        if (
          allowGrace &&
          (videoEl.buffered.length > 0 ||
            videoEl.networkState === HTMLMediaElement.NETWORK_LOADING)
        ) {
          startupProgressTimeoutRef.current = window.setTimeout(() => {
            failColdStartIfStuck(false)
          }, 6_000)
          return
        }
        reportPlaybackError(t("player.errors.sourceTimedOut"))
      }

      startupProgressTimeoutRef.current = window.setTimeout(() => {
        failColdStartIfStuck(true)
      }, startupFailMs)
    }

    let directFileCancelled = false

    const applyFourKSourceDuration = (seconds: number | undefined) => {
      if (!seconds || !Number.isFinite(seconds) || seconds <= 0) return
      applyExpectedDuration(seconds)
      applyResolvedDuration(
        streamDurationRef.current,
        video.duration,
        video.currentTime,
        true
      )
    }

    if (isFourKHls) {
      const tmdbId = parseTmdbIdFromFourKUrl(playbackUrl)
      if (tmdbId && expectedDurationRef.current < MIN_TRUSTED_DURATION_SEC) {
        void fetch(`/api/movie/${tmdbId}`, { cache: "force-cache" })
          .then((response) => (response.ok ? response.json() : null))
          .then((detail) => {
            const runtime = Number(detail?.runtime)
            if (Number.isFinite(runtime) && runtime > 0) {
              applyFourKSourceDuration(runtime * 60)
            }
          })
          .catch(() => undefined)
      }

      void (async () => {
        try {
          const requestToken =
            resolveRequestToken(playbackUrl) ||
            resolveRequestToken(playbackTokenRef.current ?? "")
          const manifestUrl = resolveProxiedMediaRequestUrl(
            playbackUrl,
            requestToken,
            proxyHeadersRef.current,
            playbackProxyOrigin
          )
          const response = await fetch(manifestUrl, {
            credentials: "omit",
            headers: {
              Referer: window.location.href,
            },
          })
          if (!response.ok) return

          const headerDuration = Number(
            response.headers.get("X-Chillflix-Source-Duration")
          )
          if (Number.isFinite(headerDuration) && headerDuration > 0) {
            applyFourKSourceDuration(headerDuration)
            return
          }

          const manifest = await response.text()
          applyFourKSourceDuration(parseChillflixSourceDurationTag(manifest))
        } catch {
          // ignore — LEVEL_LOADED / metadata will still grow duration
        }
      })()
    }

    if (source.type === "hls" && Hls.isSupported()) {
      let fatalNetworkRetries = 0
      let fatalMediaRetries = 0
      const hls = new Hls({
        enableWorker: !isFourKHls,
        lowLatencyMode: false,
        ...(isFourKHls ? {} : { audioPreference: { lang: "en" } }),
        capLevelToPlayerSize: isVidLinkSource,
        startFragPrefetch: isVidLinkSource,
        manifestLoadingTimeOut: isFourKHls
          ? fourKStartupTimeoutMs
          : isVidLinkSource
          ? 45_000
          : 30_000,
        manifestLoadingMaxRetry: isFourKHls ? 1 : isVidLinkSource ? 0 : 3,
        levelLoadingTimeOut: isFourKHls
          ? fourKStartupTimeoutMs
          : isVidLinkSource
          ? 45_000
          : 30_000,
        levelLoadingMaxRetry: isFourKHls ? 2 : 3,
        fragLoadingTimeOut: isFourKHls
          ? fourKStartupTimeoutMs
          : isVidLinkSource
          ? 45_000
          : 30_000,
        fragLoadingMaxRetry: isFourKHls ? 3 : 3,
        fetchSetup: (context, initParams) => {
          const requestUrl = normalizeCinemacityCdnPlaybackUrl(context.url)

          if (isDirectClientPlayback) {
            const headers = new Headers(initParams.headers)
            for (const [key, value] of Object.entries(directHeaders)) {
              if (key.toLowerCase() === "cookie") continue
              headers.set(key, value)
            }
            const directSetup = {
              ...initParams,
              url: requestUrl,
              headers,
            }
            if (requestUrl.includes("cccdn.net")) {
              return {
                ...directSetup,
                referrer: cinemacityReferer,
                referrerPolicy: "unsafe-url" as ReferrerPolicy,
              }
            }
            return directSetup
          }

          const requestToken =
            resolveRequestToken(requestUrl) || resolveRequestToken(playbackUrl)
          const resolvedUrl = resolveProxiedMediaRequestUrl(
            requestUrl,
            requestToken,
            proxyHeadersRef.current,
            playbackProxyOrigin
          )

          if (requestUrl.includes("cccdn.net")) {
            return {
              ...initParams,
              url: resolvedUrl,
              referrer: cinemacityReferer,
              referrerPolicy: "unsafe-url",
            }
          }

          if (resolvedUrl !== context.url) {
            return { ...initParams, url: resolvedUrl }
          }

          return initParams
        },
        xhrSetup: (xhr, url) => {
          const requestUrl = normalizeCinemacityCdnPlaybackUrl(url)

          if (isDirectClientPlayback) {
            xhr.open("GET", requestUrl, true)
            for (const [key, value] of Object.entries(directHeaders)) {
              xhr.setRequestHeader(key, value)
            }
            return
          }

          const requestToken =
            resolveRequestToken(requestUrl) || resolveRequestToken(playbackUrl)
          const resolvedUrl = resolveProxiedMediaRequestUrl(
            requestUrl,
            requestToken,
            proxyHeadersRef.current,
            playbackProxyOrigin
          )

          if (resolvedUrl !== url) {
            xhr.open("GET", resolvedUrl, true)
            return
          }

          if (requestUrl.includes("cccdn.net")) {
            return
          }
        },
      })

      hls.loadSource(playbackUrl)
      hls.attachMedia(video)
      hlsRef.current = hls

      const acceptStreamDuration = (seconds: number) =>
        Number.isFinite(seconds) &&
        seconds >= MIN_TRUSTED_DURATION_SEC &&
        seconds !== Infinity

      hls.on(Hls.Events.MEDIA_ENDED, () => {
        if (video.currentTime >= MIN_PLAYBACK_BEFORE_END_CHECK_SEC) {
          notifyPlaybackEndedRef.current()
        }
      })

      hls.on(Hls.Events.LEVEL_LOADED, (_, data) => {
        const total = data.details?.totalduration ?? 0
        if (typeof total === "number" && acceptStreamDuration(total)) {
          streamDurationRef.current = total
        }

        applyResolvedDuration(
          typeof total === "number" ? total : streamDurationRef.current,
          video.duration,
          video.currentTime,
          isFourKHls
        )
      })

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        if (sourceTimeoutRef.current) {
          window.clearTimeout(sourceTimeoutRef.current)
          sourceTimeoutRef.current = null
        }

        if (isVidLinkSource && hls.levels.length > 1) {
          const preferredLevel = hls.levels.findIndex(
            (level) => level.height > 0 && level.height <= 720
          )
          if (preferredLevel >= 0) {
            hls.startLevel = preferredLevel
          }
        }

        const levels = hls.levels.map((level, index) => ({
          index,
          height: level.height,
          label: `${level.height}p`,
        }))

        setQualities(levels)
        setCurrentQuality(hls.autoLevelEnabled ? -1 : hls.currentLevel)

        const tracks = hls.audioTracks.map((track, index) => ({
          id: String(index),
          label: trackLabel(index, track.name, track.lang),
        }))
        const safeTracks = ensureAudioTracks(tracks)
        setAvailableAudioTracks(safeTracks)
        if (hls.audioTrack >= 0) {
          setSelectedAudioTrackId(String(hls.audioTrack))
        }

        setIsLoading(false)
        void tryAutoplayRef.current?.()
      })

      hls.on(Hls.Events.AUDIO_TRACKS_UPDATED, (_, data) => {
        const tracks = (data.audioTracks ?? []).map((track, index) => ({
          id: String(index),
          label: trackLabel(index, track.name, track.lang),
        }))
        const safeTracks = ensureAudioTracks(tracks)
        setAvailableAudioTracks(safeTracks)

        if (userPickedAudioTrackRef.current) {
          setSelectedAudioTrackId((current) => {
            if (current && safeTracks.some((track) => track.id === current)) {
              return current
            }
            return pickPreferredHlsAudioTrackId(hls, safeTracks)
          })
          return
        }

        if (!audioTracksReadyForSourceRef.current) {
          audioTracksReadyForSourceRef.current = true
          const preferred = pickPreferredHlsAudioTrackId(hls, safeTracks)
          setSelectedAudioTrackId(preferred)
          const preferredIndex = Number(preferred)
          if (
            Number.isFinite(preferredIndex) &&
            preferredIndex >= 0 &&
            hls.audioTrack !== preferredIndex
          ) {
            hls.audioTrack = preferredIndex
          }
          return
        }

        setSelectedAudioTrackId((current) => {
          if (current && safeTracks.some((track) => track.id === current)) {
            return current
          }
          return pickPreferredHlsAudioTrackId(hls, safeTracks)
        })
      })

      hls.on(Hls.Events.AUDIO_TRACK_SWITCHED, (_, data) => {
        if (typeof data.id === "number") {
          setSelectedAudioTrackId(String(data.id))
        }
      })

      hls.on(Hls.Events.LEVEL_SWITCHED, (_, data) => {
        setCurrentQuality(hls.autoLevelEnabled ? -1 : data.level)
      })

      hls.on(Hls.Events.ERROR, (_, data) => {
        const httpCode = data.response?.code
        const isProxyPlaybackUrl =
          playbackUrl.includes("/api/cinepro/proxy") ||
          playbackUrl.includes("/api/flixhq/proxy")
        const isSegmentLoadError =
          data.details === Hls.ErrorDetails.MANIFEST_LOAD_ERROR ||
          data.details === Hls.ErrorDetails.LEVEL_LOAD_ERROR ||
          data.details === Hls.ErrorDetails.FRAG_LOAD_ERROR

        const needsProxyRefetch =
          isProxyRefetchHttpStatus(httpCode) &&
          isProxyPlaybackUrl &&
          isSegmentLoadError

        if (needsProxyRefetch && onRefetchSourcesRef.current) {
          const now = Date.now()
          if (now - proxyRecoveryAtRef.current > 45_000) {
            proxyRecoveryAtRef.current = now
            activeStreamBaseRef.current = ""
            setProxyRecoveryResumeTime(
              Math.max(
                0,
                video.currentTime || 0,
                skipPlaybackTimeRef.current || 0
              )
            )
            onRefetchSourcesRef.current()
            return
          }
        }

        const isTransientProxyError =
          isTransientProxyHttpStatus(httpCode) &&
          isProxyPlaybackUrl &&
          isSegmentLoadError

        if (isTransientProxyError) {
          if (
            video.paused ||
            video.readyState < HTMLMediaElement.HAVE_FUTURE_DATA
          ) {
            setIsLoading(true)
          }
          const resumeAt = Math.max(
            video.currentTime || 0,
            skipPlaybackTimeRef.current || 0
          )
          if (resumeAt > 0.5) {
            setCurrentTime(resumeAt)
          }
          if (fatalNetworkRetries < 8) {
            fatalNetworkRetries += 1
            window.setTimeout(() => {
              hls.startLoad(resumeAt > 0.5 ? resumeAt : -1)
            }, Math.min(4000, 400 * fatalNetworkRetries))
            return
          }
        }

        const isUnavailableResponse =
          typeof httpCode === "number" &&
          httpCode >= 400 &&
          (data.details === Hls.ErrorDetails.MANIFEST_LOAD_ERROR ||
            data.details === Hls.ErrorDetails.LEVEL_LOAD_ERROR ||
            data.details === Hls.ErrorDetails.FRAG_LOAD_ERROR)

        if (isUnavailableResponse) {
          const fourKHint =
            isFourKHls && httpCode === 503
              ? isFourKHlsTranscode
                ? " 2160p needs server transcode (disabled or busy). Try 1080p or wait a minute."
                : " 4K remux is disabled on the server."
              : isFourKHls && httpCode === 502
              ? " 4K transcode failed to start — try 1080p or retry."
              : ""
          const cinemacityHint =
            isCinemacityCdn && httpCode === 403
              ? " Cinemacity CDN blocked the server relay — set CINEMACITY_CCCDN_PROXY on the VPS (residential HTTP proxy), then refresh sources."
              : playbackUrl.includes("/api/cinepro/proxy") && httpCode === 403
              ? " Playback proxy was denied — refresh sources or check CINEMACITY_CCCDN_PROXY on the server."
              : playbackUrl.includes("/api/cinepro/proxy") &&
                (httpCode === 400 || httpCode === 404)
              ? " Stream relay expired — refreshing sources."
              : playbackUrl.includes("/api/flixhq/proxy") &&
                (httpCode === 400 || httpCode === 404)
              ? " Stream relay expired — refreshing sources."
              : ""
          reportPlaybackError(
            `Stream unavailable (${httpCode}).${fourKHint}${cinemacityHint}`
          )
          return
        }

        if (!data.fatal) return

        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
          if (fatalNetworkRetries < 5) {
            fatalNetworkRetries += 1
            setIsLoading(true)
            const resumeAt = Math.max(video.currentTime, skipPlaybackTimeRef.current)
            if (resumeAt > 0.5) {
              hls.startLoad(resumeAt)
            } else {
              hls.startLoad()
            }
            return
          }

          reportPlaybackError(t("player.errors.hlsNetwork"))
          return
        }

        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
          if (fatalMediaRetries < 4) {
            fatalMediaRetries += 1
            setIsLoading(true)
            hls.recoverMediaError()
            return
          }

          reportPlaybackError(t("player.errors.hlsMedia"))
          return
        }

        reportPlaybackError(`HLS fatal error: ${data.type}`)
      })
    } else {
      const fileAudioTracks = ensureAudioTracks(
        audioTracksRef.current ?? EMPTY_AUDIO_TRACKS
      )
      setQualities([])
      setCurrentQuality(-1)
      setAvailableAudioTracks(fileAudioTracks)
      setSelectedAudioTrackId(
        pickPreferredAudioTrackFromList(fileAudioTracks) ??
          fileAudioTracks[0]?.id
      )

      if (isDirectClientPlayback) {
        video.src = playbackUrl

        const onDirectError = () => {
          if (directFileCancelled) return

          void (async () => {
            try {
              const response = await fetch(playbackUrl, {
                headers: directHeaders,
                credentials: "omit",
              })
              if (!response.ok) {
                reportPlaybackError(`Stream unavailable (${response.status}).`)
                return
              }

              const blob = await response.blob()
              if (directFileCancelled) return
              video.src = URL.createObjectURL(blob)
            } catch {
              if (!directFileCancelled) {
                reportPlaybackError(t("player.errors.sourceTimedOut"))
              }
            }
          })()
        }

        video.addEventListener("error", onDirectError, { once: true })
      } else {
        video.src = playbackUrl
      }
    }

    return () => {
      directFileCancelled = true

      if (sourceTimeoutRef.current) {
        window.clearTimeout(sourceTimeoutRef.current)
        sourceTimeoutRef.current = null
      }
      if (startupProgressTimeoutRef.current) {
        window.clearTimeout(startupProgressTimeoutRef.current)
        startupProgressTimeoutRef.current = null
      }

      const next = sourcePropsRef.current
      const nextKey = `${next.id}|${next.type}`
      const nextStreamBase = playbackUrlWithoutToken(
        normalizeCinemacityCdnPlaybackUrl(next.url)
      )

      if (nextKey === sourceKey && nextStreamBase === streamBase) {
        return
      }

      if (hlsRef.current) {
        const hls = hlsRef.current
        hlsRef.current = null
        try {
          hls.stopLoad()
          hls.detachMedia()
          hls.destroy()
        } catch {
          // ignore teardown races during source swap
        }
      }

      if (activeSourceKeyRef.current === sourceKey) {
        activeSourceKeyRef.current = null
        activeStreamBaseRef.current = ""
      }
    }
  }, [
    clearAutoplayUnmuteSchedule,
    ensureAudioTracks,
    source.clientPlaybackHeaders,
    source.directClientPlayback,
    source.id,
    source.type,
    source.url,
    playbackToken,
    t,
    trackLabel,
    applyResolvedDuration,
    applyExpectedDuration,
    watchMeta?.runtimeMinutes,
  ])

  useWatchProgressSaver(
    watchMeta,
    videoRef,
    `${source.id}:s${watchMeta?.season ?? 0}:e${watchMeta?.episode ?? 0}`,
    nextTvEpisodeOnComplete
  )

  useEmbedPlayerBridge({
    enabled: embedReportingEnabled,
    watchMeta,
    mediaType: embedMediaType ?? watchMeta?.type ?? "movie",
    season: embedSeason ?? watchMeta?.season,
    episode: embedEpisode ?? watchMeta?.episode,
    videoRef,
  })

  usePlayerAnalytics({
    enabled: Boolean(watchMeta?.id),
    embedMode: embedReportingEnabled,
    mediaType: embedMediaType ?? watchMeta?.type ?? "movie",
    mediaId: watchMeta?.id ?? 0,
    season: embedSeason ?? watchMeta?.season,
    episode: embedEpisode ?? watchMeta?.episode,
    videoRef,
  })

  useEffect(() => {
    if (watchPartyGuest) return
    if (!forceAutoplay && !autoplayEnabled) return
    void tryAutoplay()
  }, [autoplayEnabled, forceAutoplay, watchPartyGuest, tryAutoplay])

  useEffect(() => {
    const hls = hlsRef.current
    if (!hls || selectedAudioTrackId === undefined) return

    const nextTrack = Number(selectedAudioTrackId)
    if (!Number.isFinite(nextTrack) || nextTrack < 0) return
    if (hls.audioTracks.length === 0 || nextTrack >= hls.audioTracks.length)
      return
    if (hls.audioTrack !== nextTrack) {
      hls.audioTrack = nextTrack
    }
  }, [selectedAudioTrackId])

  useEffect(() => {
    if (!selectedSubtitleId) return
    if (subtitles.length === 0) return
    if (!subtitles.some((subtitle) => subtitle.id === selectedSubtitleId)) {
      setSelectedSubtitleId(undefined)
    }
  }, [selectedSubtitleId, subtitles])

  const appliedSubtitlePrefRef = useRef(false)
  const subtitlesKeyRef = useRef("")

  useEffect(() => {
    const subtitlesKey = subtitles.map((subtitle) => subtitle.id).join("|")
    if (subtitlesKey !== subtitlesKeyRef.current) {
      subtitlesKeyRef.current = subtitlesKey
      appliedSubtitlePrefRef.current = false
    }

    if (appliedSubtitlePrefRef.current || subtitles.length === 0) return

    const { enabled, language } = getSubtitlePreferences()
    if (!enabled) return

    const match = findSubtitleForLanguage(subtitles, language)

    if (match) {
      appliedSubtitlePrefRef.current = true
      setSelectedSubtitleId(match.id)
    }
  }, [subtitles])

  useEffect(() => {
    if (!videoRef.current) return
    videoRef.current.playbackRate = playbackRate
  }, [playbackRate])

  useEffect(() => {
    volumeRef.current = volume
  }, [volume])

  useEffect(() => {
    if (!videoRef.current) return
    videoRef.current.muted = isMuted
    videoRef.current.volume = volume
  }, [isMuted, volume])

  useEffect(() => {
    hasAppliedContinueRef.current = false
    if (
      continueSessionKey &&
      continueAppliedSessionKeyRef.current &&
      continueAppliedSessionKeyRef.current !== continueSessionKey
    ) {
      continueAppliedSessionKeyRef.current = ""
      skipPlaybackTimeRef.current = 0
    }
  }, [source.id, continueSessionKey])

  useEffect(() => {
    hasNotifiedPlaybackEndedRef.current = false
  }, [watchMeta?.id, watchMeta?.season, watchMeta?.episode, source.id])

  useEffect(() => {
    const video = videoRef.current
    if (!video) return

    const applyContinueTime = () => {
      if (watchPartyGuestRef.current) return

      if (hasAppliedContinueRef.current) return
      if (video.readyState < HTMLMediaElement.HAVE_METADATA) return

      const isProxyRecovery =
        typeof proxyRecoveryResumeTime === "number" &&
        proxyRecoveryResumeTime > 0
      const alreadyAppliedOpening =
        Boolean(continueSessionKey) &&
        continueAppliedSessionKeyRef.current === continueSessionKey

      let target = 0
      if (isProxyRecovery) {
        target = proxyRecoveryResumeTime
      } else if (alreadyAppliedOpening) {
        // Remount mid-episode: keep live position; fall back to resumeTime if ref reset.
        target =
          skipPlaybackTimeRef.current > 0.5
            ? skipPlaybackTimeRef.current
            : Number.isFinite(continueTime) && continueTime > 0
              ? continueTime
              : 0
      } else if (Number.isFinite(continueTime) && continueTime > 0) {
        target = continueTime
      }

      if (target <= 0) {
        hasAppliedContinueRef.current = true
        if (continueSessionKey) {
          continueAppliedSessionKeyRef.current = continueSessionKey
        }
        return
      }

      const maxSeek = Number.isFinite(video.duration)
        ? video.duration - 1
        : target
      const appliedTime = Math.min(target, Math.max(0, maxSeek))
      video.currentTime = appliedTime
      skipPlaybackTimeRef.current = appliedTime
      hasAppliedContinueRef.current = true
      if (continueSessionKey) {
        continueAppliedSessionKeyRef.current = continueSessionKey
      }
      setProxyRecoveryResumeTime(undefined)
    }

    const handleLoadedMetadata = () => {
      if (sourceTimeoutRef.current) {
        window.clearTimeout(sourceTimeoutRef.current)
        sourceTimeoutRef.current = null
      }

      applyContinueTime()
      applyResolvedDuration(
        streamDurationRef.current,
        video.duration,
        video.currentTime,
        sourcePropsRef.current.url.includes("/api/4k/hls/")
      )
      setIsLoading(false)
      void tryAutoplayRef.current?.()
    }

    const handleDurationChange = () => {
      applyResolvedDuration(
        streamDurationRef.current,
        video.duration,
        video.currentTime,
        sourcePropsRef.current.url.includes("/api/4k/hls/")
      )
    }

    const getEffectiveDuration = (media: HTMLVideoElement) => {
      if (expectedDurationRef.current >= MIN_TRUSTED_DURATION_SEC) {
        return expectedDurationRef.current
      }

      const videoDuration = media.duration
      if (
        Number.isFinite(videoDuration) &&
        videoDuration >= MIN_TRUSTED_DURATION_SEC &&
        videoDuration !== Infinity
      ) {
        return videoDuration
      }

      const streamDuration = streamDurationRef.current
      if (streamDuration >= MIN_TRUSTED_DURATION_SEC) {
        return streamDuration
      }

      return NaN
    }

    const isNearPlaybackEnd = (media: HTMLVideoElement) => {
      if (media.ended) {
        return media.currentTime >= MIN_PLAYBACK_BEFORE_END_CHECK_SEC
      }

      if (media.currentTime < MIN_PLAYBACK_BEFORE_END_CHECK_SEC) {
        return false
      }

      const effectiveDuration = getEffectiveDuration(media)
      if (!Number.isFinite(effectiveDuration)) {
        return false
      }

      return media.currentTime >= effectiveDuration - 4
    }

    const notifyPlaybackEnded = () => {
      notifyPlaybackEndedRef.current()
    }

    const handleTimeUpdate = () => {
      const nextTime = video.currentTime
      skipPlaybackTimeRef.current = nextTime
      onPlaybackTimeRef.current?.(nextTime)

      if (nextTime > 0.5) {
        setHasPlayedOnce(true)
      }

      clearLoadingWhenPlaybackFlowing(video)

      const displayTime = Math.max(nextTime, skipPlaybackTimeRef.current)
      if (displayTime > 0 || needsPlaybackTimeUiRef.current) {
        setCurrentTime(displayTime)
      }

      applyResolvedDuration(
        streamDurationRef.current,
        video.duration,
        video.currentTime,
        sourcePropsRef.current.url.includes("/api/4k/hls/")
      )

      const meta = getPlaybackMeta(video)
      if (
        !hasNotifiedPlaybackStartedRef.current &&
        !canAutoFallbackPlayback(meta)
      ) {
        hasNotifiedPlaybackStartedRef.current = true
        playbackReadyRef.current?.(meta)
      }

      if (isNearPlaybackEnd(video)) {
        notifyPlaybackEnded()
      }
    }
    const handleEnded = () => {
      setIsPlaying(false)
      reportPlaybackSync()
      notifyPlaybackEnded()
    }
    const handlePlay = () => {
      setIsPlaying(true)
      reportPlaybackSync()
    }
    const handlePause = () => {
      setIsPlaying(false)
      reportPlaybackSync()
      if (isNearPlaybackEnd(video)) {
        notifyPlaybackEnded()
      }
    }
    const handleSeeked = () => {
      const nextTime = video.currentTime
      skipPlaybackTimeRef.current = nextTime
      onPlaybackTimeRef.current?.(nextTime)
      setCurrentTime(nextTime)
      reportPlaybackSync()
      clearLoadingWhenPlaybackFlowing(video)
    }
    const clearStallRecoveryTimer = () => {
      if (stallRecoveryTimerRef.current != null) {
        window.clearTimeout(stallRecoveryTimerRef.current)
        stallRecoveryTimerRef.current = null
      }
    }

    const scheduleStallRecovery = () => {
      const isColdStart = skipPlaybackTimeRef.current < 0.5
      // Mid-stream quiet recovery needs the stall callback; cold-start uses
      // reportPlaybackError so auto-fallback can leave a dead primary.
      if (!isColdStart && !onBufferStallRef.current) return
      if (stallRecoveryTimerRef.current != null) return
      const delayMs = isColdStart
        ? Math.max(
            STARTUP_PLAYBACK_FAIL_MS,
            sourcePropsRef.current.url.includes("/api/cinepro/proxy") ? 18_000 : 0
          )
        : 2200
      stallRecoveryTimerRef.current = window.setTimeout(() => {
        stallRecoveryTimerRef.current = null
        const videoEl = videoRef.current
        if (!videoEl) return
        // Still waiting / no future data → trigger source retest + resume
        if (
          videoEl.readyState >= HTMLMediaElement.HAVE_FUTURE_DATA &&
          !videoEl.paused &&
          videoEl.currentTime >= 0.25
        ) {
          return
        }
        if (isColdStart || skipPlaybackTimeRef.current < 0.5) {
          // Metadata can arrive while the first segment never plays. Give
          // proxied sources a real window before auto-fallback.
          reportPlaybackError(t("player.errors.sourceTimedOut"))
          return
        }
        const now = Date.now()
        // Allow another quiet recovery after ~18s — first stall worked, second must too.
        if (now - stallRecoveryAtRef.current < 18_000) return
        stallRecoveryAtRef.current = now
        onBufferStallRef.current?.()
      }, delayMs)
    }

    const handleWaiting = () => {
      setIsLoading(true)
      scheduleBufferingLabel()
      scheduleStallRecovery()
      if (skipPlaybackTimeRef.current > 0.5) {
        setCurrentTime(skipPlaybackTimeRef.current)
      }
    }
    const handlePlaying = () => {
      setIsLoading(false)
      hideBufferingLabel()
      clearStallRecoveryTimer()
      if (startupProgressTimeoutRef.current) {
        window.clearTimeout(startupProgressTimeoutRef.current)
        startupProgressTimeoutRef.current = null
      }
      // After real playback resumes, allow a future stall to recover again.
      if (stallRecoveryAtRef.current > 0) {
        stallRecoveryAtRef.current = Date.now() - 12_000
      }
      const video = videoRef.current
      if (!video?.muted || !pendingAutoplayUnmuteRef.current) return

      queueAutoplayUnmuteRef.current(video)
      void attemptAutoplayUnmute(video, {
        allowWithoutGesture: true,
        volume: volumeRef.current > 0 ? volumeRef.current : 1,
      }).then((unmuted) => {
        if (unmuted) {
          applyAutoplayUnmuteRef.current()
        }
      })
    }
    const handleCanPlay = () => {
      if (sourceTimeoutRef.current) {
        window.clearTimeout(sourceTimeoutRef.current)
        sourceTimeoutRef.current = null
      }
      clearLoadingWhenPlaybackFlowing(video)
      applyContinueTime()
      void tryAutoplayRef.current?.()
    }
    const handleEnterPiP = () => setIsPiP(true)
    const handleLeavePiP = () => setIsPiP(false)
    const handleError = () =>
      reportPlaybackError(t("player.errors.sourceFailed"))

    video.addEventListener("loadedmetadata", handleLoadedMetadata)
    video.addEventListener("durationchange", handleDurationChange)
    video.addEventListener("timeupdate", handleTimeUpdate)
    video.addEventListener("ended", handleEnded)
    video.addEventListener("play", handlePlay)
    video.addEventListener("pause", handlePause)
    video.addEventListener("seeked", handleSeeked)
    video.addEventListener("waiting", handleWaiting)
    video.addEventListener("playing", handlePlaying)
    video.addEventListener("canplay", handleCanPlay)
    video.addEventListener("enterpictureinpicture", handleEnterPiP)
    video.addEventListener("leavepictureinpicture", handleLeavePiP)
    video.addEventListener("error", handleError)

    const endCheckInterval = window.setInterval(() => {
      if (isNearPlaybackEnd(video)) {
        notifyPlaybackEnded()
      }
    }, 1000)

    return () => {
      window.clearInterval(endCheckInterval)
      video.removeEventListener("loadedmetadata", handleLoadedMetadata)
      video.removeEventListener("durationchange", handleDurationChange)
      video.removeEventListener("timeupdate", handleTimeUpdate)
      video.removeEventListener("ended", handleEnded)
      video.removeEventListener("play", handlePlay)
      video.removeEventListener("pause", handlePause)
      video.removeEventListener("seeked", handleSeeked)
      clearStallRecoveryTimer()
      video.removeEventListener("waiting", handleWaiting)
      video.removeEventListener("playing", handlePlaying)
      video.removeEventListener("canplay", handleCanPlay)
      video.removeEventListener("enterpictureinpicture", handleEnterPiP)
      video.removeEventListener("leavepictureinpicture", handleLeavePiP)
      video.removeEventListener("error", handleError)
    }
  }, [applyResolvedDuration, clearLoadingWhenPlaybackFlowing, hideBufferingLabel, reportPlaybackSync, scheduleBufferingLabel, source.id, t, watchPartyGuest])

  const handleMouseMove = useCallback(() => {
    setShowControls(true)
    if (controlsTimeoutRef.current) {
      clearTimeout(controlsTimeoutRef.current)
    }
    if (!menuOpenRef.current) {
      controlsTimeoutRef.current = window.setTimeout(
        () => setShowControls(false),
        3000
      )
    }
  }, [])

  useEffect(() => {
    if (!controlsWakeRef) return

    controlsWakeRef.current = handleMouseMove
    return () => {
      controlsWakeRef.current = null
    }
  }, [controlsWakeRef, handleMouseMove])

  const handlePointerDown = () => {
    if (watchPartyGuest) return

    const video = videoRef.current
    if (!video) return

    if (autoplayBlocked && video.paused) {
      setAutoplayBlocked(false)
      video.muted = false
      setIsMuted(false)
      void video
        .play()
        .catch(() =>
          playbackErrorRef.current?.(t("player.errors.sourceFailed"))
        )
      return
    }

    if (pendingAutoplayUnmute || (isMuted && !video.paused)) {
      applyAutoplayUnmute()
      return
    }
  }

  const togglePlay = () => {
    if (watchPartyGuest) return

    const video = videoRef.current
    if (!video) return

    if (video.paused) {
      setAutoplayBlocked(false)
      setPendingAutoplayUnmute(false)
      void video
        .play()
        .catch(() =>
          playbackErrorRef.current?.(t("player.errors.sourceFailed"))
        )
    } else {
      video.pause()
    }
  }

  const applyVideoSeek = useCallback(
    (timeSec: number) => {
      if (watchPartyGuest || !videoRef.current) return

      const video = videoRef.current
      const clamped = Math.max(0, Number.isFinite(timeSec) ? timeSec : 0)
      const maxSeek = duration > 1 ? Math.max(0, duration - 0.5) : clamped
      const target = Math.min(clamped, maxSeek)
      const wasPlaying = !video.paused && !video.ended

      // SEEK_FIX_NO_STARTLOAD: HLS.js loads the right segment from video.currentTime.
      // Do not call hls.startLoad() here — it resets the loader and floods the proxy on scrub.
      // Also do not force isLoading here — short seeks would flash Buffering on every click.
      video.currentTime = target
      skipPlaybackTimeRef.current = target
      onPlaybackTimeRef.current?.(target)
      setCurrentTime(target)
      reportPlaybackSync()

      if (wasPlaying) {
        void video.play().catch(() => undefined)
      }
    },
    [duration, reportPlaybackSync, watchPartyGuest]
  )

  const handleSeek = (val: number[]) => {
    applyVideoSeek(val[0])
  }

  const seekToTime = applyVideoSeek

  const getSkipPlaybackTime = useCallback(() => skipPlaybackTimeRef.current, [])

  const introSkipMediaType = embedMediaType ?? watchMeta?.type
  const introSkipTmdbId = watchMeta?.id
  const introSkipSeason = embedSeason ?? watchMeta?.season
  const introSkipEpisode = embedEpisode ?? watchMeta?.episode
  const introSkipSessionKey =
    introSkipMediaType === "tv"
      ? `tv:${introSkipTmdbId ?? 0}:${introSkipSeason ?? 0}:${
          introSkipEpisode ?? 0
        }`
      : `movie:${introSkipTmdbId ?? 0}`

  const { activeSegment: activeSkipSegment, skip: skipActiveSegment } =
    useIntroSkip({
      enabled: Boolean(
        introSkipTmdbId && introSkipMediaType && !watchPartyGuest
      ),
      mediaType: introSkipMediaType,
      tmdbId: introSkipTmdbId,
      season: introSkipSeason,
      episode: introSkipEpisode,
      getPlaybackTime: getSkipPlaybackTime,
      videoRef,
      duration,
      onSeek: seekToTime,
      playbackSessionKey: `${source.id}:${introSkipSessionKey}`,
    })

  const toggleMute = () => setIsMuted(!isMuted)

  const handleVolumeChange = (val: number[]) => {
    setVolume(val[0])
    if (val[0] === 0) {
      setIsMuted(true)
    } else if (isMuted) {
      setIsMuted(false)
    }
  }

  // Keep chrome (esp. settings) reachable during reconnect / cold load — do not
  // auto-hide controls while a status message is up or startup is still pending.
  const keepChromeVisible =
    Boolean(sourceStatusMessage) ||
    settingsOpen ||
    episodesOpen ||
    (isLoading && !hasPlayedOnce)

  const controlsOverlayVisible =
    showControls || !isPlaying || keepChromeVisible

  useEffect(() => {
    needsPlaybackTimeUiRef.current = controlsOverlayVisible
    if (controlsOverlayVisible && videoRef.current) {
      setCurrentTime(videoRef.current.currentTime)
    }
  }, [controlsOverlayVisible])

  useEffect(() => {
    onControlsOverlayVisibleChange?.(controlsOverlayVisible)
  }, [controlsOverlayVisible, onControlsOverlayVisibleChange])

  const getFullscreenTarget = () => {
    if (parentHostedEmbed) return null
    return fullscreenRootRef?.current ?? containerRef.current
  }

  useEffect(() => {
    if (!parentHostedEmbed) return

    const allowedParentOrigins = getEmbedAuthParentOrigins()

    const onMessage = (event: MessageEvent) => {
      if (!allowedParentOrigins.includes(event.origin)) return

      const type = event.data?.type
      if (type === EMBED_MESSAGE_WAKE_CHROME) {
        handleMouseMove()
        return
      }
      if (type !== EMBED_MESSAGE_FULLSCREEN_STATE) return

      const payload = event.data?.data as
        | EmbedFullscreenStatePayload
        | undefined
      const active = Boolean(payload?.active)
      setIsFullscreen(active)
      onFullscreenChange?.(active)
      if (!active) {
        unlockScreenOrientation()
      }
    }

    window.addEventListener("message", onMessage)
    return () => window.removeEventListener("message", onMessage)
  }, [handleMouseMove, parentHostedEmbed, onFullscreenChange])

  useEffect(() => {
    if (parentHostedEmbed) return

    const handleFullscreenChange = () => {
      const target = fullscreenRootRef?.current ?? containerRef.current
      const active =
        isElementFullscreen(target) || isElementFullscreen(containerRef.current)
      setIsFullscreen(active)
      onFullscreenChange?.(active)
      if (!active) {
        unlockScreenOrientation()
      }
    }

    const removeDocumentListener = addDocumentFullscreenChangeListener(
      handleFullscreenChange
    )

    const video = videoRef.current
    const removeVideoListeners = video
      ? addIosVideoFullscreenListeners(
          video,
          () => {
            setIsFullscreen(true)
            onFullscreenChange?.(true)
            void tryLockLandscapeOrientation()
          },
          () => {
            setIsFullscreen(false)
            onFullscreenChange?.(false)
            unlockScreenOrientation()
          }
        )
      : undefined

    return () => {
      removeDocumentListener()
      removeVideoListeners?.()
    }
  }, [fullscreenRootRef, onFullscreenChange, parentHostedEmbed, source.id])

  const toggleFullscreen = () => {
    if (parentHostedEmbed) {
      postEmbedMessage(EMBED_MESSAGE_TOGGLE_FULLSCREEN, {})
      return
    }

    const target = getFullscreenTarget()
    if (!target) return

    void (async () => {
      if (!isFullscreenActive()) {
        const success = await requestPlayerFullscreen(target, videoRef.current)
        if (success) {
          setIsFullscreen(true)
          onFullscreenChange?.(true)
          void tryLockLandscapeOrientation()
        } else {
          playbackErrorRef.current?.(t("player.errors.fullscreenFailed"))
        }
        return
      }

      const success = await exitPlayerFullscreen()
      if (success) {
        setIsFullscreen(false)
        onFullscreenChange?.(false)
        unlockScreenOrientation()
      } else {
        console.warn("[MediaPlayer] failed to exit fullscreen")
      }
    })()
  }

  const togglePictureInPicture = async () => {
    const video = videoRef.current
    if (!video) return

    try {
      if (document.pictureInPictureElement) {
        await document.exitPictureInPicture()
        setIsPiP(false)
      } else {
        await video.requestPictureInPicture()
        setIsPiP(true)
      }
    } catch {
      playbackErrorRef.current?.(t("player.errors.pipFailed"))
    }
  }

  const handleQualityChange = (level: number) => {
    if (!hlsRef.current) return
    hlsRef.current.currentLevel = level
    setCurrentQuality(level)
  }

  const resetVideoTransform = useCallback(() => {
    setVideoScale(VIDEO_ZOOM_MIN)
    setVideoTranslate({ x: 0, y: 0 })
    zoomGestureRef.current.mode = "idle"
    zoomGestureRef.current.moved = false
    setZoomGestureActive(false)
  }, [])

  useEffect(() => {
    videoScaleRef.current = videoScale
  }, [videoScale])

  useEffect(() => {
    videoTranslateRef.current = videoTranslate
  }, [videoTranslate])

  useEffect(() => {
    resetVideoTransform()
  }, [source.id, resetVideoTransform])

  const refreshSafeAreaInsets = useCallback(() => {
    safeAreaInsetsRef.current = resolvePanSafeAreaInsets(readSafeAreaInsets())
  }, [])

  useLayoutEffect(() => {
    refreshSafeAreaInsets()

    const handleViewportChange = () => {
      refreshSafeAreaInsets()
      applyVideoTransformRef.current(
        videoScaleRef.current,
        videoTranslateRef.current.x,
        videoTranslateRef.current.y
      )
    }

    window.addEventListener("resize", handleViewportChange)
    window.addEventListener("orientationchange", handleViewportChange)
    return () => {
      window.removeEventListener("resize", handleViewportChange)
      window.removeEventListener("orientationchange", handleViewportChange)
    }
  }, [refreshSafeAreaInsets])

  const applyVideoTransformRef = useRef<
    (scale: number, x: number, y: number) => void
  >(() => undefined)

  const applyVideoTransform = useCallback(
    (scale: number, x: number, y: number) => {
      const container = containerRef.current
      const bounds = container?.getBoundingClientRect()
      const nextScale = clampVideoScale(scale)

      if (nextScale <= VIDEO_ZOOM_MIN) {
        setVideoScale(VIDEO_ZOOM_MIN)
        setVideoTranslate({ x: 0, y: 0 })
        return
      }

      const clamped = bounds
        ? clampVideoTranslate(
            x,
            y,
            nextScale,
            bounds.width,
            bounds.height,
            safeAreaInsetsRef.current
          )
        : { x, y }

      setVideoScale(nextScale)
      setVideoTranslate(clamped)
    },
    []
  )

  useEffect(() => {
    applyVideoTransformRef.current = applyVideoTransform
  }, [applyVideoTransform])

  useEffect(() => {
    const el = videoTransformRef.current
    if (!el || watchPartyGuest) return

    const handleTouchStart = (event: TouchEvent) => {
      if (event.touches.length === 2) {
        const distance = getTouchDistance(event.touches[0], event.touches[1])
        if (distance <= 0) return

        zoomGestureRef.current = {
          mode: "pinch",
          startDistance: distance,
          startScale: videoScaleRef.current,
          startTranslateX: videoTranslateRef.current.x,
          startTranslateY: videoTranslateRef.current.y,
          lastPanX: 0,
          lastPanY: 0,
          moved: false,
        }
        setZoomGestureActive(true)
        return
      }

      if (
        event.touches.length === 1 &&
        videoScaleRef.current > VIDEO_ZOOM_MIN
      ) {
        const touch = event.touches[0]
        zoomGestureRef.current = {
          mode: "pan",
          startDistance: 0,
          startScale: videoScaleRef.current,
          startTranslateX: videoTranslateRef.current.x,
          startTranslateY: videoTranslateRef.current.y,
          lastPanX: touch.clientX,
          lastPanY: touch.clientY,
          moved: false,
        }
        setZoomGestureActive(true)
      }
    }

    const handleTouchMove = (event: TouchEvent) => {
      const gesture = zoomGestureRef.current
      if (gesture.mode === "idle") return

      event.preventDefault()

      if (gesture.mode === "pinch" && event.touches.length >= 2) {
        const distance = getTouchDistance(event.touches[0], event.touches[1])
        if (gesture.startDistance <= 0) return

        const ratio = distance / gesture.startDistance
        const nextScale = clampVideoScale(gesture.startScale * ratio)
        gesture.moved = gesture.moved || Math.abs(ratio - 1) > 0.02

        applyVideoTransform(
          nextScale,
          gesture.startTranslateX,
          gesture.startTranslateY
        )
        return
      }

      if (gesture.mode === "pan" && event.touches.length === 1) {
        const touch = event.touches[0]
        const dx = touch.clientX - gesture.lastPanX
        const dy = touch.clientY - gesture.lastPanY

        if (
          !gesture.moved &&
          (Math.abs(dx) > VIDEO_PAN_THRESHOLD_PX ||
            Math.abs(dy) > VIDEO_PAN_THRESHOLD_PX)
        ) {
          gesture.moved = true
        }

        gesture.lastPanX = touch.clientX
        gesture.lastPanY = touch.clientY

        applyVideoTransform(
          videoScaleRef.current,
          videoTranslateRef.current.x + dx,
          videoTranslateRef.current.y + dy
        )
      }
    }

    const finishGesture = () => {
      const gesture = zoomGestureRef.current
      if (gesture.mode === "idle") return

      if (gesture.moved) {
        suppressVideoTapRef.current = true
      }

      gesture.mode = "idle"
      gesture.moved = false
      setZoomGestureActive(false)
    }

    const handleTouchEnd = (event: TouchEvent) => {
      const gesture = zoomGestureRef.current

      if (gesture.mode === "pinch" && event.touches.length >= 2) {
        return
      }

      if (gesture.mode === "pan" && event.touches.length >= 1) {
        return
      }

      const wasMoved = gesture.moved

      if (gesture.mode !== "idle") {
        finishGesture()
      }

      if (event.touches.length > 0 || wasMoved) {
        return
      }

      const touch = event.changedTouches[0]
      if (!touch) return

      const now = Date.now()
      const lastTap = lastTapRef.current

      if (videoScaleRef.current > VIDEO_ZOOM_MIN) {
        const dt = now - lastTap.time
        const distance = Math.hypot(
          touch.clientX - lastTap.x,
          touch.clientY - lastTap.y
        )

        if (
          dt < VIDEO_DOUBLE_TAP_MS &&
          distance < VIDEO_DOUBLE_TAP_DISTANCE_PX
        ) {
          resetVideoTransform()
          suppressVideoTapRef.current = true
          suppressDoubleClickRef.current = true
          window.setTimeout(() => {
            suppressDoubleClickRef.current = false
          }, 400)
          lastTapRef.current = { time: 0, x: 0, y: 0 }
          return
        }
      }

      lastTapRef.current = { time: now, x: touch.clientX, y: touch.clientY }
    }

    const handleTouchCancel = () => {
      finishGesture()
    }

    el.addEventListener("touchstart", handleTouchStart, { passive: true })
    el.addEventListener("touchmove", handleTouchMove, { passive: false })
    el.addEventListener("touchend", handleTouchEnd, { passive: true })
    el.addEventListener("touchcancel", handleTouchCancel, { passive: true })

    return () => {
      el.removeEventListener("touchstart", handleTouchStart)
      el.removeEventListener("touchmove", handleTouchMove)
      el.removeEventListener("touchend", handleTouchEnd)
      el.removeEventListener("touchcancel", handleTouchCancel)
    }
  }, [applyVideoTransform, resetVideoTransform, watchPartyGuest])

  const handleVideoClick = () => {
    if (watchPartyGuest) return
    if (suppressVideoTapRef.current) {
      suppressVideoTapRef.current = false
      return
    }
    handleMouseMove()
  }

  const handleContainerDoubleClick = () => {
    if (watchPartyGuest) return
    if (suppressDoubleClickRef.current) return
    toggleFullscreen()
  }

  return (
    <div
      ref={containerRef}
      className="group relative size-full overflow-hidden bg-black"
      onPointerDown={watchPartyGuest ? undefined : handlePointerDown}
      onMouseMove={handleMouseMove}
      onMouseLeave={(event) => {
        if (menuOpenRef.current) return

        const related = event.relatedTarget
        const shell = fullscreenRootRef?.current
        if (related instanceof Node && shell?.contains(related)) {
          return
        }

        setShowControls(false)
      }}
      onDoubleClick={watchPartyGuest ? undefined : handleContainerDoubleClick}
    >
      <div
        ref={videoTransformRef}
        className="absolute origin-center will-change-transform"
        style={{
          top: "calc(-1 * env(safe-area-inset-top, 0px))",
          right: "calc(-1 * env(safe-area-inset-right, 0px))",
          bottom: "calc(-1 * env(safe-area-inset-bottom, 0px))",
          left: "calc(-1 * env(safe-area-inset-left, 0px))",
          transform: `translate(${videoTranslate.x}px, ${videoTranslate.y}px) scale(${videoScale})`,
          touchAction: zoomGestureActive ? "none" : "manipulation",
        }}
      >
        <video
          ref={videoRef}
          className="h-full w-full"
          onClick={watchPartyGuest ? undefined : handleVideoClick}
          playsInline
          muted={isMuted}
          preload="auto"
        />

        {selectedSubtitle ? (
          <CustomSubtitles
            url={selectedSubtitle.src}
            videoRef={videoRef}
            onLoadError={handleSubtitleLoadError}
          />
        ) : null}
      </div>

      {/*
        Blank <video> looks like a grey screen while we resolve/switch providers.
        Old condition hid this spinner whenever any sources existed — exactly when
        failover / “finding a server” happens. Always show chrome until first frames.
      */}
      {isLoading && !isPlaying && currentTime < 0.25 ? (
        <div className="pointer-events-none absolute inset-0 z-10 bg-black/60">
          {/*
            Keep the ring at true geometric center so it lines up with the play
            control. Status text sits below — do not center spinner+text as a column
            or the ring floats above the play icon.
          */}
          <div className="absolute inset-0 flex items-center justify-center">
            <div className="relative flex size-11 items-center justify-center">
              <div className="absolute inset-0 animate-spin rounded-full border-2 border-white/25 border-t-white" />
            </div>
          </div>
          <p className="absolute inset-x-0 top-[calc(50%+2.75rem)] px-4 text-center text-xs text-white/85 sm:text-sm">
            {sourcesLoadingMore || activeTestingProviderId
              ? t("player.status.findingStream")
              : t("player.loading.startingPlayback")}
          </p>
        </div>
      ) : null}

      {showBufferingLabel &&
      isLoading &&
      hasPlayedOnce &&
      isPlaying &&
      sources &&
      sources.length > 0 ? (
        <div className="pointer-events-none absolute inset-x-0 bottom-28 z-20 flex justify-center px-4">
          <span className="rounded-full bg-black/70 px-4 py-2 text-xs text-white/90">
            Buffering…
          </span>
        </div>
      ) : null}

      {autoplayBlocked && !isPlaying && !isLoading ? (
        <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/40">
          <span className="rounded-full bg-black/60 px-4 py-2 text-sm text-white">
            {t("player.tapToPlay")}
          </span>
        </div>
      ) : null}

      {pendingAutoplayUnmute && isPlaying && isMuted && !isLoading ? (
        <div className="pointer-events-none absolute left-4 top-4 z-20 rounded-full bg-black/60 px-3 py-1.5 text-xs text-white">
          {t("player.tapForSound")}
        </div>
      ) : null}

      {isFourKHlsPlaybackUrl(source.url) && isLoading && !isPlaying ? (
        <div className="pointer-events-none absolute bottom-24 left-0 right-0 z-30 px-4 text-center">
          <p className="mx-auto max-w-md rounded-full bg-black/60 px-4 py-2 text-xs text-white/85 sm:text-sm">
            {isFourKHlsTranscodeUrl(source.url)
              ? t("player.fourKTranscodeStarting")
              : t("player.fourKStarting")}
          </p>
        </div>
      ) : null}

      {sources && sources.length > 0 ? (
        <SourceStatusOverlay
          sources={sources}
          currentSourceId={source.id}
          sourcesLoadingMore={sourcesLoadingMore}
          sourceHealth={sourceHealth}
          activeTestingProviderId={activeTestingProviderId}
          unavailableProviders={unavailableProviders}
          isLoading={isLoading}
          isPlaying={isPlaying}
          hasPlaybackProgress={duration > 0.05 || currentTime > 0.5}
          statusInsetLeft={statusInsetLeft}
          showRealProviderNames={showRealProviderNames}
          isFullscreen={isFullscreen}
        />
      ) : null}

      {sourceStatusMessage && !sourcesLoadingMore ? (
        isTransientPlaybackStatusMessage(sourceStatusMessage) ||
        !shouldShowPlaybackRetryButton(sourceStatusMessage) ? (
          <div className="pointer-events-none absolute inset-x-0 bottom-24 z-30 flex justify-center px-4">
            <span className="rounded-full bg-black/70 px-4 py-2 text-xs text-white/90">
              {sourceStatusMessage}
            </span>
          </div>
        ) : onRefetchSources ? (
          <div className="pointer-events-none absolute inset-x-0 bottom-24 z-30 flex justify-center px-4">
            <div className="pointer-events-auto max-w-lg rounded-lg border border-amber-500/25 bg-black/80 px-3 py-2.5 shadow-lg backdrop-blur-sm">
              <PlaybackSourceRetryPanel
                message={sourceStatusMessage}
                onRetry={onRefetchSources}
                align="start"
                messageClassName="text-amber-100/90"
                showRetryButton={shouldShowPlaybackRetryButton(sourceStatusMessage)}
              />
            </div>
          </div>
        ) : (
          <div className="pointer-events-none absolute inset-x-0 bottom-24 z-30 flex justify-center px-4">
            <span className="rounded-full bg-black/70 px-4 py-2 text-xs text-white/90">
              {sourceStatusMessage}
            </span>
          </div>
        )
      ) : null}

      <PlayerControls
        isPlaying={
          watchPartyGuest ? Boolean(syncPlayback?.isPlaying) : isPlaying
        }
        isLoading={isLoading}
        watchPartyGuest={watchPartyGuest}
        onDoubleClick={toggleFullscreen}
        onWheel={(e: React.WheelEvent<HTMLDivElement>) => {
          const delta = -e.deltaY * 0.001
          const nextVolume = Math.max(0, Math.min(1, volume + delta))
          setVolume(nextVolume)
          if (nextVolume === 0) {
            setIsMuted(true)
          } else if (isMuted) {
            setIsMuted(false)
          }
        }}
        currentTime={currentTime}
        duration={duration}
        volume={volume}
        isMuted={isMuted}
        isFullscreen={isFullscreen}
        onTogglePlay={togglePlay}
        onSeek={handleSeek}
        onToggleMute={toggleMute}
        onVolumeChange={handleVolumeChange}
        onToggleFullscreen={toggleFullscreen}
        show={controlsOverlayVisible}
        onSettingsOpenChange={setSettingsOpen}
        onEpisodesOpenChange={setEpisodesOpen}
        isPiP={isPiP}
        onTogglePiP={togglePictureInPicture}
        playbackRate={playbackRate}
        onPlaybackRateChange={setPlaybackRate}
        qualities={qualities}
        currentQuality={currentQuality}
        onQualityChange={handleQualityChange}
        sources={sources}
        currentSourceId={source.id}
        onSelectSource={onSelectSource}
        onRequestProvider={onRequestProvider}
        sourcesLoadingMore={sourcesLoadingMore}
        sourceStatusMessage={sourceStatusMessage}
        onRefetchSources={onRefetchSources}
        unavailableProviders={unavailableProviders}
        sourceHealth={sourceHealth}
        activeTestingProviderId={activeTestingProviderId}
        subtitles={subtitles}
        currentSubtitleId={selectedSubtitleId}
        onSelectSubtitle={setSelectedSubtitleId}
        audioTracks={availableAudioTracks}
        currentAudioTrackId={selectedAudioTrackId}
        onSelectAudioTrack={handleSelectAudioTrack}
        autoplayEnabled={autoplayEnabled}
        onAutoplayChange={watchPartyGuest ? undefined : handleAutoplayChange}
        autoNextEnabled={autoNextEnabled}
        onAutoNextChange={onAutoNextChange}
        showAutoNext={showAutoNext && !watchPartyGuest}
        tvNavigation={
          watchPartyGuest || watchMeta?.type !== "tv" || !tvNavigation
            ? undefined
            : tvNavigation
        }
        externalSubtitlesLoading={externalSubtitlesLoading}
        showRealProviderNames={showRealProviderNames}
        hiddenProviderIds={hiddenProviderIds}
        mobileControlsTopInset={mobileControlsTopInset}
        skipSegment={
          activeSkipSegment && !watchPartyGuest
            ? {
                kind: activeSkipSegment.kind,
                onSkip: skipActiveSegment,
              }
            : null
        }
      />
    </div>
  )
}
