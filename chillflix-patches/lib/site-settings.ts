import type { RowDataPacket } from "mysql2/promise"
import { revalidateTag, unstable_cache } from "next/cache"

import { resolveApkDownloadUrl } from "@/lib/apk-download"
import { execute, query, queryOne } from "@/lib/db"

type SettingRow = RowDataPacket & { setting_key: string; setting_value: string }
type CountRow = RowDataPacket & { count: number }

export type SiteSettings = {
    registrationEnabled: boolean
    maintenanceMode: boolean
    watchPartyEnabled: boolean
    continueWatchingEnabled: boolean
    playersEnabled: boolean
    iptvEnabled: boolean
    musicEnabled: boolean
    ticketsEnabled: boolean
    apkDownloadEnabled: boolean
    apkCustomUrl: string | null
    apkVersionLabel: string | null
    apkDownloadUrl: string | null
    sharePromptEnabled: boolean
    communityPromptEnabled: boolean
    discordInviteUrl: string | null
    telegramInviteUrl: string | null
}

export type SiteFeatureFlags = Pick<
    SiteSettings,
    | "watchPartyEnabled"
    | "continueWatchingEnabled"
    | "playersEnabled"
    | "iptvEnabled"
    | "musicEnabled"
    | "ticketsEnabled"
>

export function pickSiteFeatureFlags(settings: SiteSettings): SiteFeatureFlags {
    return {
        watchPartyEnabled: settings.watchPartyEnabled,
        continueWatchingEnabled: settings.continueWatchingEnabled,
        playersEnabled: settings.playersEnabled,
        iptvEnabled: settings.iptvEnabled,
        musicEnabled: settings.musicEnabled,
        ticketsEnabled: settings.ticketsEnabled,
    }
}

export function pickSiteClientFlags(settings: SiteSettings) {
    return {
        ...pickSiteFeatureFlags(settings),
        apkDownloadEnabled: settings.apkDownloadEnabled && Boolean(settings.apkDownloadUrl),
        apkDownloadUrl: settings.apkDownloadUrl,
        apkVersionLabel: settings.apkVersionLabel,
        sharePromptEnabled: settings.sharePromptEnabled,
        communityPromptEnabled: settings.communityPromptEnabled,
        discordInviteUrl: settings.discordInviteUrl,
        telegramInviteUrl: settings.telegramInviteUrl,
    }
}

const DEFAULTS: SiteSettings = {
    registrationEnabled: true,
    maintenanceMode: false,
    watchPartyEnabled: true,
    continueWatchingEnabled: true,
    playersEnabled: true,
    iptvEnabled: true,
    musicEnabled: true,
    ticketsEnabled: true,
    apkDownloadEnabled: false,
    apkCustomUrl: null,
    apkVersionLabel: null,
    apkDownloadUrl: null,
    sharePromptEnabled: true,
    communityPromptEnabled: true,
    discordInviteUrl: "https://discord.gg/6r5KTZgqXV",
    telegramInviteUrl: "https://t.me/chillflixlol",
}

function parseInviteUrl(value: string | undefined, fallback: string) {
    const trimmed = value?.trim()
    if (!trimmed) return fallback
    return trimmed
}

function parseEnabled(value: string | undefined, defaultValue = true) {
    if (value === undefined) return defaultValue
    return value !== "false"
}

async function loadSiteSettingsFromDb(): Promise<SiteSettings> {
    const rows = await query<SettingRow[]>("SELECT setting_key, setting_value FROM site_settings")

    const map = new Map(rows.map((row) => [row.setting_key, row.setting_value]))
    const apkDownloadEnabled = parseEnabled(map.get("apk_download_enabled"), false)
    const apkCustomUrl = map.get("apk_custom_url")?.trim() || null
    const apkVersionLabel = map.get("apk_version_label")?.trim() || null

    return {
        registrationEnabled: parseEnabled(map.get("registration_enabled")),
        maintenanceMode: map.get("maintenance_mode") === "true",
        watchPartyEnabled: parseEnabled(map.get("watch_party_enabled")),
        continueWatchingEnabled: parseEnabled(map.get("continue_watching_enabled")),
        playersEnabled: parseEnabled(map.get("players_enabled")),
        iptvEnabled: parseEnabled(map.get("iptv_enabled")),
        musicEnabled: parseEnabled(map.get("music_enabled")),
        ticketsEnabled: parseEnabled(map.get("tickets_enabled")),
        apkDownloadEnabled,
        apkCustomUrl,
        apkVersionLabel,
        apkDownloadUrl: resolveApkDownloadUrl({
            enabled: apkDownloadEnabled,
            customUrl: apkCustomUrl,
        }),
        sharePromptEnabled: parseEnabled(map.get("share_prompt_enabled"), true),
        communityPromptEnabled: parseEnabled(map.get("community_prompt_enabled"), true),
        discordInviteUrl: parseInviteUrl(map.get("discord_invite_url"), "https://discord.gg/6r5KTZgqXV"),
        telegramInviteUrl: parseInviteUrl(map.get("telegram_invite_url"), "https://t.me/chillflixlol"),
    }
}

const getCachedSiteSettings = unstable_cache(
    loadSiteSettingsFromDb,
    ["site-settings"],
    { revalidate: 60, tags: ["site-settings"] }
)

export async function getSiteSettings(): Promise<SiteSettings> {
    try {
        return await getCachedSiteSettings()
    } catch {
        return DEFAULTS
    }
}

export async function updateSiteSettings(updates: Partial<SiteSettings>) {
    const now = Date.now()

    if (updates.registrationEnabled !== undefined) {
        await upsertSetting("registration_enabled", updates.registrationEnabled ? "true" : "false", now)
    }

    if (updates.maintenanceMode !== undefined) {
        await upsertSetting("maintenance_mode", updates.maintenanceMode ? "true" : "false", now)
    }

    if (updates.watchPartyEnabled !== undefined) {
        await upsertSetting(
            "watch_party_enabled",
            updates.watchPartyEnabled ? "true" : "false",
            now
        )
    }

    if (updates.continueWatchingEnabled !== undefined) {
        await upsertSetting(
            "continue_watching_enabled",
            updates.continueWatchingEnabled ? "true" : "false",
            now
        )
    }

    if (updates.playersEnabled !== undefined) {
        await upsertSetting("players_enabled", updates.playersEnabled ? "true" : "false", now)
    }

    if (updates.iptvEnabled !== undefined) {
        await upsertSetting("iptv_enabled", updates.iptvEnabled ? "true" : "false", now)
    }

    if (updates.musicEnabled !== undefined) {
        await upsertSetting("music_enabled", updates.musicEnabled ? "true" : "false", now)
    }

    if (updates.ticketsEnabled !== undefined) {
        await upsertSetting("tickets_enabled", updates.ticketsEnabled ? "true" : "false", now)
    }

    if (updates.apkDownloadEnabled !== undefined) {
        await upsertSetting(
            "apk_download_enabled",
            updates.apkDownloadEnabled ? "true" : "false",
            now
        )
    }

    if (updates.apkCustomUrl !== undefined) {
        await upsertSetting("apk_custom_url", updates.apkCustomUrl?.trim() || "", now)
    }

    if (updates.apkVersionLabel !== undefined) {
        await upsertSetting("apk_version_label", updates.apkVersionLabel?.trim() || "", now)
    }

    if (updates.sharePromptEnabled !== undefined) {
        await upsertSetting(
            "share_prompt_enabled",
            updates.sharePromptEnabled ? "true" : "false",
            now
        )
    }

    if (updates.communityPromptEnabled !== undefined) {
        await upsertSetting(
            "community_prompt_enabled",
            updates.communityPromptEnabled ? "true" : "false",
            now
        )
    }

    if (updates.discordInviteUrl !== undefined) {
        await upsertSetting("discord_invite_url", updates.discordInviteUrl?.trim() || "", now)
    }

    if (updates.telegramInviteUrl !== undefined) {
        await upsertSetting("telegram_invite_url", updates.telegramInviteUrl?.trim() || "", now)
    }

    revalidateTag("site-settings")

    const settings = await getSiteSettings()
    const { writePublicSiteStatus } = await import("@/lib/site-settings-public")
    writePublicSiteStatus(settings)

    return settings
}

async function upsertSetting(key: string, value: string, now: number) {
    const existing = await queryOne<SettingRow>(
        "SELECT setting_key FROM site_settings WHERE setting_key = ?",
        [key]
    )

    if (existing) {
        await execute("UPDATE site_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?", [
            value,
            now,
            key,
        ])
        return
    }

    await execute(
        "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)",
        [key, value, now]
    )
}


export async function ensureCommunityInviteSettings() {
    const now = Date.now()
    const defaults: Record<string, string> = {
        community_prompt_enabled: "true",
        discord_invite_url: "https://discord.gg/6r5KTZgqXV",
        telegram_invite_url: "https://t.me/chillflixlol",
    }

    for (const [key, value] of Object.entries(defaults)) {
        const existing = await queryOne<SettingRow>(
            "SELECT setting_key FROM site_settings WHERE setting_key = ?",
            [key]
        )
        if (!existing) {
            await execute(
                "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)",
                [key, value, now]
            )
        }
    }

    await syncPublicSiteStatus()
}

export async function ensureDefaultSiteSettings() {
    const count = await queryOne<CountRow>("SELECT COUNT(*) AS count FROM site_settings")

    if (Number(count?.count ?? 0) > 0) {
        await ensureCommunityInviteSettings()
        return
    }

    const now = Date.now()
    for (const [key, value] of Object.entries({
        registration_enabled: "true",
        maintenance_mode: "false",
        watch_party_enabled: "true",
        continue_watching_enabled: "true",
        players_enabled: "true",
        iptv_enabled: "true",
        music_enabled: "true",
        tickets_enabled: "true",
        apk_download_enabled: "false",
        apk_custom_url: "",
        apk_version_label: "",
        share_prompt_enabled: "true",
        community_prompt_enabled: "true",
        discord_invite_url: "https://discord.gg/6r5KTZgqXV",
        telegram_invite_url: "https://t.me/chillflixlol",
    })) {
        await execute(
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)",
            [key, value, now]
        )
    }

    await syncPublicSiteStatus()
}

async function syncPublicSiteStatus() {
    const settings = await getSiteSettings()
    const { writePublicSiteStatus } = await import("@/lib/site-settings-public")
    writePublicSiteStatus(settings)
}
