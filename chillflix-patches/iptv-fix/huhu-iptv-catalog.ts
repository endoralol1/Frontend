import "server-only"

import fs from "fs"
import path from "path"

import { IPTV_HEADERS, type IptvChannel } from "@/lib/iptv/provider"

const HUHU_ORIGIN = "https://huhu.to"
const HUHU_CATALOG_URL = `${HUHU_ORIGIN}/mediaurl-catalog.json`
const CATALOG_PAGE_SIZE = 300
const DEFAULT_MAX_PAGES = 40
const CACHE_TTL_MS = 10 * 60 * 1000
const VUFLIX_CACHE_CANDIDATES = [
  "/var/www/chillflix-newsite/storage/cache/huhu-live-tv.json",
  path.join(process.cwd(), "../chillflix-newsite/storage/cache/huhu-live-tv.json"),
]

type HuhuCatalogItem = {
  type?: string
  ids?: { id?: string | number }
  url?: string
  name?: string
  group?: string
  logo?: string
}

type HuhuCatalogResponse = {
  items?: HuhuCatalogItem[]
  nextCursor?: string | number | null
  features?: {
    filter?: Array<{ id?: string; values?: string[] }>
  }
  error?: string
}

let catalogCache: {
  expiresAt: number
  channels: IptvChannel[]
  groups: string[]
} | null = null

function huhuHeaders() {
  return {
    ...IPTV_HEADERS,
    "Content-Type": "application/json; charset=utf-8",
    Referer: `${HUHU_ORIGIN}/`,
    Origin: HUHU_ORIGIN,
  }
}

function loadVuflixHuhuCache(): { channels: IptvChannel[]; groups: string[] } | null {
  for (const filePath of VUFLIX_CACHE_CANDIDATES) {
    try {
      if (!fs.existsSync(filePath)) continue
      const ageMs = Date.now() - fs.statSync(filePath).mtimeMs
      if (ageMs > 60 * 60 * 1000) continue

      const raw = JSON.parse(fs.readFileSync(filePath, "utf8")) as {
        channels?: Array<{
          id?: string
          name?: string
          group?: string
          logo?: string
          playPage?: string
        }>
        categories?: Array<{ name?: string }>
      }

      const channels = (raw.channels ?? [])
        .map((channel) => {
          const id = channel.id ? String(channel.id) : ""
          if (!id) return null
          return {
            id,
            name: channel.name || id,
            country: channel.group || "Unknown",
            p: 0,
            playPageUrl:
              channel.playPage ||
              `${HUHU_ORIGIN}/huhu-iptv/play/${encodeURIComponent(id)}`,
            logo: channel.logo || undefined,
          } satisfies IptvChannel
        })
        .filter(Boolean) as IptvChannel[]

      if (!channels.length) continue

      const groups =
        raw.categories?.map((category) => String(category.name || "")).filter(Boolean) ??
        [...new Set(channels.map((channel) => channel.country))].sort()

      return { channels, groups }
    } catch {
      continue
    }
  }

  return null
}

async function postHuhuCatalog(body: Record<string, unknown>) {
  const response = await fetch(HUHU_CATALOG_URL, {
    method: "POST",
    headers: huhuHeaders(),
    cache: "no-store",
    signal: AbortSignal.timeout(20_000),
    body: JSON.stringify({
      language: "en",
      region: "US",
      ...body,
    }),
  })

  const text = await response.text()
  if (!response.ok || text.trimStart().startsWith("<")) {
    throw new Error(`Huhu catalog failed: HTTP ${response.status}`)
  }

  let payload: HuhuCatalogResponse
  try {
    payload = JSON.parse(text) as HuhuCatalogResponse
  } catch {
    throw new Error("Huhu catalog returned invalid JSON")
  }

  if (payload?.error) {
    throw new Error(payload.error)
  }

  return payload
}

function mapCatalogItem(item: HuhuCatalogItem): IptvChannel | null {
  const id = item.ids?.id != null ? String(item.ids.id) : null
  if (!id) return null

  return {
    id,
    name: item.name || id,
    country: item.group || "Unknown",
    p: 0,
    playPageUrl: item.url || `${HUHU_ORIGIN}/huhu-iptv/play/${encodeURIComponent(id)}`,
    logo: item.logo || undefined,
  }
}

export function buildHuhuIptvPlayPageUrl(channelId: string | number) {
  return `${HUHU_ORIGIN}/huhu-iptv/play/${encodeURIComponent(String(channelId))}`
}

export async function fetchHuhuIptvChannels(args: {
  search?: string
  country?: string
  maxPages?: number
}) {
  const search = args.search?.trim() ?? ""
  const country = args.country?.trim() ?? ""
  const maxPages = args.maxPages ?? DEFAULT_MAX_PAGES

  // Full catalog is cached; filter in-process for search/country.
  if (!search && !country && catalogCache && catalogCache.expiresAt > Date.now()) {
    return {
      channels: catalogCache.channels,
      groups: catalogCache.groups,
    }
  }

  if (catalogCache && catalogCache.expiresAt > Date.now()) {
    let channels = catalogCache.channels
    if (country) {
      channels = channels.filter((channel) => channel.country === country)
    }
    if (search) {
      const needle = search.toLowerCase()
      channels = channels.filter(
        (channel) =>
          channel.name.toLowerCase().includes(needle) ||
          channel.country.toLowerCase().includes(needle)
      )
    }
    return { channels, groups: catalogCache.groups }
  }

  const warmed = loadVuflixHuhuCache()
  if (warmed?.channels.length) {
    catalogCache = {
      expiresAt: Date.now() + CACHE_TTL_MS,
      channels: warmed.channels,
      groups: warmed.groups,
    }

    let channels = warmed.channels
    if (country) {
      channels = channels.filter((channel) => channel.country === country)
    }
    if (search) {
      const needle = search.toLowerCase()
      channels = channels.filter(
        (channel) =>
          channel.name.toLowerCase().includes(needle) ||
          channel.country.toLowerCase().includes(needle)
      )
    }
    return { channels, groups: warmed.groups }
  }

  const seen = new Set<string>()
  const channels: IptvChannel[] = []
  let groups: string[] = []
  let cursor: string | number | null = null

  for (let page = 0; page < maxPages; page += 1) {
    const payload = await postHuhuCatalog({
      catalogId: "iptv",
      id: "",
      adult: false,
      search: "",
      sort: "trending-region",
      filter: {},
      cursor,
    })

    if (!groups.length) {
      groups =
        payload.features?.filter
          ?.find((entry) => entry.id === "group")
          ?.values?.filter(Boolean) ?? []
    }

    const items = payload.items ?? []
    for (const item of items) {
      const channel = mapCatalogItem(item)
      if (!channel) continue
      const key = String(channel.id)
      if (seen.has(key)) continue
      seen.add(key)
      channels.push(channel)
    }

    if (payload.nextCursor == null || (items.length < CATALOG_PAGE_SIZE && page > 0)) {
      break
    }

    cursor = payload.nextCursor
  }

  if (!channels.length) {
    throw new Error("Huhu IPTV catalog empty")
  }

  catalogCache = {
    expiresAt: Date.now() + CACHE_TTL_MS,
    channels,
    groups,
  }

  let filtered = channels
  if (country) {
    filtered = filtered.filter((channel) => channel.country === country)
  }
  if (search) {
    const needle = search.toLowerCase()
    filtered = filtered.filter(
      (channel) =>
        channel.name.toLowerCase().includes(needle) ||
        channel.country.toLowerCase().includes(needle)
    )
  }

  return { channels: filtered, groups }
}

/** Map legacy numeric kool scrape IDs → huhu mediaurl play ids when possible. */
export async function resolveHuhuPlayIdFromLegacyId(channelId: string) {
  const { channels } = await fetchHuhuIptvChannels({ maxPages: DEFAULT_MAX_PAGES })
  const exact = channels.find((channel) => String(channel.id) === channelId)
  if (exact) return String(exact.id)

  // Logos / legacy scrapes use the numeric prefix of the mediaurl id.
  const prefixHit = channels.find((channel) =>
    String(channel.id).startsWith(channelId)
  )
  return prefixHit ? String(prefixHit.id) : null
}
