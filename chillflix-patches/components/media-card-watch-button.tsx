"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { Play } from "lucide-react"

import { LazySitePlayerModal } from "@/components/lazy-site-player-modal"
import { PlayerModalBoundary } from "@/components/player-modal-boundary"
import { useSiteFeatures } from "@/components/site-features"
import { prefetchSitePlayerAdsEnabled } from "@/hooks/useSitePlayerAdsEnabled"
import { useTranslations } from "@/lib/i18n/client"
import { notePlaybackUserGesture } from "@/lib/playback-gesture"
import { schedulePrefetchMediaSources } from "@/lib/prefetch-media-sources"
import { cn } from "@/lib/utils"
import { useClosePlaybackOnNavigate } from "@/hooks/use-close-playback-on-navigate"
import { useWatchProgressSync } from "@/hooks/use-watch-progress-sync"
import { resolveWatchPlayback } from "@/lib/watch-progress"
import type { MediaType } from "@/lib/media-types"

type MediaCardWatchButtonProps = {
  mediaType: MediaType
  mediaId: number
  title: string
  poster?: string | null
  runtimeMinutes?: number
  className?: string
  variant?: "meta" | "overlay"
  onOpenChange?: (open: boolean) => void
}

export function MediaCardWatchButton({
  mediaType,
  mediaId,
  title,
  poster,
  runtimeMinutes,
  className,
  variant = "meta",
  onOpenChange,
}: MediaCardWatchButtonProps) {
  const { t } = useTranslations()
  const { playersEnabled } = useSiteFeatures()
  const [isOpen, setIsOpen] = useState(false)
  const [hasMounted, setHasMounted] = useState(false)
  useEffect(() => {
    setHasMounted(true)
  }, [])
  const progressVersion = useWatchProgressSync()

  const playback = useMemo(() => {
    void progressVersion
    if (!hasMounted) {
      return {
        canResume: false,
        resumeTime: undefined as number | undefined,
        season: mediaType === "tv" ? 1 : undefined,
        episode: mediaType === "tv" ? 1 : undefined,
      }
    }
    return resolveWatchPlayback({
      mediaType,
      mediaId,
      title,
      poster: poster ?? undefined,
    })
  }, [hasMounted, mediaId, mediaType, poster, progressVersion, title])

  const sourceParams = {
    id: mediaId,
    type: mediaType,
    season: mediaType === "tv" ? playback.season : undefined,
    episode: mediaType === "tv" ? playback.episode : undefined,
  } as const

  const prefetchBeforePlay = useCallback(() => {
    schedulePrefetchMediaSources(sourceParams)
    void prefetchSitePlayerAdsEnabled()
  }, [sourceParams])

  const blockNavigation = (event: React.SyntheticEvent) => {
    event.preventDefault()
    event.stopPropagation()
  }

  const openPlayer = (event: React.MouseEvent) => {
    blockNavigation(event)
    notePlaybackUserGesture()
    prefetchBeforePlay()
    setIsOpen(true)
  }

  useClosePlaybackOnNavigate(() => setIsOpen(false), isOpen)

  useEffect(() => {
    onOpenChange?.(isOpen)
  }, [isOpen, onOpenChange])

  if (!playersEnabled) {
    return null
  }

  const buttonLabel = playback.canResume ? t("player.resume") : t("player.watch")
  const isMeta = variant === "meta"

  return (
    <>
      <button
        type="button"
        aria-label={buttonLabel}
        onClick={openPlayer}
        onPointerDown={blockNavigation}
        onMouseEnter={prefetchBeforePlay}
        onFocus={prefetchBeforePlay}
        className={cn(
          "pointer-events-auto",
          isMeta
            ? [
                "absolute right-2.5 bottom-2 z-30 md:right-3 md:bottom-2.5",
                "inline-flex items-center justify-center gap-0.5",
                "h-6 px-2 rounded-full",
                "bg-white/10 text-white backdrop-blur-sm",
                "border border-white/15",
                "text-[10px] font-semibold leading-none",
                "hover:bg-white/20 hover:border-white/25 transition-colors duration-200",
              ]
            : [
                "absolute right-2 bottom-[4.75rem] z-20",
                "inline-flex items-center justify-center gap-1",
                "h-8 px-2.5 rounded-full",
                "bg-black/70 text-white backdrop-blur-md",
                "border border-white/20 shadow-[0_4px_14px_rgba(0,0,0,0.45)]",
                "text-xs font-semibold",
                "hover:bg-black/85 hover:border-white/30 transition-colors duration-200",
              ],
          className
        )}
      >
        <Play className={cn("shrink-0 fill-current", isMeta ? "size-2.5" : "size-3.5")} />
        <span>{buttonLabel}</span>
      </button>

      {isOpen ? (
        <PlayerModalBoundary onClose={() => setIsOpen(false)}>
          <LazySitePlayerModal
            isOpen
            onClose={() => setIsOpen(false)}
            mediaId={mediaId}
            mediaType={mediaType}
            title={title}
            posterPath={poster ?? undefined}
            currentSeason={playback.season}
            currentEpisode={playback.episode}
            initialResumeTime={playback.resumeTime}
            runtimeMinutes={runtimeMinutes}
          />
        </PlayerModalBoundary>
      ) : null}
    </>
  )
}
