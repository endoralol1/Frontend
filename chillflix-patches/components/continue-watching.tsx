"use client"

import { useCallback, useEffect, useState } from "react"
import { X, ArrowLeft, ArrowRight } from "lucide-react"
import { usePathname } from "next/navigation"

import { Button } from "@/components/ui/button"
import { HomeSectionHeader } from "@/components/home-section-header"
import {
    Carousel,
    CarouselApi,
    CarouselContent,
    CarouselItem,
} from "@/components/ui/carousel"
import { MediaPoster } from "@/components/media-poster"
import { LazySitePlayerModal } from "@/components/lazy-site-player-modal"
import { PlayerModalBoundary } from "@/components/player-modal-boundary"
import { useAuth } from "@/hooks/use-auth"
import { useClosePlaybackOnNavigate } from "@/hooks/use-close-playback-on-navigate"
import { useLocale, useTranslations } from "@/lib/i18n/client"
import { enrichWatchProgressTitles } from "@/lib/localize-media-client"
import { useSiteFeatures } from "@/components/site-features"
import { schedulePrefetchMediaSources } from "@/lib/prefetch-media-sources"
import { notePlaybackUserGesture } from "@/lib/playback-gesture"
import { prefetchSitePlayerAdsEnabled } from "@/hooks/useSitePlayerAdsEnabled"
import {
    CONTINUE_WATCHING_UPDATED_EVENT,
    loadContinueWatching,
    loadContinueWatchingRemote,
    removeWatchProgress,
    type WatchProgressItem,
} from "@/lib/watch-progress"

function itemKey(item: WatchProgressItem) {
    if (item.type === "tv") {
        return `${item.type}-${item.id}-${item.season}-${item.episode}`
    }
    return `${item.type}-${item.id}`
}

function playerSessionKey(item: WatchProgressItem) {
    return `${item.type}-${item.id}`
}

interface ContinueWatchingProps {
    embedded?: boolean
    emptyMessage?: string
}

export function ContinueWatching({
    embedded = false,
    emptyMessage,
}: ContinueWatchingProps = {}) {
    const [items, setItems] = useState<WatchProgressItem[]>([])
    const [activeItem, setActiveItem] = useState<WatchProgressItem | null>(null)
    const [api, setApi] = useState<CarouselApi>()
    const [total, setTotal] = useState(0)
    const [current, setCurrent] = useState(0)
    const pathname = usePathname()
    const { user } = useAuth()
    const locale = useLocale()
    const { t } = useTranslations()
    const { continueWatchingEnabled } = useSiteFeatures()

    const refreshItems = useCallback(async () => {
        if (user) {
            setItems(await loadContinueWatchingRemote())
            return
        }

        const stored = loadContinueWatching()
        setItems(await enrichWatchProgressTitles(stored))
    }, [user])

    useEffect(() => {
        if (!api) return

        setTotal(api.scrollSnapList().length)
        setCurrent(api.selectedScrollSnap() + 1)

        api.on("select", () => {
            setCurrent(api.selectedScrollSnap() + 1)
        })
    }, [api])

    useEffect(() => {
        refreshItems()

        const handleUpdate = () => {
            if (activeItem) return
            refreshItems()
        }
        window.addEventListener(CONTINUE_WATCHING_UPDATED_EVENT, handleUpdate)
        window.addEventListener("storage", handleUpdate)
        window.addEventListener("focus", handleUpdate)

        return () => {
            window.removeEventListener(CONTINUE_WATCHING_UPDATED_EVENT, handleUpdate)
            window.removeEventListener("storage", handleUpdate)
            window.removeEventListener("focus", handleUpdate)
        }
    }, [refreshItems, pathname, activeItem, locale])

    function nextSlide() {
        api?.scrollNext()
    }

    function previousSlide() {
        api?.scrollPrev()
    }

    const removeItem = (item: WatchProgressItem, event: React.MouseEvent) => {
        event.stopPropagation()
        removeWatchProgress(item)
        refreshItems()
    }

    const openPlayer = (item: WatchProgressItem) => {
        notePlaybackUserGesture()
        void prefetchSitePlayerAdsEnabled()
        setActiveItem(item)
    }

    const closePlayer = () => {
        setActiveItem(null)
        refreshItems()
    }

    const updateActiveItemEpisode = useCallback((season: number, episode: number) => {
        setActiveItem((previous) =>
            previous && previous.type === "tv"
                ? {
                      ...previous,
                      season,
                      episode,
                      currentTime: 0,
                      progress: 0,
                  }
                : previous
        )
    }, [])

    useClosePlaybackOnNavigate(closePlayer, activeItem !== null)

    if (!continueWatchingEnabled) {
        return null
    }

    if (items.length === 0 && !activeItem) {
        if (!emptyMessage) return null

        return (
            <div className="rounded-xl border border-dashed bg-card/20 p-10 text-center text-muted-foreground">
                {emptyMessage}
            </div>
        )
    }

    if (items.length === 0 && activeItem) {
        return (
            <PlayerModalBoundary onClose={closePlayer}>
                <LazySitePlayerModal
                    key={playerSessionKey(activeItem)}
                    isOpen
                    onClose={closePlayer}
                    mediaId={activeItem.id}
                    mediaType={activeItem.type}
                    title={activeItem.title}
                    posterPath={activeItem.poster}
                    currentSeason={activeItem.season}
                    currentEpisode={activeItem.episode}
                    initialResumeTime={activeItem.currentTime}
                    onEpisodeChange={updateActiveItemEpisode}
                />
            </PlayerModalBoundary>
        )
    }

    return (
        <>
            <div className="space-y-2">
                {!embedded ? (
                <HomeSectionHeader
                    title={t("home.continueWatching")}
                    description={t("home.continueWatchingDesc")}
                    variant="clock"
                >
                    <div className="flex shrink-0 items-center gap-2">
                        <span className="hidden text-xs font-medium tabular-nums text-muted-foreground md:inline">
                            {current} / {total}
                        </span>
                        <div className="flex gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-7"
                                onClick={previousSlide}
                            >
                                <ArrowLeft className="size-3.5" />
                                <span className="sr-only">{t("a11y.previous")}</span>
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-7"
                                onClick={nextSlide}
                            >
                                <ArrowRight className="size-3.5" />
                                <span className="sr-only">{t("a11y.next")}</span>
                            </Button>
                        </div>
                    </div>
                </HomeSectionHeader>
                ) : (
                    <div className="flex items-center justify-end gap-2">
                        <span className="text-xs font-medium text-muted-foreground tabular-nums">
                            {current} / {total}
                        </span>
                        <div className="flex gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-7"
                                onClick={previousSlide}
                            >
                                <ArrowLeft className="size-3.5" />
                                <span className="sr-only">{t("a11y.previous")}</span>
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-7"
                                onClick={nextSlide}
                            >
                                <ArrowRight className="size-3.5" />
                                <span className="sr-only">{t("a11y.next")}</span>
                            </Button>
                        </div>
                    </div>
                )}
                <Carousel opts={{ dragFree: true, align: "start" }} setApi={setApi}>
                    <CarouselContent className="home-carousel-content">
                        {items.map((item, index) => {
                            const progressLabel =
                                item.type === "tv"
                                    ? `${item.progress}% • S${item.season}:E${item.episode}`
                                    : `${item.progress}%`

                            return (
                            <CarouselItem
                                key={itemKey(item)}
                                className="home-carousel-item"
                            >
                                <div
                                    className="group cursor-pointer"
                                    onClick={() => openPlayer(item)}
                                    onMouseEnter={() =>
                                        schedulePrefetchMediaSources({
                                            id: item.id,
                                            type: item.type,
                                            season: item.type === "tv" ? item.season : undefined,
                                            episode: item.type === "tv" ? item.episode : undefined,
                                        })
                                    }
                                >
                                    <div className="relative aspect-poster">
                                        <div
                                          className={
                                            "media-card-root absolute inset-0 origin-center " +
                                            "transform-gpu will-change-transform " +
                                            "transition-transform duration-300 ease-out " +
                                            "group-hover:z-10 group-hover:scale-[1.06]"
                                          }
                                        >
                                          <div className="size-full overflow-hidden rounded-2xl bg-black shadow-[0_2px_8px_rgba(0,0,0,0.25)] ring-1 ring-white/[0.08]">
                                            <MediaPoster
                                              image={item.poster}
                                              alt={item.title}
                                              priority={index < 4}
                                              className="rounded-2xl"
                                            />

                                            <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-white/20">
                                                <div
                                                    className="h-full bg-[#c7a24e] transition-all"
                                                    style={{ width: `${item.progress}%` }}
                                                />
                                            </div>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="absolute right-2 top-2 z-10 size-7 rounded-full bg-black/60 text-white hover:bg-black/80"
                                                onClick={(e) => removeItem(item, e)}
                                            >
                                                <X className="size-3.5" />
                                            </Button>
                                          </div>
                                        </div>
                                    </div>

                                    <div className="mt-2 px-0.5">
                                        <p className="home-cinrift-card-title line-clamp-2">
                                            {item.title}
                                        </p>
                                        <p className="home-cinrift-card-meta mt-1">
                                            {progressLabel}
                                        </p>
                                    </div>
                                </div>
                            </CarouselItem>
                            )
                        })}
                    </CarouselContent>
                </Carousel>
            </div>

            {activeItem ? (
                <PlayerModalBoundary onClose={closePlayer}>
                    <LazySitePlayerModal
                        key={playerSessionKey(activeItem)}
                        isOpen
                        onClose={closePlayer}
                        mediaId={activeItem.id}
                        mediaType={activeItem.type}
                        title={activeItem.title}
                        posterPath={activeItem.poster}
                        currentSeason={activeItem.season}
                        currentEpisode={activeItem.episode}
                        initialResumeTime={activeItem.currentTime}
                        onEpisodeChange={updateActiveItemEpisode}
                    />
                </PlayerModalBoundary>
            ) : null}
        </>
    )
}
