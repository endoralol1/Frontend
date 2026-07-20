"use client"

import { usePathname, useSelectedLayoutSegments } from "next/navigation"

interface AppParallelRoutesProps {
  children: React.ReactNode
  modal: React.ReactNode
}

const MODAL_LIST_ROUTE_KEYS = new Set([
  "popular",
  "discover",
  "top-rated",
  "upcoming",
  "now-playing",
  "anime",
  "on-the-air",
  "airing-today",
])

function isMovieTvDetailPath(pathname: string) {
  return /^\/(?:movie|tv)\/\d+/.test(pathname)
}

/** True when `@modal` is a people/collection detail intercept, not a list bypass. */
function isDetailModalIntercept(modalSegments: string[]) {
  if (modalSegments.length === 0) return false
  if (modalSegments.some((segment) => MODAL_LIST_ROUTE_KEYS.has(segment))) {
    return false
  }
  if (
    modalSegments[0] === "collection" &&
    modalSegments[1] &&
    /^\d+$/.test(modalSegments[1])
  ) {
    return true
  }
  return modalSegments.some((segment) => /^\d+$/.test(segment))
}

/**
 * When a detail intercept modal is open over another page (e.g. home), skip the
 * underlay to cut background network. List routes use `_modal-list-bypass` (null)
 * and must keep `children` — that is the real page content on /movie/popular etc.
 */
export function AppParallelRoutes({ children, modal }: AppParallelRoutesProps) {
  const pathname = usePathname()
  const modalSegments = useSelectedLayoutSegments("modal")

  // Movie/TV details are full pages — always render children, skip modal slot.
  if (isMovieTvDetailPath(pathname)) {
    return <>{children}</>
  }

  const hideUnderlay = isDetailModalIntercept(modalSegments)

  return (
    <>
      {hideUnderlay ? null : children}
      {modal}
    </>
  )
}
