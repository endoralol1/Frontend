import { createHash } from "crypto"
import * as cheerio from "cheerio"

import {
  NEWS_CATEGORIES,
  getNewsCountry,
  type NewsCategoryId,
} from "./countries"
import type { NewsArticle, NewsCountry } from "./types"

const USER_AGENT =
  "Mozilla/5.0 (compatible; Daily24NewsBot/1.0; +https://chillflix.lol/news)"

function articleId(url: string): string {
  return createHash("sha1").update(url).digest("hex").slice(0, 16)
}

function stripHtml(input: string): string {
  return input
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/\s+/g, " ")
    .trim()
}

function firstImageFromHtml(html: string): string | null {
  const match = html.match(/<img[^>]+src=["']([^"']+)["']/i)
  return match?.[1] || null
}

function pickCategoryLabel(raw: string | undefined, fallback: string): string {
  const cleaned = (raw || "").trim()
  if (!cleaned) return fallback.toUpperCase()
  return cleaned.slice(0, 42).toUpperCase()
}

function googleNewsUrl(
  country: NewsCountry,
  category: NewsCategoryId
): string {
  const base = "https://news.google.com/rss"
  const q = `hl=${encodeURIComponent(country.hl)}&gl=${encodeURIComponent(country.gl)}&ceid=${encodeURIComponent(country.ceid)}`

  if (category === "top") {
    return `${base}?${q}`
  }

  const topicMap: Record<Exclude<NewsCategoryId, "top">, string> = {
    world: "WORLD",
    business: "BUSINESS",
    technology: "TECHNOLOGY",
    sports: "SPORTS",
    entertainment: "ENTERTAINMENT",
    health: "HEALTH",
  }

  return `${base}/headlines/section/topic/${topicMap[category]}?${q}`
}

function bbcFallbackUrl(category: NewsCategoryId): string | null {
  switch (category) {
    case "world":
    case "top":
      return "https://feeds.bbci.co.uk/news/world/rss.xml"
    case "technology":
      return "https://feeds.bbci.co.uk/news/technology/rss.xml"
    case "business":
      return "https://feeds.bbci.co.uk/news/business/rss.xml"
    case "sports":
      return "https://feeds.bbci.co.uk/sport/rss.xml"
    case "entertainment":
      return "https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml"
    case "health":
      return "https://feeds.bbci.co.uk/news/health/rss.xml"
    default:
      return null
  }
}

async function fetchXml(url: string): Promise<string> {
  const res = await fetch(url, {
    headers: {
      "User-Agent": USER_AGENT,
      Accept: "application/rss+xml, application/xml, text/xml, */*",
    },
    next: { revalidate: 300 },
  })
  if (!res.ok) {
    throw new Error(`RSS fetch failed ${res.status} for ${url}`)
  }
  return res.text()
}

function parseRss(
  xml: string,
  meta: {
    country: string
    lang: string
    categoryLabel: string
    defaultSource: string
  }
): NewsArticle[] {
  const $ = cheerio.load(xml, { xml: true })
  const items: NewsArticle[] = []

  $("item").each((_, el) => {
    const node = $(el)
    const title = stripHtml(node.find("title").first().text())
    let link =
      node.find("link").first().text().trim() ||
      node.find("link").first().attr("href") ||
      ""
    if (!link) {
      link = node.find("guid").first().text().trim()
    }
    if (!title || !link) return

    const descriptionHtml =
      node.find("description").first().html() ||
      node.find("description").first().text() ||
      ""
    const summary = stripHtml(descriptionHtml).slice(0, 320)
    const mediaContent =
      node.find("media\\:content").attr("url") ||
      node.find("media\\:thumbnail").attr("url") ||
      node.find("enclosure").attr("url") ||
      firstImageFromHtml(descriptionHtml)

    const sourceName =
      node.find("source").first().text().trim() ||
      node.find("dc\\:creator").first().text().trim() ||
      meta.defaultSource
    const sourceUrl = node.find("source").attr("url") || null
    const publishedAt = node.find("pubDate").first().text().trim() || null

    items.push({
      id: articleId(link),
      title,
      summary,
      url: link,
      image: mediaContent || null,
      publishedAt,
      sourceName,
      sourceUrl,
      category: pickCategoryLabel(undefined, meta.categoryLabel),
      country: meta.country,
      lang: meta.lang,
    })
  })

  return items
}

export async function fetchNewsFeed(options: {
  countryCode?: string | null
  category?: string | null
  limit?: number
}): Promise<NewsArticle[]> {
  const country = getNewsCountry(options.countryCode)
  const category =
    (NEWS_CATEGORIES.find((c) => c.id === options.category)?.id as
      | NewsCategoryId
      | undefined) || "top"
  const categoryMeta =
    NEWS_CATEGORIES.find((c) => c.id === category) || NEWS_CATEGORIES[0]
  const limit = Math.min(Math.max(options.limit ?? 36, 6), 60)

  const urls: { url: string; defaultSource: string }[] = [
    {
      url: googleNewsUrl(country, category),
      defaultSource: "Google News",
    },
  ]

  if (country.code === "GLOBAL") {
    const bbc = bbcFallbackUrl(category)
    if (bbc) urls.push({ url: bbc, defaultSource: "BBC News" })
  }

  const collected: NewsArticle[] = []
  const seen = new Set<string>()

  for (const entry of urls) {
    try {
      const xml = await fetchXml(entry.url)
      const parsed = parseRss(xml, {
        country: country.code,
        lang: country.lang,
        categoryLabel: categoryMeta.label,
        defaultSource: entry.defaultSource,
      })
      for (const article of parsed) {
        if (seen.has(article.id)) continue
        seen.add(article.id)
        collected.push(article)
      }
    } catch (error) {
      console.error("[news] feed error", entry.url, error)
    }
  }

  return collected.slice(0, limit)
}

export async function findNewsArticle(
  id: string,
  countryCode?: string | null
): Promise<NewsArticle | null> {
  // Search across top categories for the hashed id (RSS has no stable lookup).
  const categories: NewsCategoryId[] = [
    "top",
    "world",
    "business",
    "technology",
    "sports",
    "entertainment",
    "health",
  ]

  for (const category of categories) {
    const feed = await fetchNewsFeed({
      countryCode,
      category,
      limit: 48,
    })
    const hit = feed.find((a) => a.id === id)
    if (hit) return hit
  }
  return null
}
