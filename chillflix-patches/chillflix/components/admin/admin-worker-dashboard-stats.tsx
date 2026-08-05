"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { RefreshCw } from "lucide-react"

import { ADMIN_CARD_CLASS } from "@/components/admin/admin-metrics"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

type WorkerStats = {
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

function pct(used: number, limit: number) {
    if (!limit) return 0
    return Math.min(100, Math.round((used / limit) * 100))
}

function barColor(usedPct: number) {
    if (usedPct >= 90) return "bg-destructive"
    if (usedPct >= 70) return "bg-amber-500"
    return "bg-emerald-500"
}

const AUTO_REFRESH_MS = 60_000

export function AdminWorkerDashboardStats() {
    const [stats, setStats] = useState<WorkerStats[] | null>(null)
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState<string | null>(null)
    const [lastRefresh, setLastRefresh] = useState<string | null>(null)

    const load = useCallback(async (force = false) => {
        setLoading(true)
        setError(null)
        try {
            const res = await fetch("/api/admin/worker-relay", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "stats", force }),
            })
            const json = await res.json()
            if (!res.ok || !json?.success) {
                setError(json?.error || "Failed to load Worker analytics")
                setStats([])
                return
            }
            setStats((json.stats || []) as WorkerStats[])
            setLastRefresh(new Date().toLocaleTimeString())
        } catch (e) {
            setError(e instanceof Error ? e.message : "Failed to load Worker analytics")
            setStats([])
        } finally {
            setLoading(false)
        }
    }, [])

    useEffect(() => {
        void load(false)
        const timer = window.setInterval(() => {
            void load(false)
        }, AUTO_REFRESH_MS)
        return () => window.clearInterval(timer)
    }, [load])

    const totalToday = (stats || []).reduce(
        (sum, row) => sum + (row.ok ? row.todayRequests : 0),
        0
    )
    const totalLimit = (stats || []).reduce(
        (sum, row) => sum + (row.ok ? row.freeDailyLimit : 0),
        0
    )

    return (
        <section className={cn(ADMIN_CARD_CLASS, "rounded-2xl p-3 sm:p-4")}>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        CF Workers
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Combined today:{" "}
                        <span className="font-medium text-foreground tabular-nums">
                            {totalToday.toLocaleString()}
                            {totalLimit > 0 ? ` / ${totalLimit.toLocaleString()}` : ""}
                        </span>
                        {lastRefresh ? ` · ${lastRefresh}` : ""}
                        {loading ? " · …" : ""}
                    </p>
                </div>
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 rounded-lg px-2.5"
                        disabled={loading}
                        onClick={() => void load(true)}
                    >
                        <RefreshCw className={cn("size-3.5", loading && "animate-spin")} />
                        <span className="ml-1.5">Refresh</span>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 rounded-lg px-2.5"
                        asChild
                    >
                        <Link href="/admin/stream-sources?tab=workers">Manage</Link>
                    </Button>
                </div>
            </div>

            {error ? <p className="text-xs text-destructive">{error}</p> : null}

            {!error && stats && stats.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No Workers yet.{" "}
                    <Link className="underline" href="/admin/stream-sources?tab=workers">
                        Add in Worker relay
                    </Link>
                </p>
            ) : null}

            {stats && stats.length > 0 ? (
                <div className="divide-y divide-border/40">
                    {stats.map((row) => {
                        const usedPct = row.ok
                            ? pct(row.todayRequests, row.freeDailyLimit)
                            : 0
                        return (
                            <div
                                key={row.id}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 first:pt-0 last:pb-0"
                            >
                                <div className="min-w-[7rem] flex-1">
                                    <p className="truncate text-sm font-medium leading-tight">
                                        {row.label || row.id}
                                    </p>
                                    <p className="truncate text-[11px] text-muted-foreground">
                                        {row.scriptName || "—"}
                                    </p>
                                </div>
                                {row.ok ? (
                                    <>
                                        <div className="min-w-[9rem] flex-[1.4]">
                                            <div className="mb-1 flex items-baseline justify-between gap-2 text-[11px] text-muted-foreground">
                                                <span className="tabular-nums text-foreground">
                                                    {row.todayRequests.toLocaleString()} /{" "}
                                                    {row.freeDailyLimit.toLocaleString()}
                                                </span>
                                                <span className="tabular-nums">{usedPct}%</span>
                                            </div>
                                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        "h-full transition-all",
                                                        barColor(usedPct)
                                                    )}
                                                    style={{ width: `${usedPct}%` }}
                                                />
                                            </div>
                                        </div>
                                        <div className="text-[11px] text-muted-foreground tabular-nums whitespace-nowrap">
                                            24h {row.last24hRequests.toLocaleString()}
                                            {row.todayErrors
                                                ? ` · err ${row.todayErrors}`
                                                : ""}
                                        </div>
                                    </>
                                ) : (
                                    <p className="flex-[2] text-[11px] text-destructive">
                                        {row.error || "Add CF Account ID + API token"}
                                    </p>
                                )}
                            </div>
                        )
                    })}
                </div>
            ) : null}

            {loading && !stats ? (
                <p className="text-xs text-muted-foreground">Loading…</p>
            ) : null}
        </section>
    )
}
