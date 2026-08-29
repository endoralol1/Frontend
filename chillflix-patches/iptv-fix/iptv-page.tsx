"use client"

import { useCallback, useEffect, useState } from "react"
import { Loader2, Radio, Search } from "lucide-react"

import {
  IptvPlayerModal,
  type IptvChannelSelection,
} from "@/components/iptv-player-modal"
import { type IptvSourceType } from "@/components/iptv-player"
import { cn } from "@/lib/utils"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { ensureIptvServiceWorkerReady } from "@/lib/iptv/browser-play"
import { type CountryCount } from "@/lib/iptv/counts"
import { useTranslations } from "@/lib/i18n/client"

type IptvListChannel = IptvChannelSelection

type SourceTotals = Record<IptvSourceType, number | null>

const ALL_COUNTRIES_VALUE = "all"

async function readIptvChannelsResponse(response: Response) {
  const contentType = response.headers.get("content-type") ?? ""
  if (contentType.includes("json")) {
    return response.json()
  }

  const text = await response.text()
  if (text.trimStart().startsWith("<")) {
    throw new Error("Server returned a page instead of channel data. Try refreshing.")
  }

  try {
    return JSON.parse(text)
  } catch {
    throw new Error(`Unexpected response (${response.status})`)
  }
}

function formatChannelCount(count: number | null) {
  return count == null ? "" : ` (${count.toLocaleString()})`
}

export function IptvPage() {
  const { t } = useTranslations()
  const [sourceType, setSourceType] = useState<IptvSourceType>("live")
  const [channels, setChannels] = useState<IptvListChannel[]>([])
  const [sourceTotals, setSourceTotals] = useState<SourceTotals>({
    live: null,
    "free-tv": null,
  })
  const [countryCounts, setCountryCounts] = useState<CountryCount[]>([])
  const [filteredTotal, setFilteredTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()
  const [search, setSearch] = useState("")
  const [country, setCountry] = useState("")
  const [activeChannel, setActiveChannel] = useState<IptvListChannel | null>(null)

  const loadChannels = useCallback(async () => {
    setLoading(true)
    setError(undefined)

    try {
      const params = new URLSearchParams({ source: sourceType })
      if (search.trim()) params.set("search", search.trim())
      if (country) params.set("country", country)

      const response = await fetch(`/api/iptv/channels?${params}`)
      const data = await readIptvChannelsResponse(response)

      if (!response.ok || !data.success) {
        throw new Error(data.error ?? `HTTP ${response.status}`)
      }

      setChannels(data.channels ?? [])
      setCountryCounts(data.countryCounts ?? [])
      setFilteredTotal(typeof data.total === "number" ? data.total : (data.channels?.length ?? 0))

      if (typeof data.totalAll === "number") {
        setSourceTotals((prev) => ({ ...prev, [sourceType]: data.totalAll }))
      }
    } catch (fetchError) {
      const message =
        fetchError instanceof Error ? fetchError.message : t("iptvExtra.loadChannelsFailed")
      setError(message)
      setChannels([])
    } finally {
      setLoading(false)
    }
  }, [country, search, sourceType, t])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadChannels()
    }, 80)

    return () => window.clearTimeout(timeout)
  }, [loadChannels])

  useEffect(() => {
    const storedSource = localStorage.getItem("iptv:sourceType")
    if (storedSource === "free-tv" || storedSource === "live") {
      setSourceType(storedSource)
    }
  }, [])

  useEffect(() => {
    void ensureIptvServiceWorkerReady()
  }, [])

  useEffect(() => {
    const sources: IptvSourceType[] = ["live", "free-tv"]

    void Promise.all(
      sources.map(async (source) => {
        try {
          const response = await fetch(`/api/iptv/channels?source=${source}`)
          const data = await readIptvChannelsResponse(response)

          if (response.ok && data.success && typeof data.totalAll === "number") {
            setSourceTotals((prev) => ({ ...prev, [source]: data.totalAll }))
          }
        } catch {
          // Counts are optional; the active source load still surfaces errors.
        }
      })
    )
  }, [])

  useEffect(() => {
    localStorage.setItem("iptv:sourceType", sourceType)
    setActiveChannel(null)
    setCountry("")
  }, [sourceType])

  const selectedCountryCount = country
    ? (countryCounts.find((item) => item.country === country)?.count ?? 0)
    : null
  const countrySelectLabel = country
    ? `${country} (${selectedCountryCount?.toLocaleString() ?? "0"})`
    : `${t("iptv.allCountries")} (${filteredTotal.toLocaleString()})`

  const filterSummary = [
    t("iptv.showingChannels", { count: filteredTotal }),
    country ? t("iptvExtra.inCountry", { country }) : "",
    search.trim() ? t("iptvExtra.matching", { query: search.trim() }) : "",
  ]
    .filter(Boolean)
    .join(" ")

  return (
    <section className="container space-y-6 py-6">
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <Radio className="size-5" />
          <h1 className="text-2xl font-semibold tracking-tight">{t("iptv.title")}</h1>
        </div>
        <p className="text-sm text-muted-foreground">
          {sourceType === "live" ? t("iptv.liveDescription") : t("iptv.freeTvDescription")}
        </p>
      </div>

      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant={sourceType === "live" ? "secondary" : "outline"}
            size="sm"
            onClick={() => setSourceType("live")}
          >
            {t("iptv.liveTv")}{formatChannelCount(sourceTotals.live)}
          </Button>
          <Button
            type="button"
            variant={sourceType === "free-tv" ? "secondary" : "outline"}
            size="sm"
            onClick={() => setSourceType("free-tv")}
          >
            {t("iptv.freeTv")}{formatChannelCount(sourceTotals["free-tv"])}
          </Button>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="relative min-w-0 flex-1">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t("iptv.searchPlaceholder")}
              className="pl-9"
            />
          </div>

          <Select
            value={country || ALL_COUNTRIES_VALUE}
            onValueChange={(value) => setCountry(value === ALL_COUNTRIES_VALUE ? "" : value)}
          >
            <SelectTrigger className="w-full sm:w-64">
              <SelectValue>{countrySelectLabel}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_COUNTRIES_VALUE}>
                {t("iptv.allCountries")} ({filteredTotal.toLocaleString()})
              </SelectItem>
              {countryCounts.map((item) => (
                <SelectItem key={item.country} value={item.country}>
                  {item.country} ({item.count.toLocaleString()})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {!loading && !error && (country || search.trim()) ? (
          <p className="text-xs text-muted-foreground">{filterSummary}</p>
        ) : null}
      </div>

      {loading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" />
          {t("iptv.loadingChannels")}
        </div>
      ) : error ? (
        <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm">
          {error}
        </div>
      ) : (
        <div className="grid max-h-[32rem] gap-2 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
          {channels.map((channel) => (
            <button
              key={String(channel.id)}
              type="button"
              onClick={() => setActiveChannel(channel)}
              className={cn(
                "rounded-lg border px-3 py-3 text-left transition-colors",
                "border-border/40 bg-background/40 hover:border-border/70 hover:bg-background/70",
                activeChannel?.id === channel.id && "border-primary/40 bg-primary/10"
              )}
            >
              <p className="line-clamp-2 text-sm font-medium">{channel.name}</p>
              <p className="mt-1 text-xs text-muted-foreground">{channel.country}</p>
            </button>
          ))}
        </div>
      )}

      <IptvPlayerModal
        channel={activeChannel}
        sourceType={sourceType}
        onClose={() => setActiveChannel(null)}
      />
    </section>
  )
}
