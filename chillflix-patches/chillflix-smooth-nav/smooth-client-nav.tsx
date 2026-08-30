"use client"

import { useEffect } from "react"
import { usePathname, useRouter } from "next/navigation"

import { requiresFullPageNavigation } from "@/lib/list-page-paths"

function prefersReducedMotion() {
  try {
    return !!window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches
  } catch {
    return false
  }
}

function isModifiedClick(event: MouseEvent) {
  return event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0
}

/**
 * Same-document navigations: wrap router.push in View Transitions.
 * Detail pages still hard-navigate (requiresFullPageNavigation).
 * Primary tabs (Home / Movies / TV lists) soft-navigate — instant like vuflix.
 */
export function SmoothClientNav() {
  const router = useRouter()
  const pathname = usePathname()

  useEffect(() => {
    try {
      delete document.documentElement.dataset.cfNav
    } catch {
      // ignore
    }
  }, [pathname])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (isModifiedClick(event)) return

      const target = event.target
      if (!(target instanceof Element)) return
      const anchor = target.closest("a")
      if (!anchor) return
      if (anchor.target && anchor.target !== "_self") return
      if (anchor.hasAttribute("download")) return

      const hrefAttr = anchor.getAttribute("href")
      if (!hrefAttr || hrefAttr.startsWith("#")) return

      let url: URL
      try {
        url = new URL(hrefAttr, window.location.href)
      } catch {
        return
      }
      if (url.origin !== window.location.origin) return
      if (url.pathname === window.location.pathname && url.search === window.location.search) {
        return
      }

      // Title details stay full document loads.
      if (requiresFullPageNavigation(url.pathname + url.search)) return

      event.preventDefault()
      try {
        document.documentElement.dataset.cfNav = "1"
      } catch {
        // ignore
      }

      const next = url.pathname + url.search + url.hash
      const go = () => {
        router.push(next, { scroll: true })
      }

      if (!prefersReducedMotion() && typeof document.startViewTransition === "function") {
        document.startViewTransition(go)
        return
      }
      go()
    }

    document.addEventListener("click", onClick, true)
    return () => document.removeEventListener("click", onClick, true)
  }, [router])

  return null
}
