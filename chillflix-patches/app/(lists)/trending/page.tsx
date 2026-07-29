import { TrendList } from "@/components/trend-list"
import { TrendingCommandBar } from "@/components/trending-command-bar"
import { PaginatedListBoundary } from "@/components/paginated-list-boundary"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"

interface TrendingPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata({ searchParams }: TrendingPageProps) {
  const { t } = await getTranslator()
  const pages = getLocalizedPages(t)
  const type = searchParams?.type === "tv" ? "tv" : "movie"
  const section = type === "tv" ? pages.trending.tv : pages.trending.movie

  return buildListPageMetadata({
    title: `${pages.trending.root.title} – ${section.title}`,
    description: section.description,
    path: pages.trending.root.link,
  })
}

export default async function TrendingPage({ searchParams }: TrendingPageProps) {
  const { t } = await getTranslator()
  const pages = getLocalizedPages(t)
  const type = searchParams?.type === "tv" ? "tv" : "movie"
  const section = type === "tv" ? pages.trending.tv : pages.trending.movie

  return (
    <PaginatedListBoundary searchParams={searchParams}>
      <TrendList
        type={type}
        time="day"
        title={section.title}
        description={section.description}
        page={searchParams?.page ?? "1"}
        toolbar={<TrendingCommandBar />}
      />
    </PaginatedListBoundary>
  )
}
