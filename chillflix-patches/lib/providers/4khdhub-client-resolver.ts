export type FourKClientStream = {
  url: string
  type: "file" | "hls"
  quality: string
  filename: string
  codec?: string
}

export type FourKClientResolveResult = {
  streams: FourKClientStream[]
  failureReason?: string
}

type FourKClientMedia = {
  type: "movie" | "tv"
  tmdbId: string
  season?: string
  episode?: string
}

function sameOrigin(origin: string) {
  if (typeof window === "undefined") return false
  try {
    return new URL(origin).origin === window.location.origin
  } catch {
    return false
  }
}

async function parseResolvePayload(response: Response) {
  const text = await response.text()
  if (!text.trim()) {
    throw new Error(`Empty 4K resolve response (HTTP ${response.status}).`)
  }

  try {
    return JSON.parse(text) as {
      streams?: FourKClientStream[]
      error?: string
    }
  } catch {
    const snippet = text.replace(/\s+/g, " ").slice(0, 120)
    throw new Error(
      `4K resolve returned non-JSON (HTTP ${response.status}): ${snippet}`
    )
  }
}

export async function resolveFourKStreamsClient(
  media: FourKClientMedia,
  apiOrigin?: string
): Promise<FourKClientResolveResult> {
  const params = new URLSearchParams({
    tmdbId: media.tmdbId,
    type: media.type,
  })

  if (media.type === "tv") {
    if (media.season) params.set("season", media.season)
    if (media.episode) params.set("episode", media.episode)
  }

  const origin = (apiOrigin ?? "").trim().replace(/\/$/, "") || undefined
  const resolveUrl =
    origin && !sameOrigin(origin)
      ? `${origin}/api/4k/resolve?${params.toString()}`
      : `/api/4k/resolve?${params.toString()}`

  try {
    const response = await fetch(resolveUrl, {
      cache: "no-store",
      // Keep CF / session cookies on same-origin player pages.
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
      },
    })

    const data = await parseResolvePayload(response)

    if (!response.ok) {
      return {
        streams: [],
        failureReason: data.error ?? `4K resolve failed (HTTP ${response.status}).`,
      }
    }

    const streams = (data.streams ?? []).filter((stream) => Boolean(stream?.url))
    if (!streams.length) {
      return {
        streams: [],
        failureReason: data.error ?? "No 4K streams returned.",
      }
    }

    return { streams }
  } catch (error) {
    const detail = error instanceof Error ? error.message : "Unknown error"
    return {
      streams: [],
      failureReason: detail.startsWith("4K resolve")
        ? detail
        : `4K resolve request failed: ${detail}`,
    }
  }
}
