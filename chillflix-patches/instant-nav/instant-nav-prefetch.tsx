"use client"

import { useEffect } from "react"
import { useRouter } from "next/navigation"

import { pages } from "@/config"

const PRIMARY_ROUTES = [
  pages.home.link,
  pages.movie.discover.link,
  pages.tv.discover.link,
] as const

/**
 * Warm the Next.js router cache for primary nav targets so Movies/TV/Home
 * clicks feel instant after the first paint.
 */
export function InstantNavPrefetch() {
  const router = useRouter()

  useEffect(() => {
    let cancelled = false
    const run = () => {
      if (cancelled) return
      for (const href of PRIMARY_ROUTES) {
        try {
          router.prefetch(href)
        } catch {
          // ignore
        }
      }
    }

    // Prefer idle time so we don't contend with first paint / hero images.
    if (typeof window !== "undefined" && "requestIdleCallback" in window) {
      const id = window.requestIdleCallback(run, { timeout: 1800 })
      return () => {
        cancelled = true
        window.cancelIdleCallback(id)
      }
    }

    const timer = window.setTimeout(run, 400)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [router])

  return null
}
