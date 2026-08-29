import { IPTV_HEADERS } from "@/lib/iptv/provider"
import {
  filterFreeTvChannels,
  parseFreeTvPlaylist,
  type FreeTvChannel,
} from "@/lib/iptv/free-tv"

/** Public country playlist — used when kool.to / mediahub is blocked from this network. */
const LIVE_FALLBACK_PLAYLIST_URL =
  process.env.IPTV_LIVE_FALLBACK_URL?.trim() ||
  "https://iptv-org.github.io/iptv/index.country.m3u"

const CACHE_TTL_MS = 30 * 60 * 1000

export type LiveFallbackChannel = FreeTvChannel & {
  source: "live-fallback"
}

let playlistCache: { expiresAt: number; channels: LiveFallbackChannel[] } | null =
  null

export async function fetchLiveFallbackChannels(): Promise<LiveFallbackChannel[]> {
  if (playlistCache && playlistCache.expiresAt > Date.now()) {
    return playlistCache.channels
  }

  const response = await fetch(LIVE_FALLBACK_PLAYLIST_URL, {
    headers: IPTV_HEADERS,
    cache: "no-store",
    signal: AbortSignal.timeout(25_000),
  })

  if (!response.ok) {
    throw new Error(`Live fallback playlist failed: HTTP ${response.status}`)
  }

  const text = await response.text()
  if (text.trimStart().startsWith("<")) {
    throw new Error("Live fallback playlist returned HTML instead of M3U")
  }

  const channels = parseFreeTvPlaylist(text).map((channel, index) => ({
    ...channel,
    id: `live-${index + 1}`,
    source: "live-fallback" as const,
  }))

  if (!channels.length) {
    throw new Error("Live fallback playlist is empty")
  }

  playlistCache = {
    expiresAt: Date.now() + CACHE_TTL_MS,
    channels,
  }

  return channels
}

export function filterLiveFallbackChannels(
  channels: LiveFallbackChannel[],
  args: { search?: string; country?: string }
) {
  return filterFreeTvChannels(channels, args) as LiveFallbackChannel[]
}
