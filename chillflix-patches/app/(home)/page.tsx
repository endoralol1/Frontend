import { headers } from "next/headers"
import { pages, siteConfig } from "@/config"
import { settleTmdbList, tmdb } from "@/tmdb/api"

import { fetchHeroMovies } from "@/lib/fetch-hero-movies"
import { getHomeCarouselSections } from "@/lib/home-carousel-sections"
import { getTranslator } from "@/lib/i18n/server"
import { JsonLd } from "@/components/json-ld"
import {
  buildHomePageMetadata,
  buildOrganizationJsonLd,
  buildWebsiteJsonLd,
} from "@/lib/seo"
import { getSiteSettings } from "@/lib/site-settings"
import { getCountryName, normalizeRegionCode } from "@/lib/utils"
import {
  DeferredContinueWatching,
  DeferredRecommendedForYou,
  DeferredTrendCarousel,
} from "@/components/deferred-home-sections"
import { LazyHomeCarousel } from "@/components/lazy-home-carousel"
import { MovieHero } from "@/components/movie-hero"
import { ScrollReveal } from "@/components/scroll-reveal"

export const metadata = buildHomePageMetadata()

export const revalidate = 120

function getHomeRegion(): string {
  const headersList = headers()
  const cookieRegion = headersList.get("x-user-region")
  if (cookieRegion) return cookieRegion

  const vercelCountry = headersList.get("x-vercel-ip-country")
  if (vercelCountry) return vercelCountry

  const cfCountry = headersList.get("cf-ipcountry")
  if (cfCountry && cfCountry !== "XX") return cfCountry

  return "US"
}

export default async function Home() {
  const { t, locale } = await getTranslator()
  const region = normalizeRegionCode(getHomeRegion())
  const carouselSections = getHomeCarouselSections(t)

  const [settled, { continueWatchingEnabled }] = await Promise.all([
    Promise.allSettled([
      tmdb.trending.movie({ time: "day", page: "1" }),
      tmdb.trending.tv({ time: "day", page: "1" }),
      tmdb.movie.list({ list: "popular", page: "1", region }),
    ]),
    getSiteSettings(),
  ])

  const countryName = getCountryName(region) ?? t("common.yourCountry")
  const trendingMovies = settleTmdbList(settled[0]).results.slice(0, 12)
  const trendingTv = settleTmdbList(settled[1]).results.slice(0, 12)
  const popularMovies = settleTmdbList(settled[2]).results.slice(0, 12)
  const moviesWithType = popularMovies.map((movie) => ({
    ...movie,
    media_type: "movie" as const,
  }))

  const heroMovies = await fetchHeroMovies(trendingMovies, locale)

  return (
    <section className="home-page space-y-8 pt-0">
      <JsonLd data={buildWebsiteJsonLd()} />
      <JsonLd data={buildOrganizationJsonLd()} />
      <h1 className="sr-only">
        Watch Movies & TV Shows Online on {siteConfig.name}
      </h1>
      <div className="home-hero-bleed">
        <MovieHero
          key={locale}
          movies={heroMovies}
          label={t("home.trendingNow")}
          priority
        />
      </div>

      <div className="space-y-8">
        {continueWatchingEnabled ? (
          <ScrollReveal>
            <DeferredContinueWatching />
          </ScrollReveal>
        ) : null}

        <ScrollReveal>
          <DeferredRecommendedForYou />
        </ScrollReveal>

        <ScrollReveal>
          <DeferredTrendCarousel
            type="movie"
            title={t("home.top10MoviesIn", { country: countryName })}
            description={t("home.top10MoviesDesc")}
            link={pages.trending.movie.link}
            items={trendingMovies}
          />
        </ScrollReveal>

        <ScrollReveal>
          <DeferredTrendCarousel
            type="movie"
            title={t("home.popularOnChillflix")}
            description={t("home.popularOnChillflixDesc")}
            link={pages.movie.popular.link}
            items={moviesWithType}
          />
        </ScrollReveal>

        <ScrollReveal>
          <DeferredTrendCarousel
            type="tv"
            title={t("home.top10TvIn", { country: countryName })}
            description={t("home.top10TvDesc")}
            link={pages.trending.tv.link}
            items={trendingTv}
          />
        </ScrollReveal>

        {carouselSections.map((section, index) => (
          <LazyHomeCarousel
            key={section.id}
            sectionId={section.id}
            type={section.type}
            title={section.getTitle(countryName)}
            description={section.description}
            link={section.link}
            region={region}
            revealDelay={Math.min(index * 70, 280)}
          />
        ))}
      </div>
    </section>
  )
}
