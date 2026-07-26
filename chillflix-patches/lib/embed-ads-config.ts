import "server-only"

import type { RowDataPacket } from "mysql2/promise"

import { execute, queryOne } from "@/lib/db"
import { canManageSiteSettings, type UserRole } from "@/lib/permissions"
import { getPublicPlayerSiteUrl } from "@/lib/seo"
import { resolveCrossOriginStaticUrl } from "@/lib/playback-proxy-origin"
import type { EmbedAdScriptBundle } from "@/lib/embed-ads-inject"
import {
    EMBED_ADS_ACLIB_FIRSTPARTY_PATH,
    EMBED_ADS_ANTIBLOCK_BOOT_PATH,
    EMBED_ADS_EMBED_TAG_ZONE,
    EMBED_ADS_JNBHI_TAG_SCRIPT_SRC,
    EMBED_ADS_LLVPN_TAG_SCRIPT_SRC,
    EMBED_ADS_SITE_TAG_ZONE,
    EMBED_ADS_TAG_ON_ERROR,
    EMBED_ADS_TAG_ON_LOAD,
    EMBED_ADS_TAG_ZONE,
    EMBED_ADS_VIGNETTE_SCRIPT_SRC,
    EMBED_ADS_VIGNETTE_ZONE,
    type VignetteAdConfig,
} from "@/lib/embed-ads-shared"

export {
    EMBED_ADS_EMBED_TAG_ZONE,
    EMBED_ADS_LLVPN_TAG_SCRIPT_SRC,
    EMBED_ADS_SITE_TAG_ZONE,
    EMBED_ADS_TAG_ZONE,
} from "@/lib/embed-ads-shared"
export type { VignetteAdConfig } from "@/lib/embed-ads-shared"

const SETTING_KEY = "embed_ads_config"

type SettingRow = RowDataPacket & { setting_key: string; setting_value: string }

export type EmbedAdsDomainMode = "all" | "allowlist" | "blocklist"

export type EmbedAdsDomainRule = {
    host: string
    /** Ad-free through this calendar day (YYYY-MM-DD), inclusive. */
    adFreeUntil: string | null
    /** Force ads on/off for this host (overrides date when set). */
    adsEnabled?: boolean | null
}

export type EmbedAdsConfig = {
    enabled: boolean
    /** Popunder ads on chillflix.lol Watch modal / inline player. */
    sitePlayerEnabled: boolean
    /** llvpn tag zone when parent is chillflix.lol (site player). */
    siteTagZone: string
    /** jnbhi tag zone for chillflix.pw embeds and third-party iframes. */
    embedTagZone: string
    /** Vignette (dd133) on /embed pages. */
    vignetteEmbedEnabled: boolean
    /** Vignette (dd133) on movie/TV detail pages. */
    vignetteDetailEnabled: boolean
    /** dd133 vignette zone id. */
    vignetteZone: string
    /** @deprecated Use siteTagZone */
    tagZone?: string
    domainMode: EmbedAdsDomainMode
    /** Hostnames used with allowlist / blocklist mode. */
    domains: string[]
    /** Everyone ad-free through this day (YYYY-MM-DD), inclusive. */
    globalAdFreeUntil: string | null
    domainRules: EmbedAdsDomainRule[]
}

const DEFAULTS: EmbedAdsConfig = {
    enabled: false,
    sitePlayerEnabled: false,
    siteTagZone: EMBED_ADS_SITE_TAG_ZONE,
    embedTagZone: EMBED_ADS_EMBED_TAG_ZONE,
    vignetteEmbedEnabled: false,
    vignetteDetailEnabled: false,
    vignetteZone: EMBED_ADS_VIGNETTE_ZONE,
    domainMode: "blocklist",
    domains: [
        "chillflix.lol",
        "www.chillflix.lol",
        "localhost",
        "127.0.0.1",
    ],
    globalAdFreeUntil: null,
    domainRules: [],
}

export function normalizeEmbedHost(host: string): string {
    return host.trim().toLowerCase().replace(/^www\./, "")
}

export function parseEmbedAdsConfig(raw: string | undefined | null): EmbedAdsConfig {
    if (!raw?.trim()) return { ...DEFAULTS, domains: [], domainRules: [] }

    try {
        const parsed = JSON.parse(raw) as Partial<EmbedAdsConfig>
        const domainMode =
            parsed.domainMode === "allowlist" || parsed.domainMode === "blocklist"
                ? parsed.domainMode
                : "all"

        return {
            enabled: Boolean(parsed.enabled),
            sitePlayerEnabled: Boolean(parsed.sitePlayerEnabled),
            siteTagZone:
                typeof parsed.siteTagZone === "string" && parsed.siteTagZone.trim()
                    ? parsed.siteTagZone.trim()
                    : typeof parsed.tagZone === "string" && parsed.tagZone.trim()
                      ? parsed.tagZone.trim()
                      : EMBED_ADS_SITE_TAG_ZONE,
            embedTagZone:
                typeof parsed.embedTagZone === "string" && parsed.embedTagZone.trim()
                    ? parsed.embedTagZone.trim()
                    : EMBED_ADS_EMBED_TAG_ZONE,
            vignetteEmbedEnabled: Boolean(parsed.vignetteEmbedEnabled),
            vignetteDetailEnabled: Boolean(parsed.vignetteDetailEnabled),
            vignetteZone:
                typeof parsed.vignetteZone === "string" && parsed.vignetteZone.trim()
                    ? parsed.vignetteZone.trim()
                    : EMBED_ADS_VIGNETTE_ZONE,
            domainMode,
            domains: Array.isArray(parsed.domains)
                ? parsed.domains
                      .map((entry) => normalizeEmbedHost(String(entry)))
                      .filter(Boolean)
                : [],
            globalAdFreeUntil: normalizeDateInput(parsed.globalAdFreeUntil),
            domainRules: Array.isArray(parsed.domainRules)
                ? parsed.domainRules
                      .map((rule) => ({
                          host: normalizeEmbedHost(String(rule?.host ?? "")),
                          adFreeUntil: normalizeDateInput(rule?.adFreeUntil ?? null),
                          adsEnabled:
                              rule?.adsEnabled === true
                                  ? true
                                  : rule?.adsEnabled === false
                                    ? false
                                    : null,
                      }))
                      .filter((rule) => rule.host)
                : [],
        }
    } catch {
        return { ...DEFAULTS, domains: [], domainRules: [] }
    }
}

function normalizeDateInput(value: unknown): string | null {
    if (typeof value !== "string" || !value.trim()) return null
    const trimmed = value.trim()
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return trimmed
    const parsed = new Date(trimmed)
    if (Number.isNaN(parsed.getTime())) return null
    return parsed.toISOString().slice(0, 10)
}

function isAdFreeThrough(dateStr: string | null, now: Date): boolean {
    if (!dateStr) return false
    const end = new Date(`${dateStr}T23:59:59.999Z`)
    return now.getTime() <= end.getTime()
}

function isPlayerOwnHost(host: string): boolean {
    try {
        const playerHost = normalizeEmbedHost(new URL(getPublicPlayerSiteUrl()).hostname)
        return host === playerHost
    } catch {
        return host === "chillflix.pw"
    }
}

export function resolveEmbedAdsForHost(
    config: EmbedAdsConfig,
    host: string | null,
    now = new Date()
): boolean {
    if (isAdFreeThrough(config.globalAdFreeUntil, now)) return false

    const normalized = host ? normalizeEmbedHost(host) : null

    // Native /embed on chillflix.pw (not a third-party iframe). Uses embed toggle only.
    if (normalized && isPlayerOwnHost(normalized)) {
        return config.enabled
    }

    // First-party: chillflix.lol iframing its own player uses site-player ads toggle.
    if (
        normalized &&
        (normalized === "chillflix.lol" || normalized === "www.chillflix.lol") &&
        config.sitePlayerEnabled
    ) {
        return true
    }

    if (!config.enabled) return false

    if (normalized) {
        const rule = config.domainRules.find((entry) => entry.host === normalized)
        if (rule) {
            if (rule.adsEnabled === true) return true
            if (rule.adsEnabled === false) return false
            if (isAdFreeThrough(rule.adFreeUntil, now)) return false
        }
    }

    if (config.domainMode === "allowlist") {
        if (!normalized) return false
        return config.domains.includes(normalized)
    }

    if (config.domainMode === "blocklist") {
        if (!normalized) return true
        return !config.domains.includes(normalized)
    }

    return true
}

export function resolveSitePlayerAds(config: EmbedAdsConfig, now = new Date()): boolean {
    if (!config.sitePlayerEnabled) return false
    if (isAdFreeThrough(config.globalAdFreeUntil, now)) return false
    return true
}

export function buildVignetteAdConfig(config: EmbedAdsConfig): VignetteAdConfig {
    return {
        zone: config.vignetteZone?.trim() || EMBED_ADS_VIGNETTE_ZONE,
        src: EMBED_ADS_VIGNETTE_SCRIPT_SRC,
    }
}

/** Vignette on /embed — same domain targeting as popunder embed ads. */
export function resolveVignetteEmbedAds(
    config: EmbedAdsConfig,
    host: string | null,
    now = new Date()
): boolean {
    if (!config.vignetteEmbedEnabled) return false
    if (isAdFreeThrough(config.globalAdFreeUntil, now)) return false

    return resolveEmbedAdsForHost(
        {
            ...config,
            enabled: true,
            sitePlayerEnabled: false,
        },
        host,
        now
    )
}

/** Vignette on chillflix.lol movie/TV detail layouts. */
export function resolveVignetteDetailAds(config: EmbedAdsConfig, now = new Date()): boolean {
    if (!config.vignetteDetailEnabled) return false
    if (isAdFreeThrough(config.globalAdFreeUntil, now)) return false
    return true
}

/** Site owners never receive popunder ads on chillflix.lol site player. */
export function resolveAdsForViewer(
    configShowAds: boolean,
    viewerRole: UserRole | null | undefined
): boolean {
    if (!configShowAds) return false
    if (canManageSiteSettings(viewerRole ?? "user")) return false
    return true
}

export async function getEmbedAdsConfig(): Promise<EmbedAdsConfig> {
    const row = await queryOne<SettingRow>(
        "SELECT setting_value FROM site_settings WHERE setting_key = ?",
        [SETTING_KEY]
    )

    return parseEmbedAdsConfig(row?.setting_value)
}

export async function updateEmbedAdsConfig(updates: EmbedAdsConfig): Promise<EmbedAdsConfig> {
    const normalized = parseEmbedAdsConfig(JSON.stringify(updates))
    const now = Date.now()
    const payload = JSON.stringify(normalized)

    const existing = await queryOne<SettingRow>(
        "SELECT setting_key FROM site_settings WHERE setting_key = ?",
        [SETTING_KEY]
    )

    if (existing) {
        await execute(
            "UPDATE site_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?",
            [payload, now, SETTING_KEY]
        )
    } else {
        await execute(
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)",
            [SETTING_KEY, payload, now]
        )
    }

    return normalized
}

function isChillflixLolHost(host: string | null) {
    if (!host) return false
    const normalized = normalizeEmbedHost(host)
    return normalized === "chillflix.lol"
}

export type EmbedAdSurface = "site-player" | "embed"

export function resolveEmbedAdZone(
    config: EmbedAdsConfig,
    options?: { host?: string | null; surface?: EmbedAdSurface }
): string {
    if (options?.surface === "site-player" || isChillflixLolHost(options?.host ?? null)) {
        return config.siteTagZone?.trim() || EMBED_ADS_SITE_TAG_ZONE
    }

    return config.embedTagZone?.trim() || EMBED_ADS_EMBED_TAG_ZONE
}

export function buildEmbedAdScripts(
    config: EmbedAdsConfig,
    options?: { host?: string | null; surface?: EmbedAdSurface }
): EmbedAdScriptBundle {
    const isSitePlayer =
        options?.surface === "site-player" || isChillflixLolHost(options?.host ?? null)

    if (isSitePlayer) {
        // Zone 11200416 — Mapple-style first-party anti-adblock lib + runPop.
        return {
            integration: "aclib-firstparty",
            zone: resolveEmbedAdZone(config, options),
            aclibSrc: EMBED_ADS_ACLIB_FIRSTPARTY_PATH,
        }
    }

    return {
        integration: "monetag-antiblock",
        zone: resolveEmbedAdZone(config, options),
        antiAdblockBoot: resolveCrossOriginStaticUrl(EMBED_ADS_ANTIBLOCK_BOOT_PATH),
        jnbhiTag: EMBED_ADS_JNBHI_TAG_SCRIPT_SRC,
        tagOnError: EMBED_ADS_TAG_ON_ERROR,
        tagOnLoad: EMBED_ADS_TAG_ON_LOAD,
    }
}

export function resolveEmbedderHostFromReferrer(referrer: string | null | undefined): string | null {
    if (!referrer?.trim()) return null
    try {
        return normalizeEmbedHost(new URL(referrer).hostname)
    } catch {
        return null
    }
}
