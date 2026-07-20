"use client"

import { useEffect, useRef, useState, type ReactNode } from "react"
import dynamic from "next/dynamic"
import type {
  MovieWithMediaType,
  PersonWithMediaType,
  TvShowWithMediaType,
} from "@/tmdb/models"

import { cn } from "@/lib/utils"
import { HomeCarouselSkeleton } from "@/components/home-carousel-skeleton"

const LazyRecommendedForYou = dynamic(
  () =>
    import("@/components/recommended-for-you").then((module) => ({
      default: module.RecommendedForYou,
    })),
  { loading: () => <HomeCarouselSkeleton /> }
)

const LazyContinueWatching = dynamic(
  () =>
    import("@/components/continue-watching").then((module) => ({
      default: module.ContinueWatching,
    })),
  { loading: () => null }
)

const LazyTrendCarousel = dynamic(
  () =>
    import("@/components/trend-carousel").then((module) => ({
      default: module.TrendCarousel,
    })),
  { loading: () => <HomeCarouselSkeleton /> }
)

type DeferredSectionProps = {
  children: ReactNode
  minHeightClassName?: string
  rootMargin?: string
}

function DeferredSection({
  children,
  minHeightClassName = "min-h-[280px]",
  rootMargin = "500px 0px",
}: DeferredSectionProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const element = containerRef.current
    if (!element || visible) return

    if (typeof IntersectionObserver === "undefined") {
      setVisible(true)
      return
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return
        setVisible(true)
        observer.disconnect()
      },
      { rootMargin }
    )

    observer.observe(element)
    return () => observer.disconnect()
  }, [rootMargin, visible])

  return (
    <div ref={containerRef} className={cn(!visible && minHeightClassName)}>
      {visible ? children : <HomeCarouselSkeleton />}
    </div>
  )
}

export function DeferredRecommendedForYou() {
  return (
    <DeferredSection>
      <LazyRecommendedForYou />
    </DeferredSection>
  )
}

export function DeferredContinueWatching() {
  const containerRef = useRef<HTMLDivElement>(null)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const element = containerRef.current
    if (!element || visible) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) return
        setVisible(true)
        observer.disconnect()
      },
      { rootMargin: "500px 0px" }
    )

    observer.observe(element)
    return () => observer.disconnect()
  }, [visible])

  return (
    <div ref={containerRef} className={cn(!visible && "min-h-px")}>
      {visible ? <LazyContinueWatching /> : null}
    </div>
  )
}

type DeferredTrendCarouselProps = {
  type: "movie" | "tv" | "person"
  title?: string
  description?: string
  link?: string
  items: MovieWithMediaType[] | TvShowWithMediaType[] | PersonWithMediaType[]
  eagerPosterCount?: number
}

export function DeferredTrendCarousel(props: DeferredTrendCarouselProps) {
  return (
    <DeferredSection>
      <LazyTrendCarousel {...props} />
    </DeferredSection>
  )
}
