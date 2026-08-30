"use client"

import { useEffect } from "react"
import { usePathname } from "next/navigation"

/**
 * Clears transient nav flags after route changes.
 *
 * Do NOT intercept clicks / router.push here — capture-phase preventDefault +
 * startViewTransition(router.push) broke Home/Movies/TV (clicks did nothing or
 * stayed on the wrong underlay). List + detail routes hard-navigate via
 * requiresFullPageNavigation; CSS @view-transition handles cross-document fades.
 */
export function SmoothClientNav() {
  const pathname = usePathname()

  useEffect(() => {
    try {
      delete document.documentElement.dataset.cfNav
    } catch {
      // ignore
    }
  }, [pathname])

  return null
}
