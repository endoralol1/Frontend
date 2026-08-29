import {
  fetchWithRedirects,
  isHlsManifestBody,
  isHttpUrl,
  type ProxyFetchResult,
} from "@/lib/iptv/proxy"
import { rewriteHlsManifestUrls } from "@/lib/iptv/manifest-rewrite"
import {
  buildMediahubPlayPageUrl,
  resolveMediahubStream,
} from "@/lib/iptv/mediahub-catalog"
import { isFlaresolverrEnabled } from "@/lib/outbound-http-proxy"

const PROVIDERS = [
  { origin: "https://huhu.to", referer: "https://huhu.to/" },
  { origin: "https://kool.to", referer: "https://kool.to/" },
]

function refererForTarget(targetUrl: string) {
  try {
    const url = new URL(targetUrl)
    if (url.hostname.endsWith("kool.to")) return "https://kool.to/"
    if (url.hostname.endsWith("huhu.to")) return "https://huhu.to/"
    return `${url.origin}/`
  } catch {
    return "https://kool.to/"
  }
}

function isProviderErrorBody(body: string) {
  const trimmed = body.trim()
  if (!trimmed.startsWith("{")) return false
  try {
    const parsed = JSON.parse(trimmed) as { error?: string }
    return Boolean(parsed.error)
  } catch {
    return false
  }
}

export function rewriteLiveFetchManifest(manifest: string, masterUrl: string, requestOrigin: string) {
  let origin = requestOrigin.replace(/\/$/, "")

  // Browsers load /play over https — never rewrite segments to http:// or localhost.
  try {
    const url = new URL(origin)
    const host = url.hostname
    if (
      host === "localhost" ||
      host === "127.0.0.1" ||
      host === "::1" ||
      host.endsWith("chillflix.lol") ||
      host.endsWith("chillflix.pw")
    ) {
      const publicHost =
        host === "localhost" || host === "127.0.0.1" || host === "::1"
          ? "www.chillflix.lol"
          : host
      origin = `https://${publicHost}`
    }
  } catch {
    origin = "https://www.chillflix.lol"
  }

  return rewriteHlsManifestUrls(manifest, masterUrl, (absolute) =>
    `${origin}/api/iptv/live-fetch?u=${encodeURIComponent(absolute)}`
  )
}

export function rewriteBrowserIptvFetchManifest(manifest: string, masterUrl: string) {
  return rewriteHlsManifestUrls(manifest, masterUrl, (absolute) =>
    `/iptv-fetch?u=${encodeURIComponent(absolute)}`
  )
}

async function fetchProviderPlayManifestViaFlaresolverr(channelId: string) {
  if (!isFlaresolverrEnabled()) {
    return null
  }

  const { fetchUrlViaFlaresolverr } = await import("@/lib/iptv/flaresolverr")

  for (const provider of PROVIDERS) {
    const playUrl = `${provider.origin}/play/${encodeURIComponent(channelId)}/index.m3u8`

    try {
      const solved = await fetchUrlViaFlaresolverr(playUrl, 10000)

      if (!solved || solved.status === 404) {
        continue
      }

      const bodyText = solved.body

      if (isProviderErrorBody(bodyText)) {
        continue
      }

      if (isHlsManifestBody(bodyText)) {
        return {
          status: solved.status,
          finalUrl: solved.finalUrl,
          body: Buffer.from(bodyText),
          contentType: "application/vnd.apple.mpegurl",
        } satisfies ProxyFetchResult
      }

      if (solved.finalUrl.includes(".m3u8")) {
        const follow = await fetchUrlViaFlaresolverr(solved.finalUrl, 10000)
        if (follow && isHlsManifestBody(follow.body)) {
          return {
            status: follow.status,
            finalUrl: follow.finalUrl,
            body: Buffer.from(follow.body),
            contentType: "application/vnd.apple.mpegurl",
          } satisfies ProxyFetchResult
        }
      }
    } catch {
      continue
    }
  }

  return null
}

async function fetchProviderPlayManifest(channelId: string, options?: { allowFlaresolverr?: boolean }) {
  for (const provider of PROVIDERS) {
    const playUrl = `${provider.origin}/play/${encodeURIComponent(channelId)}/index.m3u8`

    try {
      const result = await fetchWithRedirects(playUrl, {
        Referer: provider.referer,
      })

      if (result.status === 404) {
        continue
      }

      const bodyText = result.body.toString("utf8")

      if (isProviderErrorBody(bodyText)) {
        continue
      }

      if (!isHlsManifestBody(bodyText)) {
        continue
      }

      return result
    } catch {
      continue
    }
  }

  if (!options?.allowFlaresolverr || !isFlaresolverrEnabled()) {
    return null
  }

  return fetchProviderPlayManifestViaFlaresolverr(channelId)
}

const HUHU_ORIGIN = "https://huhu.to"
const HUHU_RESOLVE_URL = `${HUHU_ORIGIN}/mediaurl-resolve.json`

function buildHuhuIptvPlayPageUrl(channelId: string) {
  return `${HUHU_ORIGIN}/huhu-iptv/play/${encodeURIComponent(channelId)}`
}

async function resolveHuhuMediaurlStream(playPageUrl: string) {
  const response = await fetch(HUHU_RESOLVE_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      Accept: "*/*",
      Origin: HUHU_ORIGIN,
      Referer: `${HUHU_ORIGIN}/`,
      "User-Agent":
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    },
    cache: "no-store",
    signal: AbortSignal.timeout(12_000),
    body: JSON.stringify({
      language: "en",
      region: "US",
      url: playPageUrl,
    }),
  })

  const text = await response.text()
  if (!response.ok || text.trimStart().startsWith("<")) {
    throw new Error(`Huhu resolve failed: HTTP ${response.status}`)
  }

  let payload: { url?: string; error?: string } | Array<{ url?: string }>
  try {
    payload = JSON.parse(text) as typeof payload
  } catch {
    throw new Error("Huhu resolve returned invalid JSON")
  }

  if (!Array.isArray(payload) && payload?.error) {
    throw new Error(payload.error)
  }

  const entry = Array.isArray(payload) ? payload[0] : payload
  const streamUrl = entry?.url?.trim()
  if (!streamUrl) {
    throw new Error("Channel could not be resolved")
  }

  return streamUrl
}

async function fetchResolvedPlayPageManifest(playPageUrl: string) {
  const origin = new URL(playPageUrl).origin
  let streamUrl: string | null = null

  // vuflix / Live TV IDs resolve on huhu mediaurl (works from this VPS).
  if (origin.includes("huhu.to") || playPageUrl.includes("huhu-iptv")) {
    try {
      streamUrl = await resolveHuhuMediaurlStream(playPageUrl)
    } catch {
      streamUrl = null
    }
  }

  // kool mediahub (often Cloudflare-blocked from datacenter IPs).
  if (!streamUrl) {
    try {
      streamUrl = await resolveMediahubStream(playPageUrl)
    } catch {
      streamUrl = null
    }
  }

  // Last try: force huhu resolve even for kool-style play pages.
  if (!streamUrl) {
    const huhuPage = playPageUrl.includes("huhu.to")
      ? playPageUrl
      : buildHuhuIptvPlayPageUrl(
          playPageUrl.split("/").pop()?.split("?")[0] || playPageUrl
        )
    streamUrl = await resolveHuhuMediaurlStream(huhuPage)
  }

  const result = await fetchWithRedirects(streamUrl, {
    Referer: `${origin}/`,
  })

  const bodyText = result.body.toString("utf8")
  if (!isHlsManifestBody(bodyText)) {
    return null
  }

  return result
}

export async function resolveLivePlayManifest(
  channelId: string,
  requestOrigin: string,
  playPageUrl?: string
) {
  let resolvedId = channelId

  // chillflix local scrape used numeric IDs; huhu MediaURL ids are longer hex strings.
  if (/^\d+$/.test(channelId)) {
    try {
      const { resolveHuhuPlayIdFromLegacyId } = await import(
        "@/lib/iptv/huhu-iptv-catalog"
      )
      const mapped = await resolveHuhuPlayIdFromLegacyId(channelId)
      if (mapped) {
        resolvedId = mapped
      }
    } catch {
      // keep original id
    }
  }

  const playPageCandidates = [
    playPageUrl,
    buildHuhuIptvPlayPageUrl(resolvedId),
    buildHuhuIptvPlayPageUrl(channelId),
    buildMediahubPlayPageUrl(resolvedId),
    buildMediahubPlayPageUrl(channelId),
  ].filter((value, index, list): value is string =>
    Boolean(value && list.indexOf(value) === index)
  )

  for (const candidate of playPageCandidates) {
    try {
      const result = await fetchResolvedPlayPageManifest(candidate)
      if (!result) continue

      const bodyText = result.body.toString("utf8")
      return {
        ok: true as const,
        manifest: rewriteLiveFetchManifest(bodyText, result.finalUrl, requestOrigin),
      }
    } catch {
      continue
    }
  }

  const result = await fetchProviderPlayManifest(resolvedId, {
    allowFlaresolverr: isFlaresolverrEnabled(),
  })

  if (!result) {
    return {
      ok: false as const,
      status: 404,
      error: "Stream not available from this network.",
    }
  }

  const bodyText = result.body.toString("utf8")

  return {
    ok: true as const,
    manifest: rewriteLiveFetchManifest(bodyText, result.finalUrl, requestOrigin),
  }
}

export async function fetchLiveProxiedAsset(targetUrl: string, range?: string) {
  if (!isHttpUrl(targetUrl)) {
    return null
  }

  const referer = refererForTarget(targetUrl)
  const headers: Record<string, string> = {
    Referer: referer,
  }

  if (range) {
    headers.Range = range
  }

  const result = await fetchWithRedirects(targetUrl, headers)
  const bodyText = result.body.toString("utf8")

  if (isHlsManifestBody(bodyText)) {
    return {
      kind: "manifest" as const,
      result,
      bodyText,
    }
  }

  return {
    kind: "binary" as const,
    result,
    bodyText,
  }
}

export type LiveProxiedAsset = NonNullable<Awaited<ReturnType<typeof fetchLiveProxiedAsset>>>

export function manifestResponseFromAsset(
  asset: LiveProxiedAsset & { kind: "manifest" },
  requestOrigin: string
) {
  return rewriteLiveFetchManifest(asset.bodyText, asset.result.finalUrl, requestOrigin)
}

export function manifestResponseFromBrowserAsset(
  asset: LiveProxiedAsset & { kind: "manifest" }
) {
  return rewriteBrowserIptvFetchManifest(asset.bodyText, asset.result.finalUrl)
}

export function binaryResponseFromAsset(asset: LiveProxiedAsset & { kind: "binary" }) {
  return asset.result
}
