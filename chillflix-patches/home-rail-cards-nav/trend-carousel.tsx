"use client"

import React, { useEffect, useState } from "react"
import Link from "next/link"
import {
  MovieWithMediaType,
  PersonWithMediaType,
  TvShowWithMediaType,
} from "@/tmdb/models"
import { ArrowLeft, ArrowRight, Film } from "lucide-react"

import { cn } from "@/lib/utils"
import { useTranslations } from "@/lib/i18n/client"
import { Button, buttonVariants } from "@/components/ui/button"
import {
  Carousel,
  CarouselApi,
  CarouselContent,
  CarouselItem,
} from "@/components/ui/carousel"
import { MovieCard } from "@/components/movie-card"
import { HomeSectionHeader } from "@/components/home-section-header"
import { PersonCard } from "@/components/person-card"
import { TvCard } from "@/components/tv-card"

interface TrendCarouselProps {
  title?: string
  description?: string
  link?: string
  items: MovieWithMediaType[] | TvShowWithMediaType[] | PersonWithMediaType[]
  type: "movie" | "tv" | "person"
  /** Eager-load poster images for the first N visible cards. */
  eagerPosterCount?: number
  headerVariant?: "default" | "accent" | "clock"
}

export const TrendCarousel: React.FC<TrendCarouselProps> = ({
  title,
  description,
  link,
  items,
  eagerPosterCount = 6,
  headerVariant = "accent",
}) => {
  const { t } = useTranslations()
  const [api, setApi] = useState<CarouselApi>()
  const [total, setTotal] = useState(0)
  const [current, setCurrent] = useState(0)

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

  return (
    <div className="space-y-2">
      <HomeSectionHeader
        title={title ?? ""}
        description={description}
        icon={title ? Film : undefined}
        variant={headerVariant}
      >
        <div className="flex shrink-0 items-center gap-2">
          {link && (
            <Link
              href={link}
              className={cn(buttonVariants({ size: "sm", variant: "outline" }), "hidden md:flex")}
              prefetch={false}
            >
              {t("common.exploreMore")}
            </Link>
          )}
<div className="flex gap-1">
            <Button
              variant="outline"
              size="icon"
              className="size-7"
              onClick={previousSlide}
            >
              <ArrowLeft className="size-3.5" />
              <span className="sr-only">{t("common.previous")}</span>
            </Button>
            <Button
              variant="outline"
              size="icon"
              className="size-7"
              onClick={nextSlide}
            >
              <ArrowRight className="size-3.5" />
              <span className="sr-only">{t("common.next")}</span>
            </Button>
          </div>
        </div>
      </HomeSectionHeader>

      <Carousel opts={{ dragFree: true }} setApi={setApi}>

        <CarouselContent className="home-carousel-content">
          {items.map((item, index) => (
            <CarouselItem
              key={item.id}
              className="home-carousel-item"
            >
              {item.media_type === "tv" ? (
                <TvCard
                  key={item.id}
                  {...item}
                  priority={index < eagerPosterCount}
                />
              ) : item.media_type === "person" ? (
                <PersonCard key={item.id} {...item} />
              ) : (
                <MovieCard
                  key={item.id}
                  {...item}
                  priority={index < eagerPosterCount}
                />
              )}
            </CarouselItem>
          ))}
        </CarouselContent>
      </Carousel>
    </div>
  )
}
