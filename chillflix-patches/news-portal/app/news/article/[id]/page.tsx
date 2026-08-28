import { notFound } from "next/navigation"

import { NewsArticleView } from "@/components/news/news-article-view"
import { NewsHeader } from "@/components/news/news-header"
import { getNewsCountry } from "@/lib/news/countries"
import { findNewsArticle } from "@/lib/news/rss"

export const revalidate = 300

export default async function NewsArticlePage({
  params,
  searchParams,
}: {
  params: { id: string }
  searchParams: { country?: string }
}) {
  const country = getNewsCountry(searchParams.country)
  const article = await findNewsArticle(params.id, country.code)

  if (!article) {
    notFound()
  }

  return (
    <>
      <NewsHeader country={country.code} />
      <main className="n24-main">
        <div className="n24-wrap">
          <NewsArticleView article={article} country={country.code} />
        </div>
      </main>
    </>
  )
}
