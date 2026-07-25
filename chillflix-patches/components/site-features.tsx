"use client"

import { createContext, useContext, useEffect, useState, type ReactNode } from "react"

import type { SiteFeatureFlags } from "@/lib/site-settings"

export type SiteFeatures = SiteFeatureFlags & {
    chatEnabled: boolean
    apkDownloadEnabled: boolean
    apkDownloadUrl: string | null
    apkVersionLabel: string | null
    sharePromptEnabled: boolean
    communityPromptEnabled: boolean
    discordInviteUrl: string | null
    telegramInviteUrl: string | null
}

const DEFAULTS: SiteFeatures = {
    watchPartyEnabled: true,
    continueWatchingEnabled: true,
    playersEnabled: true,
    iptvEnabled: true,
    musicEnabled: true,
    ticketsEnabled: true,
    // Prefer off until server/API says otherwise — avoids pulling chat JS by default.
    chatEnabled: false,
    apkDownloadEnabled: false,
    apkDownloadUrl: null,
    apkVersionLabel: null,
    sharePromptEnabled: true,
    communityPromptEnabled: true,
    discordInviteUrl: "https://discord.gg/6r5KTZgqXV",
    telegramInviteUrl: "https://t.me/chillflixlol",
}

const SiteFeaturesContext = createContext<SiteFeatures>(DEFAULTS)

export const SITE_FEATURES_UPDATED_EVENT = "chillflix:site-features-updated"

export function useSiteFeatures() {
    return useContext(SiteFeaturesContext)
}

function mapFeatures(data: Partial<SiteFeatures>): SiteFeatures {
    return {
        watchPartyEnabled: data.watchPartyEnabled !== false,
        continueWatchingEnabled: data.continueWatchingEnabled !== false,
        playersEnabled: data.playersEnabled !== false,
        iptvEnabled: data.iptvEnabled !== false,
        musicEnabled: data.musicEnabled !== false,
        ticketsEnabled: data.ticketsEnabled !== false,
        chatEnabled: data.chatEnabled === true,
        apkDownloadEnabled:
            Boolean(data.apkDownloadEnabled) && Boolean(data.apkDownloadUrl),
        apkDownloadUrl: data.apkDownloadUrl ?? null,
        apkVersionLabel: data.apkVersionLabel ?? null,
        sharePromptEnabled: data.sharePromptEnabled !== false,
        communityPromptEnabled: data.communityPromptEnabled !== false,
        discordInviteUrl:
            typeof data.discordInviteUrl === "string" && data.discordInviteUrl.trim()
                ? data.discordInviteUrl.trim()
                : "https://discord.gg/6r5KTZgqXV",
        telegramInviteUrl:
            typeof data.telegramInviteUrl === "string" && data.telegramInviteUrl.trim()
                ? data.telegramInviteUrl.trim()
                : "https://t.me/chillflixlol",
    }
}

async function fetchSiteFeatures(): Promise<SiteFeatures> {
    const res = await fetch("/api/site/status", { cache: "no-store" })
    const data = await res.json()
    return mapFeatures(data)
}

export function SiteFeaturesProvider({
    children,
    initialFeatures,
}: {
    children: ReactNode
    initialFeatures?: Partial<SiteFeatures>
}) {
    const [features, setFeatures] = useState<SiteFeatures>(() =>
        initialFeatures ? mapFeatures(initialFeatures) : DEFAULTS
    )

    useEffect(() => {
        void fetchSiteFeatures()
            .then(setFeatures)
            .catch(() => {})

        const onUpdate = () => {
            void fetchSiteFeatures()
                .then(setFeatures)
                .catch(() => {})
        }

        window.addEventListener(SITE_FEATURES_UPDATED_EVENT, onUpdate)
        return () => window.removeEventListener(SITE_FEATURES_UPDATED_EVENT, onUpdate)
    }, [])

    return (
        <SiteFeaturesContext.Provider value={features}>{children}</SiteFeaturesContext.Provider>
    )
}
