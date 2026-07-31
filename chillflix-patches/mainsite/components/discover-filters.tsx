"use client"

import { useState } from "react"
import { useFilters } from "@/hooks"
import { WatchProvider } from "@/tmdb/models"
import { Genre } from "@/tmdb/models/commons"
import { SlidersHorizontal, Sparkles } from "lucide-react"

import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"
import { Badge } from "@/components/ui/badge"
import { Button, buttonVariants } from "@/components/ui/button"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Separator } from "@/components/ui/separator"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import { DiscoverFilterDate } from "@/components/discover-filter-date"
import { DiscoverFilterGenre } from "@/components/discover-filter-genre"
import { DiscoverFilterCountry } from "@/components/discover-filter-country"
import { DiscoverFilterLang } from "@/components/discover-filter-lang"
import { DiscoverFilterProvider } from "@/components/discover-filter-provider"
import { DiscoverFilterVoteAverage } from "@/components/discover-filter-vote-average"
import { DiscoverFilterVoteCount } from "@/components/discover-filter-vote-count"

interface DiscoverFiltersProps {
  type: "movie" | "tv"
  genres: Genre[]
  providers: WatchProvider[]
  compact?: boolean
}

function FilterPanel({
  className,
  children,
}: {
  className?: string
  children: React.ReactNode
}) {
  return (
    <div
      className={cn(
        "rounded-lg border border-border/60 bg-muted/20 p-4",
        className
      )}
    >
      {children}
    </div>
  )
}

export const DiscoverFilters: React.FC<DiscoverFiltersProps> = ({
  type,
  genres,
  providers,
  compact,
}) => {
  const { t } = useTranslations()
  const [open, setOpen] = useState(false)
  const { count, getFilter, setFilter, saveFilters, clearFilters } =
    useFilters(type)

  const dateGteKey =
    type === "movie" ? "primary_release_date.gte" : "first_air_date.gte"
  const dateLteKey =
    type === "movie" ? "primary_release_date.lte" : "first_air_date.lte"

  return (
    <Sheet open={open} onOpenChange={setOpen} modal={false}>
      <SheetTrigger
        className={cn(
          buttonVariants({ variant: "outline", size: "sm" }),
          compact
            ? "h-7 shrink-0 gap-1 rounded-md border-border/60 bg-muted/30 px-2.5 text-[11px] hover:bg-muted/50"
            : "h-8 gap-1.5 rounded-md border-border/60 bg-muted/30 px-3.5 text-sm hover:bg-muted/50"
        )}
      >
        <SlidersHorizontal className={cn("shrink-0", compact ? "size-3" : "size-4")} />
        <span>{t("discover.filters")}</span>
        {count > 0 ? (
          <Badge
            className={cn(
              "shrink-0 rounded-full border-0 bg-primary font-semibold leading-none text-primary-foreground",
              compact
                ? "h-4 min-w-4 px-1 text-[9px]"
                : "h-5 min-w-5 px-1.5 text-[10px]"
            )}
          >
            {count}
          </Badge>
        ) : null}
      </SheetTrigger>

      <SheetContent className="flex w-full flex-col gap-0 border-border/50 bg-background/95 p-0 backdrop-blur-xl sm:max-w-md">
        <SheetHeader className="space-y-3 border-b border-border/40 px-5 pb-5 pt-6 text-left">
          <div className="flex items-start gap-3 pr-8">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <Sparkles className="size-5" />
            </div>
            <div className="space-y-1">
              <SheetTitle className="text-xl">{t("discover.filters")}</SheetTitle>
              <SheetDescription className="text-sm leading-relaxed">
                {t("discover.sheetDescription")}
              </SheetDescription>
            </div>
          </div>
        </SheetHeader>

        <ScrollArea className="min-h-0 flex-1">
          <div className="space-y-4 px-5 py-5">
            <FilterPanel>
              <DiscoverFilterGenre
                genres={genres}
                includeValue={getFilter("with_genres") ?? ""}
                excludeValue={getFilter("without_genres") ?? ""}
                onIncludeChange={(value) => setFilter({ with_genres: value })}
                onExcludeChange={(value) => setFilter({ without_genres: value })}
              />
            </FilterPanel>

            <FilterPanel>
              <p className="mb-3 text-sm font-medium text-foreground">
                {t("discover.releaseDate")}
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <DiscoverFilterDate
                  label={t("discover.from")}
                  align="start"
                  value={getFilter(dateGteKey)}
                  disableAfter={getFilter(dateLteKey)}
                  onChange={(value) => setFilter({ [dateGteKey]: value })}
                />

                <DiscoverFilterDate
                  label={t("discover.to")}
                  align="end"
                  value={getFilter(dateLteKey)}
                  disableBefore={getFilter(dateGteKey)}
                  onChange={(value) => setFilter({ [dateLteKey]: value })}
                />
              </div>
            </FilterPanel>

            <FilterPanel className="space-y-4">
              <DiscoverFilterLang
                value={getFilter("with_original_language")}
                onChange={(value) =>
                  setFilter({ with_original_language: value })
                }
              />

              <Separator className="bg-border/50" />

              <DiscoverFilterCountry
                value={getFilter("with_origin_country") ?? ""}
                onChange={(value) =>
                  setFilter({ with_origin_country: value })
                }
              />

              <Separator className="bg-border/50" />

              <DiscoverFilterProvider
                providers={providers}
                value={getFilter("with_watch_providers")}
                onChange={(value) => setFilter({ with_watch_providers: value })}
              />
            </FilterPanel>

            <FilterPanel className="space-y-5">
              <DiscoverFilterVoteAverage
                value={getFilter("vote_average.gte")}
                onChange={(value) => setFilter({ "vote_average.gte": value })}
              />

              <Separator className="bg-border/50" />

              <DiscoverFilterVoteCount
                value={getFilter("vote_count.gte")}
                onChange={(value) => setFilter({ "vote_count.gte": value })}
              />
            </FilterPanel>
          </div>
        </ScrollArea>

        <SheetFooter className="mt-auto gap-2 border-t border-border/40 bg-background/80 px-5 py-4 backdrop-blur-md sm:flex-row sm:justify-between">
          <Button
            size="lg"
            variant="ghost"
            className="rounded-full text-muted-foreground hover:text-foreground"
            onClick={() => {
              clearFilters()
              setOpen(false)
            }}
          >
            {t("discover.clearAll")}
          </Button>
          <Button
            size="lg"
            className="rounded-full px-8"
            onClick={() => {
              saveFilters()
              setOpen(false)
            }}
          >
            {t("discover.apply")}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
