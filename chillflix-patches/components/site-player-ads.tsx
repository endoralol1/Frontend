"use client"

import { useEffect } from "react"

import { injectEmbedAdScripts } from "@/lib/embed-ads-inject"
import {
    installSitePlayerAdGuard,
    uninstallSitePlayerAdGuard,
} from "@/lib/site-player-ad-guard"

/** Loads Monetag zone 11200416 on the first-party site player modal. */
export function SitePlayerAds() {
    useEffect(() => {
        let cancelled = false

        installSitePlayerAdGuard()

        void fetch("/api/embed/ads?surface=site-player", { cache: "no-store" })
            .then((response) => response.json())
            .then((data) => {
                if (cancelled) return
                if (data?.showAds && data.scripts) {
                    injectEmbedAdScripts(data.scripts)
                }
            })
            .catch(() => {
                // fail closed — no ads if config cannot be loaded
            })

        return () => {
            cancelled = true
            uninstallSitePlayerAdGuard()
        }
    }, [])

    return null
}
