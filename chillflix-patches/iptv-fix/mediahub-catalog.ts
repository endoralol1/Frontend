import "server-only"

import { IPTV_BASE_URL, IPTV_HEADERS, type IptvChannel } from "@/lib/iptv/provider"

const MEDIAHUB_PREFIX = "mediahubmx"
const CATALOG_PAGE_SIZE = 300
const DEFAULT_MAX_PAGES = 12

type MediahubCatalogItem = {
  type?: string
  ids?: { id?: string | number }
  url?: string
  name?: string
  group?: string
  logo?: string
}

type MediahubCatalogResponse = {
  items?: MediahubCatalogItem[]
  nextCursor?: string | number | null
  features?: {
    filter?: Array<{ id?: string; values?: string[] }>
  }
  error?: string
}

type MediahubResolveEntry = {
  url?: string
  name?: string
  id?: string
}

function mediahubHeaders() {
  return {
    ...IPTV_HEADERS,
    "Content-Type": "application/json; charset=utf-8",
    Referer: `${IPTV_BASE_URL}/`,
    Origin: IPTV_BASE_URL,
  }
}

async function postMediahub<T>(action: string, body: Record<string, unknown>): Promise<T> {
  const response = await fetch(`${IPTV_BASE_URL}/${MEDIAHUB_PREFIX}-${action}.json`, {
    method: "POST",
    headers: mediahubHeaders(),
    cache: "no-store",
    signal: AbortSignal.timeout(8_000),
    body: JSON.stringify({
      language: "en",
      region: "US",
      ...body,
    }),
  })

  const text = await response.text()
  if (!response.ok || text.trimStart().startsWith("<")) {
    throw new Error(`Mediahub ${action} failed: HTTP ${response.status}`)
  }

  let payload: T & { error?: string }
  try {
    payload = JSON.parse(text) as T & { error?: string }
  } catch {
    throw new Error(`Mediahub ${action} returned invalid JSON`)
  }

  if (payload?.error) {
    throw new Error(payload.error)
  }

  return payload
}

function mapCatalogItem(item: MediahubCatalogItem): IptvChannel | null {
  const id = item.ids?.id != null ? String(item.ids.id) : null
  if (!id) return null

  return {
    id,
    name: item.name || id,
    country: item.group || "Unknown",
    p: 0,
    playPageUrl: item.url || `${IPTV_BASE_URL}/kool-iptv/play/${encodeURIComponent(id)}`,
    logo: item.logo || undefined,
  }
}

export async function fetchMediahubCatalogPage(args: {
  search?: string
  country?: string
  cursor?: string | number | null
  sort?: "trending-region" | "name"
}) {
  const payload = await postMediahub<MediahubCatalogResponse>("catalog", {
    catalogId: "iptv",
    id: "",
    adult: false,
    search: args.search?.trim() ?? "",
    sort: args.sort ?? "trending-region",
    filter: args.country ? { group: args.country } : {},
    cursor: args.cursor ?? null,
  })

  const channels = (payload.items ?? []).map(mapCatalogItem).filter(Boolean) as IptvChannel[]
  const groups =
    payload.features?.filter?.find((entry) => entry.id === "group")?.values?.filter(Boolean) ?? []

  return {
    channels,
    groups,
    nextCursor: payload.nextCursor ?? null,
  }
}

export async function fetchMediahubChannels(args: {
  search?: string
  country?: string
  maxPages?: number
}) {
  const maxPages = args.maxPages ?? DEFAULT_MAX_PAGES
  const seen = new Set<string>()
  const channels: IptvChannel[] = []
  let groups: string[] = []
  let cursor: string | number | null = null

  for (let page = 0; page < maxPages; page += 1) {
    const result = await fetchMediahubCatalogPage({
      search: args.search,
      country: args.country,
      cursor,
    })

    if (!groups.length && result.groups.length) {
      groups = result.groups
    }

    for (const channel of result.channels) {
      const key = String(channel.id)
      if (seen.has(key)) continue
      seen.add(key)
      channels.push(channel)
    }

    if (result.nextCursor == null || (result.channels.length < CATALOG_PAGE_SIZE && page > 0)) {
      break
    }

    cursor = result.nextCursor
  }

  return { channels, groups }
}

export async function resolveMediahubStream(playPageUrl: string) {
  const payload = await postMediahub<MediahubResolveEntry[] | MediahubResolveEntry>("resolve", {
    url: playPageUrl,
  })

  const entry = Array.isArray(payload) ? payload[0] : payload
  const streamUrl = entry?.url?.trim()

  if (!streamUrl) {
    throw new Error("Channel could not be resolved")
  }

  return streamUrl
}

export function buildMediahubPlayPageUrl(channelId: string | number) {
  return `${IPTV_BASE_URL}/kool-iptv/play/${encodeURIComponent(String(channelId))}`
}
