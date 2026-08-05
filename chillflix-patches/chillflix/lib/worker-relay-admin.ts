import "server-only"

import { execFile } from "node:child_process"
import { mkdir, readFile, writeFile } from "node:fs/promises"
import { dirname } from "node:path"
import { promisify } from "node:util"

const execFileAsync = promisify(execFile)

export type WorkerRelayEntry = {
    id: string
    label: string
    url: string
    secret: string
    enabled: boolean
    /** Cloudflare account ID (for analytics API). */
    cfAccountId?: string
    /** Cloudflare API token with Account Analytics Read. */
    cfApiToken?: string
    /** Worker script name; defaults from workers.dev subdomain. */
    cfScriptName?: string
}

export type WorkerAnalyticsSnapshot = {
    id: string
    label: string
    scriptName: string
    ok: boolean
    error?: string
    todayRequests: number
    todayErrors: number
    last24hRequests: number
    last24hErrors: number
    freeDailyLimit: number
    fetchedAt: string
}

export type WorkerRelayConfig = {
    enabled: boolean
    preferWorker: boolean
    cacheTtlSeconds: number
    workers: WorkerRelayEntry[]
}

const DEFAULT_CONFIG: WorkerRelayConfig = {
    enabled: true,
    preferWorker: true,
    cacheTtlSeconds: 7200,
    workers: [],
}

function configPath() {
    const appDir = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    return (
        process.env.WORKER_RELAY_CONFIG_PATH?.trim() ||
        `${appDir}/config/worker-relay.json`
    )
}

function scriptPathCandidates() {
    const explicit = process.env.WORKER_RELAY_SCRIPT_PATH?.trim()
    const newsite =
        process.env.VUFLIX_APP_DIR?.trim() || "/var/www/chillflix-newsite"
    const cinepro = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    return [
        explicit,
        `${newsite}/workers/yoru-relay.js`,
        `${cinepro}/workers/yoru-relay.js`,
        "/var/www/chillflix-newsite/workers/yoru-relay.js",
    ].filter((p): p is string => Boolean(p))
}

/** Latest Worker script to paste into Cloudflare. */
export async function readYoruRelayScript(): Promise<{
    content: string
    path: string
}> {
    let lastError: unknown
    for (const path of scriptPathCandidates()) {
        try {
            const content = await readFile(path, "utf8")
            if (content.trim()) return { content, path }
        } catch (error) {
            lastError = error
        }
    }
    throw new Error(
        lastError instanceof Error
            ? `yoru-relay.js not found: ${lastError.message}`
            : "yoru-relay.js not found"
    )
}

function clampTtl(value: unknown) {
    const n = Number(value)
    if (!Number.isFinite(n)) return DEFAULT_CONFIG.cacheTtlSeconds
    return Math.min(86_400, Math.max(60, Math.floor(n)))
}

function normalizeWorkerUrl(raw: string): string {
    let url = String(raw || "")
        .trim()
        .replace(/\/$/, "")
    if (!url) return ""
    if (!/^https?:\/\//i.test(url)) {
        url = `https://${url}`
    }
    return url
}

export function deriveScriptNameFromUrl(url: string): string {
    try {
        const host = new URL(normalizeWorkerUrl(url)).hostname
        // divine-frost-3156.vuflix.workers.dev → divine-frost-3156
        const first = host.split(".")[0] || ""
        return first.trim()
    } catch {
        return ""
    }
}

function sanitizeWorker(raw: unknown, index: number): WorkerRelayEntry | null {
    if (!raw || typeof raw !== "object") return null
    const row = raw as Record<string, unknown>
    const url = normalizeWorkerUrl(String(row.url || ""))
    const secret = String(row.secret || "").trim()
    if (!url) return null
    const cfAccountId = String(row.cfAccountId || "").trim()
    const cfApiToken = String(row.cfApiToken || "").trim()
    const cfScriptName =
        String(row.cfScriptName || "").trim() || deriveScriptNameFromUrl(url)
    return {
        id: String(row.id || `worker-${index + 1}`).trim() || `worker-${index + 1}`,
        label: String(row.label || `Worker ${index + 1}`).trim() || `Worker ${index + 1}`,
        url,
        secret,
        enabled: row.enabled !== false,
        cfAccountId: cfAccountId || undefined,
        cfApiToken: cfApiToken || undefined,
        cfScriptName: cfScriptName || undefined,
    }
}

function maskSecret(value: string | undefined) {
    if (!value) return ""
    if (value.length <= 8) return "…****…"
    return `${value.slice(0, 4)}…${value.slice(-4)}`
}

export function sanitizeWorkerRelayConfig(raw: unknown): WorkerRelayConfig {
    const row = raw && typeof raw === "object" ? (raw as Record<string, unknown>) : {}
    const workers = Array.isArray(row.workers)
        ? row.workers
              .map((w, i) => sanitizeWorker(w, i))
              .filter((w): w is WorkerRelayEntry => Boolean(w))
        : []

    return {
        enabled: row.enabled !== false,
        preferWorker: row.preferWorker !== false,
        cacheTtlSeconds: clampTtl(row.cacheTtlSeconds),
        workers,
    }
}

/** Mask secrets for admin GET responses. */
export function toPublicWorkerRelayConfig(config: WorkerRelayConfig) {
    return {
        ...config,
        workers: config.workers.map((w) => ({
            ...w,
            secret: maskSecret(w.secret),
            secretSet: Boolean(w.secret),
            cfApiToken: maskSecret(w.cfApiToken),
            cfApiTokenSet: Boolean(w.cfApiToken),
            cfAccountId: w.cfAccountId || "",
            cfScriptName: w.cfScriptName || deriveScriptNameFromUrl(w.url),
        })),
        path: configPath(),
    }
}

async function readEnvRelay(): Promise<{ url: string; secret: string }> {
    const appDir = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    try {
        const env = await readFile(`${appDir}/.env`, "utf8")
        let url = ""
        let secret = ""
        for (const line of env.split("\n")) {
            if (line.startsWith("YORU_RELAY_URL=")) url = line.slice(15).trim()
            if (line.startsWith("YORU_RELAY_SECRET=")) secret = line.slice(18).trim()
        }
        return { url: url.replace(/\/$/, ""), secret }
    } catch {
        return { url: "", secret: "" }
    }
}

export async function getWorkerRelayConfig(): Promise<WorkerRelayConfig> {
    const path = configPath()
    try {
        const raw = JSON.parse(await readFile(path, "utf8"))
        const config = sanitizeWorkerRelayConfig(raw)
        if (!config.workers.length) {
            const env = await readEnvRelay()
            if (env.url && env.secret) {
                config.workers = [
                    {
                        id: "primary",
                        label: "Primary",
                        url: env.url,
                        secret: env.secret,
                        enabled: true,
                    },
                ]
            }
        } else {
            const env = await readEnvRelay()
            for (const w of config.workers) {
                if (!w.secret && env.secret) w.secret = env.secret
            }
        }
        return config
    } catch {
        const env = await readEnvRelay()
        if (env.url && env.secret) {
            return {
                ...DEFAULT_CONFIG,
                workers: [
                    {
                        id: "primary",
                        label: "Primary",
                        url: env.url,
                        secret: env.secret,
                        enabled: true,
                    },
                ],
            }
        }
        return { ...DEFAULT_CONFIG }
    }
}

/**
 * Merge PATCH body. Blank secret on an existing worker keeps the previous secret.
 */
export async function updateWorkerRelayConfig(body: unknown): Promise<WorkerRelayConfig> {
    const previous = await getWorkerRelayConfig()
    const incoming = sanitizeWorkerRelayConfig(body)
    const prevById = new Map(previous.workers.map((w) => [w.id, w]))

    const workers = incoming.workers.map((w, index) => {
        const prev =
            prevById.get(w.id) ||
            previous.workers.find((p) => p.url === w.url) ||
            null
        const secret =
            w.secret && !w.secret.includes("…")
                ? w.secret
                : prev?.secret || ""
        const cfApiToken =
            w.cfApiToken && !w.cfApiToken.includes("…")
                ? w.cfApiToken
                : prev?.cfApiToken || ""
        return {
            ...w,
            id: w.id || `worker-${index + 1}`,
            secret,
            cfApiToken: cfApiToken || undefined,
            cfAccountId: w.cfAccountId || prev?.cfAccountId || undefined,
            cfScriptName:
                w.cfScriptName ||
                prev?.cfScriptName ||
                deriveScriptNameFromUrl(w.url) ||
                undefined,
        }
    })

    const next = sanitizeWorkerRelayConfig({
        ...incoming,
        workers,
    })

    const path = configPath()
    await mkdir(dirname(path), { recursive: true })
    await writeFile(path, `${JSON.stringify(next, null, 2)}\n`, "utf8")

    // Confirm persistence so a silent permission failure can't drop workers.
    const verify = sanitizeWorkerRelayConfig(
        JSON.parse(await readFile(path, "utf8"))
    )
    if (verify.workers.length !== next.workers.length) {
        throw new Error(
            `Worker relay save did not persist (expected ${next.workers.length} workers, file has ${verify.workers.length})`
        )
    }

    // Keep .env in sync with the first enabled worker (back-compat for other tools).
    const primary = next.workers.find((w) => w.enabled && w.url && w.secret)
    if (primary) {
        await syncEnvRelay(primary.url, primary.secret, next.preferWorker && next.enabled)
    }

    return next
}

async function upsertEnvFile(
    envPath: string,
    updates: Record<string, string>
) {
    let content = ""
    try {
        content = await readFile(envPath, "utf8")
    } catch {
        content = ""
    }

    const upsert = (text: string, key: string, value: string) => {
        const line = `${key}=${value}`
        const re = new RegExp(`^${key}=.*$`, "m")
        if (re.test(text)) return text.replace(re, line)
        return `${text.replace(/\n+$/, "")}\n${line}\n`
    }

    for (const [key, value] of Object.entries(updates)) {
        content = upsert(content, key, value)
    }
    await writeFile(envPath, content.replace(/\n+$/, "\n"), "utf8")
}

async function syncEnvRelay(url: string, secret: string, preferWorker: boolean) {
    const appDir = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    const newsiteDir =
        process.env.VUFLIX_APP_DIR?.trim() || "/var/www/chillflix-newsite"
    const updates = {
        YORU_RELAY_URL: url,
        YORU_RELAY_SECRET: secret,
        VAPLAYER_PREFER_WORKER: preferWorker ? "1" : "0",
    }
    await upsertEnvFile(`${appDir}/.env`, updates)
    // Vuflix Cineplay Yoru still reads .env; keep primary worker in sync.
    await upsertEnvFile(`${newsiteDir}/.env`, {
        YORU_RELAY_URL: url,
        YORU_RELAY_SECRET: secret,
    })
}

export async function testWorkerRelay(worker: {
    url: string
    secret: string
}): Promise<{ ok: boolean; status?: number; error?: string; cache?: string }> {
    const base = worker.url.replace(/\/$/, "")
    const secret = worker.secret.trim()
    if (!base || !secret) {
        return { ok: false, error: "url and secret required" }
    }

    try {
        const health = await fetch(`${base}/health`, { signal: AbortSignal.timeout(8_000) })
        if (!health.ok) {
            return { ok: false, status: health.status, error: `health HTTP ${health.status}` }
        }

        const probeUrl = new URL(`${base}/vaplayer`)
        probeUrl.searchParams.set("type", "movie")
        probeUrl.searchParams.set("tmdb", "550")
        probeUrl.searchParams.set("key", secret)
        probeUrl.searchParams.set("cacheTtl", "120")

        const res = await fetch(probeUrl.toString(), {
            headers: { Accept: "application/json", "x-yoru-key": secret },
            signal: AbortSignal.timeout(20_000),
        })
        const json = (await res.json().catch(() => null)) as {
            ok?: boolean
            error?: string
            sources?: unknown[]
        } | null

        return {
            ok: Boolean(res.ok && json?.ok && Array.isArray(json.sources) && json.sources.length),
            status: res.status,
            error: json?.ok ? undefined : String(json?.error || `HTTP ${res.status}`),
            cache: res.headers.get("x-yoru-cache") || undefined,
        }
    } catch (error) {
        return {
            ok: false,
            error: error instanceof Error ? error.message : "Worker test failed",
        }
    }
}

/** Optional: bounce cinepro so long-lived process picks env (JSON is hot-reloaded). */
export async function bounceCineproForRelay(): Promise<{ restarted: boolean; error?: string }> {
    const appDir = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    try {
        await execFileAsync(
            "bash",
            [
                "-c",
                `cd '${appDir}' && pm2 restart cinepro --update-env`,
            ],
            { timeout: 30_000 }
        )
        return { restarted: true }
    } catch (error) {
        return {
            restarted: false,
            error: error instanceof Error ? error.message : "pm2 restart failed",
        }
    }
}

const FREE_DAILY_LIMIT = 100_000

async function queryWorkerInvocations(opts: {
    accountId: string
    apiToken: string
    scriptName: string
    startIso: string
    endIso: string
}): Promise<{ requests: number; errors: number }> {
    const query = `
      query WorkerStats($accountTag: string, $scriptName: string, $start: Time, $end: Time) {
        viewer {
          accounts(filter: { accountTag: $accountTag }) {
            workersInvocationsAdaptive(
              limit: 10000
              filter: {
                scriptName: $scriptName
                datetime_geq: $start
                datetime_leq: $end
              }
            ) {
              sum { requests errors }
            }
          }
        }
      }
    `
    const res = await fetch("https://api.cloudflare.com/client/v4/graphql", {
        method: "POST",
        headers: {
            Authorization: `Bearer ${opts.apiToken}`,
            "Content-Type": "application/json",
        },
        body: JSON.stringify({
            query,
            variables: {
                accountTag: opts.accountId,
                scriptName: opts.scriptName,
                start: opts.startIso,
                end: opts.endIso,
            },
        }),
        signal: AbortSignal.timeout(20_000),
    })
    const json = (await res.json().catch(() => null)) as {
        errors?: Array<{ message?: string }>
        data?: {
            viewer?: {
                accounts?: Array<{
                    workersInvocationsAdaptive?: Array<{
                        sum?: { requests?: number; errors?: number }
                    }>
                }>
            }
        }
    } | null

    if (!res.ok) {
        throw new Error(`Cloudflare API HTTP ${res.status}`)
    }
    if (json?.errors?.length) {
        throw new Error(json.errors.map((e) => e.message || "GraphQL error").join("; "))
    }

    const rows =
        json?.data?.viewer?.accounts?.[0]?.workersInvocationsAdaptive || []
    let requests = 0
    let errors = 0
    for (const row of rows) {
        requests += Number(row?.sum?.requests || 0)
        errors += Number(row?.sum?.errors || 0)
    }
    return { requests, errors }
}

export async function fetchWorkerAnalytics(
    worker: WorkerRelayEntry
): Promise<WorkerAnalyticsSnapshot> {
    const scriptName =
        worker.cfScriptName?.trim() || deriveScriptNameFromUrl(worker.url)
    const accountId = worker.cfAccountId?.trim() || ""
    const apiToken = worker.cfApiToken?.trim() || ""
    const base = {
        id: worker.id,
        label: worker.label,
        scriptName,
        freeDailyLimit: FREE_DAILY_LIMIT,
        fetchedAt: new Date().toISOString(),
        todayRequests: 0,
        todayErrors: 0,
        last24hRequests: 0,
        last24hErrors: 0,
    }

    if (!accountId || !apiToken || !scriptName) {
        return {
            ...base,
            ok: false,
            error: !accountId
                ? "Set CF Account ID"
                : !apiToken
                  ? "Set CF API token"
                  : "Set CF script name",
        }
    }

    try {
        const now = new Date()
        const endIso = now.toISOString()
        const startToday = new Date(
            Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate())
        ).toISOString()
        const start24h = new Date(now.getTime() - 24 * 60 * 60 * 1000).toISOString()

        const [today, last24h] = await Promise.all([
            queryWorkerInvocations({
                accountId,
                apiToken,
                scriptName,
                startIso: startToday,
                endIso,
            }),
            queryWorkerInvocations({
                accountId,
                apiToken,
                scriptName,
                startIso: start24h,
                endIso,
            }),
        ])

        return {
            ...base,
            ok: true,
            todayRequests: today.requests,
            todayErrors: today.errors,
            last24hRequests: last24h.requests,
            last24hErrors: last24h.errors,
        }
    } catch (error) {
        return {
            ...base,
            ok: false,
            error: error instanceof Error ? error.message : "Analytics fetch failed",
        }
    }
}

type AnalyticsCache = {
    at: number
    stats: WorkerAnalyticsSnapshot[]
}

let analyticsCache: AnalyticsCache | null = null
const ANALYTICS_CACHE_MS = 55_000

export async function fetchAllWorkerAnalytics(options?: {
    force?: boolean
}): Promise<WorkerAnalyticsSnapshot[]> {
    const now = Date.now()
    if (
        !options?.force &&
        analyticsCache &&
        now - analyticsCache.at < ANALYTICS_CACHE_MS
    ) {
        return analyticsCache.stats
    }

    const config = await getWorkerRelayConfig()
    const stats = await Promise.all(config.workers.map((w) => fetchWorkerAnalytics(w)))
    analyticsCache = { at: now, stats }
    return stats
}
