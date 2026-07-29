import { spawn, type ChildProcessWithoutNullStreams, type SpawnOptions } from "node:child_process"
import crypto from "node:crypto"
import fs from "node:fs"
import path from "node:path"

const SESSION_TTL_MS = 2 * 60 * 60 * 1000

function getSessionTtlMs() {
    if (!isBoundedCachesEnabled()) return SESSION_TTL_MS
    return getBoundedCacheLimits().fourKSessionTtlMinutes * 60_000
}
const TRANSCODE_STARTUP_TIMEOUT_MS = 180_000
const REMUX_STARTUP_TIMEOUT_MS = 90_000
const MAX_CONCURRENT_SESSIONS = 2
const FFMPEG_BIN = process.env.FFMPEG_PATH?.trim() || "ffmpeg"
const FFPROBE_BIN = process.env.FFPROBE_PATH?.trim() || "ffprobe"
const SOURCE_DURATION_PROBE_TIMEOUT_MS = 12_000

import type { HlsSessionMode } from "@/lib/4khdhub/hls-flags"
import { getBoundedCacheLimits, isBoundedCachesEnabled } from "@/lib/bounded-cache"
import {
  isFfmpegAvailable,
  isFfmpegRemuxAvailable,
  isFfmpegTranscodeAvailable,
} from "@/lib/4khdhub/hls-flags"

export type { HlsSessionMode } from "@/lib/4khdhub/hls-flags"
export { isFfmpegAvailable, isFfmpegRemuxAvailable, isFfmpegTranscodeAvailable }

export type HlsTranscodeSession = {
  id: string
  key: string
  dir: string
  sourceUrl: string
  mode: HlsSessionMode
  process: ChildProcessWithoutNullStreams | null
  createdAt: number
  startup: Promise<void>
  sourceDurationSec?: number
  sourceDurationProbe?: Promise<number | undefined>
  error?: string
}

type SessionStore = {
  sessions: Map<string, HlsTranscodeSession>
  lastCleanupAt: number
}

const globalStore = globalThis as typeof globalThis & {
  __chillflixFourKHls?: SessionStore
}

function getStore(): SessionStore {
  if (!globalStore.__chillflixFourKHls) {
    globalStore.__chillflixFourKHls = {
      sessions: new Map(),
      lastCleanupAt: 0,
    }
  }
  return globalStore.__chillflixFourKHls
}

function getSessionDir() {
  const configured = process.env.FOUR_K_HLS_DIR?.trim()
  if (configured) {
    fs.mkdirSync(configured, { recursive: true })
    return configured
  }

  const base =
    process.env.NODE_ENV === "production"
      ? "/tmp/chillflix-4k-hls"
      : path.join(process.cwd(), ".cache", "4k-hls")
  fs.mkdirSync(base, { recursive: true })
  return base
}

function hashKey(key: string) {
  return crypto.createHash("sha256").update(key).digest("hex").slice(0, 20)
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function playlistHasSegments(content: string) {
  return content
    .split("\n")
    .some((line) => line.trim().endsWith(".ts") && !line.startsWith("#"))
}

function runCommand(
  command: string,
  args: string[],
  options: SpawnOptions = {}
): Promise<{ stdout: string; stderr: string; code: number | null }> {
  return new Promise((resolve, reject) => {
    const proc = spawn(command, args, {
      stdio: ["ignore", "pipe", "pipe"],
      ...options,
    })

    let stdout = ""
    let stderr = ""

    proc.stdout.on("data", (chunk: Buffer) => {
      stdout = `${stdout}${chunk.toString("utf8")}`.slice(-4000)
    })
    proc.stderr.on("data", (chunk: Buffer) => {
      stderr = `${stderr}${chunk.toString("utf8")}`.slice(-4000)
    })

    proc.on("error", reject)
    proc.on("close", (code) => resolve({ stdout, stderr, code }))
  })
}

async function probeSourceDurationSec(sourceUrl: string): Promise<number | undefined> {
  const headers = buildFfmpegHeaders(sourceUrl)
  const args = [
    "-hide_banner",
    "-loglevel",
    "error",
    "-probesize",
    "32M",
    "-analyzeduration",
    "32M",
    "-headers",
    headers,
    "-show_entries",
    "format=duration",
    "-of",
    "default=noprint_wrappers=1:nokey=1",
    sourceUrl,
  ]

  try {
    const result = await Promise.race([
      runCommand(FFPROBE_BIN, args),
      sleep(SOURCE_DURATION_PROBE_TIMEOUT_MS).then(() => {
        throw new Error("ffprobe duration probe timed out")
      }),
    ])

    if (result.code !== 0) return undefined

    const seconds = Number(result.stdout.trim())
    if (!Number.isFinite(seconds) || seconds <= 0) return undefined
    return seconds
  } catch {
    return undefined
  }
}

function startSourceDurationProbe(session: HlsTranscodeSession) {
  if (session.sourceDurationProbe) return session.sourceDurationProbe

  session.sourceDurationProbe = probeSourceDurationSec(session.sourceUrl).then((seconds) => {
    if (seconds) {
      session.sourceDurationSec = seconds
    }
    return seconds
  })

  return session.sourceDurationProbe
}

export async function resolveSessionSourceDuration(
  session: HlsTranscodeSession,
  waitMs = 2_500
): Promise<number | undefined> {
  if (session.sourceDurationSec) return session.sourceDurationSec

  const probe = startSourceDurationProbe(session)
  if (waitMs <= 0) return undefined

  try {
    const seconds = await Promise.race([
      probe,
      sleep(waitMs).then(() => undefined),
    ])
    return seconds ?? session.sourceDurationSec
  } catch {
    return session.sourceDurationSec
  }
}

function ensureEventPlaylistHeader(manifest: string) {
  if (manifest.includes("#EXT-X-ENDLIST")) return manifest
  if (manifest.includes("#EXT-X-PLAYLIST-TYPE:")) return manifest

  const lines = manifest.split("\n")
  const extm3uIndex = lines.findIndex((line) => line.trim() === "#EXTM3U")
  if (extm3uIndex < 0) return manifest

  lines.splice(extm3uIndex + 1, 0, "#EXT-X-PLAYLIST-TYPE:EVENT")
  return lines.join("\n")
}

function cleanupExpiredSessions() {
  const store = getStore()
  const now = Date.now()
  if (now - store.lastCleanupAt < 60_000) return
  store.lastCleanupAt = now

  for (const [id, session] of store.sessions.entries()) {
    if (now - session.createdAt > getSessionTtlMs()) {
      try {
        session.process?.kill("SIGTERM")
      } catch {
        // ignore
      }
      fs.rmSync(session.dir, { recursive: true, force: true })
      store.sessions.delete(id)
    }
  }
}

function evictOtherSessions(store: SessionStore) {
  for (const [id, session] of store.sessions.entries()) {
    try {
      session.process?.kill("SIGTERM")
    } catch {
      // ignore
    }
    fs.rmSync(session.dir, { recursive: true, force: true })
    store.sessions.delete(id)
  }
}

function buildFfmpegHeaders(sourceUrl: string) {
  const referer = sourceUrl.includes("googleusercontent.com")
    ? "https://gamerxyt.com/"
    : sourceUrl.includes("hub.latent.click")
      ? "https://hubcloud.ist/"
      : "https://hubcloud.ist/"

  return [
    "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    `Referer: ${referer}`,
    "Accept: */*",
  ].join("\r\n")
}

async function waitForTranscodeReady(
  session: HlsTranscodeSession,
  timeoutMs = TRANSCODE_STARTUP_TIMEOUT_MS
) {
  const playlistPath = path.join(session.dir, "index.m3u8")
  const started = Date.now()

  while (Date.now() - started < timeoutMs) {
    if (session.error) {
      throw new Error(session.error)
    }

    if (fs.existsSync(playlistPath)) {
      const content = fs.readFileSync(playlistPath, "utf8")
      if (playlistHasSegments(content)) {
        return
      }
    }

    await sleep(1000)
  }

  throw new Error("4K transcode startup timed out. Try 1080p or retry in a moment.")
}

function getActiveTranscodeSession(exceptId?: string) {
  const store = getStore()
  for (const session of store.sessions.values()) {
    if (exceptId && session.id === exceptId) continue
    if (session.mode !== "transcode" || session.error) continue
    return session
  }
  return null
}

export function isFourKTranscodeSlotBusy(requestedId?: string) {
  cleanupExpiredSessions()
  const busy = getActiveTranscodeSession(requestedId)
  return Boolean(busy)
}

function buildFfmpegArgs(
  mode: HlsSessionMode,
  sourceUrl: string,
  playlistPath: string,
  segmentPattern: string
) {
  const headers = buildFfmpegHeaders(sourceUrl)
  const inputArgs = [
    "-hide_banner",
    "-loglevel",
    "error",
    "-probesize",
    mode === "remux" ? "32M" : "64M",
    "-analyzeduration",
    mode === "remux" ? "32M" : "64M",
    "-headers",
    headers,
    "-reconnect",
    "1",
    "-reconnect_streamed",
    "1",
    "-reconnect_delay_max",
    "5",
    "-i",
    sourceUrl,
    "-map",
    "0:v:0",
    "-map",
    "0:a:0?",
    "-sn",
    "-avoid_negative_ts",
    "make_zero",
    "-max_interleave_delta",
    "0",
  ]

  if (mode === "remux") {
    return [
      ...inputArgs,
      "-c:v",
      "copy",
      "-c:a",
      "aac",
      "-profile:a",
      "aac_low",
      "-b:a",
      "192k",
      "-ar",
      "48000",
      "-ac",
      "2",
      "-f",
      "hls",
      "-hls_time",
      "6",
      "-hls_list_size",
      "0",
      "-hls_flags",
      "independent_segments+append_list",
      "-hls_segment_filename",
      segmentPattern,
      playlistPath,
    ]
  }

  return [
    ...inputArgs,
    "-c:v",
    "libx264",
    "-preset",
    "veryfast",
    "-crf",
    "22",
    "-pix_fmt",
    "yuv420p",
    "-vf",
    "scale='min(3840,iw)':-2",
    "-maxrate",
    "24M",
    "-bufsize",
    "48M",
    "-c:a",
    "aac",
    "-b:a",
    "192k",
    "-ac",
    "2",
    "-f",
    "hls",
    "-hls_time",
    "4",
    "-hls_list_size",
    "0",
    "-hls_flags",
    "independent_segments+append_list",
    "-hls_segment_filename",
    segmentPattern,
    playlistPath,
  ]
}

export function isHlsSessionWarm(session: HlsTranscodeSession) {
  const playlistPath = path.join(session.dir, "index.m3u8")
  if (!fs.existsSync(playlistPath)) return false
  try {
    const content = fs.readFileSync(playlistPath, "utf8")
    return playlistHasSegments(content)
  } catch {
    return false
  }
}

export function getOrStartHlsSession(
  key: string,
  sourceUrl: string,
  mode: HlsSessionMode = "transcode"
): HlsTranscodeSession {
  cleanupExpiredSessions()

  const store = getStore()
  const id = hashKey(`${mode}::${key}`)
  const existing = store.sessions.get(id)
  if (existing && Date.now() - existing.createdAt < getSessionTtlMs() && !existing.error) {
    return existing
  }

  if (mode === "transcode" && getActiveTranscodeSession(id)) {
    throw new Error(
      "Another 4K transcode is already running. Try 1080p or wait about a minute."
    )
  }

  if (store.sessions.size >= MAX_CONCURRENT_SESSIONS) {
    evictOtherSessions(store)
  }

  const dir = path.join(getSessionDir(), id)
  fs.rmSync(dir, { recursive: true, force: true })
  fs.mkdirSync(dir, { recursive: true })

  const playlistPath = path.join(dir, "index.m3u8")
  const segmentPattern = path.join(dir, "seg_%03d.ts")

  let processRef: ChildProcessWithoutNullStreams | null = null

  const startup = new Promise<void>((resolve, reject) => {
    const args = buildFfmpegArgs(mode, sourceUrl, playlistPath, segmentPattern)

    const proc = spawn(FFMPEG_BIN, args, {
      stdio: ["ignore", "ignore", "pipe"],
    })
    processRef = proc

    let stderr = ""
    proc.stderr.on("data", (chunk: Buffer) => {
      stderr = `${stderr}${chunk.toString("utf8")}`.slice(-4000)
    })

    proc.on("error", (error) => {
      const session = store.sessions.get(id)
      if (session) {
        session.error = error.message
      }
      reject(error)
    })

    proc.on("close", (code) => {
      if (code !== 0 && code !== null) {
        const session = store.sessions.get(id)
        const message =
          stderr.trim() || `ffmpeg exited with code ${code ?? "unknown"}`
        if (session) {
          session.error = message
        }
      }
    })

    void waitForTranscodeReady(
      {
        id,
        key,
        dir,
        sourceUrl,
        mode,
        process: proc,
        createdAt: Date.now(),
        startup: Promise.resolve(),
      },
      mode === "remux" ? REMUX_STARTUP_TIMEOUT_MS : TRANSCODE_STARTUP_TIMEOUT_MS
    )
      .then(resolve)
      .catch(reject)
  })

  const session: HlsTranscodeSession = {
    id,
    key,
    dir,
    sourceUrl,
    mode,
    process: null,
    createdAt: Date.now(),
    startup,
  }

  store.sessions.set(id, session)

  startSourceDurationProbe(session)

  startup
    .then(() => {
      const current = store.sessions.get(id)
      if (current && processRef) {
        current.process = processRef
      }
    })
    .catch(() => undefined)

  return session
}

export async function readRewrittenManifest(
  session: HlsTranscodeSession,
  manifestBaseUrl: string
) {
  await session.startup

  const playlistPath = path.join(session.dir, "index.m3u8")
  const raw = fs.readFileSync(playlistPath, "utf8")
  const base = manifestBaseUrl.replace(/\/$/, "")

  const rewritten = raw
    .split("\n")
    .map((line) => {
      const trimmed = line.trim()
      if (!trimmed || trimmed.startsWith("#")) return line
      if (!trimmed.endsWith(".ts")) return line
      return `${base}/segment/${session.id}/${trimmed}`
    })
    .join("\n")

  let manifest = ensureEventPlaylistHeader(rewritten)

  if (session.sourceDurationSec) {
    const durationTag = `#EXT-X-CHILLFLIX-SOURCE-DURATION:${session.sourceDurationSec.toFixed(3)}`
    if (!manifest.includes("#EXT-X-CHILLFLIX-SOURCE-DURATION:")) {
      const lines = manifest.split("\n")
      const extm3uIndex = lines.findIndex((line) => line.trim() === "#EXTM3U")
      if (extm3uIndex >= 0) {
        lines.splice(extm3uIndex + 1, 0, durationTag)
        manifest = lines.join("\n")
      }
    }
  }

  return manifest
}

export function readTranscodeSegment(sessionId: string, filename: string) {
  cleanupExpiredSessions()

  const store = getStore()
  const session = [...store.sessions.values()].find((entry) => entry.id === sessionId)
  if (!session) return null

  const safeName = path.basename(filename)
  if (!/^seg_\d+\.ts$/.test(safeName)) return null

  const filePath = path.join(session.dir, safeName)
  if (!fs.existsSync(filePath)) return null

  return {
    filePath,
    buffer: fs.readFileSync(filePath),
  }
}

/** Wipe on-disk HLS segments and in-memory sessions (safe on every boot). */
export function purgeFourKHlsStorage() {
  const store = getStore()

  for (const session of store.sessions.values()) {
    try {
      session.process?.kill("SIGTERM")
    } catch {
      // ignore
    }
  }
  store.sessions.clear()

  try {
    const baseDir = getSessionDir()
    if (!fs.existsSync(baseDir)) return

    for (const entry of fs.readdirSync(baseDir)) {
      fs.rmSync(path.join(baseDir, entry), { recursive: true, force: true })
    }
  } catch (error) {
    console.error("[4k-hls] Failed to purge storage:", error)
  }
}

export function buildHlsSessionKey(args: {
  tmdbId: string
  type: string
  season?: string
  episode?: string
  quality: string
  filename: string
}) {
  return [
    args.tmdbId,
    args.type,
    args.season ?? "",
    args.episode ?? "",
    args.quality,
    args.filename,
  ].join("::")
}

export function getFourKHlsSessionCount() {
    return getStore().sessions.size
}

export function clearFourKHlsSessions() {
    purgeFourKHlsStorage()
}
