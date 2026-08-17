import "server-only"

import { execSync } from "child_process"
import * as fs from "fs"
import * as path from "path"

export type AdminStorageItem = {
    id: string
    label: string
    path: string
    bytes: number
    count: number
    safe: boolean
    note: string
}

function dirBytes(dir: string): { bytes: number; count: number } {
    if (!fs.existsSync(dir)) return { bytes: 0, count: 0 }
    let bytes = 0
    let count = 0
    const walk = (d: string) => {
        let entries: fs.Dirent[]
        try {
            entries = fs.readdirSync(d, { withFileTypes: true })
        } catch {
            return
        }
        for (const entry of entries) {
            const full = path.join(d, entry.name)
            try {
                if (entry.isDirectory()) walk(full)
                else if (entry.isFile()) {
                    count += 1
                    bytes += fs.statSync(full).size
                }
            } catch {
                // skip unreadable
            }
        }
    }
    walk(dir)
    return { bytes, count }
}

function globBytes(pattern: string): { bytes: number; count: number } {
    try {
        const out = execSync(`bash -lc 'shopt -s nullglob; files=(${pattern}); echo \${#files[@]}; du -cb \${files[@]} 2>/dev/null | tail -1'`, {
            encoding: "utf8",
            maxBuffer: 8 * 1024 * 1024,
        })
        const lines = out.trim().split(/\n/)
        const count = Number(lines[0] || 0)
        const bytes = Number((lines[1] || "0").split(/\s+/)[0] || 0)
        return { bytes: Number.isFinite(bytes) ? bytes : 0, count: Number.isFinite(count) ? count : 0 }
    } catch {
        return { bytes: 0, count: 0 }
    }
}

export function getDiskStats() {
    try {
        const out = execSync("df -B1 / | tail -1", { encoding: "utf8" })
        const parts = out.trim().split(/\s+/)
        const total = Number(parts[1] || 0)
        const used = Number(parts[2] || 0)
        const available = Number(parts[3] || 0)
        const usedPercent = total > 0 ? Math.round((used / total) * 1000) / 10 : 0
        return { totalBytes: total, usedBytes: used, availableBytes: available, usedPercent, mount: "/" }
    } catch {
        return { totalBytes: 0, usedBytes: 0, availableBytes: 0, usedPercent: 0, mount: "/" }
    }
}

export function getAdminStorageInventory(): {
    disk: ReturnType<typeof getDiskStats>
    items: AdminStorageItem[]
    skipped: Array<{ label: string; reason: string }>
} {
    const nginxGz = globBytes("/var/log/nginx/{vuflix.access,chillflix.cf,access,error}.log.*.gz")
    const pm2 = dirBytes("/root/.pm2/logs")
    const npm = dirBytes("/root/.npm")
    const rootCache = dirBytes("/root/.cache")

    return {
        disk: getDiskStats(),
        items: [
            {
                id: "nginx_gz",
                label: "Old nginx logs (.gz)",
                path: "/var/log/nginx/*.log.*.gz",
                bytes: nginxGz.bytes,
                count: nginxGz.count,
                safe: true,
                note: "Compressed rotations. Always OK to delete.",
            },
            {
                id: "pm2_logs",
                label: "PM2 process logs",
                path: "/root/.pm2/logs",
                bytes: pm2.bytes,
                count: pm2.count,
                safe: true,
                note: "Truncates stdout/stderr logs. Apps keep running.",
            },
            {
                id: "npm_cache",
                label: "npm cache",
                path: "/root/.npm",
                bytes: npm.bytes,
                count: npm.count,
                safe: true,
                note: "Safe. Next install may re-download packages.",
            },
            {
                id: "root_cache",
                label: "Root user cache",
                path: "/root/.cache",
                bytes: rootCache.bytes,
                count: rootCache.count,
                safe: true,
                note: "Generic caches. Does not touch live site builds.",
            },
        ],
        skipped: [
            {
                label: "Live Chillflix .next build",
                reason: "Left alone on purpose — clearing cache slows the site; wiping needs a full rebuild.",
            },
            {
                label: "Vuflix stream temp (cfhls)",
                reason: "Created by Vuflix only — clean it from Vuflix Admin → Storage.",
            },
            {
                label: "Vuflix API / TMDB JSON cache",
                reason: "Vuflix-only. Button is there on Vuflix; leave it if you have space.",
            },
        ],
    }
}

export function cleanAdminStorageTarget(id: string): { ok: true; message: string; freedBytes: number } | { error: string; status: number } {
    if (id === "nginx_gz") {
        let freed = 0
        let n = 0
        for (const f of fs.readdirSync("/var/log/nginx")) {
            if (!/\.(log)\.\d+\.gz$/.test(f)) continue
            if (!/^(vuflix\.access|chillflix\.cf|access|error)\.log\.\d+\.gz$/.test(f)) continue
            const full = path.join("/var/log/nginx", f)
            try {
                const sz = fs.statSync(full).size
                fs.unlinkSync(full)
                freed += sz
                n += 1
            } catch {
                // skip
            }
        }
        return { ok: true, message: `Deleted ${n} old nginx .gz log(s).`, freedBytes: freed }
    }

    if (id === "pm2_logs") {
        const dir = "/root/.pm2/logs"
        if (!fs.existsSync(dir)) return { ok: true, message: "PM2 logs missing.", freedBytes: 0 }
        let freed = 0
        let n = 0
        for (const name of fs.readdirSync(dir)) {
            const full = path.join(dir, name)
            try {
                if (!fs.statSync(full).isFile()) continue
                const sz = fs.statSync(full).size
                fs.writeFileSync(full, "")
                freed += sz
                n += 1
            } catch {
                // skip
            }
        }
        return { ok: true, message: `Truncated ${n} PM2 log file(s).`, freedBytes: freed }
    }

    if (id === "npm_cache") {
        const before = dirBytes("/root/.npm").bytes
        try {
            execSync("npm cache clean --force", { stdio: "ignore" })
        } catch {
            // continue with rm
        }
        for (const part of ["_cacache", "_logs", "_npx"]) {
            try {
                fs.rmSync(path.join("/root/.npm", part), { recursive: true, force: true })
            } catch {
                // skip
            }
        }
        const after = dirBytes("/root/.npm").bytes
        return { ok: true, message: "Cleared npm cache.", freedBytes: Math.max(0, before - after) }
    }

    if (id === "root_cache") {
        const before = dirBytes("/root/.cache").bytes
        if (fs.existsSync("/root/.cache")) {
            for (const name of fs.readdirSync("/root/.cache")) {
                try {
                    fs.rmSync(path.join("/root/.cache", name), { recursive: true, force: true })
                } catch {
                    // skip
                }
            }
        }
        const after = dirBytes("/root/.cache").bytes
        return { ok: true, message: "Cleared root user cache.", freedBytes: Math.max(0, before - after) }
    }

    return { error: "Unknown storage target.", status: 404 }
}

export function formatStorageBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
}
