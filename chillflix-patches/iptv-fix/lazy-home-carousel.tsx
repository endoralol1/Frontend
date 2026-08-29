"use client"

import { useEffect, useRef, useState } from "react"
import dynamic from "next/dynamic"
import type { MovieWithMediaType, TvShowWithMediaType } from "@/tmdb/models"

import { isLowEndDevice } from "@/lib/device-profile"
import type { HomeCarouselMediaItem } from "@/lib/home-carousel-sections"
import { HomeCarouselSkeleton } from "@/components/home-carousel-skeleton"
import { ScrollReveal } from "@/components/scroll-reveal"

const LazyTrendCarousel = dynamic(
  () =>
    import("@/components/trend-carousel").then((module) => ({
      default: module.TrendCarousel,
    })),
  { loading: () => <HomeCarouselSkeleton /> }
)

type LazyHomeCarouselProps = {
  sectionId: string
  type: "movie" | "tv"
  title: string
  description?: string
  link?: string
  region: string
  revealDelay?: number
  /** Start fetching immediately (first home rows). */
  eager?: boolean
}

const LOW_END_ROW_ITEM_LIMIT = 12
const LOW_END_ROW_MARGIN = "1200px 0px"
const DEFAULT_RESERVED_HEIGHT = 360

export function LazyHomeCarousel({
  sectionId,
  type,
  title,
  description,
  link,
  region,
  revealDelay = 0,
  eager = false,
}: LazyHomeCarouselProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const [shouldLoad, setShouldLoad] = useState(eager)
  const [isNearViewport, setIsNearViewport] = useState(eager)
  const [lowEndDevice, setLowEndDevice] = useState(false)
  const [reservedHeight, setReservedHeight] = useState(DEFAULT_RESERVED_HEIGHT)
  const [items, setItems] = useState<HomeCarouselMediaItem[] | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    setLowEndDevice(isLowEndDevice())
  }, [])

  useEffect(() => {
    const element = containerRef.current
    if (!element) return

    const observer = new IntersectionObserver(
      ([entry]) => {
        setIsNearViewport(entry.isIntersecting)
        if (entry.isIntersecting) {
          setShouldLoad(true)
          if (!lowEndDevice) observer.disconnect()
        }
      },
      { rootMargin: lowEndDevice ? LOW_END_ROW_MARGIN : "900px 0px" }
    )

    observer.observe(element)
    return () => observer.disconnect()
  }, [lowEndDevice])

  useEffect(() => {
    if (!shouldLoad || items !== null) return

    let cancelled = false

    void (async () => {
      try {
        const params = new URLSearchParams({ section: sectionId, region })
        const response = await fetch(`/api/home/carousel?${params.toString()}`)
        if (!response.ok) throw new Error("Failed to load carousel")

        const data = (await response.json()) as {
          results?: HomeCarouselMediaItem[]
        }
        if (!cancelled) {
          setItems(data.results ?? [])
        }
      } catch {
        if (!cancelled) {
          setFailed(true)
          setItems([])
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [shouldLoad, sectionId, region, items])

  const renderContent = !lowEndDevice || isNearViewport
  const visibleItems =
    lowEndDevice && items ? items.slice(0, LOW_END_ROW_ITEM_LIMIT) : items

  useEffect(() => {
    const element = contentRef.current
    if (!renderContent || !element) return

    const measure = () => {
      const nextHeight = Math.ceil(element.getBoundingClientRect().height)
      if (nextHeight > 0) {
        setReservedHeight((current) =>
          Math.abs(current - nextHeight) > 2 ? nextHeight : current
        )
      }
    }

    const frame = window.requestAnimationFrame(measure)
    if (typeof ResizeObserver === "undefined") {
      return () => window.cancelAnimationFrame(frame)
    }

    const observer = new ResizeObserver(measure)
    observer.observe(element)
    return () => {
      window.cancelAnimationFrame(frame)
      observer.disconnect()
    }
  }, [renderContent, items, lowEndDevice])

  return (
    <ScrollReveal delay={revealDelay}>
      <div ref={containerRef} style={{ minHeight: `${reservedHeight}px` }}>
        {renderContent ? (
          <div ref={contentRef}>
            {visibleItems === null ? (
              <HomeCarouselSkeleton />
            ) : failed || visibleItems.length === 0 ? null : (
              <LazyTrendCarousel
                type={type}
                title={title}
                description={description}
                link={link}
                items={
                  type === "tv"
                    ? (visibleItems as TvShowWithMediaType[])
                    : (visibleItems as MovieWithMediaType[])
                }
              />
            )}
          </div>
        ) : null}
      </div>
    </ScrollReveal>
  )
}
