import { Suspense } from "react"
import { redirect } from "next/navigation"
import { tmdb } from "@/tmdb/api"
import { Movie, Person, TvShow } from "@/tmdb/models"

import { PaginatedListBoundary } from "@/components/paginated-list-boundary"
import { SearchCommandBar } from "@/components/search-command-bar"
import { SearchResultCard } from "@/components/search-result-card"
import { ListPagination } from "@/components/list-pagination"
import {
  filterSearchResults,
  normalizeSearchResults,
  SearchMediaType,
  sortSearchResults,
} from "@/lib/search-results"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"
import { listSearchKey } from "@/lib/list-search-key"

interface SearchProps {
  searchParams: Record<string, string | undefined>
}

export async function generateMetadata({ searchParams }: SearchProps) {
  const { t } = await getTranslator()
  const query = (searchParams.q ?? "").trim()
  return buildListPageMetadata({
    title: t("search.metaTitle", { query }),
    description: query
      ? t("search.metaTitle", { query })
      : t("site.description"),
    path: query ? `/search?q=${encodeURIComponent(query)}` : "/search",
  })
}

export default function Search({ searchParams }: SearchProps) {
  if (!searchParams.q) {
    redirect("/")
  }

  return (
    <PaginatedListBoundary searchParams={searchParams}>
      <Suspense key={listSearchKey(searchParams)} fallback={null}>
        <SearchResults searchParams={searchParams} />
      </Suspense>
    </PaginatedListBoundary>
  )
}

async function fetchSearchResults(
  type: SearchMediaType,
  query: string,
  page?: string,
  year?: string
) {
  if (type === "movie") {
    return tmdb.search.movie({ query, page, year })
  }

  if (type === "tv") {
    return tmdb.search.tv({ query, page, year })
  }

  if (type === "person") {
    return tmdb.search.person({ query, page })
  }

  return tmdb.search.multi({ query, page })
}

async function SearchResults({ searchParams }: SearchProps) {
  const { t } = await getTranslator()
  const type = (searchParams.type ?? "all") as SearchMediaType
  const query = searchParams.q!

  let genres: Awaited<ReturnType<typeof tmdb.genres.movie>>["genres"] = []

  try {
    const genresResult =
      type === "tv" ? await tmdb.genres.tv() : await tmdb.genres.movie()
    genres = genresResult.genres
  } catch {
    genres = []
  }

  const response = await fetchSearchResults(
    type,
    query,
    searchParams.page,
    searchParams.year
  )

  const normalized = normalizeSearchResults(
    type,
    response.results as Array<Movie | TvShow | Person>
  )
  const filtered = filterSearchResults(normalized, searchParams)
  const results = sortSearchResults(filtered, searchParams.sort_by)

  if (!results.length) {
    return (
      <div className="container space-y-6">
        <SearchCommandBar genres={genres} type={type} />
        <div className="flex h-[33vh] items-end justify-center">
          <div className="text-center">
            <h1 className="text-2xl">{t("search.noResults")}</h1>
            <p className="text-muted-foreground">
              {t("search.noResultsFor", { query })}
              <br />
              {t("search.tryDifferent")}
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="container h-full">
      <div className="space-y-8">
        <div className="md:mb-12 md:mt-6">
          <h1 className="mb-2 text-2xl font-medium">{t("search.resultsFor")}</h1>
          <p className="text-xl text-muted-foreground">&quot;{query}&quot;</p>
        </div>

        <SearchCommandBar genres={genres} type={type} />

        <div className="grid-list">
          {results.map((result) => (
            <SearchResultCard key={`${result.media_type}-${result.id}`} media={result} />
          ))}
        </div>

        <ListPagination currentPage={response.page} totalPages={response.total_pages} />
      </div>
    </div>
  )
}
