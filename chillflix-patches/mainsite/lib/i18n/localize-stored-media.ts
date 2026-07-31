import type { MediaType } from "@/lib/media-types"
import type { WatchProgressItem } from "@/lib/watch-progress"
import { createTtlMapCache } from "@/lib/ttl-map-cache"
import { tmdb } from "@/tmdb/api"

export type MediaRef = {
    type: MediaType
    id: number
}

export type LocalizedMedia = {
    title: string
    poster: string | null
}

/** Locale titles change rarely — cache aggressively to stop homepage TMDB storms. */
const TITLE_CACHE_TTL_MS = 6 * 60 * 60 * 1000
const titleCache = createTtlMapCache<LocalizedMedia>({
    name: "media-titles",
    ttlMs: TITLE_CACHE_TTL_MS,
    maxEntries: 20_000,
})

export function mediaRefKey(ref: MediaRef): string {
    return `${ref.type}:${ref.id}`
}

async function fetchOneLocalized(ref: MediaRef): Promise<LocalizedMedia | null> {
    return titleCache.getOrSet(mediaRefKey(ref), async () => {
        if (ref.type === "movie") {
            const detail = await tmdb.movie.detail({ id: String(ref.id) })
            const title = detail.title?.trim()
            if (!title) {
                // Negative-ish: store empty title marker? Skip cache on empty by throwing
                throw new Error("missing title")
            }
            return {
                title,
                poster: detail.poster_path ?? null,
            }
        }

        const detail = await tmdb.tv.detail({ id: String(ref.id) })
        const title = detail.name?.trim()
        if (!title) throw new Error("missing title")
        return {
            title,
            poster: detail.poster_path ?? null,
        }
    }).catch(() => null)
}

/**
 * Fetches current-locale titles from TMDB for stored library rows
 * (continue watching, favorites, watchlist, history).
 */
export async function fetchLocalizedMediaMap(
    refs: MediaRef[]
): Promise<Map<string, LocalizedMedia>> {
    const unique = new Map<string, MediaRef>()
    for (const ref of refs) {
        if (!ref.id || !ref.type) continue
        unique.set(mediaRefKey(ref), ref)
    }

    const result = new Map<string, LocalizedMedia>()

    await Promise.all(
        Array.from(unique.values()).map(async (ref) => {
            const localized = await fetchOneLocalized(ref)
            if (localized) result.set(mediaRefKey(ref), localized)
        })
    )

    return result
}

function applyLocalized<
    T extends { title: string; poster?: string | null },
>(
    item: T,
    type: MediaType,
    id: number,
    map: Map<string, LocalizedMedia>
): T {
    const localized = map.get(mediaRefKey({ type, id }))
    if (!localized) return item

    return {
        ...item,
        title: localized.title,
        poster: localized.poster ?? item.poster ?? null,
    }
}

export async function localizeWatchProgressItems(
    items: WatchProgressItem[]
): Promise<WatchProgressItem[]> {
    if (items.length === 0) return items

    const map = await fetchLocalizedMediaMap(
        items.map((item) => ({ type: item.type, id: item.id }))
    )

    return items.map((item) => applyLocalized(item, item.type, item.id, map))
}

export async function localizeLibraryMediaRows<
    T extends {
        media_type: MediaType
        media_id: number
        title: string
        poster?: string | null
    },
>(items: T[]): Promise<T[]> {
    if (items.length === 0) return items

    const map = await fetchLocalizedMediaMap(
        items.map((item) => ({ type: item.media_type, id: item.media_id }))
    )

    return items.map((item) =>
        applyLocalized(item, item.media_type, item.media_id, map)
    )
}

export function getMediaTitleCacheSize() {
    return titleCache.size()
}

export function clearMediaTitleCache() {
    titleCache.clear()
}
