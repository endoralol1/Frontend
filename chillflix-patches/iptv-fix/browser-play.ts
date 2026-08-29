export const IPTV_SW_VERSION = "live18"
export const IPTV_SW_PATH = `/iptv-play-sw.js?v=${IPTV_SW_VERSION}`
export const IPTV_FETCH_PATH = "/iptv-fetch"
export const IPTV_HUHU_ORIGIN = "https://huhu.to"

export const IPTV_SESSION_COOKIE_KEY = "iptv:sessionCookies"
export const IPTV_SW_RELOAD_KEY = "iptv:sw-reload"
export const IPTV_EMBED_PATH = "/iptv/embed.html"

const LOCAL_IPTV_FETCH_PREFIX = "/api/iptv/live-fetch"

const BLOCKED_PLAY_HOSTS = ["kool.to", "huhu.to"]

function resolvePageOrigin() {
  if (typeof window === "undefined") {
    return "http://localhost"
  }

  return window.location.origin
}

export function isCurrentIptvPlayWorker(scriptUrl: string) {
  return scriptUrl.includes("iptv-play-sw") && scriptUrl.includes(`v=${IPTV_SW_VERSION}`)
}

export function shouldProxyIptvMediaUrl(url: string) {
  if (!url || url.startsWith("blob:") || url.startsWith("data:")) {
    return false
  }

  try {
    const parsed = new URL(url, resolvePageOrigin())
    const pageOrigin = resolvePageOrigin()

    if (parsed.origin === pageOrigin) {
      if (
        parsed.pathname.startsWith(IPTV_FETCH_PATH) ||
        parsed.pathname.startsWith("/iptv-play/") ||
        parsed.pathname.startsWith("/api/iptv/live/") ||
        parsed.pathname.startsWith(LOCAL_IPTV_FETCH_PREFIX) ||
        /^\/play\/[^/]+\/index\.m3u8$/i.test(parsed.pathname)
      ) {
        return false
      }

      return BLOCKED_PLAY_HOSTS.some((host) => parsed.hostname.endsWith(host))
    }

    return true
  } catch {
    return false
  }
}

export function wrapIptvFetchUrl(url: string, useLocalApi = isLocalIptvDevHost()) {
  if (!shouldProxyIptvMediaUrl(url)) {
    return url
  }

  const absolute = new URL(url, resolvePageOrigin()).href
  const encoded = encodeURIComponent(absolute)

  if (useLocalApi) {
    return `${LOCAL_IPTV_FETCH_PREFIX}?u=${encoded}`
  }

  return `${IPTV_FETCH_PATH}?u=${encoded}`
}

export function createIptvHlsConfig(useLocalApi = isLocalIptvDevHost()) {
  return {
    enableWorker: false,
    lowLatencyMode: true,
    maxBufferLength: 20,
    fetchSetup: (context: { url: string }, initParams: RequestInit | undefined) => {
      const proxied = wrapIptvFetchUrl(context.url, useLocalApi)

      if (proxied !== context.url) {
        context.url = proxied
      }

      return new Request(context.url, initParams ?? {})
    },
  }
}

export function buildBrowserPlayUrl(channelId: string) {
  return `/iptv-play/${encodeURIComponent(channelId)}/index.m3u8`
}

export function buildLiveEmbedUrl(channelId: string) {
  const params = new URLSearchParams({ id: channelId, v: IPTV_SW_VERSION })
  return `${IPTV_EMBED_PATH}?${params}`
}

/** Same-origin play path as localhost:8081 — SW proxies to huhu behind this URL. */
export function buildLocalLiveApiUrl(channelId: string, playPageUrl?: string) {
  const base = `/play/${encodeURIComponent(channelId)}/index.m3u8`
  if (!playPageUrl) return base
  return `${base}?playPageUrl=${encodeURIComponent(playPageUrl)}`
}

export function buildHuhuPlayUrl(channelId: string) {
  return `${IPTV_HUHU_ORIGIN}/play/${encodeURIComponent(channelId)}/index.m3u8`
}

/** For internal/debug use only — player should load buildLocalLiveApiUrl(). */
export function buildLivePlayProxyUrl(channelId: string) {
  return `${LOCAL_IPTV_FETCH_PREFIX}?u=${encodeURIComponent(buildHuhuPlayUrl(channelId))}`
}

export function isLocalIptvDevHost() {
  if (typeof window === "undefined") return false
  return window.location.hostname === "localhost" || window.location.hostname === "127.0.0.1"
}

function isUsableManifest(text: string) {
  const trimmed = text.trim()
  if (!trimmed.startsWith("#EXTM3U")) return false
  return trimmed.includes("#EXTINF") || trimmed.includes("#EXT-X-STREAM-INF")
}

export function getStoredIptvSessionCookies() {
  if (typeof window === "undefined") return ""
  return localStorage.getItem(IPTV_SESSION_COOKIE_KEY)?.trim() ?? ""
}

export function setStoredIptvSessionCookies(cookies: string) {
  if (typeof window === "undefined") return
  const trimmed = cookies.trim()
  if (trimmed) {
    localStorage.setItem(IPTV_SESSION_COOKIE_KEY, trimmed)
  } else {
    localStorage.removeItem(IPTV_SESSION_COOKIE_KEY)
  }
  syncIptvCookiesToServiceWorker()
}

export function syncIptvCookiesToServiceWorker() {
  if (typeof window === "undefined" || !("serviceWorker" in navigator)) return

  const cookies = getStoredIptvSessionCookies()
  const worker = navigator.serviceWorker.controller

  if (worker && isCurrentIptvPlayWorker(worker.scriptURL)) {
    worker.postMessage({ type: "set-cookies", cookies })
    return
  }

  void navigator.serviceWorker.getRegistrations().then((registrations) => {
    const active = registrations.find((registration) =>
      isCurrentIptvPlayWorker(registration.active?.scriptURL ?? "")
    )?.active

    if (active) {
      active.postMessage({ type: "set-cookies", cookies })
    }
  })
}

async function unregisterStaleIptvWorkers() {
  const registrations = await navigator.serviceWorker.getRegistrations()

  for (const registration of registrations) {
    const scriptUrls = [
      registration.active?.scriptURL,
      registration.installing?.scriptURL,
      registration.waiting?.scriptURL,
    ].filter(Boolean) as string[]

    const isLegacy = scriptUrls.some(
      (scriptUrl) => scriptUrl.includes("iptv") && !scriptUrl.includes("iptv-play-sw")
    )
    const isStalePlayWorker = scriptUrls.some(
      (scriptUrl) => scriptUrl.includes("iptv-play-sw") && !isCurrentIptvPlayWorker(scriptUrl)
    )

    if (isLegacy || isStalePlayWorker) {
      await registration.unregister()
    }
  }
}

async function waitForServiceWorkerControl(timeoutMs = 10000) {
  if (isCurrentIptvPlayWorker(navigator.serviceWorker.controller?.scriptURL ?? "")) {
    return true
  }

  await new Promise<void>((resolve) => {
    const timeout = window.setTimeout(resolve, timeoutMs)
    navigator.serviceWorker.addEventListener(
      "controllerchange",
      () => {
        window.clearTimeout(timeout)
        resolve()
      },
      { once: true }
    )
  })

  return isCurrentIptvPlayWorker(navigator.serviceWorker.controller?.scriptURL ?? "")
}

export async function registerIptvServiceWorker() {
  if (typeof window === "undefined" || !("serviceWorker" in navigator)) {
    return null
  }

  try {
    await unregisterStaleIptvWorkers()

    const existing = await navigator.serviceWorker.getRegistration("/")
    const activeUrl = existing?.active?.scriptURL ?? ""

    if (isCurrentIptvPlayWorker(activeUrl)) {
      await navigator.serviceWorker.ready
      syncIptvCookiesToServiceWorker()
      return existing
    }

    if (existing) {
      await existing.unregister()
    }

    const registration = await navigator.serviceWorker.register(IPTV_SW_PATH, {
      scope: "/",
      updateViaCache: "none",
    })

    if (registration.installing) {
      await new Promise<void>((resolve) => {
        const worker = registration.installing
        if (!worker) {
          resolve()
          return
        }
        worker.addEventListener("statechange", () => {
          if (worker.state === "activated" || worker.state === "redundant") resolve()
        })
      })
    }

    await navigator.serviceWorker.ready
    await waitForServiceWorkerControl()
    syncIptvCookiesToServiceWorker()

    return registration
  } catch {
    return null
  }
}

export async function prepareIptvBrowserPlay() {
  const registration = await registerIptvServiceWorker()
  if (!registration) return false

  syncIptvCookiesToServiceWorker()

  if (await waitForServiceWorkerControl(4000)) {
    return true
  }

  if (typeof window !== "undefined" && !sessionStorage.getItem(IPTV_SW_RELOAD_KEY)) {
    sessionStorage.setItem(IPTV_SW_RELOAD_KEY, "1")
    window.location.reload()
    return false
  }

  sessionStorage.removeItem(IPTV_SW_RELOAD_KEY)
  return await waitForServiceWorkerControl(8000)
}

export async function fetchLiveManifestText(channelId: string): Promise<string | null> {
  const ready = await prepareIptvBrowserPlay()
  if (!ready) return null

  const manifestUrl = buildLocalLiveApiUrl(channelId)

  try {
    const response = await fetch(manifestUrl, { cache: "no-store", redirect: "follow" })

    if (!response.ok) {
      return null
    }

    const text = await response.text()
    if (!isUsableManifest(text)) {
      return null
    }

    return text
  } catch {
    return null
  }
}

export async function ensureIptvServiceWorkerReady() {
  await prepareIptvBrowserPlay()
}

export function isIptvServiceWorkerControlling() {
  if (typeof window === "undefined" || !("serviceWorker" in navigator)) {
    return false
  }

  return isCurrentIptvPlayWorker(navigator.serviceWorker.controller?.scriptURL ?? "")
}
