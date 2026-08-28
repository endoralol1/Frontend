import Link from "next/link"

import type { NewsArticle } from "@/lib/news/types"

function articleHref(article: NewsArticle, country: string) {
  return `/news/article/${article.id}?country=${encodeURIComponent(country)}`
}

function placeholder(seed: string) {
  const hue = Array.from(seed).reduce((n, ch) => n + ch.charCodeAt(0), 0) % 360
  return `data:image/svg+xml,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue},55%,35%)"/><stop offset="1" stop-color="hsl(${(hue + 40) % 360},45%,18%)"/></linearGradient></defs><rect width="1200" height="675" fill="url(#g)"/><text x="60" y="600" fill="rgba(255,255,255,.75)" font-family="Arial" font-size="42" font-weight="700">DAILY24</text></svg>`
  )}`
}

export function NewsHomeView({
  articles,
  country,
}: {
  articles: NewsArticle[]
  country: string
}) {
  if (articles.length === 0) {
    return (
      <div className="n24-empty">
        No stories right now. Try another country or refresh in a minute.
      </div>
    )
  }

  const [lead, sideA, sideB, ...rest] = articles
  const tagClass = (index: number) =>
    index % 3 === 1 ? "alt" : index % 3 === 2 ? "orange" : undefined

  return (
    <>
      <section className="n24-hero">
        <article className="n24-hero-lead">
          <Link href={articleHref(lead, country)}>
            <img
              src={lead.image || placeholder(lead.id)}
              alt=""
              loading="eager"
            />
            <span className="n24-tag">{lead.category}</span>
            <h1>{lead.title}</h1>
          </Link>
        </article>

        <div className="n24-side">
          {sideA ? (
            <article className="n24-side-card">
              <Link href={articleHref(sideA, country)}>
                <img
                  src={sideA.image || placeholder(sideA.id)}
                  alt=""
                  loading="lazy"
                />
                <span className="n24-tag alt">{sideA.category}</span>
                <h2>{sideA.title}</h2>
                {sideA.summary ? <p>{sideA.summary}</p> : null}
              </Link>
            </article>
          ) : null}

          {sideB ? (
            <article className="n24-side-card compact">
              <div>
                <Link href={articleHref(sideB, country)}>
                  <span className="n24-tag orange">{sideB.category}</span>
                  <h2>{sideB.title}</h2>
                  {sideB.summary ? <p>{sideB.summary}</p> : null}
                </Link>
              </div>
              <Link href={articleHref(sideB, country)}>
                <img
                  src={sideB.image || placeholder(sideB.id)}
                  alt=""
                  loading="lazy"
                />
              </Link>
            </article>
          ) : null}
        </div>
      </section>

      <div className="n24-ad">AD</div>

      <h2 className="n24-section-title">Latest</h2>
      <section className="n24-grid">
        {rest.map((article, index) => (
          <article key={article.id} className="n24-card">
            <Link href={articleHref(article, country)}>
              <img
                src={article.image || placeholder(article.id)}
                alt=""
                loading="lazy"
              />
              <span className={`n24-tag ${tagClass(index) || ""}`.trim()}>
                {article.category}
              </span>
              <h3>{article.title}</h3>
              {article.summary ? <p>{article.summary}</p> : null}
            </Link>
          </article>
        ))}
      </section>
    </>
  )
}
