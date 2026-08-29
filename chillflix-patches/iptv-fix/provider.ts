import fs from "fs"
import path from "path"

export type IptvChannel = {
  id: number | string
  name: string
  country: string
  p: number
  /** kool.to play page URL — resolved via mediahubmx-resolve.json at playback time */
  playPageUrl?: string
  logo?: string
}

export const IPTV_BASE_URL = "https://kool.to"
export const IPTV_PROVIDER_HOST = "kool.to"
export const IPTV_PLAY_ORIGINS = ["https://huhu.to", "https://kool.to"] as const

export const IPTV_HEADERS: Record<string, string> = {
  "User-Agent":
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
  Accept: "*/*",
  "Accept-Language": "en-US,en;q=0.9",
  Referer: `${IPTV_BASE_URL}/`,
  Origin: IPTV_BASE_URL,
  "sec-ch-ua": '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
  "sec-ch-ua-mobile": "?0",
  "sec-ch-ua-platform": '"macOS"',
  "Sec-Fetch-Dest": "empty",
  "Sec-Fetch-Mode": "cors",
  "Sec-Fetch-Site": "same-origin",
}

const CHANNEL_CACHE_TTL_MS = 10 * 60 * 1000
const LOCAL_CHANNELS_PATH = "television/network_scrape/channels.json"
const M3U8_URL_RE = /https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*/i

let channelCache: { expiresAt: number; channels: IptvChannel[] } | null = null
let localChannelCache: { expiresAt: number; channels: IptvChannel[] } | null = null

function headersForOrigin(origin: string): Record<string, string> {
  return {
    ...IPTV_HEADERS,
    Referer: `${origin}/`,
    Origin: origin,
  }
}

function parseChannelsPayload(text: string): IptvChannel[] | null {
  const trimmed = text.trim()
  if (!trimmed || trimmed.startsWith("<")) {
    return null
  }

  try {
    const channels = JSON.parse(trimmed) as IptvChannel[]
    return Array.isArray(channels) ? channels : null
  } catch {
    return null
  }
}

async function fetchRemoteIptvChannels(): Promise<IptvChannel[] | null> {
  for (const origin of IPTV_PLAY_ORIGINS) {
    try {
      const response = await fetch(`${origin}/channels`, {
        headers: headersForOrigin(origin),
        cache: "no-store",
      })

      if (!response.ok) {
        continue
      }

      const channels = parseChannelsPayload(await response.text())
      if (channels?.length) {
        return channels
      }
    } catch {
      continue
    }
  }

  return null
}

function loadLocalIptvChannels(): IptvChannel[] {
  if (localChannelCache && localChannelCache.expiresAt > Date.now()) {
    return localChannelCache.channels
  }

  const filePath = path.join(process.cwd(), LOCAL_CHANNELS_PATH)

  if (!fs.existsSync(filePath)) {
    throw new Error("IPTV channel list unavailable (provider offline, no local cache)")
  }

  const channels = parseChannelsPayload(fs.readFileSync(filePath, "utf8"))
  if (!channels?.length) {
    throw new Error("Local IPTV channel cache is empty or invalid")
  }

  localChannelCache = {
    expiresAt: Date.now() + CHANNEL_CACHE_TTL_MS,
    channels,
  }

  return channels
}

export { loadLocalIptvChannels }

export function getIptvPlayPath(channelId: number | string, kind: "m3u8" | "html" = "m3u8") {
  return `/play/${channelId}/index.${kind === "html" ? "html" : "m3u8"}`
}

export function getIptvPlayUrl(channelId: number | string, kind: "m3u8" | "html" = "m3u8") {
  return `${IPTV_BASE_URL}${getIptvPlayPath(channelId, kind)}`
}

export function extractM3u8FromBody(body: string) {
  return body.match(M3U8_URL_RE)?.[0] ?? null
}

export function isM3u8Playlist(body: string) {
  return body.trimStart().startsWith("#EXTM3U")
}

export async function fetchIptvChannels(): Promise<IptvChannel[]> {
  if (channelCache && channelCache.expiresAt > Date.now()) {
    return channelCache.channels
  }

  try {
    const { fetchMediahubChannels } = await import("@/lib/iptv/mediahub-catalog")
    const { channels } = await fetchMediahubChannels({ maxPages: 4 })
    if (channels.length) {
      channelCache = {
        expiresAt: Date.now() + CHANNEL_CACHE_TTL_MS,
        channels,
      }
      return channels
    }
  } catch {
    // Fall through to legacy cache.
  }

  const channels = (await fetchRemoteIptvChannels()) ?? loadLocalIptvChannels()

  channelCache = {
    expiresAt: Date.now() + CHANNEL_CACHE_TTL_MS,
    channels,
  }

  return channels
}

export function getIptvCountries(channels: IptvChannel[]) {
  return [...new Set(channels.map((channel) => channel.country).filter(Boolean))].sort()
}

export function filterIptvChannels(
  channels: IptvChannel[],
  args: { search?: string; country?: string }
) {
  const search = args.search?.trim().toLowerCase()

  return channels.filter((channel) => {
    if (args.country && channel.country !== args.country) {
      return false
    }

    if (!search) {
      return true
    }

    return (
      channel.name.toLowerCase().includes(search) ||
      channel.country.toLowerCase().includes(search)
    )
  })
}

export function isAllowedProviderUrl(url: string) {
  try {
    const parsed = new URL(url)
    return parsed.origin === IPTV_BASE_URL
  } catch {
    return false
  }
}
