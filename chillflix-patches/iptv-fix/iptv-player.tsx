"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import Hls from "hls.js"
import { Loader2 } from "lucide-react"

import {
  buildLocalLiveApiUrl,
  createIptvHlsConfig,
  isLocalIptvDevHost,
  isIptvServiceWorkerControlling,
  prepareIptvBrowserPlay,
  wrapIptvFetchUrl,
} from "@/lib/iptv/browser-play"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

export type IptvSourceType = "live" | "free-tv"

type ResolvedSource = {
  label: string
  kind: string
  proxyUrl: string
}

function appendPlaybackToken(href: string, playbackToken: string) {
  const url = new URL(href, "http://playback.local")
  url.searchParams.set("pt", playbackToken)
  return `${url.pathname}${url.search}`
}

function buildProxyPath(targetUrl: string, playbackToken: string) {
  return appendPlaybackToken(
    `/api/iptv/proxy?url=${encodeURIComponent(targetUrl)}`,
    playbackToken
  )
}

async function mintIptvPlaybackSession(
  channelId: string,
  source: IptvSourceType,
  seedUrl?: string,
  fallbackError?: string
) {
  const params = new URLSearchParams({
    channelId,
    source,
  })

  if (seedUrl) {
    params.set("seedUrl", seedUrl)
  }

  const response = await fetch(`/api/iptv/session?${params}`)
  const data = await response.json()

  if (!response.ok || !data.playbackToken) {
    throw new Error(data.error ?? fallbackError ?? "Failed to start IPTV playback")
  }

  return String(data.playbackToken)
}

export type IptvPlayerProps = {
  channelId: string
  sourceType: IptvSourceType
  channelName?: string
  channelCountry?: string
  freeTvUrl?: string
  livePlayPageUrl?: string
  className?: string
  videoClassName?: string
}

export function IptvPlayer({
  channelId,
  sourceType,
  channelName: initialName,
  channelCountry: initialCountry = "",
  freeTvUrl,
  livePlayPageUrl,
  className,
  videoClassName,
}: IptvPlayerProps) {
  const { t } = useTranslations()
  const videoRef = useRef<HTMLVideoElement>(null)
  const hlsRef = useRef<Hls | null>(null)
  const [videoElement, setVideoElement] = useState<HTMLVideoElement | null>(null)

  const assignVideoRef = useCallback((node: HTMLVideoElement | null) => {
    videoRef.current = node
    setVideoElement(node)
  }, [])

  const [channelName, setChannelName] = useState(initialName ?? t("iptvExtra.liveChannel"))
  const [channelCountry, setChannelCountry] = useState(initialCountry)
  const [loading, setLoading] = useState(true)
  const [playerError, setPlayerError] = useState<string>()

  const isLive = sourceType === "live"
  const hasDirectStreamUrl = Boolean(freeTvUrl)
  const useLocalLiveApi = isLive && !hasDirectStreamUrl
  const useBrowserLiveProxy = useLocalLiveApi && !isLocalIptvDevHost()

  useEffect(() => {
    setChannelName(initialName ?? t("iptvExtra.liveChannel"))
    setChannelCountry(initialCountry)
  }, [initialCountry, initialName, channelId, t])

  useEffect(() => {
    if (!videoElement) return

    const video = videoElement

    let cancelled = false

    setPlayerError(undefined)
    setLoading(true)

    if (hlsRef.current) {
      hlsRef.current.destroy()
      hlsRef.current = null
    }

    video.removeAttribute("src")
    video.load()

    const cleanupPlayer = () => {
      if (hlsRef.current) {
        hlsRef.current.destroy()
        hlsRef.current = null
      }
    }

    const markReady = () => {
      if (!cancelled) {
        setLoading(false)
      }
    }

    const playSource = (streamUrl: string) =>
      new Promise<boolean>((resolve) => {
        if (cancelled) {
          resolve(false)
          return
        }

        cleanupPlayer()

        const playbackUrl = wrapIptvFetchUrl(streamUrl, useLocalLiveApi)

        const finish = (ok: boolean) => {
          if (ok) {
            markReady()
            setPlayerError(undefined)
          }
          resolve(ok)
        }

        if (Hls.isSupported()) {
          const hls = new Hls(createIptvHlsConfig(useLocalLiveApi))

          hlsRef.current = hls
          hls.loadSource(playbackUrl)
          hls.attachMedia(video)

          hls.on(Hls.Events.MANIFEST_PARSED, () => {
            void video.play().catch(() => undefined)
            finish(true)
          })

          hls.on(Hls.Events.ERROR, (_, data) => {
            if (!data.fatal) return
            finish(false)
          })
        } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
          video.src = playbackUrl
          void video
            .play()
            .then(() => finish(true))
            .catch(() => finish(false))
        } else {
          finish(false)
        }
      })

    const trySources = async (sources: ResolvedSource[], lastError?: string) => {
      for (let index = 0; index < sources.length; index += 1) {
        if (cancelled) return false

        const played = await playSource(sources[index].proxyUrl)
        if (played) {
          return true
        }

        if (index === sources.length - 1) {
          setPlayerError(lastError ?? t("iptvExtra.loadFailed"))
        }
      }

      return false
    }

    void (async () => {
      try {
        if (useBrowserLiveProxy) {
          const ready = await prepareIptvBrowserPlay()
          if (!ready || cancelled) {
            if (!cancelled) {
              setPlayerError(t("iptvExtra.proxyFailed"))
            }
            return
          }

          if (!isIptvServiceWorkerControlling() && !cancelled) {
            setPlayerError(t("iptvExtra.proxyFailed"))
            return
          }
        }

        // Direct playlist URL (Free-TV, or Live TV fallback when mediahub is blocked).
        if (!isLive || freeTvUrl) {
          let streamUrl = freeTvUrl

          if (!streamUrl) {
            const channelsResponse = await fetch(
              `/api/iptv/channels?source=${isLive ? "live" : "free-tv"}`
            )
            const channelsData = await channelsResponse.json()
            const channel = (channelsData.channels ?? []).find(
              (item: {
                id: string
                url?: string
                name?: string
                country?: string
              }) => String(item.id) === channelId
            )

            if (!channel?.url) {
              setPlayerError(t("iptvExtra.channelNotFound"))
              return
            }

            streamUrl = channel.url
            if (channel.name) setChannelName(channel.name)
            if (channel.country) setChannelCountry(channel.country)
          }

          const playbackToken = await mintIptvPlaybackSession(
            channelId,
            isLive ? "live" : "free-tv",
            streamUrl,
            t("iptvExtra.playbackFailed")
          )

          await trySources(
            [
              {
                label: "playlist",
                kind: "playlist",
                proxyUrl: buildProxyPath(streamUrl, playbackToken),
              },
            ],
            t("iptvExtra.loadFailed")
          )
          return
        }

        const liveUrl = buildLocalLiveApiUrl(channelId, livePlayPageUrl)
        await trySources(
          [
            {
              label: "live-api",
              kind: "live",
              proxyUrl: liveUrl,
            },
          ],
          t("iptvExtra.loadFailed")
        )
      } catch {
        if (!cancelled) {
          setPlayerError(
            isLive ? t("iptvExtra.loadFailed") : t("iptvExtra.freeTvLoadFailed")
          )
        }
      } finally {
        markReady()
      }
    })()

    return () => {
      cancelled = true
      cleanupPlayer()
    }
  }, [channelId, freeTvUrl, isLive, livePlayPageUrl, useBrowserLiveProxy, useLocalLiveApi, videoElement, t])

  return (
    <div className={cn("space-y-3", className)}>
      <div className="overflow-hidden rounded-xl border border-border/50 bg-black">
        <video
          ref={assignVideoRef}
          className={cn("aspect-video w-full", videoClassName)}
          controls
          playsInline
          autoPlay
          muted
        />
      </div>

      {loading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="size-4 animate-spin" />
          {t("iptvExtra.loadingStream")}
        </p>
      ) : null}

      {playerError ? <p className="text-sm text-amber-500">{playerError}</p> : null}

      <div className="sr-only" aria-live="polite">
        {channelName}
        {channelCountry ? `, ${channelCountry}` : ""}
      </div>
    </div>
  )
}
