import { clearAvailabilityCaches, getAvailabilityCacheSize } from "@/lib/providers/availability-caches"
import { clearByseResolveCaches, getByseResolveCacheStats } from "@/lib/providers/flixhq/byse-resolve"
import { clearCineproSourceCache, getCineproSourceCacheSize } from "@/lib/cinepro-sources-cache"
import {
    clearCineproProxyPayloadCache,
    getCineproProxyPayloadCacheSize,
} from "@/lib/cinepro/proxy-payload-cache"
import { clearFourKHlsSessions, getFourKHlsSessionCount } from "@/lib/4khdhub/hls-transcode"

export type MemoryCacheStats = {
    bysePlaylist: number
    byseInflight: number
    cineproSource: number
    cineproProxyPayload: number
    availability: number
    fourKSessions: number
}

export function getMemoryCacheStats(): MemoryCacheStats {
    const byse = getByseResolveCacheStats()
    return {
        bysePlaylist: byse.playlist,
        byseInflight: byse.inflight,
        cineproSource: getCineproSourceCacheSize(),
        cineproProxyPayload: getCineproProxyPayloadCacheSize(),
        availability: getAvailabilityCacheSize(),
        fourKSessions: getFourKHlsSessionCount(),
    }
}

export function clearAllMemoryCaches() {
    clearByseResolveCaches()
    clearCineproSourceCache()
    clearCineproProxyPayloadCache()
    clearAvailabilityCaches()
    clearFourKHlsSessions()
}
