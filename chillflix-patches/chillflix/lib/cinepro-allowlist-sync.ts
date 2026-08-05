import "server-only"

import { execFile } from "node:child_process"
import { readFile, writeFile } from "node:fs/promises"
import { promisify } from "node:util"

import { isNativeChillflixProvider } from "@/lib/native-chillflix-providers"
import type { StreamSourcesConfig } from "@/lib/stream-sources-defaults"
import {
    ON_DEMAND_CINEPRO_PROVIDER_IDS,
    normalizeProviderName,
} from "@/lib/stream-sources-defaults"

const execFileAsync = promisify(execFile)

function isLocalCineproUrl(cineproUrl: string | undefined) {
    if (!cineproUrl?.trim()) return false

    try {
        const host = new URL(cineproUrl).hostname.toLowerCase()
        return host === "localhost" || host === "127.0.0.1"
    } catch {
        return false
    }
}

function normalizeAllowlist(value: string | undefined) {
    if (!value?.trim()) return []

    return [...new Set(value.split(",").map((id) => normalizeProviderName(id)).filter(Boolean))].sort()
}

function buildAllowlistValue(config: StreamSourcesConfig): string | undefined {
    const enabled = config.sources
        .filter((entry) => entry.enabled && entry.builtin)
        .map((entry) => normalizeProviderName(entry.id))
        .filter((id) => Boolean(id) && !isNativeChillflixProvider(id) && !ON_DEMAND_CINEPRO_PROVIDER_IDS.has(id))

    if (enabled.length === 0) {
        return undefined
    }

    return [...new Set(enabled)].join(",")
}

async function readEnvAllowlist(envPath: string) {
    try {
        const content = await readFile(envPath, "utf8")
        for (const line of content.split("\n")) {
            if (line.startsWith("CINEPRO_PROVIDER_ALLOWLIST=")) {
                return line.slice("CINEPRO_PROVIDER_ALLOWLIST=".length).trim()
            }
        }
    } catch {
        // ignore
    }

    return undefined
}

async function updateEnvAllowlist(envPath: string, allowlist: string | undefined) {
    let content = ""
    try {
        content = await readFile(envPath, "utf8")
    } catch {
        content = ""
    }

    const lines = content.split("\n")
    const nextLines: string[] = []
    let replaced = false

    for (const line of lines) {
        if (line.startsWith("CINEPRO_PROVIDER_ALLOWLIST=")) {
            if (allowlist) {
                nextLines.push(`CINEPRO_PROVIDER_ALLOWLIST=${allowlist}`)
            }
            replaced = true
            continue
        }

        nextLines.push(line)
    }

    if (!replaced && allowlist) {
        if (nextLines.length > 0 && nextLines[nextLines.length - 1] !== "") {
            nextLines.push("")
        }
        nextLines.push(`CINEPRO_PROVIDER_ALLOWLIST=${allowlist}`)
    }

    const nextContent = nextLines.join("\n").replace(/\n+$/, "\n")
    await writeFile(envPath, nextContent, "utf8")
}

async function restartCinepro(appDir: string) {
    await execFileAsync(
        "bash",
        [
            "-c",
            `cd '${appDir}' && set -a && [ -f .env ] && . ./.env && set +a && (pm2 delete cinepro 2>/dev/null || true) && pm2 start dist/server.js --name cinepro --cwd '${appDir}' --update-env && pm2 save`,
        ],
        { timeout: 45_000 }
    )
}

export type CineproAllowlistSyncResult = {
    synced: boolean
    allowlist?: string
    changed?: boolean
    reason?: "remote-cinepro" | "pm2-restart-failed" | "already-synced"
}

/**
 * Keep CinePro scraping aligned with Admin → Stream Sources (enabled builtin providers).
 * Only runs when CINEPRO_URL points at this host (typical VPS layout).
 */
export async function syncCineproProviderAllowlist(
    config: StreamSourcesConfig
): Promise<CineproAllowlistSyncResult> {
    const cineproUrl = process.env.CINEPRO_URL?.trim()
    if (!isLocalCineproUrl(cineproUrl)) {
        return { synced: false, reason: "remote-cinepro" }
    }

    const appDir = process.env.CINEPRO_APP_DIR?.trim() || "/var/www/cinepro"
    const envPath = `${appDir}/.env`
    const allowlist = buildAllowlistValue(config)
    const currentAllowlist = await readEnvAllowlist(envPath)
    const changed =
        normalizeAllowlist(currentAllowlist).join(",") !== normalizeAllowlist(allowlist).join(",")

    if (!changed) {
        return { synced: true, allowlist, changed: false, reason: "already-synced" }
    }

    await updateEnvAllowlist(envPath, allowlist)

    try {
        await restartCinepro(appDir)
    } catch (error) {
        console.warn("[cinepro-allowlist-sync] pm2 restart failed:", error)
        return { synced: false, reason: "pm2-restart-failed", allowlist, changed: true }
    }

    return { synced: true, allowlist, changed: true }
}

/** Sync before source checks so admin-enabled providers always match CinePro scrapers. */
export async function ensureCineproProviderAllowlistSynced(
    config: StreamSourcesConfig
): Promise<CineproAllowlistSyncResult> {
    return syncCineproProviderAllowlist(config)
}
