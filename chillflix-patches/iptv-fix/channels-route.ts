import { NextResponse } from "next/server"

import { getCountryCounts } from "@/lib/iptv/counts"
import {
  fetchFreeTvChannels,
  filterFreeTvChannels,
} from "@/lib/iptv/free-tv"
import { fetchMediahubChannels } from "@/lib/iptv/mediahub-catalog"
import {
  IPTV_BASE_URL,
  filterIptvChannels,
} from "@/lib/iptv/provider"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

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
        source: "https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8",
      })
    }

    const { channels, groups } = await fetchMediahubChannels({
      search,
      country,
      maxPages: search || country ? 20 : 12,
    })
    const forCountryCounts = search
      ? filterIptvChannels(channels, { search })
      : channels

    return NextResponse.json({
      success: true,
      sourceType: "live",
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
      totalAll: channels.length,
      source: `${IPTV_BASE_URL}/${"mediahubmx-catalog.json"}`,
    })
  } catch (error) {
    const message = error instanceof Error ? error.message : "Failed to load IPTV channels"

    return NextResponse.json({ success: false, error: message }, { status: 502 })
  }
}
