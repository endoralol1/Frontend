/** Movie/TV/people list slugs intercepted as `[id]` by `@modal/(.)…/[id]` — need full navigation. */
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

/** Paginated browse routes where ?page= must use a full document navigation. */
export function isBrowseListPath(pathname: string) {
  const path = pathOnly(pathname)
  if (path === "/search") return true
  if (path === "/trending") return true
  if (path.startsWith("/trending/")) return true
  return requiresFullPageNavigation(pathname)
}

/**
 * Soft Next Link breaks these routes (URL can change / click no-ops while the
 * @modal intercept keeps the underlay). Use a real document navigation instead.
 */
export function requiresFullPageNavigation(href: string) {
  const path = pathOnly(href)

  if (path === "/search") return true
  if (path === "/trending") return true
  if (path.startsWith("/trending/")) return true

  // Title details: avoid Netflix-style @modal intercept overlay
  if (/^\/(?:movie|tv)\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  if (/^\/people\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  if (/^\/collection\/\d+(?:\/|$)/.test(path)) {
    return true
  }

  const movieMatch = path.match(/^\/movie\/([^/]+)$/)
  if (movieMatch && MOVIE_LIST_SLUGS.has(movieMatch[1])) {
    return true
  }

  const tvMatch = path.match(/^\/tv\/([^/]+)$/)
  if (tvMatch && TV_LIST_SLUGS.has(tvMatch[1])) {
    return true
  }

  const peopleMatch = path.match(/^\/people\/([^/]+)$/)
  if (peopleMatch && PEOPLE_LIST_SLUGS.has(peopleMatch[1])) {
    return true
  }

  return false
}
