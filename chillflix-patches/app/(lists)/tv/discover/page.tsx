import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"
import { DiscoverTvPageBoundary } from "./discover-tv-page"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).tv.discover
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default function Discover({ searchParams }: ListPageProps) {
  return <DiscoverTvPageBoundary searchParams={searchParams} />
}
