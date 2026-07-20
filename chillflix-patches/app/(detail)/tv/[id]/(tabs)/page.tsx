import Link from "next/link"
import { pages } from "@/config"
import { getCachedTvDetail } from "@/lib/cached-tmdb-detail"
import { format } from "@/tmdb/utils"

import { getTranslator } from "@/lib/i18n/server"
import { formatValue, joiner, pad } from "@/lib/utils"
import { Badge } from "@/components/ui/badge"
import { PersonDetailLink } from "@/components/person-detail-link"
import { MediaDetailFacts } from "@/components/media-detail-facts"
import { MediaBackdrop } from "@/components/media-backdrop"
import { DetailWatchTrigger } from "@/components/detail-watch-trigger"
import { getSiteSettings } from "@/lib/site-settings"

export default async function Detail({ params }: { params: { id: string } }) {
  const [{ t }, { playersEnabled }, detail] = await Promise.all([
    getTranslator(),
    getSiteSettings(),
    getCachedTvDetail(params.id),
  ])
  const {
    name,
    overview,
    poster_path,
    first_air_date,
    last_air_date,
    status,
    original_name,
    created_by,
    number_of_seasons,
    number_of_episodes,
    spoken_languages,
    production_companies,
    networks,
    episode_run_time,
    production_countries,
    original_language,
    last_episode_to_air: lastEpisode,
  } = detail

  const items = [
    {
      title: "detail.facts.createdBy",
      value: created_by.length
        ? created_by.map(({ id, name }) => (
            <PersonDetailLink key={id} id={id}>
              {name}
            </PersonDetailLink>
          ))
        : "—",
    },
    {
      title: "detail.facts.status",
      value: formatValue(status),
    },
    {
      title: "detail.factsExtra.originalName",
      value: formatValue(original_name),
    },
    {
      title: "detail.facts.firstAirDate",
      value: formatValue(first_air_date, format.date),
    },
    {
      title: "detail.facts.lastAirDate",
      value: formatValue(last_air_date, format.date),
    },
    {
      title: "detail.factsExtra.seasons",
      value: formatValue(number_of_seasons),
    },
    {
      title: "detail.factsExtra.episodes",
      value: formatValue(number_of_episodes),
    },
    {
      title: "detail.facts.episodeRuntime",
      value: formatValue(episode_run_time, format.runtime),
    },
    {
      title: "detail.factsExtra.language",
      value: joiner(spoken_languages, "english_name"),
    },
    {
      title: "detail.facts.originalLanguage",
      value: formatValue(original_language, format.country),
    },
    {
      title: "detail.factsExtra.productionCountries",
      value: joiner(production_countries, "name"),
    },
    {
      title: "detail.facts.productionCompanies",
      value: production_companies.length
        ? production_companies.map(({ id, name }) => (
            <Link key={id} href={`${pages.tv.discover.link}?with_companies=${id}`}>
              {name}
            </Link>
          ))
        : "—",
    },
    {
      title: "detail.facts.networks",
      value: networks.length
        ? networks.map(({ id, name }) => (
            <Link key={id} href={`${pages.tv.discover.link}?with_networks=${id}`}>
              {name}
            </Link>
          ))
        : "—",
    },
  ]

  return (
    <section className="space-y-6">
      <MediaDetailFacts items={items} />

      {lastEpisode && (
        <div className="overflow-hidden rounded-2xl border border-border/45 bg-card/20 shadow-lg ring-1 ring-white/5">
          <div className="h-hero relative w-full">
            <MediaBackdrop
              image={lastEpisode.still_path}
              alt={lastEpisode.name}
              className="rounded-none border-0"
            />
            <div className="overlay rounded-none border-0">
              <div className="pointer-events-auto w-full space-y-3 p-5 md:p-8">
                <Badge className="gap-1 rounded-full bg-primary/90 text-primary-foreground shadow-sm">
                  <span>S{pad(lastEpisode.season_number)}</span>
                  <span>E{pad(lastEpisode.episode_number)}</span>
                </Badge>

                <h2 className="text-balance text-xl font-semibold tracking-tight md:text-2xl">
                  {lastEpisode.name}
                </h2>
                <p className="line-clamp-3 max-w-2xl text-sm leading-relaxed text-muted-foreground md:line-clamp-4 md:text-base">
                  {lastEpisode.overview}
                </p>
                {playersEnabled ? (
                  <DetailWatchTrigger
                    mediaId={params.id}
                    mediaType="tv"
                    title={name}
                    description={overview}
                    currentSeason={lastEpisode.season_number}
                    currentEpisode={lastEpisode.episode_number}
                    posterPath={poster_path}
                    className="mt-2"
                    buttonClassName="shadow-md"
                  />
                ) : (
                  <Link
                    href={`${pages.tv.root.link}/${params.id}/seasons/${lastEpisode.season_number}/episodes/${lastEpisode.episode_number}`}
                    className="mt-2 inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-md transition hover:opacity-90"
                    prefetch={false}
                  >
                    {t("detail.viewEpisode")}
                  </Link>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}
