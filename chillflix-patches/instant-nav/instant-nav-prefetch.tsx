"use client"

import { useEffect } from "react"
import { usePathname, useRouter } from "next/navigation"

import { pages } from "@/config"

const PRIMARY_ROUTES = [
  pages.home.link,
  pages.movie.discover.link,
  pages.tv.discover.link,
] as const

/**
 * Warm router cache for primary tabs and keep them fresh while browsing.
 */
export function InstantNavPrefetch() {
  const router = useRouter()
  const pathname = usePathname()

  useEffect(() => {
    let cancelled = false
    const run = () => {
      if (cancelled) return
      for (const href of PRIMARY_ROUTES) {
        if (href === pathname) continue
        try {
          router.prefetch(href)
        } catch {
          // ignore
        }
      }
    }

    if (typeof window !== "undefined" && "requestIdleCallback" in window) {
      const id = window.requestIdleCallback(run, { timeout: 900 })
      return () => {
        cancelled = true
        window.cancelIdleCallback(id)
      }
    }

    const timer = window.setTimeout(run, 200)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [router, pathname])

  // Re-warm on visibility so returning tabs stay hot
  useEffect(() => {
    const onVis = () => {
      if (document.visibilityState !== "visible") return
      for (const href of PRIMARY_ROUTES) {
        try {
          router.prefetch(href)
        } catch {
          // ignore
        }
      }
    }
    document.addEventListener("visibilitychange", onVis)
    return () => document.removeEventListener("visibilitychange", onVis)
  }, [router])

  return null
}
