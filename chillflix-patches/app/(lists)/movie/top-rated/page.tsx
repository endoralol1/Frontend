import { PaginatedMovieList } from "@/components/paginated-movie-list"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).movie.topRated
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default async function TopRated({ searchParams }: ListPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).movie.topRated

  return (
    <PaginatedMovieList
      searchParams={searchParams}
      list="top_rated"
      page={searchParams?.page ?? "1"}
      title={page.title}
      description={page.description}
    />
  )
}
