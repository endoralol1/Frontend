import { buildCineproProxyHref } from "@/lib/cinepro/proxy"
import { fourKStreamNeedsRemux, isMkvFilename } from "@/lib/4khdhub/codec"
import { isFfmpegAvailable } from "@/lib/4khdhub/hls-flags"
import { resolveFourKStreamsClient } from "@/lib/providers/4khdhub-client-resolver"
import { resolveFourKApiOrigin } from "@/lib/playback-proxy-origin"
import { resolveVidifyStreamsClient } from "@/lib/providers/vidify-client-resolver"
import { resolveVideasyStreamsClient } from "@/lib/providers/videasy-client-resolver"
import { resolveVidrockStreamsClient } from "@/lib/providers/vidrock-client-resolver"
import {
  CLIENT_RESOLVE_PROVIDER_IDS,
  DEFAULT_PROVIDER_LABELS,
  normalizeProviderName,
} from "@/lib/stream-sources-defaults"
import {
  isVidrockClientPlaybackTestEnabled,
  VIDROCK_CLIENT_TEST_MESSAGE,
} from "@/lib/vidrock-client-test"

export type ClientResolvedSource = {
  url: string
  type: string
  quality: string
  provider: { id: string; name: string }
  audioTracks: Array<{ id: string; language: string; label: string }>
  directClientPlayback?: boolean
  clientPlaybackHeaders?: Record<string, string>
}

export { VIDROCK_CLIENT_TEST_MESSAGE }

export type ClientResolveParams = {
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
  playbackToken?: string
  requestOrigin?: string
}

function inferSourceType(streamUrl: string) {
  return streamUrl.includes(".m3u8") ? "hls" : "file"
}

function buildProxyHeaders(referer: string) {
  return {
    Referer: referer,
    Origin: "https://cloudorchestranova.com",
    Accept: "*/*",
    "Accept-Language": "en-US,en;q=0.9",
  }
}

function buildVideasyProxyHeaders() {
  return {
    Referer: "https://player.videasy.to/",
    Origin: "https://player.videasy.to",
    Accept: "*/*",
    "Accept-Language": "en-US,en;q=0.9",
  }
}

export function isClientResolveProvider(providerId: string) {
  const key = normalizeProviderName(providerId)
  if (key === "vidrock") {
    return isVidrockClientPlaybackTestEnabled()
  }
  return CLIENT_RESOLVE_PROVIDER_IDS.has(key)
}

export function isServerScrapeDiagnosticForClientProvider(diagnostic: {
  code?: string
  message?: string
}) {
  if (diagnostic.code !== "PROVIDER_ERROR") return false

  const match = diagnostic.message?.match(/^([^:]+):/)
  if (!match) return false

  return isClientResolveProvider(match[1].trim())
}

export function stripClientProviderServerDiagnostics<
  T extends { code?: string; message?: string },
>(diagnostics: T[]): T[] {
  return diagnostics.filter((item) => !isServerScrapeDiagnosticForClientProvider(item))
}

export const CLIENT_RESOLVE_PROVIDER_MESSAGE =
  "Resolves in the viewer's browser (uses their IP), not on the VPS. Test by playing a title on the site, or use Test on this provider while logged in here."

export async function resolveClientProviderSources(
  providerId: string,
  params: ClientResolveParams
): Promise<{ sources: ClientResolvedSource[]; failureReason?: string }> {
  const key = normalizeProviderName(providerId)
  const origin =
    params.requestOrigin ??
    (typeof window !== "undefined" ? window.location.origin : "https://chillflix.lol")

  if (key === "vidify") {
    const resolved = await resolveVidifyStreamsClient({
      type: params.type,
      tmdbId: params.tmdbId,
      season: params.season,
      episode: params.episode,
    })

    if (!resolved.streams.length) {
      return { sources: [], failureReason: resolved.failureReason }
    }

    const proxyHeaders = buildProxyHeaders(resolved.referer)
    const label = DEFAULT_PROVIDER_LABELS.vidify ?? "Vidify"

    const sources = resolved.streams.map((streamUrl, index) => ({
      url: buildCineproProxyHref(
        origin,
        streamUrl,
        proxyHeaders,
        params.playbackToken
      ),
      type: inferSourceType(streamUrl),
      quality: index === 0 ? "Auto" : `Stream ${index + 1}`,
      provider: { id: "vidify", name: label },
      audioTracks: [{ id: "original", language: "Original", label: "Original" }],
    }))

    return { sources }
  }

  if (key === "4khdhub") {
    const fourKOrigin = resolveFourKApiOrigin(origin)

    const resolved = await resolveFourKStreamsClient(
      {
        type: params.type,
        tmdbId: params.tmdbId,
        season: params.type === "tv" ? params.season : undefined,
        episode: params.type === "tv" ? params.episode : undefined,
      },
      fourKOrigin
    )

    if (!resolved.streams.length) {
      return { sources: [], failureReason: resolved.failureReason }
    }

    const label = DEFAULT_PROVIDER_LABELS["4khdhub"] ?? "4K"
    const proxyHeaders = {
      Referer: "https://hubcloud.ist/",
      Origin: "https://hubcloud.ist",
      Accept: "*/*",
      "Accept-Language": "en-US,en;q=0.9",
    }

    const sources = resolved.streams.flatMap((stream) => {
      const audioTracks = [{ id: "original", language: "en", label: "English" }]
      const base = {
        quality: stream.quality,
        provider: { id: "4khdhub", name: label },
        audioTracks,
      }

      const buildHlsSource = (mode: "remux" | "transcode") => {
        const search = new URLSearchParams({
          tmdbId: params.tmdbId,
          type: params.type,
          quality: stream.quality,
          filename: stream.filename,
          mode,
        })
        if (params.type === "tv") {
          if (params.season) search.set("season", params.season)
          if (params.episode) search.set("episode", params.episode)
        }

        return [
          {
            ...base,
            // Always use the HLS manifest route for container/codec conversion.
            // Do not fall back to a proxied raw file typed as "hls" — that hits the
            // 3s player probe timeout and never plays (prefer4k false timeout).
            url: `${fourKOrigin}/api/4k/hls/manifest?${search.toString()}`,
            type: "hls",
          },
        ]
      }

      // HEVC: prefer remux (one ffmpeg slot, video copy). Full transcode is limited to
      // one concurrent job site-wide — probing every HEVC quality as transcode causes
      // 502/503 FOUR_K_TRANSCODE_BUSY in the browser console.
      if (stream.codec === "hevc") {
        if (isFfmpegAvailable("remux")) return buildHlsSource("remux")
        if (isFfmpegAvailable("transcode")) return buildHlsSource("transcode")
        return []
      }

      if (fourKStreamNeedsRemux(stream)) {
        if (isFfmpegAvailable("remux")) return buildHlsSource("remux")
        if (isFfmpegAvailable("transcode")) return buildHlsSource("transcode")
        // MKV/lossy-audio without ffmpeg cannot direct-play in the browser.
        if (isMkvFilename(stream.filename)) return []
        return []
      }

      return [
        {
          ...base,
          url: buildCineproProxyHref(
            origin,
            stream.url,
            proxyHeaders,
            params.playbackToken
          ),
          type: stream.type === "hls" && !isMkvFilename(stream.filename) ? "hls" : "file",
        },
      ]
    })

    if (!sources.length) {
      return {
        sources: [],
        failureReason:
          resolved.failureReason ??
          "No direct-play 4K streams available without server transcoding.",
      }
    }

    return { sources }
  }

  if (key === "videasy") {
    const resolved = await resolveVideasyStreamsClient({
      type: params.type,
      tmdbId: params.tmdbId,
      season: params.season,
      episode: params.episode,
    })

    if (!resolved.streams.length) {
      return { sources: [], failureReason: resolved.failureReason }
    }

    const proxyHeaders = buildVideasyProxyHeaders()
    const label = DEFAULT_PROVIDER_LABELS.videasy ?? "Videasy"

    const sources = resolved.streams.map((stream, index) => ({
      url: buildCineproProxyHref(
        origin,
        stream.url,
        proxyHeaders,
        params.playbackToken
      ),
      type: stream.type,
      quality: stream.quality === "Auto" && index > 0 ? `Stream ${index + 1}` : stream.quality,
      provider: { id: "videasy", name: label },
      audioTracks: [{ id: "original", language: "en", label: "English" }],
    }))

    return { sources }
  }

  if (key === "vidrock") {
    const resolved = await resolveVidrockStreamsClient({
      type: params.type,
      tmdbId: params.tmdbId,
      season: params.season,
      episode: params.episode,
    })

    if (!resolved.streams.length) {
      return { sources: [], failureReason: resolved.failureReason }
    }

    const label = DEFAULT_PROVIDER_LABELS.vidrock ?? "VidRock"

    const sources = resolved.streams.map((stream) => ({
      url: stream.url,
      type: stream.type,
      quality: stream.quality,
      provider: { id: "vidrock", name: label },
      audioTracks: [{ id: "original", language: "en", label: "English" }],
      directClientPlayback: true,
      clientPlaybackHeaders: stream.headers,
    }))

    return { sources }
  }

  return { sources: [], failureReason: `Unknown client-resolve provider: ${providerId}` }
}
