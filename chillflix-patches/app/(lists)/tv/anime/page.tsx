import { tmdb } from "@/tmdb/api"

import { PaginatedListBoundary } from "@/components/paginated-list-boundary"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"
import { ListBrowsePage } from "@/components/list-browse-page"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).tv.anime
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default function AnimeTvPage({ searchParams }: ListPageProps) {
  return (
    <PaginatedListBoundary searchParams={searchParams}>
      <AnimeTvContent searchParams={searchParams} />
    </PaginatedListBoundary>
  )
}

async function AnimeTvContent({ searchParams }: ListPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).tv.anime

  const [discoverResult, genresResult] = await Promise.all([
    tmdb.discover.tv({
      with_genres: "16",
      with_original_language: "ja",
      sort_by: "popularity.desc",
      page: searchParams?.page,
    }),
    tmdb.genres.tv(),
  ])

  const { results: shows, page: currentPage, total_pages: totalPages } =
    discoverResult

  return (
    <ListBrowsePage
      type="tv"
      title={page.title}
      description={page.description}
      items={shows}
      genres={genresResult.genres}
      currentPage={currentPage}
      totalPages={totalPages}
    />
  )
}
