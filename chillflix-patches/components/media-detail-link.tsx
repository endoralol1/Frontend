"use client"

import Link from "next/link"
import type { ComponentProps } from "react"

import { clearDetailModalSession } from "@/lib/detail-modal-session"

type MediaDetailLinkProps = ComponentProps<typeof Link>

function isMovieTvDetailHref(href: MediaDetailLinkProps["href"]) {
  if (typeof href !== "string") return false
  return /^\/(?:movie|tv)\/\d+/.test(href.split("?")[0] ?? href)
}

/** Full-page navigation for movie/TV details (avoids stale parallel-route soft nav). */
export function MediaDetailLink({
  href,
  onClick,
  prefetch = false,
  scroll,
  ...props
}: MediaDetailLinkProps) {
  return (
    <Link
      href={href}
      prefetch={prefetch}
      scroll={scroll}
      {...props}
      onClick={(event) => {
        onClick?.(event)
        if (event.defaultPrevented) return

        clearDetailModalSession()

        if (isMovieTvDetailHref(href)) {
          event.preventDefault()
          window.location.assign(href as string)
        }
      }}
    />
  )
}
