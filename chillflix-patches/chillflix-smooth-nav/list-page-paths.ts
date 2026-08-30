/** Movie/TV/people list slugs (not numeric ids). Used for browse detection. */
const MOVIE_LIST_SLUGS = new Set([
  "discover",
  "popular",
  "top-rated",
  "upcoming",
  "now-playing",
  "anime",
])

const TV_LIST_SLUGS = new Set([
  "discover",
  "popular",
  "top-rated",
  "airing-today",
  "on-the-air",
  "anime",
])

const PEOPLE_LIST_SLUGS = new Set(["popular"])

function pathOnly(href: string) {
  const raw = href.split("?")[0]?.split("#")[0] ?? href
  return raw.replace(/\/$/, "") || "/"
}

/** Paginated browse routes (lists / search / trending). */
export function isBrowseListPath(pathname: string) {
  const path = pathOnly(pathname)
  if (path === "/search") return true
  if (path === "/trending") return true
  if (path.startsWith("/trending/")) return true

  const movieMatch = path.match(/^\/movie\/([^/]+)$/)
  if (movieMatch && MOVIE_LIST_SLUGS.has(movieMatch[1])) return true

  const tvMatch = path.match(/^\/tv\/([^/]+)$/)
  if (tvMatch && TV_LIST_SLUGS.has(tvMatch[1])) return true

  const peopleMatch = path.match(/^\/people\/([^/]+)$/)
  if (peopleMatch && PEOPLE_LIST_SLUGS.has(peopleMatch[1])) return true

  return false
}

/**
 * Routes that must use a real document navigation.
 *
 * List routes (`/movie/discover`, `/tv/popular`, …) soft-navigate — `@modal/(.)…`
 * already exports a null bypass for those slugs so the underlay updates correctly.
 *
 * Keep hard-nav only for title/person/collection detail URLs so we don't open the
 * Netflix-style intercept overlay when the product wants a full detail page.
 */
export function requiresFullPageNavigation(href: string) {
  const path = pathOnly(href)

  if (/^\/(?:movie|tv)\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  if (/^\/people\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  if (/^\/collection\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  return false
}
