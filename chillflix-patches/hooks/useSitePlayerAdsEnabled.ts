"use client"

import { useEffect, useState } from "react"

import { injectEmbedAdScripts, type EmbedAdScriptBundle } from "@/lib/embed-ads-inject"

let cachedEnabled: boolean | null = null
let cachedScripts: EmbedAdScriptBundle | null = null
let inflight: Promise<boolean> | null = null
let scriptsInjected = false

function maybeInjectCachedScripts() {
    if (scriptsInjected || !cachedEnabled || !cachedScripts) return
    if (typeof document === "undefined") return
    injectEmbedAdScripts(cachedScripts)
    scriptsInjected = true
}

/** Prefetch site-player ad config and inject Monetag early so the next tap can pop. */
export function prefetchSitePlayerAdsEnabled() {
    if (cachedEnabled !== null) {
        maybeInjectCachedScripts()
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
            if (cachedEnabled && data?.scripts) {
                cachedScripts = data.scripts as EmbedAdScriptBundle
                maybeInjectCachedScripts()
            }
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
            maybeInjectCachedScripts()
            return
        }

        void prefetchSitePlayerAdsEnabled().then(setEnabled)
    }, [])

    return {
        enabled: enabled === true,
        loading: enabled === null,
    }
}
