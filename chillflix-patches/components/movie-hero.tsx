"use client"

import { getMatchScore } from "@/lib/media-match-score"
import { useCallback, useEffect, useRef, useState } from "react"
import Image from "next/image"
import { pages } from "@/config"
import { wsrvImage } from "@/tmdb/utils"
import { Info } from "lucide-react"

import { isLowEndDevice } from "@/lib/device-profile"
import type { HeroMovie } from "@/lib/fetch-hero-movies"
import { useTranslations } from "@/lib/i18n/client"
import type { DetailWatchPlayerSession } from "@/components/detail-watch-context"
import { DetailWatchTrigger } from "@/components/detail-watch-trigger"
import { HeroTrailerBackdrop } from "@/components/hero-trailer-backdrop"
import { LazySitePlayerModal } from "@/components/lazy-site-player-modal"
import { MediaDetailLink } from "@/components/media-detail-link"
import { PlayerModalBoundary } from "@/components/player-modal-boundary"

interface MovieHeroProps {
  movies: HeroMovie[]
  label: string
  count?: number
  priority?: boolean
}

function formatHeroRuntime(minutes?: number): string | null {
  if (!minutes || minutes <= 0) return null
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60
  if (hours > 0) return `${hours}h ${mins.toString().padStart(2, "0")}m`
  return `${mins}m`
}

function getReleaseYear(releaseDate?: string): string | null {
  if (!releaseDate) return null
  const year = releaseDate.slice(0, 4)
  return /^\d{4}$/.test(year) ? year : null
}


export const MovieHero: React.FC<MovieHeroProps> = ({
  movies,
  count = 1,
  priority,
}) => {
  const [currentIndex, setCurrentIndex] = useState(0)
  const [lockedIndex, setLockedIndex] = useState<number | null>(null)
  const [playerSession, setPlayerSession] =
    useState<DetailWatchPlayerSession | null>(null)
  const progressRef = useRef<HTMLDivElement>(null)
  const hoverPausedRef = useRef(false)
  const pointerPausedRef = useRef(false)
  const playbackPausedRef = useRef(false)
  const { t } = useTranslations()
  const items = movies
  const activeIndex =
    lockedIndex ?? currentIndex % Math.max(items.length, 1)
  const item = items[activeIndex % items.length] ?? items[0]

  const pauseCarousel = useCallback(() => {
    hoverPausedRef.current = true
  }, [])

  const resumeCarousel = useCallback(() => {
    if (playbackPausedRef.current) return
    hoverPausedRef.current = false
  }, [])

  const pauseCarouselFromPointer = useCallback(() => {
    pointerPausedRef.current = true
  }, [])

  const resumeCarouselFromPointer = useCallback(() => {
    if (playbackPausedRef.current) return
    pointerPausedRef.current = false
  }, [])

  const handlePlaybackOpenChange = useCallback((open: boolean) => {
    playbackPausedRef.current = open
    if (open) {
      setLockedIndex(currentIndex)
      return
    }
    setLockedIndex(null)
    setPlayerSession(null)
  }, [currentIndex])

  const handleRequestPlayback = useCallback(
    (session: DetailWatchPlayerSession) => {
      playbackPausedRef.current = true
      setLockedIndex(currentIndex)
      setPlayerSession(session)
    },
    [currentIndex]
  )

  const closeHeroPlayer = useCallback(() => {
    playbackPausedRef.current = false
    setLockedIndex(null)
    setPlayerSession(null)
  }, [])

  useEffect(() => {
    if (progressRef.current) {
      progressRef.current.style.width = "0%"
    }
  }, [activeIndex])

  useEffect(() => {
    if (items.length <= 1 || isLowEndDevice()) return

    const duration = 20000
    const interval = 50
    const increment = (interval / duration) * 100

    const progressTimer = window.setInterval(() => {
      if (
        hoverPausedRef.current ||
        pointerPausedRef.current ||
        playbackPausedRef.current
      ) {
        return
      }

      const bar = progressRef.current
      if (!bar) return

      const currentWidth = Number.parseFloat(bar.style.width) || 0
      if (currentWidth >= 100) {
        bar.style.width = "0%"
        setCurrentIndex((i) => (i + 1) % items.length)
        return
      }

      bar.style.width = `${currentWidth + increment}%`
    }, interval)

    return () => window.clearInterval(progressTimer)
  }, [items.length])

  if (!item) {
    return (
      <div
        className="home-hero-skeleton h-[clamp(640px,88svh,820px)] w-full md:h-[750px]"
        aria-busy="true"
        aria-label="Loading"
      />
    )
  }

  const matchScore = getMatchScore(item.vote_average)
  const releaseYear = getReleaseYear(item.release_date)
  const runtimeLabel = formatHeroRuntime(item.runtime)
  const ratingLabel =
    item.vote_average > 0 ? item.vote_average.toFixed(1) : null
  const ageLabel = item.adult ? "18+" : "16+"

  return (
    <>
      <div
        className={`${
          count > 1 ? "h-hero-small" : "h-[clamp(600px,78svh,720px)] md:h-[750px]"
        } group relative isolate overflow-hidden bg-black`}
        onMouseEnter={pauseCarousel}
        onMouseLeave={resumeCarousel}
        onPointerDown={pauseCarouselFromPointer}
        onPointerUp={resumeCarouselFromPointer}
        onPointerCancel={resumeCarouselFromPointer}
        onPointerLeave={resumeCarouselFromPointer}
      >
        <HeroTrailerBackdrop
          key={item.id}
          variant="hero"
          disableTrailer
          className="z-0 border-0"
          backdropPath={item.backdrop_path}
          alt={item.title}
          priority={priority}
        />

        <div className="home-hero-overlay-linear pointer-events-none absolute inset-0 z-[1]" aria-hidden />
        <div className="home-hero-overlay-radial pointer-events-none absolute inset-0 z-[1]" aria-hidden />
        <div className="home-hero-grain pointer-events-none absolute inset-0 z-[1]" aria-hidden />

        <div className="home-hero-panel pointer-events-none absolute inset-0 z-10 flex items-end">
          <div className="pointer-events-auto w-full px-4 pb-10 pt-24 sm:px-5 md:max-w-[760px] md:px-12 md:pb-14">
            <div className="flex max-w-lg flex-col items-start text-left">
              {item.logo_path ? (
                <div
                  className="animate-fade-in-down mb-3 flex w-full items-end justify-start"
                  style={{ animationDelay: "0.18s" }}
                >
                  <Image
                    src={wsrvImage.logo(item.logo_path, "original")}
                    alt={item.title}
                    width={count > 1 ? 220 : 420}
                    height={count > 1 ? 70 : 130}
                    className="size-auto max-h-[88px] max-w-[78vw] object-contain object-left drop-shadow-[0_4px_18px_rgba(0,0,0,0.75)] md:max-h-[118px] md:max-w-[440px]"
                    priority={priority}
                    unoptimized
                  />
                </div>
              ) : (
                <h2
                  className="animate-fade-in-down mb-3 line-clamp-2 max-w-xl text-[2rem] font-bold leading-[1.02] tracking-[-0.03em] text-white drop-shadow-md md:text-5xl"
                  style={{ animationDelay: "0.18s" }}
                >
                  {item.title}
                </h2>
              )}

              <div className="animate-fade-in-down" style={{ animationDelay: "0.24s" }}>
                <div className="home-hero-meta">
                  {matchScore != null ? (
                    <span className="home-hero-meta-match">{matchScore}% Match</span>
                  ) : null}
                  {releaseYear ? <span className="home-hero-meta-item">{releaseYear}</span> : null}
                  {releaseYear ? <span className="home-hero-meta-dot" aria-hidden /> : null}
                  <span className="home-hero-meta-pill">{ageLabel}</span>
                  {runtimeLabel ? (
                    <>
                      <span className="home-hero-meta-dot" aria-hidden />
                      <span className="home-hero-meta-item">{runtimeLabel}</span>
                    </>
                  ) : null}
                  {ratingLabel ? (
                    <span className="home-hero-meta-pill">{ratingLabel}</span>
                  ) : null}
                </div>

                <p className="home-hero-desc mb-5 line-clamp-3 max-w-[420px] md:max-w-xl">
                  {item.overview}
                </p>

                <div className="flex w-full max-w-md items-center gap-2.5">
                  <DetailWatchTrigger
                    mediaId={item.id}
                    mediaType="movie"
                    title={item.title}
                    description={item.overview}
                    posterPath={item.poster_path}
                    runtimeMinutes={item.runtime}
                    className="min-w-0 flex-1"
                    buttonClassName="home-hero-play-btn"
                    showPlayIcon
                    preferPlayLabel
                    unstyled
                    onRequestPlayback={handleRequestPlayback}
                    onPlaybackOpenChange={handlePlaybackOpenChange}
                  />
                  <MediaDetailLink
                    href={`${pages.movie.root.link}/${item.id}`}
                    className="home-hero-details-btn"
                  >
                    <Info className="size-4 shrink-0" strokeWidth={2.25} />
                    {t("hero.details")}
                  </MediaDetailLink>
                </div>
              </div>
            </div>
          </div>
        </div>

        {items.length > 1 ? (
          <div
            className="hero-carousel-progress absolute bottom-4 left-5 z-20 h-[3px] w-20 overflow-hidden rounded-full bg-white/20 md:bottom-7 md:left-12 md:w-28"
            aria-hidden
          >
            <div
              ref={progressRef}
              className="h-full rounded-full bg-white/90 transition-all duration-75"
              style={{ width: "0%" }}
            />
          </div>
        ) : null}
      </div>

      {playerSession ? (
        <PlayerModalBoundary onClose={closeHeroPlayer}>
          <LazySitePlayerModal
            isOpen
            onClose={closeHeroPlayer}
            mediaId={playerSession.mediaId}
            mediaType={playerSession.mediaType}
            title={playerSession.title}
            description={playerSession.description}
            currentSeason={playerSession.currentSeason}
            currentEpisode={playerSession.currentEpisode}
            posterPath={playerSession.posterPath}
            prefer4k={playerSession.prefer4k}
            runtimeMinutes={playerSession.runtimeMinutes}
            initialResumeTime={playerSession.initialResumeTime}
          />
        </PlayerModalBoundary>
      ) : null}
    </>
  )
}
