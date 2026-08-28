import { NewsHeader } from "@/components/news/news-header"
import { NewsHomeView } from "@/components/news/news-home-view"
import { getNewsCountry } from "@/lib/news/countries"
import { fetchNewsFeed } from "@/lib/news/rss"

export const revalidate = 300

export default async function NewsHomePage({
  searchParams,
}: {
  searchParams: { country?: string; category?: string; q?: string }
}) {
  const country = getNewsCountry(searchParams.country)
  const category = searchParams.category || "top"
  const query = (searchParams.q || "").trim().toLowerCase()

  let articles = await fetchNewsFeed({
    countryCode: country.code,
    category,
    limit: 36,
  })

  if (query) {
    articles = articles.filter(
      (article) =>
        article.title.toLowerCase().includes(query) ||
        article.summary.toLowerCase().includes(query) ||
        article.sourceName.toLowerCase().includes(query)
    )
  }

  return (
    <>
      <NewsHeader country={country.code} category={category} />
      <main className="n24-main">
        <div className="n24-wrap">
          <NewsHomeView articles={articles} country={country.code} />
        </div>
      </main>
    </>
  )
}
