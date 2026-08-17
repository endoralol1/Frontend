import "server-only"

import { execSync } from "child_process"
import * as fs from "fs"
import * as path from "path"

import type { RowDataPacket } from "mysql2/promise"

import { PM2_PROCESS_NAMES } from "@/config/services"
import { execute, getMysqlDatabaseName, query, queryOne } from "@/lib/db"

export type AdminLogKind = "file" | "database"

export type AdminLogCategory = "security" | "process" | "web" | "database"

export type AdminLogSourceDefinition = {
    id: string
    kind: AdminLogKind
    name: string
    description: string
    category: AdminLogCategory
    deletable: boolean
    downloadable: boolean
}

type FileSourceDefinition = AdminLogSourceDefinition & {
    kind: "file"
    resolvePath: () => string
}

type DatabaseSourceDefinition = AdminLogSourceDefinition & {
    kind: "database"
    table: string
    timeColumn: string
    searchColumns: string[]
}

const PM2_HOME = process.env.PM2_HOME?.trim() || "/root/.pm2"
const NGINX_ACCESS_LOG =
    process.env.NGINX_ACCESS_LOG?.trim() || "/var/log/nginx/access.log"
const NGINX_ERROR_LOG = process.env.NGINX_ERROR_LOG?.trim() || "/var/log/nginx/error.log"

function pm2LogPath(processName: string, stream: "out" | "error") {
    return path.join(PM2_HOME, "logs", `${processName}-${stream}.log`)
}

const FILE_SOURCES: FileSourceDefinition[] = [
    {
        id: "security-events",
        kind: "file",
        name: "Security events",
        description: "Recent blocked auth, playback, and proxy events (JSON).",
        category: "security",
        deletable: true,
        downloadable: true,
        resolvePath: () => path.join(process.cwd(), "security-events.json"),
    },
    {
        id: "security-stats",
        kind: "file",
        name: "Security counters",
        description: "Hourly security event counters (JSON).",
        category: "security",
        deletable: true,
        downloadable: true,
        resolvePath: () => path.join(process.cwd(), "security-stats.json"),
    },
    {
        id: "pm2-chillflix-out",
        kind: "file",
        name: "chillflix.lol stdout",
        description: "PM2 stdout log for the main site process.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.chillflix, "out"),
    },
    {
        id: "pm2-chillflix-error",
        kind: "file",
        name: "chillflix.lol stderr",
        description: "PM2 stderr log for the main site process.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.chillflix, "error"),
    },
    {
        id: "pm2-player-out",
        kind: "file",
        name: "chillflix.pw stdout",
        description: "PM2 stdout log for the embed player process.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.chillflixPlayer, "out"),
    },
    {
        id: "pm2-player-error",
        kind: "file",
        name: "chillflix.pw stderr",
        description: "PM2 stderr log for the embed player process.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.chillflixPlayer, "error"),
    },
    {
        id: "pm2-cinepro-out",
        kind: "file",
        name: "CinePro stdout",
        description: "PM2 stdout log for stream source resolver.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.cinepro, "out"),
    },
    {
        id: "pm2-cinepro-error",
        kind: "file",
        name: "CinePro stderr",
        description: "PM2 stderr log for stream source resolver.",
        category: "process",
        deletable: true,
        downloadable: true,
        resolvePath: () => pm2LogPath(PM2_PROCESS_NAMES.cinepro, "error"),
    },
    {
        id: "nginx-access",
        kind: "file",
        name: "nginx access (default)",
        description: "Default web server access log.",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => NGINX_ACCESS_LOG,
    },
    {
        id: "nginx-error",
        kind: "file",
        name: "nginx error",
        description: "Web server error log.",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => NGINX_ERROR_LOG,
    },
    {
        id: "nginx-chillflix",
        kind: "file",
        name: "Chillflix nginx access",
        description: "Main Chillflix access log (chillflix.cf.log).",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => "/var/log/nginx/chillflix.cf.log",
    },
    {
        id: "nginx-chillflix-1",
        kind: "file",
        name: "Chillflix nginx access (rotated)",
        description: "Yesterday's Chillflix access log.",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => "/var/log/nginx/chillflix.cf.log.1",
    },
    {
        id: "nginx-vuflix",
        kind: "file",
        name: "Vuflix nginx access",
        description: "Vuflix access log (vuflix.access.log).",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => "/var/log/nginx/vuflix.access.log",
    },
    {
        id: "nginx-vuflix-1",
        kind: "file",
        name: "Vuflix nginx access (rotated)",
        description: "Yesterday's Vuflix access log.",
        category: "web",
        deletable: true,
        downloadable: true,
        resolvePath: () => "/var/log/nginx/vuflix.access.log.1",
    },
]

const DATABASE_SOURCES: DatabaseSourceDefinition[] = [
    {
        id: "db-chat-messages",
        kind: "database",
        name: "Chat messages",
        description: "Community chat messages (including soft-deleted).",
        category: "database",
        deletable: true,
        downloadable: true,
        table: "chat_messages",
        timeColumn: "created_at",
        searchColumns: ["body"],
    },
    {
        id: "db-support-tickets",
        kind: "database",
        name: "Support tickets",
        description: "User support ticket records.",
        category: "database",
        deletable: true,
        downloadable: true,
        table: "support_tickets",
        timeColumn: "created_at",
        searchColumns: ["subject", "ticket_number", "status", "category"],
    },
    {
        id: "db-support-ticket-messages",
        kind: "database",
        name: "Ticket messages",
        description: "Replies inside support tickets.",
        category: "database",
        deletable: true,
        downloadable: true,
        table: "support_ticket_messages",
        timeColumn: "created_at",
        searchColumns: ["body"],
    },
    {
        id: "db-watch-history",
        kind: "database",
        name: "Watch history",
        description: "Completed watch records per user.",
        category: "database",
        deletable: true,
        downloadable: true,
        table: "watch_history",
        timeColumn: "watched_at",
        searchColumns: ["title", "media_type"],
    },
    {
        id: "db-continue-watching",
        kind: "database",
        name: "Continue watching",
        description: "In-progress playback progress rows.",
        category: "database",
        deletable: true,
        downloadable: true,
        table: "continue_watching",
        timeColumn: "updated_at",
        searchColumns: ["title", "media_type"],
    },
]

const ALL_SOURCES = [...FILE_SOURCES, ...DATABASE_SOURCES]

export type AdminLogInventoryItem = AdminLogSourceDefinition & {
    exists: boolean
    readable: boolean
    bytes: number
    rows: number | null
    displayPath: string
    updatedAt: string | null
}

export type AdminLogInventory = {
    sources: AdminLogInventoryItem[]
    summary: {
        totalBytes: number
        fileBytes: number
        databaseBytes: number
        totalRows: number
    }
}

type TableSizeRow = RowDataPacket & {
    table_name: string
    table_rows: number | null
    total_bytes: number | null
}

function safeStat(filePath: string) {
    try {
        return fs.statSync(filePath)
    } catch {
        return null
    }
}

function canRead(filePath: string) {
    try {
        fs.accessSync(filePath, fs.constants.R_OK)
        return true
    } catch {
        return false
    }
}

function canWrite(filePath: string) {
    try {
        fs.accessSync(filePath, fs.constants.W_OK)
        return true
    } catch {
        return false
    }
}

function displayPath(filePath: string) {
    const cwd = process.cwd()
    if (filePath.startsWith(cwd)) {
        return filePath.slice(cwd.length + 1) || path.basename(filePath)
    }
    return filePath
}

async function getDatabaseTableSizes() {
    const tables = DATABASE_SOURCES.map((source) => source.table)
    if (tables.length === 0) return new Map<string, TableSizeRow>()

    const placeholders = tables.map(() => "?").join(", ")
    const rows = await query<TableSizeRow[]>(
        `SELECT
            table_name,
            table_rows,
            (data_length + index_length) AS total_bytes
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN (${placeholders})`,
        tables
    )

    return new Map(rows.map((row) => [row.table_name, row]))
}

function getFileSource(id: string) {
    return FILE_SOURCES.find((source) => source.id === id) ?? null
}

function getDatabaseSource(id: string) {
    return DATABASE_SOURCES.find((source) => source.id === id) ?? null
}

export function getAdminLogSource(id: string) {
    return ALL_SOURCES.find((source) => source.id === id) ?? null
}

export async function getAdminLogInventory(): Promise<AdminLogInventory> {
    const tableSizes = await getDatabaseTableSizes()

    const fileItems: AdminLogInventoryItem[] = FILE_SOURCES.map((source) => {
        const filePath = source.resolvePath()
        const stat = safeStat(filePath)
        const exists = Boolean(stat)
        const readable = exists && canRead(filePath)

        return {
            id: source.id,
            kind: source.kind,
            name: source.name,
            description: source.description,
            category: source.category,
            deletable: source.deletable && (!exists || canWrite(filePath)),
            downloadable: source.downloadable && readable,
            exists,
            readable,
            bytes: stat?.size ?? 0,
            rows: null,
            displayPath: displayPath(filePath),
            updatedAt: stat ? stat.mtime.toISOString() : null,
        }
    })

    const databaseItems: AdminLogInventoryItem[] = DATABASE_SOURCES.map((source) => {
        const size = tableSizes.get(source.table)
        const bytes = Number(size?.total_bytes ?? 0)
        const rows = size?.table_rows != null ? Number(size.table_rows) : null

        return {
            id: source.id,
            kind: source.kind,
            name: source.name,
            description: source.description,
            category: source.category,
            deletable: source.deletable,
            downloadable: source.downloadable,
            exists: true,
            readable: true,
            bytes,
            rows,
            displayPath: `mysql://${source.table}`,
            updatedAt: null,
        }
    })

    const sources = [...fileItems, ...databaseItems]
    const fileBytes = fileItems.reduce((sum, item) => sum + item.bytes, 0)
    const databaseBytes = databaseItems.reduce((sum, item) => sum + item.bytes, 0)
    const totalRows = databaseItems.reduce((sum, item) => sum + (item.rows ?? 0), 0)

    return {
        sources,
        summary: {
            totalBytes: fileBytes + databaseBytes,
            fileBytes,
            databaseBytes,
            totalRows,
        },
    }
}

function readTailLines(filePath: string, maxLines: number) {
    try {
        const output = execSync(`tail -n ${maxLines} ${shellQuote(filePath)}`, {
            encoding: "utf8",
            maxBuffer: 4 * 1024 * 1024,
        })
        return output.split(/\r?\n/)
    } catch {
        const raw = fs.readFileSync(filePath, "utf8")
        return raw.split(/\r?\n/).slice(-maxLines)
    }
}

function shellQuote(value: string) {
    return `'${value.replace(/'/g, `'\\''`)}'`
}

function filterLines(lines: string[], queryText?: string) {
    const q = queryText?.trim().toLowerCase()
    if (!q) return lines
    return lines.filter((line) => line.toLowerCase().includes(q))
}

function readSecurityEventsContent(filePath: string, options: { q?: string; limit: number }) {
    const raw = fs.readFileSync(filePath, "utf8")
    const parsed = JSON.parse(raw) as { events?: unknown[] }
    let events = Array.isArray(parsed.events) ? parsed.events : []

    if (options.q?.trim()) {
        const q = options.q.trim().toLowerCase()
        events = events.filter((event) => JSON.stringify(event).toLowerCase().includes(q))
    }

    const slice = events.slice(0, options.limit)
    const lines = slice.map((event) => JSON.stringify(event))
    return {
        lines,
        totalLines: events.length,
        truncated: events.length > slice.length,
        content: lines.join("\n"),
    }
}

function readJsonFileContent(filePath: string, options: { q?: string; limit: number }) {
    if (path.basename(filePath) === "security-events.json") {
        return readSecurityEventsContent(filePath, options)
    }

    const raw = fs.readFileSync(filePath, "utf8")
    if (!options.q?.trim()) {
        const lines = raw.split(/\r?\n/)
        const slice = lines.slice(-options.limit)
        return {
            lines: slice,
            totalLines: lines.length,
            truncated: lines.length > slice.length,
            content: slice.join("\n"),
        }
    }

    const q = options.q.trim().toLowerCase()
    const lines = raw.split(/\r?\n/).filter((line) => line.toLowerCase().includes(q))
    const slice = lines.slice(-options.limit)
    return {
        lines: slice,
        totalLines: lines.length,
        truncated: lines.length > slice.length,
        content: slice.join("\n"),
    }
}

export async function readAdminLogContent(
    id: string,
    options: { q?: string; limit?: number } = {}
) {
    const limit = Math.min(Math.max(options.limit ?? 300, 1), 2000)
    const source = getAdminLogSource(id)
    if (!source) {
        return { error: "Unknown log source.", status: 404 as const }
    }

    if (source.kind === "file") {
        const fileSource = getFileSource(id)
        if (!fileSource) {
            return { error: "Unknown log source.", status: 404 as const }
        }

        const filePath = fileSource.resolvePath()
        if (!safeStat(filePath)) {
            return { error: "Log file not found on this server.", status: 404 as const }
        }
        if (!canRead(filePath)) {
            return { error: "Log file is not readable.", status: 403 as const }
        }

        const isJson = filePath.endsWith(".json")
        const payload = isJson
            ? readJsonFileContent(filePath, { q: options.q, limit })
            : (() => {
                  const lines = filterLines(readTailLines(filePath, limit * 3), options.q).slice(
                      -limit
                  )
                  return {
                      lines,
                      totalLines: lines.length,
                      truncated: false,
                      content: lines.join("\n"),
                  }
              })()

        return {
            source,
            displayPath: displayPath(filePath),
            ...payload,
        }
    }

    const dbSource = getDatabaseSource(id)
    if (!dbSource) {
        return { error: "Unknown log source.", status: 404 as const }
    }

    const params: string[] = []
    let where = ""
    if (options.q?.trim()) {
        const q = `%${options.q.trim()}%`
        const clauses = dbSource.searchColumns.map((column) => `${column} LIKE ?`)
        where = `WHERE (${clauses.join(" OR ")})`
        params.push(...dbSource.searchColumns.map(() => q))
    }

    const rows = await query<RowDataPacket[]>(
        `SELECT * FROM ${dbSource.table}
         ${where}
         ORDER BY ${dbSource.timeColumn} DESC
         LIMIT ?`,
        [...params, limit]
    )

    const countRow = await queryOne<RowDataPacket & { count: number }>(
        `SELECT COUNT(*) AS count FROM ${dbSource.table} ${where}`,
        params
    )

    const lines = rows.map((row) => JSON.stringify(row))
    return {
        source,
        displayPath: `mysql://${dbSource.table}`,
        lines,
        totalLines: Number(countRow?.count ?? rows.length),
        truncated: Number(countRow?.count ?? 0) > rows.length,
        content: lines.join("\n"),
    }
}

export async function downloadAdminLogContent(
    id: string,
    options: { q?: string; limit?: number } = {}
): Promise<
    | { error: string; status: number }
    | { source: AdminLogSourceDefinition; filename: string; content: string }
> {
    const result = await readAdminLogContent(id, { ...options, limit: options.limit ?? 5000 })
    if ("error" in result) {
        return { error: result.error, status: result.status }
    }

    return {
        source: result.source,
        filename: `${result.source.id}-${new Date().toISOString().slice(0, 10)}.txt`,
        content: result.content,
    }
}

export async function clearAdminLogSource(
    id: string,
    options: { olderThanDays?: number; confirm?: boolean } = {}
) {
    const source = getAdminLogSource(id)
    if (!source) {
        return { error: "Unknown log source.", status: 404 as const }
    }
    if (!source.deletable) {
        return { error: "This log source cannot be deleted.", status: 403 as const }
    }
    if (!options.confirm) {
        return { error: "Confirmation required.", status: 400 as const }
    }

    if (source.kind === "file") {
        const fileSource = getFileSource(id)
        if (!fileSource) {
            return { error: "Unknown log source.", status: 404 as const }
        }

        const filePath = fileSource.resolvePath()
        if (!safeStat(filePath)) {
            return { ok: true, message: "Log file was already empty or missing." }
        }
        if (!canWrite(filePath)) {
            return { error: "Log file is not writable.", status: 403 as const }
        }

        if (filePath.endsWith("security-events.json")) {
            fs.writeFileSync(filePath, JSON.stringify({ events: [] }, null, 2))
            return { ok: true, message: "Security events cleared." }
        }

        if (filePath.endsWith("security-stats.json")) {
            fs.writeFileSync(filePath, JSON.stringify({ buckets: {} }, null, 2))
            return { ok: true, message: "Security counters cleared." }
        }

        fs.writeFileSync(filePath, "")
        return { ok: true, message: "Log file truncated." }
    }

    const dbSource = getDatabaseSource(id)
    if (!dbSource) {
        return { error: "Unknown log source.", status: 404 as const }
    }

    if (options.olderThanDays && options.olderThanDays > 0) {
        const cutoff = Date.now() - options.olderThanDays * 24 * 60 * 60 * 1000
        let removed = 0

        if (dbSource.id === "db-support-tickets") {
            const messageResult = await execute(
                `DELETE m FROM support_ticket_messages m
                 INNER JOIN support_tickets t ON t.id = m.ticket_id
                 WHERE t.${dbSource.timeColumn} < ?`,
                [cutoff]
            )
            removed += Number(messageResult.affectedRows ?? 0)
        }

        if (dbSource.id === "db-chat-messages") {
            await execute(
                `DELETE r FROM chat_message_reactions r
                 INNER JOIN chat_messages m ON m.id = r.message_id
                 WHERE m.${dbSource.timeColumn} < ?`,
                [cutoff]
            )
            await execute(
                `DELETE b FROM chat_message_bot_targets b
                 INNER JOIN chat_messages m ON m.id = b.message_id
                 WHERE m.${dbSource.timeColumn} < ?`,
                [cutoff]
            )
        }

        const result = await execute(
            `DELETE FROM ${dbSource.table} WHERE ${dbSource.timeColumn} < ?`,
            [cutoff]
        )
        removed += Number(result.affectedRows ?? 0)
        return {
            ok: true,
            message: `Removed ${removed.toLocaleString()} rows older than ${options.olderThanDays} days.`,
        }
    }

    if (dbSource.id === "db-support-tickets") {
        const messageResult = await execute("DELETE FROM support_ticket_messages")
        const ticketResult = await execute("DELETE FROM support_tickets")
        const removed =
            Number(messageResult.affectedRows ?? 0) + Number(ticketResult.affectedRows ?? 0)
        return {
            ok: true,
            message: `Removed ${removed.toLocaleString()} ticket rows (messages + tickets).`,
        }
    }

    if (dbSource.id === "db-chat-messages") {
        await execute("DELETE FROM chat_message_reactions")
        await execute("DELETE FROM chat_message_bot_targets")
    }

    const result = await execute(`DELETE FROM ${dbSource.table}`)
    const removed = Number(result.affectedRows ?? 0)
    return {
        ok: true,
        message: `Removed ${removed.toLocaleString()} rows from ${dbSource.table}.`,
    }
}

export function formatAdminLogBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
}
