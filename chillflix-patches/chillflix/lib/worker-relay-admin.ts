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

function clampTtl(value: unknown) {
    const n = Number(value)
    if (!Number.isFinite(n)) return DEFAULT_CONFIG.cacheTtlSeconds
    return Math.min(86_400, Math.max(60, Math.floor(n)))
}

function sanitizeWorker(raw: unknown, index: number): WorkerRelayEntry | null {
    if (!raw || typeof raw !== "object") return null
    const row = raw as Record<string, unknown>
    const url = String(row.url || "")
        .trim()
        .replace(/\/$/, "")
    const secret = String(row.secret || "").trim()
    if (!url) return null
    return {
        id: String(row.id || `worker-${index + 1}`).trim() || `worker-${index + 1}`,
        label: String(row.label || `Worker ${index + 1}`).trim() || `Worker ${index + 1}`,
        url,
        secret,
        enabled: row.enabled !== false,
    }
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
            secret: w.secret ? `${w.secret.slice(0, 4)}…${w.secret.slice(-4)}` : "",
            secretSet: Boolean(w.secret),
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
        return {
            ...w,
            id: w.id || `worker-${index + 1}`,
            secret,
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
