import { PersonList } from "@/components/person-list"
import { PaginatedListBoundary } from "@/components/paginated-list-boundary"
import { getLocalizedPages } from "@/lib/i18n/localized-pages"
import { getTranslator } from "@/lib/i18n/server"
import { buildListPageMetadata } from "@/lib/seo"

interface ListPageProps {
  searchParams?: Record<string, string>
}

export async function generateMetadata() {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).people.popular
  return buildListPageMetadata({
    title: page.title,
    description: page.description,
    path: page.link,
  })
}

export default async function Popular({ searchParams }: ListPageProps) {
  const { t } = await getTranslator()
  const page = getLocalizedPages(t).people.popular

  return (
    <PaginatedListBoundary searchParams={searchParams}>
      <PersonList
        list="popular"
        page={searchParams?.page ?? "1"}
        title={page.title}
        description={page.description}
      />
    </PaginatedListBoundary>
  )
}
