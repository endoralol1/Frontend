/** Automatic playback probes/picks only these providers (first stream each). */
export const AUTOMATIC_PROBE_PROVIDER_ORDER = [
    "cinesu",
    "onlyflix",
    "soapy",
    "telennovelas",
    "cinemacity",
    "movies123",
    "vaplayer",
    "vidapi",
    "vidnest",
    "peachify",
    "vixsrc",
    "icefy",
    "fsharetv",
] as const

export const DEFAULT_PROVIDER_ORDER = [
    "cinesu",
    "onlyflix",
    "soapy",
    "telennovelas",
    "cinemacity",
    "movies123",
    "vidlink",
    "flixhq",
    "huhu",
    "flixhqz",
    "notorrent",
    "vidrock",
    "icefy",
    "vidsrc",
    "fsharetv",
    "vaplayer",
    "vidapi",
    "vidapiru",
    "vidify",
    "mafiaembed",
    "streammafia",
    "videasy",
    "4khdhub",
    "vixsrc",
    "vidzee",
    "popr",
    "vidnest",
    "02moviedownloader",
    "peachify",
] as const

export const DEFAULT_PROVIDER_LABELS: Record<(typeof DEFAULT_PROVIDER_ORDER)[number], string> = {
    "4khdhub": "4K",
    cinesu: "CineSu",
    vidlink: "VidLink",
    flixhq: "FlixHQ",
    huhu: "Huhu",
    flixhqz: "Flixhqz",
    notorrent: "NoTorrent",
    onlyflix: "OnlyFlix",
    soapy: "SoaPy",
    cinemacity: "Cinemacity",
    movies123: "123Movies",
    vidrock: "VidRock",
    icefy: "Icefy",
    vidsrc: "VidSrc",
    fsharetv: "FshareTV",
    vaplayer: "VAPlayer",
    vidapi: "VidAPI",
    vidapiru: "vidapi.ru",
    vidify: "Vidify",
    mafiaembed: "MafiaEmbed",
    streammafia: "StreamMafia",
    videasy: "Videasy",
    vixsrc: "VixSrc",
    vidzee: "VidZee",
    popr: "Popr",
    vidnest: "VidNest",
    "02moviedownloader": "02MovieDownloader",
    peachify: "Peachify",
    telennovelas: "Telennovelas",
}

export function normalizeProviderName(name: string) {
    return name.trim().toLowerCase().replace(/\s+/g, "")
}

/** ISO country codes for language-hint flags in player source settings. */
export const PROVIDER_LANGUAGE_FLAGS: Partial<
    Record<(typeof DEFAULT_PROVIDER_ORDER)[number], string>
> = {
    telennovelas: "ES",
}

export function countryCodeToFlagEmoji(countryCode: string): string {
    const normalized = countryCode.trim().toUpperCase()
    if (normalized.length !== 2) return ""
    const first = normalized.charAt(0)
    const second = normalized.charAt(1)
    return String.fromCodePoint(127397 + first.charCodeAt(0), 127397 + second.charCodeAt(0))
}

export function getProviderLanguageFlag(providerId: string): string | undefined {
    const key = normalizeProviderName(providerId) as (typeof DEFAULT_PROVIDER_ORDER)[number]
    const country = PROVIDER_LANGUAGE_FLAGS[key]
    if (!country) return undefined
    const flag = countryCodeToFlagEmoji(country)
    return flag || undefined
}

export type StreamSourceEntry = {
    id: string
    name: string
    enabled: boolean
    builtin?: boolean
    /** Only the site owner can enable or receive streams from this provider. */
    ownerOnly?: boolean
    /**
     * Optional per-provider wait budget in seconds.
     * Applies to link fetch + first playback before auto-fallback.
     * Omit / blank = use global defaults (45s fetch, ~15–18s playback).
     * When set, raises those budgets (never shortens below code defaults).
     */
    timeoutSeconds?: number
}

/** Default provider wait when admin leaves the field blank. */
export const DEFAULT_PROVIDER_WAIT_SECONDS = 45
export const MIN_PROVIDER_WAIT_SECONDS = 5
export const MAX_PROVIDER_WAIT_SECONDS = 120

/** Clamp admin wait input; blank/invalid → undefined (use global defaults). */
export function clampProviderWaitSeconds(value: unknown): number | undefined {
    if (value === null || value === undefined || value === "") {
        return undefined
    }
    const n = typeof value === "number" ? value : Number(value)
    if (!Number.isFinite(n)) {
        return undefined
    }
    const rounded = Math.round(n)
    if (rounded <= 0) {
        return undefined
    }
    return Math.min(
        MAX_PROVIDER_WAIT_SECONDS,
        Math.max(MIN_PROVIDER_WAIT_SECONDS, rounded)
    )
}

export function providerWaitSecondsToMs(
    seconds: number | undefined,
    fallbackMs: number
): number {
    if (seconds == null) {
        return fallbackMs
    }
    return seconds * 1000
}

export type CustomPlayerKind = "embed" | "api"

export type CustomPlayerEntry = {
    id: string
    name: string
    enabled: boolean
    kind: CustomPlayerKind
    movieTemplate: string
    tvTemplate: string
    responsePath?: string
    builtin?: boolean
}

/** External embed players retired from the public picker (users fall back to Chillflix player). */
export const RETIRED_EXTERNAL_PLAYER_IDS = new Set<string>(["vidlinkpro"])

/** https://vidlink.pro — embed API (iframe); disabled — use Chillflix player instead */
export const DEFAULT_EXTERNAL_PLAYERS: CustomPlayerEntry[] = [
    {
        id: "vidlinkpro",
        name: "VidLink.pro",
        enabled: false,
        kind: "embed",
        builtin: true,
        movieTemplate: "https://vidlink.pro/movie/{tmdbId}?autoplay=true",
        tvTemplate:
            "https://vidlink.pro/tv/{tmdbId}/{season}/{episode}?autoplay=true&nextbutton=true",
    },
]

export type StreamSourcesConfig = {
    sources: StreamSourceEntry[]
    players: CustomPlayerEntry[]
}

export const OWNER_ONLY_PROVIDER_IDS = new Set<string>()

/** Never auto-probed; only shown when catalog has the title and user picks manually. */
export const MANUAL_ONLY_PROVIDER_IDS = new Set<string>(["4khdhub"])

/** Resolved in the viewer's browser (uses their IP), not on the VPS scraper. */
export const CLIENT_RESOLVE_PROVIDER_IDS = new Set<string>([
    "vidify",
    "videasy",
    "4khdhub",
])

export const DEFAULT_PROVIDER_CATALOG: StreamSourceEntry[] = DEFAULT_PROVIDER_ORDER.map((id) => ({
    id,
    name: DEFAULT_PROVIDER_LABELS[id],
    enabled: !OWNER_ONLY_PROVIDER_IDS.has(id),
    builtin: true,
    ownerOnly: OWNER_ONLY_PROVIDER_IDS.has(id) ? true : undefined,
}))
