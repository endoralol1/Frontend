"use client"

import { useMemo } from "react"
import { useSearchParams } from "next/navigation"
import { pages } from "@/config"
import { Genre } from "@/tmdb/models/commons"
import { WatchProvider } from "@/tmdb/models"

import { DiscoverRemovableChip } from "@/components/discover-chip"
import { assignDiscoverUrl } from "@/lib/discover-navigation"
import { filterDiscoverParams, getCountryName } from "@/lib/utils"
import { useTranslations } from "@/lib/i18n/client"
import { Button } from "@/components/ui/button"

const DEFAULT_SORT = "popularity.desc"

interface DiscoverActiveFiltersProps {
  type: "movie" | "tv"
  genres: Genre[]
  providers: WatchProvider[]
}

function formatFilterLabel(
  key: string,
  value: string,
  genres: Genre[],
  providers: WatchProvider[],
  t: (key: string) => string
) {
  if (key === "with_genres") {
    const names = value
      .split(",")
      .map((id) => genres.find((g) => String(g.id) === id)?.name)
      .filter(Boolean)
    return names.length ? names.join(", ") : value
  }

  if (key === "without_genres") {
    const names = value
      .split(",")
      .map((id) => genres.find((g) => String(g.id) === id)?.name)
      .filter(Boolean)
    return names.length
      ? `${t("discover.excludedGenres")}: ${names.join(", ")}`
      : value
  }

  if (key === "with_watch_providers") {
    const names = value
      .split("|")
      .map((id) => providers.find((p) => String(p.provider_id) === id)?.provider_name)
      .filter(Boolean)
    return names.length ? names.join(", ") : value
  }

  if (key === "sort_by") {
    const sortKeys: Record<string, string> = {
      "popularity.desc": "discover.sortOptions.highestPopularity",
      "popularity.asc": "discover.sortOptions.lowestPopularity",
      "primary_release_date.desc": "discover.sortOptions.recentlyReleased",
      "primary_release_date.asc": "discover.sortOptions.leastRecent",
      "vote_average.desc": "discover.sortOptions.highestRating",
      "vote_average.asc": "discover.sortOptions.lowestRating",
      "vote_count.desc": "discover.sortOptions.mostVoted",
      "vote_count.asc": "discover.sortOptions.leastVoted",
      "first_air_date.desc": "discover.sortOptions.recentlyReleased",
      "first_air_date.asc": "discover.sortOptions.leastRecent",
    }
    const labelKey = sortKeys[value]
    return labelKey ? t(labelKey) : value
  }

  if (key.includes("vote_average")) return `${t("discover.minRating")}: ${value}+`
  if (key.includes("vote_count")) return `${t("discover.minimumVotes")}: ${value}`
  if (key.includes("release_date") || key.includes("air_date")) {
    return `${key.includes("gte") ? t("discover.from") : t("discover.to")}: ${value}`
  }
  if (key === "with_original_language") return `${t("discover.language")}: ${value.toUpperCase()}`
  if (key === "with_origin_country") {
    const names = value
      .split("|")
      .map((code) => getCountryName(code) || code.toUpperCase())
      .filter(Boolean)
    return names.length
      ? `${t("discover.country")}: ${names.join(", ")}`
      : value
  }

  return `${key}: ${value}`
}

export function DiscoverActiveFilters({
  type,
  genres,
  providers,
}: DiscoverActiveFiltersProps) {
  const { t } = useTranslations()
  const searchParams = useSearchParams()
  const pathname =
    type === "movie" ? pages.movie.discover.link : pages.tv.discover.link

  const explicitSort = searchParams.get("sort_by")
  const showSortChip = Boolean(explicitSort && explicitSort !== DEFAULT_SORT)

  const activeEntries = useMemo(() => {
    const params = filterDiscoverParams(Object.fromEntries(searchParams))
    return Object.entries(params).filter(([, value]) => Boolean(value))
  }, [searchParams])

  const hasActiveState = activeEntries.length > 0 || showSortChip

  const removeFilter = (key: string) => {
    const next = new URLSearchParams(searchParams.toString())
    next.delete(key)
    if (next.get("page")) next.set("page", "1")
    const qs = next.toString()
    assignDiscoverUrl(qs ? `${pathname}?${qs}` : pathname)
  }

  const clearAll = () => {
    const next = new URLSearchParams()
    const sortBy = searchParams.get("sort_by")
    if (sortBy) next.set("sort_by", sortBy)
    const qs = next.toString()
    assignDiscoverUrl(qs ? `${pathname}?${qs}` : pathname)
  }

  if (!hasActiveState) {
    return null
  }

  return (
    <div className="flex flex-1 flex-wrap items-center gap-1.5">
      {showSortChip && explicitSort ? (
        <DiscoverRemovableChip
          label={formatFilterLabel("sort_by", explicitSort, genres, providers, t)}
          onRemove={() => removeFilter("sort_by")}
          removeLabel={t("common.close")}
        />
      ) : null}

      {activeEntries.map(([key, value]) => (
        <DiscoverRemovableChip
          key={key}
          label={formatFilterLabel(key, value, genres, providers, t)}
          onRemove={() => removeFilter(key)}
          removeLabel={t("common.close")}
        />
      ))}

      <Button
        type="button"
        variant="ghost"
        size="sm"
        onClick={clearAll}
        className="h-6 rounded-full px-2 text-[11px] text-muted-foreground"
      >
        {t("discover.clearAll")}
      </Button>
    </div>
  )
}
