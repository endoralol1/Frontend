import { TrendList } from "@/components/trend-list"
import { PaginatedListBoundary } from "@/components/paginated-list-boundary"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"

interface TrendingPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).trending.people
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default async function TrendingPage({ searchParams }: TrendingPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).trending.people

  return (
    <PaginatedListBoundary searchParams={searchParams}>
      <TrendList
        type="people"
        time="day"
        title={page.title}
        description={page.description}
        page={searchParams?.page ?? "1"}
      />
    </PaginatedListBoundary>
  )
}
