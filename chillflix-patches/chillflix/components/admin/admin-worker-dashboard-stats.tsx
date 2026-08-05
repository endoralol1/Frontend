"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import { Cloud, RefreshCw } from "lucide-react"

import { ADMIN_CARD_CLASS } from "@/components/admin/admin-metrics"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
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
        <section>
            <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        Cloudflare Worker analytics
                    </h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        All enabled Workers from Stream Sources → Worker relay. Auto-refreshes
                        every 1 min (server-cached so CF API is not hammered).
                        {lastRefresh ? ` Last: ${lastRefresh}` : ""}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="rounded-xl"
                        disabled={loading}
                        onClick={() => void load(true)}
                    >
                        <RefreshCw className={cn("mr-2 size-3.5", loading && "animate-spin")} />
                        Refresh now
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="rounded-xl"
                        asChild
                    >
                        <Link href="/admin/stream-sources?tab=workers">Manage workers</Link>
                    </Button>
                </div>
            </div>

            {error ? (
                <Card className={ADMIN_CARD_CLASS}>
                    <CardContent className="py-6 text-sm text-destructive">{error}</CardContent>
                </Card>
            ) : null}

            {!error && stats && stats.length === 0 ? (
                <Card className={ADMIN_CARD_CLASS}>
                    <CardContent className="py-8 text-sm text-muted-foreground">
                        No Workers configured yet. Add them in{" "}
                        <Link className="underline" href="/admin/stream-sources?tab=workers">
                            Stream Sources → Worker relay
                        </Link>
                        .
                    </CardContent>
                </Card>
            ) : null}

            {stats && stats.length > 0 ? (
                <>
                    <div className="mb-4 rounded-2xl border border-border/50 bg-card/40 p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Combined today (configured workers with API access)
                        </p>
                        <p className="mt-1 text-2xl font-semibold tabular-nums">
                            {totalToday.toLocaleString()}
                            {totalLimit > 0 ? (
                                <span className="text-base font-normal text-muted-foreground">
                                    {" "}
                                    / {totalLimit.toLocaleString()}
                                </span>
                            ) : null}
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {stats.map((row) => {
                            const usedPct = row.ok
                                ? pct(row.todayRequests, row.freeDailyLimit)
                                : 0
                            return (
                                <Card key={row.id} className={ADMIN_CARD_CLASS}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Cloud className="size-4 text-sky-400" />
                                            {row.label || row.id}
                                        </CardTitle>
                                        <CardDescription className="truncate">
                                            {row.scriptName || "script unknown"}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        {row.ok ? (
                                            <>
                                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                                    <span>
                                                        Today:{" "}
                                                        <strong className="tabular-nums">
                                                            {row.todayRequests.toLocaleString()}
                                                        </strong>{" "}
                                                        / {row.freeDailyLimit.toLocaleString()} (
                                                        {usedPct}%)
                                                    </span>
                                                    <span>
                                                        24h:{" "}
                                                        <strong className="tabular-nums">
                                                            {row.last24hRequests.toLocaleString()}
                                                        </strong>
                                                    </span>
                                                    <span>
                                                        Errors:{" "}
                                                        <strong className="tabular-nums">
                                                            {row.todayErrors}
                                                        </strong>
                                                    </span>
                                                </div>
                                                <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={cn(
                                                            "h-full transition-all",
                                                            barColor(usedPct)
                                                        )}
                                                        style={{ width: `${usedPct}%` }}
                                                    />
                                                </div>
                                            </>
                                        ) : (
                                            <p className="text-destructive">
                                                {row.error ||
                                                    "Add CF Account ID + API token in Worker relay"}
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            )
                        })}
                    </div>
                </>
            ) : null}

            {loading && !stats ? (
                <Card className={ADMIN_CARD_CLASS}>
                    <CardContent className="py-8 text-sm text-muted-foreground">
                        Loading Worker analytics…
                    </CardContent>
                </Card>
            ) : null}
        </section>
    )
}
