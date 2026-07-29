import { PaginatedTvList } from "@/components/paginated-tv-list"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).tv.airingToday
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default async function AiringToday({ searchParams }: ListPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).tv.airingToday

  return (
    <PaginatedTvList
      searchParams={searchParams}
      list="airing_today"
      page={searchParams?.page ?? "1"}
      title={page.title}
      description={page.description}
    />
  )
}
