import Link from "next/link"

import type { NewsArticle } from "@/lib/news/types"

function placeholder(seed: string) {
  const hue = Array.from(seed).reduce((n, ch) => n + ch.charCodeAt(0), 0) % 360
  return `data:image/svg+xml,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="675" viewBox="0 0 1200 675"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="hsl(${hue},55%,35%)"/><stop offset="1" stop-color="hsl(${(hue + 40) % 360},45%,18%)"/></linearGradient></defs><rect width="1200" height="675" fill="url(#g)"/></svg>`
  )}`
}

export function NewsArticleView({
  article,
  country,
}: {
  article: NewsArticle
  country: string
}) {
  const published = article.publishedAt
    ? new Date(article.publishedAt).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
      })
    : null

  return (
    <article className="n24-article">
      <Link href={`/news?country=${encodeURIComponent(country)}`}>← Back to news</Link>
      <div>
        <span className="n24-tag">{article.category}</span>
      </div>
      <h1>{article.title}</h1>
      <div className="n24-article-meta">
        {published ? <span>{published} · </span> : null}
        <span>{article.sourceName}</span>
      </div>
      <img src={article.image || placeholder(article.id)} alt="" />
      <div className="n24-article-body">
        {article.summary ? (
          <p className="n24-summary" style={{ fontSize: "1.05rem", color: "#222" }}>
            {article.summary}
          </p>
        ) : (
          <p>
            Full story is available from the original publisher. Open the source
            link below for the complete article.
          </p>
        )}
        <p style={{ marginTop: "1.25rem" }}>
          <a
            href={article.url}
            target="_blank"
            rel="noopener noreferrer"
            style={{ color: "#e30613", fontWeight: 800 }}
          >
            Read the full article on {article.sourceName} →
          </a>
        </p>
      </div>

      <aside className="n24-credits" aria-label="Source credits">
        <strong>Source / credits</strong>
        This story was aggregated from{" "}
        <a href={article.sourceUrl || article.url} target="_blank" rel="noopener noreferrer">
          {article.sourceName}
        </a>
        . We do not claim ownership of the original reporting. Please visit the
        publisher for the full article, corrections, and licensing.
        <div style={{ marginTop: "0.55rem" }}>
          Original URL:{" "}
          <a href={article.url} target="_blank" rel="noopener noreferrer">
            {article.url}
          </a>
        </div>
      </aside>
    </article>
  )
}
