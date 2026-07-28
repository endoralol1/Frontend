"use client"

import { useEffect } from "react"

import { prefetchSitePlayerAdsEnabled } from "@/hooks/useSitePlayerAdsEnabled"

/**
 * Loads Monetag site-player popunder scripts early (not only after Watch opens).
 * Critical for mobile “Request Desktop Site”: the Watch tap must hit an already-armed
 * click listener while the user gesture is still valid.
 */
export function SitePlayerAdsPrime() {
    useEffect(() => {
        void prefetchSitePlayerAdsEnabled()

        const primeOnGesture = () => {
            void prefetchSitePlayerAdsEnabled()
        }

        window.addEventListener("pointerdown", primeOnGesture, {
            once: true,
            capture: true,
            passive: true,
        })
        window.addEventListener("touchstart", primeOnGesture, {
            once: true,
            capture: true,
            passive: true,
        })
        window.addEventListener("click", primeOnGesture, {
            once: true,
            capture: true,
            passive: true,
        })

        return () => {
            window.removeEventListener("pointerdown", primeOnGesture, true)
            window.removeEventListener("touchstart", primeOnGesture, true)
            window.removeEventListener("click", primeOnGesture, true)
        }
    }, [])

    return null
}
