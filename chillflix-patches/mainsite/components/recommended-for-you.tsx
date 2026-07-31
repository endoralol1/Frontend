"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { Film, ArrowLeft, ArrowRight } from "lucide-react"
import { Movie, TvShow } from "@/tmdb/models"

import { Button } from "@/components/ui/button"
import { HomeCarouselSkeleton } from "@/components/home-carousel-skeleton"
import { HomeSectionHeader } from "@/components/home-section-header"
import {
    Carousel,
    CarouselApi,
    CarouselContent,
    CarouselItem,
} from "@/components/ui/carousel"
import { MovieCard } from "@/components/movie-card"
import { TvCard } from "@/components/tv-card"
import { useAuth } from "@/hooks/use-auth"
import { useTranslations } from "@/lib/i18n/client"
import { LIBRARY_UPDATED } from "@/hooks/use-user-library"
import {
    CONTINUE_WATCHING_UPDATED_EVENT,
    loadContinueWatching,
    loadContinueWatchingRemote,
} from "@/lib/watch-progress"

export function RecommendedForYou() {
    const { user, loading: authLoading } = useAuth()
    const { t } = useTranslations()
    const [items, setItems] = useState<(Movie | TvShow)[]>([])
    const [initialLoading, setInitialLoading] = useState(true)
    const [api, setApi] = useState<CarouselApi>()
    const [total, setTotal] = useState(0)
    const [current, setCurrent] = useState(0)
    const hasLoadedRef = useRef(false)
    const refreshTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)
    const [playerOpen, setPlayerOpen] = useState(false)

    useEffect(() => {
        if (!api) return

        setTotal(api.scrollSnapList().length)
        setCurrent(api.selectedScrollSnap() + 1)

        api.on("select", () => {
            setCurrent(api.selectedScrollSnap() + 1)
        })
    }, [api])

    function nextSlide() {
        api?.scrollNext()
    }

    function previousSlide() {
        api?.scrollPrev()
    }

    const loadRecommendations = useCallback(async (background = false) => {
        if (!background) {
            setInitialLoading(true)
        }

        try {
            const continueItems = user
                ? await loadContinueWatchingRemote()
                : loadContinueWatching()

            const seeds = continueItems.map((item) => ({
                id: item.id,
                type: item.type,
                weight: item.progress >= 50 ? 2 : 1.5,
                at: item.timestamp,
            }))

            const response = await fetch("/api/recommendations", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ seeds }),
            })

            if (!response.ok) {
                throw new Error("Failed to load recommendations")
            }

            const data = await response.json()
            setItems(data.results ?? [])
            hasLoadedRef.current = true
        } catch (error) {
            console.error("Failed to fetch recommendations:", error)
            if (!background) {
                setItems([])
            }
        } finally {
            setInitialLoading(false)
        }
    }, [user])

    useEffect(() => {
        if (authLoading) return
        void loadRecommendations(hasLoadedRef.current)
    }, [authLoading, loadRecommendations])

    useEffect(() => {
        const scheduleRefresh = () => {
            if (!hasLoadedRef.current || playerOpen) return

            if (refreshTimeoutRef.current) {
                clearTimeout(refreshTimeoutRef.current)
            }

            // Was 400ms — caused reco/title storms on every progress tick.
            refreshTimeoutRef.current = setTimeout(() => {
                void loadRecommendations(true)
            }, 30_000)
        }

        window.addEventListener(CONTINUE_WATCHING_UPDATED_EVENT, scheduleRefresh)
        window.addEventListener(LIBRARY_UPDATED, scheduleRefresh)

        return () => {
            if (refreshTimeoutRef.current) {
                clearTimeout(refreshTimeoutRef.current)
            }
            window.removeEventListener(CONTINUE_WATCHING_UPDATED_EVENT, scheduleRefresh)
            window.removeEventListener(LIBRARY_UPDATED, scheduleRefresh)
        }
    }, [loadRecommendations, playerOpen])

    if (authLoading || (initialLoading && items.length === 0)) {
        return <HomeCarouselSkeleton />
    }

    if (items.length === 0) {
        return null
    }

    return (
        <div className="space-y-2">
            <HomeSectionHeader
                title={t("home.recommendedForYou")}
                description={t("home.recommendedForYouDesc")}
                variant="accent"
            >
                <div className="flex shrink-0 items-center gap-2">
                    <span className="text-xs font-medium text-muted-foreground tabular-nums hidden md:inline">
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

            <Carousel opts={{ dragFree: true }} setApi={setApi}>
                <CarouselContent className="home-carousel-content">
                    {items.map((item) => (
                        <CarouselItem
                            key={`${"name" in item ? "tv" : "movie"}-${item.id}`}
                            className="home-carousel-item"
                        >
                            {"name" in item && item.name ? (
                                <TvCard
                                    {...(item as TvShow)}
                                    onWatchOpenChange={setPlayerOpen}
                                />
                            ) : (
                                <MovieCard
                                    {...(item as Movie)}
                                    onWatchOpenChange={setPlayerOpen}
                                />
                            )}
                        </CarouselItem>
                    ))}
                </CarouselContent>
            </Carousel>
        </div>
    )
}
