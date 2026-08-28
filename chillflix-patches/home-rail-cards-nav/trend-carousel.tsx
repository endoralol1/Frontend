"use client"

import React from "react"
import Link from "next/link"
import {
  MovieWithMediaType,
  PersonWithMediaType,
  TvShowWithMediaType,
} from "@/tmdb/models"
import { Film } from "lucide-react"

import { cn } from "@/lib/utils"
import { useTranslations } from "@/lib/i18n/client"
import { buttonVariants } from "@/components/ui/button"
import {
  Carousel,
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



  return (
    <div className="space-y-2">
      <HomeSectionHeader
        title={title ?? ""}
        description={description}
        icon={title ? Film : undefined}
        variant={headerVariant}
      >
        {link ? (
          <Link
            href={link}
            className={cn(buttonVariants({ size: "sm", variant: "outline" }), "hidden md:flex")}
            prefetch={false}
          >
            {t("common.exploreMore")}
          </Link>
        ) : null}
      </HomeSectionHeader>

      <Carousel opts={{ dragFree: true }}>

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
