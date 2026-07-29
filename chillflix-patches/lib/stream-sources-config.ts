import "server-only"

import type { RowDataPacket } from "mysql2/promise"

import { sanitizeCustomPlayer } from "@/lib/custom-players"
import type { UserRole } from "@/lib/permissions"
import { canManageSiteSettings } from "@/lib/permissions"
import {
    DEFAULT_EXTERNAL_PLAYERS,
    DEFAULT_PLAYBACK_TIMINGS,
    DEFAULT_PROVIDER_CATALOG,
    OWNER_ONLY_PROVIDER_IDS,
    RETIRED_EXTERNAL_PLAYER_IDS,
    clampProviderWaitSeconds,
    normalizeProviderName,
    sanitizePlaybackTimings,
    type CustomPlayerEntry,
    type StreamPlaybackTimings,
    type StreamSourceEntry,
    type StreamSourcesConfig,
} from "@/lib/stream-sources-defaults"
import { execute, queryOne } from "@/lib/db"

const SETTING_KEY = "stream_sources_config"

type SettingRow = RowDataPacket & { setting_key: string; setting_value: string }

export type { CustomPlayerEntry, StreamSourceEntry, StreamSourcesConfig }

export type StreamSourcesPublic = {
    sources: StreamSourceEntry[]
    players: CustomPlayerEntry[]
    order: string[]
    enabledIds: string[]
    enabledPlayers: CustomPlayerEntry[]
    timings: StreamPlaybackTimings
}

function normalizeEntry(entry: StreamSourceEntry): StreamSourceEntry {
    const timeoutSeconds = clampProviderWaitSeconds(entry.timeoutSeconds)
    return {
        id: normalizeProviderName(entry.id),
        name: entry.name.trim() || entry.id,
        enabled: Boolean(entry.enabled),
        builtin: entry.builtin,
        ownerOnly: entry.ownerOnly,
        ...(timeoutSeconds != null ? { timeoutSeconds } : {}),
    }
}

function resolveOwnerOnlyFlag(entry: StreamSourceEntry, builtin?: StreamSourceEntry) {
    if (!OWNER_ONLY_PROVIDER_IDS.has(entry.id)) {
        return undefined
    }
    return entry.ownerOnly ?? builtin?.ownerOnly
}

function isSourceEnabledForViewer(entry: StreamSourceEntry, viewerRole?: UserRole) {
    if (!entry.enabled) return false
    if (entry.ownerOnly && !canManageSiteSettings(viewerRole ?? "user")) {
        return false
    }
    return true
}

function normalizePlayer(entry: CustomPlayerEntry): CustomPlayerEntry {
    return sanitizeCustomPlayer(entry) ?? {
        id: normalizeProviderName(entry.id),
        name: entry.name.trim() || entry.id,
        enabled: Boolean(entry.enabled),
        kind: entry.kind === "api" ? "api" : "embed",
        movieTemplate: entry.movieTemplate?.trim() ?? "",
        tvTemplate: entry.tvTemplate?.trim() ?? "",
        responsePath: entry.responsePath?.trim() || undefined,
    }
}

export function getDefaultStreamSourcesConfig(): StreamSourcesConfig {
    return {
        sources: DEFAULT_PROVIDER_CATALOG.map((entry) => ({ ...entry })),
        players: DEFAULT_EXTERNAL_PLAYERS.map((entry) => ({ ...entry })),
        timings: { ...DEFAULT_PLAYBACK_TIMINGS },
    }
}

function mergeWithDefaults(parsed: StreamSourcesConfig): StreamSourcesConfig {
    const defaults = getDefaultStreamSourcesConfig()
    const seen = new Set<string>()
    const merged: StreamSourceEntry[] = []
    const timings = sanitizePlaybackTimings(
        (parsed as StreamSourcesConfig & { timings?: unknown }).timings
    )

    for (const entry of parsed.sources.map(normalizeEntry)) {
        if (!entry.id || seen.has(entry.id)) continue
        seen.add(entry.id)

        const builtin = defaults.sources.find((item) => item.id === entry.id)
        merged.push({
            ...entry,
            name: entry.name || builtin?.name || entry.id,
            builtin: builtin?.builtin ?? entry.builtin,
            ownerOnly: resolveOwnerOnlyFlag(entry, builtin),
        })
    }

    for (const entry of defaults.sources) {
        if (seen.has(entry.id)) continue
        merged.push({ ...entry })
    }

    const playerSeen = new Set<string>()
    const players: CustomPlayerEntry[] = []

    for (const entry of (Array.isArray(parsed.players) ? parsed.players : []).map(
        (item) => sanitizeCustomPlayer(item as Partial<CustomPlayerEntry>)
    )) {
        if (!entry || playerSeen.has(entry.id)) continue
        playerSeen.add(entry.id)

        const builtin = DEFAULT_EXTERNAL_PLAYERS.find((item) => item.id === entry.id)
        players.push({
            ...entry,
            name: entry.name || builtin?.name || entry.id,
            builtin: builtin?.builtin ?? entry.builtin,
            movieTemplate: builtin?.movieTemplate ?? entry.movieTemplate,
            tvTemplate: builtin?.tvTemplate ?? entry.tvTemplate,
        })
    }

    for (const entry of DEFAULT_EXTERNAL_PLAYERS) {
        if (playerSeen.has(entry.id)) continue
        players.push({ ...entry })
    }

    return { sources: merged, players, timings }
}

function parseStoredConfig(raw: string | undefined): StreamSourcesConfig {
    if (!raw?.trim()) {
        return getDefaultStreamSourcesConfig()
    }

    try {
        const parsed = JSON.parse(raw) as StreamSourcesConfig
        if (!Array.isArray(parsed?.sources)) {
            return getDefaultStreamSourcesConfig()
        }

        return mergeWithDefaults({
            sources: parsed.sources as StreamSourceEntry[],
            players: Array.isArray(parsed.players) ? (parsed.players as CustomPlayerEntry[]) : [],
            timings: sanitizePlaybackTimings(parsed.timings),
        })
    } catch {
        return getDefaultStreamSourcesConfig()
    }
}

async function upsertSetting(value: string) {
    const now = Date.now()
    const existing = await queryOne<SettingRow>(
        "SELECT setting_key FROM site_settings WHERE setting_key = ?",
        [SETTING_KEY]
    )

    if (existing) {
        await execute("UPDATE site_settings SET setting_value = ?, updated_at = ? WHERE setting_key = ?", [
            value,
            now,
            SETTING_KEY,
        ])
        return
    }

    await execute(
        "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)",
        [SETTING_KEY, value, now]
    )
}

export async function getStreamSourcesConfig(): Promise<StreamSourcesConfig> {
    const row = await queryOne<SettingRow>(
        "SELECT setting_value FROM site_settings WHERE setting_key = ?",
        [SETTING_KEY]
    )

    return parseStoredConfig(row?.setting_value)
}

/** Enabled providers in admin list order (top = first tested for playback). */
export function normalizePlaybackProviderOrder(enabledIds: string[]): string[] {
    const seen = new Set<string>()
    return enabledIds
        .map((id) => normalizeProviderName(id))
        .filter((id) => {
            if (!id || seen.has(id)) return false
            seen.add(id)
            return true
        })
}

export function toPublicStreamSourcesConfig(
    config: StreamSourcesConfig,
    viewerRole?: UserRole
): StreamSourcesPublic {
    const sources = config.sources.map(normalizeEntry)
    const players = config.players.map(normalizePlayer)
    const enabledIds = sources
        .filter((entry) => isSourceEnabledForViewer(entry, viewerRole))
        .map((entry) => entry.id)
    const playbackOrder = normalizePlaybackProviderOrder(enabledIds)
    const enabledPlayers = players.filter(
        (entry) => entry.enabled && !RETIRED_EXTERNAL_PLAYER_IDS.has(entry.id)
    )

    return {
        sources: sources.map((entry) => ({
            ...entry,
            enabled: isSourceEnabledForViewer(entry, viewerRole),
        })),
        players: players.map((entry) =>
            RETIRED_EXTERNAL_PLAYER_IDS.has(entry.id)
                ? { ...entry, enabled: false }
                : entry
        ),
        order: playbackOrder,
        enabledIds: playbackOrder,
        enabledPlayers,
        timings: sanitizePlaybackTimings(config.timings),
    }
}

export function sanitizeStreamSourcesUpdate(body: unknown): StreamSourcesConfig {
    if (!body || typeof body !== "object") {
        throw new Error("Invalid stream sources payload.")
    }

    const record = body as {
        sources?: unknown
        players?: unknown
        timings?: unknown
        reset?: boolean
    }

    if (record.reset) {
        return getDefaultStreamSourcesConfig()
    }

    if (!Array.isArray(record.sources)) {
        throw new Error("Expected a sources array.")
    }

    const defaults = getDefaultStreamSourcesConfig()
    const builtinIds = new Set(defaults.sources.map((entry) => entry.id))
    const seen = new Set<string>()
    const sources: StreamSourceEntry[] = []

    for (const item of record.sources) {
        if (!item || typeof item !== "object") continue

        const raw = item as Partial<StreamSourceEntry>
        const id = normalizeProviderName(String(raw.id ?? ""))
        const name = String(raw.name ?? "").trim()

        if (!id || !/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) {
            continue
        }

        if (seen.has(id)) continue
        seen.add(id)

        const builtin = defaults.sources.find((item) => item.id === id)
        const timeoutSeconds = clampProviderWaitSeconds(raw.timeoutSeconds)

        sources.push({
            id,
            name: name || id,
            enabled: raw.enabled !== false,
            builtin: builtinIds.has(id) ? true : undefined,
            ownerOnly: resolveOwnerOnlyFlag(
                { id, name: name || id, enabled: raw.enabled !== false, ownerOnly: raw.ownerOnly },
                builtin
            ),
            ...(timeoutSeconds != null ? { timeoutSeconds } : {}),
        })
    }

    if (sources.length === 0) {
        throw new Error("At least one stream source is required.")
    }

    for (const entry of defaults.sources) {
        if (seen.has(entry.id)) continue
        sources.push({ ...entry })
    }

    const players: CustomPlayerEntry[] = []
    const playerSeen = new Set<string>()

    const builtinPlayerIds = new Set(DEFAULT_EXTERNAL_PLAYERS.map((entry) => entry.id))

    if (Array.isArray(record.players)) {
        for (const item of record.players) {
            const sanitized = sanitizeCustomPlayer(item as Partial<CustomPlayerEntry>)
            if (!sanitized || playerSeen.has(sanitized.id)) continue
            playerSeen.add(sanitized.id)
            players.push({
                ...sanitized,
                builtin: builtinPlayerIds.has(sanitized.id) ? true : undefined,
            })
        }
    }

    for (const entry of DEFAULT_EXTERNAL_PLAYERS) {
        if (playerSeen.has(entry.id)) continue
        players.push({ ...entry })
    }

    return {
        sources,
        players,
        timings: sanitizePlaybackTimings(record.timings ?? defaults.timings),
    }
}

export function getCustomPlayerById(
    config: StreamSourcesConfig,
    playerId: string
): CustomPlayerEntry | undefined {
    const key = normalizeProviderName(playerId)
    return config.players.find((entry) => normalizeProviderName(entry.id) === key && entry.enabled)
}

export async function updateStreamSourcesConfig(body: unknown): Promise<StreamSourcesConfig> {
    const next = sanitizeStreamSourcesUpdate(body)
    await upsertSetting(JSON.stringify(next))
    return next
}

export function getPrimaryEnabledProviderId(config: StreamSourcesConfig): string | undefined {
    return config.sources
        .map(normalizeEntry)
        .filter((entry) => entry.enabled)
        .map((entry) => entry.id)[0]
}

const NATIVE_BULK_PROVIDER_IDS = new Set(["vidlink", "flixhq", "huhu"])

export function isNativeBulkProvider(providerId: string): boolean {
    return NATIVE_BULK_PROVIDER_IDS.has(normalizeProviderName(providerId))
}

/** Instant native batch (Huhu/VidLink/FlixHQ) only when #1 in admin is also native. */
export function shouldOfferNativeImmediateFallback(config: StreamSourcesConfig): boolean {
    const primaryId = getPrimaryEnabledProviderId(config)
    if (!primaryId) return false
    return isNativeBulkProvider(primaryId)
}

type NativeBulkFilterOptions = {
    /** Loading/fast responses: include FlixHQ/VidLink when filter would otherwise be empty. */
    allowNativeFallbackWhenEmpty?: boolean
}

function pickNativeFallbackSource<T extends { provider: { id: string } }>(
    sources: T[]
): T | undefined {
    return (
        sources.find(
            (source) => normalizeProviderName(source.provider.id) === "flixhq"
        ) ?? sources[0]
    )
}

/** Native VidLink/FlixHQ are manual or fast-path only unless they are #1 in admin. */
export function filterNativeSourcesForBulkResponse<
    T extends { provider: { id: string } }
>(
    config: StreamSourcesConfig,
    sources: T[],
    requestedProvider?: string,
    options?: NativeBulkFilterOptions
): T[] {
    if (requestedProvider?.trim()) {
        return sources
    }

    const order = normalizePlaybackProviderOrder(
        config.sources
            .filter((entry) => normalizeEntry(entry).enabled)
            .map((entry) => entry.id)
    )
    const primaryId = order[0]
    if (!primaryId) {
        const filtered = sources.filter(
            (source) =>
                !NATIVE_BULK_PROVIDER_IDS.has(
                    normalizeProviderName(source.provider.id)
                )
        )
        if (filtered.length > 0 || !options?.allowNativeFallbackWhenEmpty) {
            return filtered
        }

        const nativeSources = sources.filter((source) =>
            NATIVE_BULK_PROVIDER_IDS.has(normalizeProviderName(source.provider.id))
        )
        const fallback = pickNativeFallbackSource(nativeSources)
        return fallback ? [fallback] : filtered
    }

    const primaryKey = normalizeProviderName(primaryId)

    const filtered = sources.filter((source) => {
        const key = normalizeProviderName(source.provider.id)
        if (!NATIVE_BULK_PROVIDER_IDS.has(key)) {
            return true
        }
        return key === primaryKey
    })

    if (filtered.length > 0 || !options?.allowNativeFallbackWhenEmpty) {
        return filtered
    }

    const nativeSources = sources.filter((source) =>
        NATIVE_BULK_PROVIDER_IDS.has(normalizeProviderName(source.provider.id))
    )
    const fallback = pickNativeFallbackSource(nativeSources)
    return fallback ? [fallback] : filtered
}

export function isProviderEnabledInConfig(
    config: StreamSourcesConfig,
    providerId: string,
    viewerRole?: UserRole
): boolean {
    const key = normalizeProviderName(providerId)
    const entry = config.sources.find((item) => normalizeProviderName(item.id) === key)
    if (!entry) return false
    return isSourceEnabledForViewer(normalizeEntry(entry), viewerRole)
}

export function applyStreamSourcesConfig<T extends { provider: { id: string; name: string } }>(
    sources: T[],
    config: StreamSourcesConfig,
    options?: { viewerRole?: UserRole }
): T[] {
    const normalized = config.sources.map(normalizeEntry)
    const order = normalizePlaybackProviderOrder(
        normalized
            .filter((entry) => isSourceEnabledForViewer(entry, options?.viewerRole))
            .map((entry) => entry.id)
    )
    const enabledIds = new Set(order)

    const rank = (source: T) => {
        const key = normalizeProviderName(source.provider.id || source.provider.name)
        const index = order.indexOf(key)
        return index === -1 ? order.length + 100 : index
    }

    return sources
        .filter((source) =>
            enabledIds.has(normalizeProviderName(source.provider.id || source.provider.name))
        )
        .sort((a, b) => rank(a) - rank(b))
}

export function getProviderLabelFromConfig(
    config: StreamSourcesConfig,
    providerId: string
): string | undefined {
    const key = normalizeProviderName(providerId)
    return config.sources.find((entry) => normalizeProviderName(entry.id) === key)?.name
}
