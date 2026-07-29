"use client"

import { useEffect, useState } from "react"

/**
 * SSR-safe media query hook.
 * Always returns false on the server and on the first client render, then
 * updates after mount. The stock @custom-react-hooks/use-media-query uses
 * useState(window.matchMedia(...)) which mismatches SSR (false) vs client
 * hydration (true on mobile) and triggers React #418 / #423.
 */
export function useMediaQuery(query: string) {
  const [matches, setMatches] = useState(false)

  useEffect(() => {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
      return
    }

    const mediaQueryList = window.matchMedia(query)
    const onChange = (event: MediaQueryListEvent) => {
      setMatches(event.matches)
    }

    setMatches(mediaQueryList.matches)
    mediaQueryList.addEventListener("change", onChange)
    return () => mediaQueryList.removeEventListener("change", onChange)
  }, [query])

  return matches
}
