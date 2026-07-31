import { tmdb } from "@/tmdb/api"
import type { WithImages } from "@/tmdb/api/types"
import type { Movie } from "@/tmdb/models"
import type { Genre } from "@/tmdb/models/commons"
import { getTmdbLogoLanguageFilter, pickPreferredLogo } from "@/tmdb/utils"

import { getRandomItems } from "@/lib/utils"

export type HeroMovie = Movie & {
  logo_path?: string
  genres?: Genre[]
  runtime?: number
  /** 1-based rank in TMDB trending/day list used for the hero pool */
  trendingRank?: number
  isHot?: boolean
  isNewlyLaunched?: boolean
}

const HERO_CAROUSEL_SIZE = 5
const HOT_TRENDING_RANK = 5
const NEW_LOOKBACK_DAYS = 45
const NEW_UPCOMING_DAYS = 21

export function isNewlyLaunchedRelease(releaseDate?: string | null): boolean {
  if (!releaseDate) return false
  const releasedAt = Date.parse(`${releaseDate}T00:00:00Z`)
  if (Number.isNaN(releasedAt)) return false
  const diffDays = (Date.now() - releasedAt) / 86_400_000
  return diffDays >= -NEW_UPCOMING_DAYS && diffDays <= NEW_LOOKBACK_DAYS
}

export async function fetchHeroMovies(
  trendingMovies: Movie[],
  locale: string
): Promise<HeroMovie[]> {
  const pool = trendingMovies.slice(0, HERO_CAROUSEL_SIZE)
  if (pool.length === 0) return []

  const include_image_language = getTmdbLogoLanguageFilter(locale)
  const selected = getRandomItems(
    pool,
    Math.min(HERO_CAROUSEL_SIZE, pool.length)
  )

  return Promise.all(
    selected.map(async (movie) => {
      const trendingRank =
        trendingMovies.findIndex((item) => item.id === movie.id) + 1 || undefined
      const isHot =
        (trendingRank != null && trendingRank > 0 && trendingRank <= HOT_TRENDING_RANK) ||
        (typeof movie.popularity === "number" && movie.popularity >= 120)
      const isNewlyLaunched = isNewlyLaunchedRelease(movie.release_date)

      try {
        const details = await tmdb.movie.detail<WithImages>({
          id: movie.id.toString(),
          append: "images",
          include_image_language,
        })

        const logo = pickPreferredLogo(details?.images?.logos, locale)

        return {
          ...movie,
          logo_path: logo?.file_path,
          genres: details?.genres,
          runtime: details?.runtime,
          adult: details?.adult ?? movie.adult,
          trendingRank,
          isHot,
          isNewlyLaunched:
            isNewlyLaunched || isNewlyLaunchedRelease(details?.release_date),
        }
      } catch (error) {
        console.error(`Hero detail fetch failed (movie ${movie.id}):`, error)
        return {
          ...movie,
          trendingRank,
          isHot,
          isNewlyLaunched,
        }
      }
    })
  )
}
