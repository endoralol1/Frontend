import { NextResponse } from "next/server"

import { getCountryCounts } from "@/lib/iptv/counts"
import {
  fetchFreeTvChannels,
  filterFreeTvChannels,
} from "@/lib/iptv/free-tv"
import {
  buildMediahubPlayPageUrl,
  fetchMediahubChannels,
} from "@/lib/iptv/mediahub-catalog"
import {
  IPTV_BASE_URL,
  filterIptvChannels,
  loadLocalIptvChannels,
  type IptvChannel,
} from "@/lib/iptv/provider"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function withPlayPageUrls(channels: IptvChannel[]) {
  return channels.map((channel) => ({
    ...channel,
    playPageUrl:
      channel.playPageUrl || buildMediahubPlayPageUrl(String(channel.id)),
  }))
}

function livePayload(
  channels: IptvChannel[],
  groups: string[],
  args: { search?: string; source: string; totalAll?: number }
) {
  const forCountryCounts = args.search
    ? filterIptvChannels(channels, { search: args.search })
    : channels

  return {
    success: true as const,
    sourceType: "live" as const,
    provider: "Live TV",
    channels,
    countryCounts:
      groups.length > 0
        ? groups.map((countryName) => ({
            country: countryName,
            count: channels.filter((item) => item.country === countryName).length,
          }))
        : getCountryCounts(forCountryCounts),
    total: channels.length,
    totalAll: args.totalAll ?? channels.length,
    source: args.source,
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

      return NextResponse.json({
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
        maxPages: search || country ? 20 : 12,
      })

      if (channels.length) {
        return NextResponse.json(
          livePayload(channels, groups, {
            search,
            source: `${IPTV_BASE_URL}/mediahubmx-catalog.json`,
          })
        )
      }
    } catch {
      // Server IP is Cloudflare-blocked on kool.to — use the local kool scrape.
    }

    // Same Live TV channel universe as before (kool network scrape), not a public IPTV dump.
    const local = withPlayPageUrls(loadLocalIptvChannels())
    const forCountryCounts = filterIptvChannels(local, { search })
    const filtered = filterIptvChannels(local, { search, country })

    return NextResponse.json({
      success: true,
      sourceType: "live",
      provider: "Live TV",
      channels: filtered,
      countryCounts: getCountryCounts(forCountryCounts),
      total: filtered.length,
      totalAll: local.length,
      source: "local-kool-catalog",
    })
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "Failed to load IPTV channels"

    return NextResponse.json({ success: false, error: message }, { status: 502 })
  }
}
