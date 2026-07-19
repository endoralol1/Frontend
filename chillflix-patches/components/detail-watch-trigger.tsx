"use client"

import { useEffect, useMemo, useState } from "react"
import { useSearchParams } from "next/navigation"
import { Play } from "lucide-react"

import { useTranslations } from "@/lib/i18n/client"
import { notePlaybackUserGesture } from "@/lib/playback-gesture"
import { schedulePrefetchMediaSources } from "@/lib/prefetch-media-sources"
import { cn } from "@/lib/utils"
import { resolveWatchPlayback } from "@/lib/watch-progress"
import { useWatchProgressSync } from "@/hooks/use-watch-progress-sync"
import { prefetchSitePlayerAdsEnabled } from "@/hooks/useSitePlayerAdsEnabled"
import { detailWatchButtonClass } from "@/components/detail-action-button-styles"
import {
  useDetailWatchOptional,
  type DetailWatchPlayerSession,
} from "@/components/detail-watch-context"
import { LazySitePlayerModal } from "@/components/lazy-site-player-modal"
import { PlayerModalBoundary } from "@/components/player-modal-boundary"
import { useSiteFeatures } from "@/components/site-features"

interface DetailWatchTriggerProps {
  mediaId: string | number
  mediaType: "movie" | "tv"
  title: string
  description?: string
  currentSeason?: number
  currentEpisode?: number
  posterPath?: string
  className?: string
  buttonClassName?: string
  showPlayIcon?: boolean
  prefer4k?: boolean
  runtimeMinutes?: number
  preferPlayLabel?: boolean
  unstyled?: boolean
  /** Parent-owned player (e.g. hero carousel must not unmount playback). */
  onRequestPlayback?: (session: DetailWatchPlayerSession) => void
  onPlaybackOpenChange?: (open: boolean) => void
}

export function DetailWatchTrigger({
  mediaId,
  mediaType,
  title,
  description,
  currentSeason,
  currentEpisode,
  posterPath,
  className,
  buttonClassName,
  showPlayIcon = false,
  prefer4k: prefer4kProp = false,
  runtimeMinutes,
  preferPlayLabel = false,
  unstyled = false,
  onRequestPlayback,
  onPlaybackOpenChange,
}: DetailWatchTriggerProps) {
  const { t } = useTranslations()
  const searchParams = useSearchParams()
  const prefer4k = prefer4kProp || searchParams.get("prefer4k") === "1"
  const [isOpen, setIsOpen] = useState(false)
  // Avoid Resume vs Play / season mismatches: localStorage watch progress is
  // unavailable on the server, so reading it during render causes React #418/#423.
  const [hasMounted, setHasMounted] = useState(false)
  useEffect(() => {
    setHasMounted(true)
  }, [])
  const progressVersion = useWatchProgressSync()
  const detailWatch = useDetailWatchOptional()
  const { playersEnabled } = useSiteFeatures()

  const playback = useMemo(() => {
    void progressVersion
    if (!hasMounted) {
      return {
        canResume: false,
        resumeTime: undefined as number | undefined,
        season: mediaType === "tv" ? currentSeason ?? 1 : undefined,
        episode: mediaType === "tv" ? currentEpisode ?? 1 : undefined,
      }
    }
    return resolveWatchPlayback({
      mediaType,
      mediaId: Number(mediaId),
      title,
      poster: posterPath,
      season: currentSeason,
      episode: currentEpisode,
    })
  }, [
    currentEpisode,
    currentSeason,
    hasMounted,
    mediaId,
    mediaType,
    posterPath,
    progressVersion,
    title,
  ])

  const resolvedSeason =
    mediaType === "tv" ? currentSeason ?? playback.season : undefined
  const resolvedEpisode =
    mediaType === "tv" ? currentEpisode ?? playback.episode : undefined

  const sourceParams = {
    id: mediaId,
    type: mediaType,
    season: mediaType === "tv" ? resolvedSeason : undefined,
    episode: mediaType === "tv" ? resolvedEpisode : undefined,
  } as const

  const prefetchBeforePlay = () => {
    schedulePrefetchMediaSources(sourceParams)
    void prefetchSitePlayerAdsEnabled()
  }

  const buttonLabel = playback.canResume
    ? t("player.resume")
    : preferPlayLabel
      ? t("player.play")
      : t("player.watch")

  const openPlayback = () => {
    notePlaybackUserGesture()
    prefetchBeforePlay()

    const session: DetailWatchPlayerSession = {
      mediaId,
      mediaType,
      title,
      description,
      currentSeason: resolvedSeason,
      currentEpisode: resolvedEpisode,
      posterPath,
      prefer4k,
      runtimeMinutes,
      initialResumeTime: playback.resumeTime,
    }

    if (detailWatch) {
      onPlaybackOpenChange?.(true)
      detailWatch.openPlayer(session)
      return
    }

    if (onRequestPlayback) {
      onPlaybackOpenChange?.(true)
      onRequestPlayback(session)
      return
    }

    onPlaybackOpenChange?.(true)
    setIsOpen(true)
  }

  const closePlayback = () => {
    onPlaybackOpenChange?.(false)
    setIsOpen(false)
  }

  if (!playersEnabled) {
    return null
  }

  return (
    <span className={cn("inline-flex", className)}>
      <button
        type="button"
        onClick={openPlayback}
        onMouseEnter={prefetchBeforePlay}
        onFocus={prefetchBeforePlay}
        className={
          unstyled
            ? cn(buttonClassName)
            : detailWatchButtonClass(buttonClassName)
        }
      >
        {showPlayIcon ? (
          <Play className="mr-2 size-4 fill-current" aria-hidden />
        ) : null}
        {buttonLabel}
      </button>

      {!detailWatch && !onRequestPlayback && isOpen ? (
        <PlayerModalBoundary onClose={closePlayback}>
          <LazySitePlayerModal
            isOpen
            onClose={closePlayback}
            mediaId={mediaId}
            mediaType={mediaType}
            title={title}
            description={description}
            currentSeason={resolvedSeason}
            currentEpisode={resolvedEpisode}
            posterPath={posterPath}
            prefer4k={prefer4k}
            runtimeMinutes={runtimeMinutes}
            initialResumeTime={playback.resumeTime}
          />
        </PlayerModalBoundary>
      ) : null}
    </span>
  )
}
