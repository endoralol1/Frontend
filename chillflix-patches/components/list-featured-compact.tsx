import Image from "next/image"
import { MediaDetailLink } from "@/components/media-detail-link"
import { pages } from "@/config"
import { Movie, TvShow } from "@/tmdb/models"
import { Genre } from "@/tmdb/models/commons"
import { format, wsrvImage } from "@/tmdb/utils"
import { Star } from "lucide-react"

import { formatValue } from "@/lib/utils"
import { getTranslator } from "@/lib/i18n/server"

interface ListFeaturedCompactProps {
  type: "movie" | "tv"
  item: Movie | TvShow
  genres?: Genre[]
}

export async function ListFeaturedCompact({
  type,
  item,
  genres = [],
}: ListFeaturedCompactProps) {
  const { t } = await getTranslator()
  const isMovie = type === "movie"
  const movie = isMovie ? (item as Movie) : null
  const show = !isMovie ? (item as TvShow) : null

  const title = isMovie ? movie!.title : show!.name
  const href = isMovie
    ? `${pages.movie.root.link}/${item.id}`
    : `${pages.tv.root.link}/${item.id}`
  const date = isMovie ? movie!.release_date : show!.first_air_date

  const poster = item.poster_path
    ? wsrvImage.poster(item.poster_path, "w342")
    : null

  const genreNames = item.genre_ids
    .map((id) => genres.find((genre) => genre.id === id)?.name)
    .filter(Boolean)
    .slice(0, 2)

  const year = formatValue(date, format.year)
  const rating =
    item.vote_average > 0 ? item.vote_average.toFixed(1) : null

  return (
    <MediaDetailLink
      href={href}
      className="browse-panel group relative block overflow-hidden bg-card/95 shadow-sm ring-1 ring-black/20 backdrop-blur-md transition hover:bg-card"
    >
      <div className="relative z-10 flex min-h-[4.75rem] items-center gap-2.5 p-2.5 sm:gap-3 sm:p-3">
        <div className="relative h-[3.75rem] w-[2.5rem] shrink-0 overflow-hidden rounded-md border border-border/60 bg-muted/40 sm:h-[4.25rem] sm:w-[2.85rem]">
          {poster ? (
            <Image
              src={poster}
              alt={title}
              fill
              unoptimized
              sizes="56px"
              className="object-cover"
            />
          ) : (
            <div className="flex h-full items-center justify-center text-[10px] text-muted-foreground">
              {t("common.notAvailable")}
            </div>
          )}
        </div>

        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-medium uppercase tracking-[0.12em] text-muted-foreground">
            {t("discover.featuredPick")}
          </p>

          <h2 className="truncate text-sm font-semibold tracking-tight text-foreground">
            {title}
          </h2>

          <div className="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] text-muted-foreground">
            {year ? <span>{year}</span> : null}
            {rating ? (
              <span className="inline-flex items-center gap-0.5 tabular-nums">
                <Star className="size-3 text-muted-foreground" />
                {rating}
              </span>
            ) : null}
            {genreNames.map((name) => (
              <span
                key={name}
                className="rounded-full border border-border/60 bg-muted/40 px-1.5 py-px text-[10px]"
              >
                {name}
              </span>
            ))}
          </div>

          {item.overview ? (
            <p className="mt-1 line-clamp-1 text-[11px] leading-snug text-muted-foreground">
              {item.overview}
            </p>
          ) : null}
        </div>
      </div>
    </MediaDetailLink>
  )
}
