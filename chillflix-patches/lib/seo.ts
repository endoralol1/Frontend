import type { Metadata } from "next"
import { siteConfig } from "@/config"
import { wsrvImage } from "@/tmdb/utils"

function normalizeSiteUrl(value: string) {
  return value.trim().replace(/\/$/, "")
}

export function getSiteUrl(): string {
  const fromEnv =
    process.env.NEXT_PUBLIC_SITE_URL || process.env.SITE_URL || ""

  if (fromEnv) {
    return normalizeSiteUrl(fromEnv)
  }

  return "https://www.chillflix.lol"
}

/** Canonical origin for /embed/* iframes (player domain). Falls back to getSiteUrl(). */
export function getEmbedSiteUrl(): string {
  const fromEnv =
    process.env.NEXT_PUBLIC_EMBED_SITE_URL ||
    process.env.NEXT_PUBLIC_PLAYER_SITE_URL ||
    process.env.EMBED_SITE_URL ||
    ""

  if (fromEnv) {
    return normalizeSiteUrl(fromEnv)
  }

  return getSiteUrl()
}

/** Public player / embed origin for third-party developers (chillflix.pw). */
export function getPublicPlayerSiteUrl(): string {
  const fromEnv =
    process.env.NEXT_PUBLIC_PUBLIC_PLAYER_SITE_URL?.trim() ||
    process.env.NEXT_PUBLIC_PLAYER_SITE_URL?.trim() ||
    ""

  if (fromEnv) {
    return normalizeSiteUrl(fromEnv)
  }

  return "https://chillflix.pw"
}

export function getPublicPlayerApiDocsUrl(): string {
  return `${getPublicPlayerSiteUrl()}/player-api`
}

export function getSiteOgImageUrl(): string {
  return `${getSiteUrl()}/opengraph-image`
}

function extractYear(date?: string | null): string | undefined {
  if (!date?.length) return undefined
  const year = date.slice(0, 4)
  return /^\d{4}$/.test(year) ? year : undefined
}

function buildSocialMetadata({
  title,
  description,
  url,
  images,
  type = "website",
}: {
  title: string
  description: string
  url: string
  images: NonNullable<Metadata["openGraph"]>["images"]
  type?: "website" | "video.movie" | "video.tv_show"
}): Pick<Metadata, "description" | "openGraph" | "twitter" | "alternates"> {
  const imageUrls =
    images?.map((image) =>
      typeof image === "string" ? image : image.url
    ) ?? []

  return {
    description,
    openGraph: {
      type,
      siteName: siteConfig.name,
      title,
      description,
      url,
      images,
    },
    twitter: {
      card: "summary_large_image",
      title,
      description,
      images: imageUrls.length > 0 ? imageUrls : undefined,
    },
    alternates: {
      canonical: url,
    },
  }
}

export function buildHomePageMetadata(): Metadata {
  const url = getSiteUrl()
  const title = siteConfig.share.siteTitle
  const description = siteConfig.share.siteDescription
  const images = [
    {
      url: getSiteOgImageUrl(),
      width: 1200,
      height: 630,
      alt: siteConfig.share.siteTitle,
    },
  ]

  return {
    title,
    ...buildSocialMetadata({
      title,
      description,
      url,
      images,
      type: "website",
    }),
  }
}

export function buildMoviePageMetadata({
  title,
  description,
  path,
  posterPath,
  releaseDate,
}: {
  title: string
  description?: string | null
  path: string
  posterPath?: string | null
  releaseDate?: string | null
}): Metadata {
  const url = `${getSiteUrl()}${path}`
  const year = extractYear(releaseDate)
  const displayTitle = year ? `${title} (${year})` : title
  const ogTitle = `${siteConfig.name} | ${displayTitle}`
  const desc =
    description?.trim().slice(0, 160) || siteConfig.description

  const images = posterPath
    ? [
        {
          url: wsrvImage.poster(posterPath, "w780"),
          width: 780,
          height: 1170,
          alt: displayTitle,
        },
      ]
    : [
        {
          url: getSiteOgImageUrl(),
          width: 1200,
          height: 630,
          alt: siteConfig.share.siteTitle,
        },
      ]

  return {
    title: {
      default: displayTitle,
      template: `%s | ${displayTitle} | ${siteConfig.name}`,
    },
    ...buildSocialMetadata({
      title: ogTitle,
      description: desc,
      url,
      images,
      type: "video.movie",
    }),
  }
}

export function buildTvPageMetadata({
  name,
  description,
  path,
  posterPath,
  firstAirDate,
}: {
  name: string
  description?: string | null
  path: string
  posterPath?: string | null
  firstAirDate?: string | null
}): Metadata {
  const url = `${getSiteUrl()}${path}`
  const year = extractYear(firstAirDate)
  const displayTitle = year ? `${name} (${year})` : name
  const ogTitle = `${siteConfig.name} | ${displayTitle}`
  const desc =
    description?.trim().slice(0, 160) || siteConfig.description

  const images = posterPath
    ? [
        {
          url: wsrvImage.poster(posterPath, "w780"),
          width: 780,
          height: 1170,
          alt: displayTitle,
        },
      ]
    : [
        {
          url: getSiteOgImageUrl(),
          width: 1200,
          height: 630,
          alt: siteConfig.share.siteTitle,
        },
      ]

  return {
    title: {
      default: displayTitle,
      template: `%s | ${displayTitle} | ${siteConfig.name}`,
    },
    ...buildSocialMetadata({
      title: ogTitle,
      description: desc,
      url,
      images,
      type: "video.tv_show",
    }),
  }
}

function buildAggregateRating(
  voteAverage?: number,
  voteCount?: number
): Record<string, unknown> | undefined {
  if (
    voteAverage == null ||
    voteCount == null ||
    voteCount <= 0 ||
    voteAverage <= 0
  ) {
    return undefined
  }

  return {
    "@type": "AggregateRating",
    ratingValue: Number(voteAverage.toFixed(1)),
    bestRating: 10,
    worstRating: 0,
    ratingCount: voteCount,
  }
}


export function buildListPageMetadata({
  title,
  description,
  path,
}: {
  title: string
  description: string
  path: string
}): Metadata {
  const normalizedPath = path.startsWith("/") ? path : `/${path}`
  const url = `${getSiteUrl()}${normalizedPath === "/" ? "" : normalizedPath}`
  const desc = description.trim().slice(0, 160) || siteConfig.description
  const images = [
    {
      url: getSiteOgImageUrl(),
      width: 1200,
      height: 630,
      alt: title,
    },
  ]

  return {
    title,
    ...buildSocialMetadata({
      title,
      description: desc,
      url,
      images,
      type: "website",
    }),
  }
}

export function buildWebsiteJsonLd() {
  const url = getSiteUrl()
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: siteConfig.name,
    url,
    description: siteConfig.share.siteDescription,
    potentialAction: {
      "@type": "SearchAction",
      target: {
        "@type": "EntryPoint",
        urlTemplate: `${url}/search?q={search_term_string}`,
      },
      "query-input": "required name=search_term_string",
    },
  }
}

export function buildOrganizationJsonLd() {
  const url = getSiteUrl()
  return {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: siteConfig.name,
    url,
    logo: `${url}/apple-touch-icon.png`,
    description: siteConfig.share.siteDescription,
  }
}

export function buildMovieJsonLd({
  id,
  title,
  description,
  posterPath,
  releaseDate,
  voteAverage,
  voteCount,
}: {
  id: number
  title: string
  description?: string | null
  posterPath?: string | null
  releaseDate?: string | null
  voteAverage?: number
  voteCount?: number
}) {
  const url = `${getSiteUrl()}/movie/${id}`
  const aggregateRating = buildAggregateRating(voteAverage, voteCount)

  return {
    "@context": "https://schema.org",
    "@type": "Movie",
    name: title,
    description: description?.trim() || undefined,
    url,
    image: posterPath
      ? wsrvImage.poster(posterPath, "w500")
      : undefined,
    datePublished: releaseDate || undefined,
    aggregateRating,
  }
}

export function buildTvSeriesJsonLd({
  id,
  name,
  description,
  posterPath,
  firstAirDate,
  voteAverage,
  voteCount,
}: {
  id: number
  name: string
  description?: string | null
  posterPath?: string | null
  firstAirDate?: string | null
  voteAverage?: number
  voteCount?: number
}) {
  const url = `${getSiteUrl()}/tv/${id}`
  const aggregateRating = buildAggregateRating(voteAverage, voteCount)

  return {
    "@context": "https://schema.org",
    "@type": "TVSeries",
    name,
    description: description?.trim() || undefined,
    url,
    image: posterPath ? wsrvImage.poster(posterPath, "w500") : undefined,
    datePublished: firstAirDate || undefined,
    aggregateRating,
  }
}
