import { NextRequest, NextResponse } from "next/server"
import { createHash } from "node:crypto"

import { getErrorMessage } from "@/lib/api-error"
import { getCurrentUser } from "@/lib/auth"
import type { MediaType } from "@/lib/media-types"
import {
    getPersonalizedRecommendations,
    type RecommendationSeed,
    type RecommendedItem,
} from "@/lib/recommendations"
import { createTtlMapCache } from "@/lib/ttl-map-cache"

const RECO_CACHE_TTL_MS = 10 * 60 * 1000
const recoCache = createTtlMapCache<RecommendedItem[]>({
    name: "recommendations",
    ttlMs: RECO_CACHE_TTL_MS,
    maxEntries: 4_000,
})

function parseClientSeeds(body: unknown): RecommendationSeed[] {
    if (!body || typeof body !== "object" || !("seeds" in body)) return []

    const seeds = (body as { seeds?: unknown }).seeds
    if (!Array.isArray(seeds)) return []

    return seeds
        .map((seed) => {
            if (!seed || typeof seed !== "object") return null

            const entry = seed as {
                id?: unknown
                type?: unknown
                weight?: unknown
                at?: unknown
            }

            const id = Number(entry.id)
            const type = entry.type
            if (!Number.isFinite(id) || id <= 0) return null
            if (type !== "movie" && type !== "tv") return null

            const weight = Number(entry.weight)
            const at = Number(entry.at)

            return {
                id,
                type: type as MediaType,
                ...(Number.isFinite(weight) && weight > 0 ? { weight } : {}),
                ...(Number.isFinite(at) && at > 0 ? { at } : {}),
            }
        })
        .filter((seed): seed is RecommendationSeed => seed !== null)
}

function recoCacheKey(userId: string | undefined, seeds: RecommendationSeed[]) {
    const normalized = [...seeds]
        .map((s) => ({
            id: s.id,
            type: s.type,
            // Bucket weights/time so tiny progress updates do not bust cache
            w: Math.round((s.weight ?? 1) * 2) / 2,
            day: s.at ? Math.floor(s.at / 86_400_000) : 0,
        }))
        .sort((a, b) => (a.type === b.type ? a.id - b.id : a.type.localeCompare(b.type)))

    const raw = JSON.stringify({ u: userId ?? "anon", s: normalized })
    return createHash("sha1").update(raw).digest("hex").slice(0, 24)
}

export async function POST(request: NextRequest) {
    try {
        const user = await getCurrentUser(request)
        let clientSeeds: RecommendationSeed[] = []

        try {
            const body = await request.json()
            clientSeeds = parseClientSeeds(body)
        } catch {
            // Empty body is fine for logged-in users.
        }

        const key = recoCacheKey(user?.id, clientSeeds)
        const results = await recoCache.getOrSet(key, () =>
            getPersonalizedRecommendations({
                userId: user?.id,
                clientSeeds,
            })
        )

        return NextResponse.json({ results })
    } catch (error) {
        console.error("Recommendations failed:", error)
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to load recommendations") },
            { status: 500 }
        )
    }
}

export function getRecommendationsCacheSize() {
    return recoCache.size()
}

export function clearRecommendationsCache() {
    recoCache.clear()
}
