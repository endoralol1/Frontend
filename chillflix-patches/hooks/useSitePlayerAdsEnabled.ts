"use client"

import { useEffect, useState } from "react"

let cachedEnabled: boolean | null = null
let inflight: Promise<boolean> | null = null

/** Prefetch whether site-player ads are enabled (does not inject scripts). */
export function prefetchSitePlayerAdsEnabled() {
    if (cachedEnabled !== null) {
        return Promise.resolve(cachedEnabled)
    }

    if (inflight) {
        return inflight
    }

    inflight = fetch("/api/embed/ads?surface=site-player", {
        cache: "no-store",
        credentials: "include",
    })
        .then((response) => response.json())
        .then((data) => {
            cachedEnabled = Boolean(data?.showAds)
            return cachedEnabled
        })
        .catch(() => {
            cachedEnabled = false
            return false
        })
        .finally(() => {
            inflight = null
        })

    return inflight
}

export function useSitePlayerAdsEnabled() {
    const [enabled, setEnabled] = useState<boolean | null>(cachedEnabled)

    useEffect(() => {
        if (cachedEnabled !== null) {
            setEnabled(cachedEnabled)
            return
        }

        void prefetchSitePlayerAdsEnabled().then(setEnabled)
    }, [])

    return {
        enabled: enabled === true,
        loading: enabled === null,
    }
}
