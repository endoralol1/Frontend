import Link from "next/link"
import { Suspense } from "react"

import { NEWS_CATEGORIES } from "@/lib/news/countries"
import { NewsCountryToggle } from "@/components/news/news-country-toggle"

export function NewsHeader({
  country,
  category = "top",
}: {
  country: string
  category?: string
}) {
  const qs = `country=${encodeURIComponent(country)}`

  return (
    <>
      <header className="n24-topbar">
        <div className="n24-wrap n24-topbar-inner">
          <Link href={`/news?${qs}`} className="n24-logo" aria-label="Daily24 home">
            <span className="n24-logo-24">24</span>
            <span className="n24-logo-name">DAILY</span>
          </Link>

          <button type="button" className="n24-burger" aria-label="Menu">
            <span />
            <span />
            <span />
          </button>

          <nav className="n24-nav" aria-label="Sections">
            <Link href={`/news?${qs}`} className="n24-plus">
              Top
            </Link>
            {NEWS_CATEGORIES.filter((item) => item.id !== "top").map((item) => (
              <Link
                key={item.id}
                href={`/news?${qs}&category=${item.id}`}
                className={category === item.id ? "is-active" : undefined}
              >
                {item.label}
              </Link>
            ))}
          </nav>

          <div className="n24-actions">
            <Suspense
              fallback={
                <select className="n24-country" disabled aria-label="Country and language">
                  <option>{country}</option>
                </select>
              }
            >
              <NewsCountryToggle current={country} />
            </Suspense>
          </div>
        </div>
      </header>

      <div className="n24-ask">
        <form className="n24-wrap n24-ask-inner" action="/news" method="get">
          <input type="hidden" name="country" value={country} />
          {category !== "top" ? (
            <input type="hidden" name="category" value={category} />
          ) : null}
          <span className="n24-ask-brand">24ASK</span>
          <input
            name="q"
            placeholder="+ Ask anything about the news…"
            aria-label="Search news"
          />
          <button type="submit" aria-label="Search">
            →
          </button>
        </form>
      </div>
    </>
  )
}
