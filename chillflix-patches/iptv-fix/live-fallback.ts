import { IPTV_HEADERS } from "@/lib/iptv/provider"
import {
  filterFreeTvChannels,
  parseFreeTvPlaylist,
  type FreeTvChannel,
} from "@/lib/iptv/free-tv"

/**
 * Curated category playlists (not the full 14k country index).
 * Used only when kool.to / mediahub is blocked from this network.
 */
const LIVE_FALLBACK_PLAYLISTS = (
  process.env.IPTV_LIVE_FALLBACK_URLS?.trim() ||
  [
    "https://iptv-org.github.io/iptv/categories/general.m3u",
    "https://iptv-org.github.io/iptv/categories/news.m3u",
    "https://iptv-org.github.io/iptv/categories/sports.m3u",
    "https://iptv-org.github.io/iptv/categories/entertainment.m3u",
  ].join(",")
)
  .split(",")
  .map((value) => value.trim())
  .filter(Boolean)

/** Keep Live TV in the same ballpark as before (mediahub pages / Free-TV). */
const LIVE_FALLBACK_MAX_CHANNELS = Number(
  process.env.IPTV_LIVE_FALLBACK_MAX?.trim() || 2800
)

const CACHE_TTL_MS = 30 * 60 * 1000

export type LiveFallbackChannel = FreeTvChannel & {
  source: "live-fallback"
}

let playlistCache: { expiresAt: number; channels: LiveFallbackChannel[] } | null =
  null

async function fetchPlaylistText(url: string) {
  const response = await fetch(url, {
    headers: IPTV_HEADERS,
    cache: "no-store",
    signal: AbortSignal.timeout(20_000),
  })

  if (!response.ok) {
    throw new Error(`Live fallback playlist failed: HTTP ${response.status}`)
  }

  const text = await response.text()
  if (text.trimStart().startsWith("<")) {
    throw new Error("Live fallback playlist returned HTML instead of M3U")
  }

  return text
}

export async function fetchLiveFallbackChannels(): Promise<LiveFallbackChannel[]> {
  if (playlistCache && playlistCache.expiresAt > Date.now()) {
    return playlistCache.channels
  }

  const seenUrls = new Set<string>()
  const merged: LiveFallbackChannel[] = []

  for (const playlistUrl of LIVE_FALLBACK_PLAYLISTS) {
    try {
      const text = await fetchPlaylistText(playlistUrl)
      for (const channel of parseFreeTvPlaylist(text)) {
        const key = channel.url.trim()
        if (!key || seenUrls.has(key)) continue
        seenUrls.add(key)
        merged.push({
          ...channel,
          id: `live-${merged.length + 1}`,
          source: "live-fallback",
        })

        if (merged.length >= LIVE_FALLBACK_MAX_CHANNELS) {
          break
        }
      }
    } catch {
      // Try remaining playlists; one failure should not wipe Live TV.
    }

    if (merged.length >= LIVE_FALLBACK_MAX_CHANNELS) {
      break
    }
  }

  if (!merged.length) {
    throw new Error("Live fallback playlist is empty")
  }

  playlistCache = {
    expiresAt: Date.now() + CACHE_TTL_MS,
    channels: merged,
  }

  return merged
}

export function filterLiveFallbackChannels(
  channels: LiveFallbackChannel[],
  args: { search?: string; country?: string }
) {
  return filterFreeTvChannels(channels, args) as LiveFallbackChannel[]
}
