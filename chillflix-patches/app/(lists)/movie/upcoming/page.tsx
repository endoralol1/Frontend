import { PaginatedMovieList } from "@/components/paginated-movie-list"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).movie.upcoming
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default async function Upcoming({ searchParams }: ListPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).movie.upcoming

  return (
    <PaginatedMovieList
      searchParams={searchParams}
      list="upcoming"
      page={searchParams?.page ?? "1"}
      title={page.title}
      description={page.description}
    />
  )
}
