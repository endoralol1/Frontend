import { IPTV_HEADERS } from "@/lib/iptv/provider"

export type FreeTvChannel = {
  id: string
  name: string
  country: string
  url: string
  source: "free-tv"
}

const PLAYLIST_URL = "https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8"
const CACHE_TTL_MS = 30 * 60 * 1000

let playlistCache: { expiresAt: number; channels: FreeTvChannel[] } | null = null

function parseAttrs(line: string) {
  const attrs: Record<string, string> = {}

  for (const match of line.matchAll(/([a-zA-Z0-9-]+)="([^"]*)"/g)) {
    attrs[match[1].toLowerCase()] = match[2]
  }

  return attrs
}

function pickCountry(attrs: Record<string, string>) {
  const raw = attrs["tvg-country"] || attrs.country || attrs["group-title"] || "Unknown"
  const part = raw.split(/[|/]/)[0]?.trim()
  return part || "Unknown"
}

export function parseFreeTvPlaylist(text: string): FreeTvChannel[] {
  const lines = text.split("\n")
  const channels: FreeTvChannel[] = []

  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index]?.trim()

    if (!line?.startsWith("#EXTINF:")) {
      continue
    }

    const attrs = parseAttrs(line)
    const comma = line.indexOf(",")
    const fallbackName = comma >= 0 ? line.slice(comma + 1).trim() : ""
    const name = attrs["tvg-name"] || fallbackName || `Free-TV ${channels.length + 1}`

    let url = ""

    for (let cursor = index + 1; cursor < lines.length; cursor += 1) {
      const next = lines[cursor]?.trim()

      if (!next || next.startsWith("#")) {
        continue
      }

      url = next
      index = cursor
      break
    }

    if (!url.startsWith("http://") && !url.startsWith("https://")) {
      continue
    }

    channels.push({
      id: `free-${channels.length + 1}`,
      name,
      country: pickCountry(attrs),
      url,
      source: "free-tv",
    })
  }

  return channels
}

export async function fetchFreeTvChannels(): Promise<FreeTvChannel[]> {
  if (playlistCache && playlistCache.expiresAt > Date.now()) {
    return playlistCache.channels
  }

  const response = await fetch(PLAYLIST_URL, {
    headers: IPTV_HEADERS,
    cache: "no-store",
  })

  if (!response.ok) {
    throw new Error(`Free-TV playlist request failed: HTTP ${response.status}`)
  }

  const text = await response.text()
  const channels = parseFreeTvPlaylist(text)

  playlistCache = {
    expiresAt: Date.now() + CACHE_TTL_MS,
    channels,
  }

  return channels
}

export function getFreeTvCountries(channels: FreeTvChannel[]) {
  return [...new Set(channels.map((channel) => channel.country).filter(Boolean))].sort()
}

export function filterFreeTvChannels(
  channels: FreeTvChannel[],
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
