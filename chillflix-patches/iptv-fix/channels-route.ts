import { NextResponse } from "next/server"

import { getCountryCounts } from "@/lib/iptv/counts"
import {
  fetchFreeTvChannels,
  filterFreeTvChannels,
} from "@/lib/iptv/free-tv"
import {
  fetchLiveFallbackChannels,
  filterLiveFallbackChannels,
} from "@/lib/iptv/live-fallback"
import {
  buildMediahubPlayPageUrl,
  fetchMediahubChannels,
} from "@/lib/iptv/mediahub-catalog"
import {
  IPTV_BASE_URL,
  filterIptvChannels,
  loadLocalIptvChannels,
} from "@/lib/iptv/provider"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function jsonOk(body: Record<string, unknown>) {
  return NextResponse.json(body, {
    headers: {
      "cache-control": "private, max-age=30",
    },
  })
}

function loadLiveFromLocalCache(args: { search?: string; country?: string }) {
  const channels = loadLocalIptvChannels().map((channel) => ({
    ...channel,
    playPageUrl:
      channel.playPageUrl || buildMediahubPlayPageUrl(String(channel.id)),
  }))
  const forCountryCounts = args.search
    ? filterIptvChannels(channels, { search: args.search })
    : channels
  const filtered = filterIptvChannels(channels, args)

  return {
    channels: filtered,
    countryCounts: getCountryCounts(forCountryCounts),
    total: filtered.length,
    totalAll: channels.length,
    source: "local-cache",
    provider: "Live TV",
  }
}

async function loadLiveFromFallbackPlaylist(args: {
  search?: string
  country?: string
}) {
  const channels = await fetchLiveFallbackChannels()
  const forCountryCounts = filterLiveFallbackChannels(channels, {
    search: args.search,
  })
  const filtered = filterLiveFallbackChannels(channels, args)

  return {
    channels: filtered,
    countryCounts: getCountryCounts(forCountryCounts),
    total: filtered.length,
    totalAll: channels.length,
    source: "iptv-org",
    provider: "Live TV",
  }
}

export async function GET(request: Request) {
  const { searchParams } = new URL(request.url)
  const search = searchParams.get("search") ?? undefined
  const country = searchParams.get("country") ?? undefined
  const source = searchParams.get("source") ?? "live"

  try {
    if (source === "free-tv") {
      const channels = await fetchFreeTvChannels()
      const forCountryCounts = filterFreeTvChannels(channels, { search })
      const filtered = filterFreeTvChannels(channels, { search, country })

      return jsonOk({
        success: true,
        sourceType: "free-tv",
        provider: "Free-TV",
        channels: filtered,
        countryCounts: getCountryCounts(forCountryCounts),
        total: filtered.length,
        totalAll: channels.length,
        source:
          "https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8",
      })
    }

    try {
      const { channels, groups } = await fetchMediahubChannels({
        search,
        country,
        maxPages: search || country ? 8 : 4,
      })

      if (channels.length) {
        const forCountryCounts = search
          ? filterIptvChannels(channels, { search })
          : channels

        return jsonOk({
          success: true,
          sourceType: "live",
          provider: "Live TV",
          channels,
          countryCounts:
            groups.length > 0
              ? groups.map((countryName) => ({
                  country: countryName,
                  count: channels.filter((item) => item.country === countryName)
                    .length,
                }))
              : getCountryCounts(forCountryCounts),
          total: channels.length,
          totalAll: channels.length,
          source: `${IPTV_BASE_URL}/mediahubmx-catalog.json`,
        })
      }
    } catch {
      // kool.to / mediahub often Cloudflare-blocks datacenter IPs — fall through.
    }

    try {
      const fallback = await loadLiveFromFallbackPlaylist({ search, country })
      return jsonOk({
        success: true,
        sourceType: "live",
        ...fallback,
      })
    } catch {
      // Last resort: scraped local catalog.
    }

    const local = loadLiveFromLocalCache({ search, country })
    return jsonOk({
      success: true,
      sourceType: "live",
      ...local,
    })
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Failed to load IPTV channels"

    return NextResponse.json({ success: false, error: message }, { status: 502 })
  }
}
