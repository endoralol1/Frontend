"use client"

import { useCallback, useEffect, useState } from "react"
import Link from "next/link"
import {
    Activity,
    BarChart3,
    Bookmark,
    Clock,
    Gamepad2,
    Heart,
    LayoutDashboard,
    List,
    RefreshCw,
    ShieldCheck,
    UserCheck,
    UserMinus,
    Users,
    Wrench,
} from "lucide-react"

import { AdminWorkerDashboardStats } from "@/components/admin/admin-worker-dashboard-stats"
import {
    AdminQuickLinkCard,
    ADMIN_CARD_CLASS,
    MetricCard,
    StatusPill,
} from "@/components/admin/admin-metrics"
import { AdminShell, RoleBadge } from "@/components/admin/admin-shell"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { useToast } from "@/components/ui/use-toast"
import { RECENT_SIGNUPS_LIMIT } from "@/lib/admin-constants"
import type { AdminLiveWatching, AdminPopularContent } from "@/lib/admin-popular-content-shared"

type AdminStats = {
    users: {
        total: number
        active: number
        suspended: number
        owners: number
        moderators: number
        recentSignups: Array<{
            id: string
            email: string
            username: string
            name: string
            role: string
            createdAt: number
        }>
    }
    library: {
        favorites: number
        watchlist: number
        history: number
        continueWatching: number
    }
    games: {
        catalogCount: number
        syncStatus: string
        lastSyncAt: string | null
        lastError: string | null
    }
    site: {
        registrationEnabled: boolean
        maintenanceMode: boolean
    }
    popular: AdminPopularContent
    live: AdminLiveWatching
}

function formatSignupTime(timestamp: number) {
    return new Date(timestamp).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    })
}

export function AdminDashboard() {
    const { toast } = useToast()
    const [stats, setStats] = useState<AdminStats | null>(null)
    const [loading, setLoading] = useState(true)
    const [syncing, setSyncing] = useState(false)

    const loadStats = useCallback(async () => {
        setLoading(true)
        try {
            const res = await fetch("/api/admin/stats")
            const data = await res.json()
            if (data.stats) setStats(data.stats)
            else setStats(null)
        } catch {
            toast({ title: "Failed to load dashboard", variant: "destructive" })
            setStats(null)
        } finally {
            setLoading(false)
        }
    }, [toast])

    useEffect(() => {
        void loadStats()
    }, [loadStats])

    const triggerGamesSync = async () => {
        setSyncing(true)
        try {
            const response = await fetch("/api/games/sync", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ mode: "incremental", maxNew: 40 }),
            })
            const data = await response.json()
            if (!response.ok) {
                toast({ title: data.error || "Sync failed", variant: "destructive" })
                return
            }
            toast({ title: "Games sync started" })
            await loadStats()
        } catch {
            toast({ title: "Sync request failed", variant: "destructive" })
        } finally {
            setSyncing(false)
        }
    }

    return (
        <AdminShell>
            {loading && !stats ? (
                <div className="space-y-6">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-28 rounded-2xl" />
                        ))}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-28 rounded-2xl" />
                        ))}
                    </div>
                    <Skeleton className="h-64 rounded-2xl" />
                </div>
            ) : stats ? (
                <div className="space-y-8">
                    <section>
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Users
                        </h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label="Total users"
                                value={stats.users.total}
                                icon={Users}
                            />
                            <MetricCard
                                label="Active"
                                value={stats.users.active}
                                icon={UserCheck}
                                accent="live"
                            />
                            <MetricCard
                                label="Suspended"
                                value={stats.users.suspended}
                                icon={UserMinus}
                                accent="rose"
                            />
                            <MetricCard
                                label="Staff"
                                value={stats.users.owners + stats.users.moderators}
                                hint={`${stats.users.owners} owners · ${stats.users.moderators} moderators`}
                                icon={ShieldCheck}
                                accent="violet"
                            />
                        </div>
                    </section>

                    <section>
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Library activity
                        </h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <MetricCard
                                label="Favorites"
                                value={stats.library.favorites}
                                icon={Heart}
                                accent="rose"
                            />
                            <MetricCard
                                label="Watchlist"
                                value={stats.library.watchlist}
                                icon={List}
                                accent="sky"
                            />
                            <MetricCard
                                label="Watch history"
                                value={stats.library.history}
                                icon={Clock}
                            />
                            <MetricCard
                                label="Continue watching"
                                value={stats.library.continueWatching}
                                icon={Bookmark}
                                accent="violet"
                            />
                        </div>
                    </section>

                    <AdminWorkerDashboardStats />

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Card className="border-border/50 bg-card/30 shadow-sm">
                            <CardHeader>
                                <CardTitle>Recent signups</CardTitle>
                                <CardDescription>
                                    Latest {RECENT_SIGNUPS_LIMIT} registered accounts
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {stats.users.recentSignups.length === 0 ? (
                                    <div className="rounded-xl border border-dashed border-border/60 bg-muted/20 py-10 text-center text-sm text-muted-foreground">
                                        No users yet
                                    </div>
                                ) : (
                                    <div className="max-h-[min(28rem,70vh)] space-y-3 overflow-y-auto pr-1">
                                        {stats.users.recentSignups.map((signup) => (
                                        <div
                                            key={signup.id}
                                            className="flex items-center justify-between gap-3 rounded-xl border border-border/40 bg-background/30 px-4 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {signup.name}
                                                    <span className="text-muted-foreground">
                                                        {" "}
                                                        @{signup.username}
                                                    </span>
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {signup.email}
                                                </p>
                                                <p className="mt-0.5 text-[11px] text-muted-foreground/80">
                                                    {formatSignupTime(signup.createdAt)}
                                                </p>
                                            </div>
                                            <RoleBadge role={signup.role} />
                                        </div>
                                        ))}
                                    </div>
                                )}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full rounded-xl"
                                    asChild
                                >
                                    <Link href="/admin/users">Manage users</Link>
                                </Button>
                            </CardContent>
                        </Card>

                        <Card className="border-border/50 bg-card/30 shadow-sm">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Gamepad2 className="size-5 text-primary" />
                                    Games catalog
                                </CardTitle>
                                <CardDescription>Provider sync status</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <MetricCard
                                    label="Games in catalog"
                                    value={stats.games.catalogCount}
                                    icon={Gamepad2}
                                    accent="sky"
                                />
                                <div className="rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                                    <p className="text-xs uppercase tracking-wide text-muted-foreground">
                                        Sync status
                                    </p>
                                    <p className="mt-1 font-semibold capitalize">
                                        {stats.games.syncStatus}
                                    </p>
                                    {stats.games.lastSyncAt ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Last sync:{" "}
                                            {new Date(stats.games.lastSyncAt).toLocaleString()}
                                        </p>
                                    ) : null}
                                    {stats.games.lastError ? (
                                        <p className="mt-2 text-xs text-destructive">
                                            {stats.games.lastError}
                                        </p>
                                    ) : null}
                                </div>
                                <Button
                                    className="w-full rounded-xl"
                                    onClick={() => void triggerGamesSync()}
                                    disabled={syncing}
                                >
                                    {syncing ? (
                                        <>
                                            <RefreshCw className="mr-2 size-4 animate-spin" />
                                            Starting sync…
                                        </>
                                    ) : (
                                        "Run games sync"
                                    )}
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    <section>
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Quick links
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <AdminQuickLinkCard
                                title="Analytics"
                                description="Traffic, page views, and top pages"
                                href="/admin/analytics"
                                icon={BarChart3}
                                accent="sky"
                            />
                            <AdminQuickLinkCard
                                title="Health"
                                description="PM2, database, disk, and playback checks"
                                href="/admin/health"
                                icon={Activity}
                                accent="live"
                            />
                            <AdminQuickLinkCard
                                title="Security"
                                description="Playback guard, rate limits, blocked requests"
                                href="/admin/security"
                                icon={ShieldCheck}
                                accent="violet"
                            />
                            <AdminQuickLinkCard
                                title="Site settings"
                                description="Registration, maintenance, and site config"
                                href="/admin/settings"
                                icon={Wrench}
                            />
                        </div>
                    </section>

                    <Card className="border-border/50 bg-card/30 shadow-sm">
                        <CardHeader>
                            <CardTitle>Site status</CardTitle>
                            <CardDescription>Current public site flags</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <StatusPill
                                label="Registration"
                                active={stats.site.registrationEnabled}
                                activeLabel="Open"
                                inactiveLabel="Closed"
                            />
                            <StatusPill
                                label="Maintenance mode"
                                active={stats.site.maintenanceMode}
                                activeLabel="On"
                                inactiveLabel="Off"
                            />
                            {stats.site.maintenanceMode ? (
                                <p className="text-xs text-amber-400">
                                    Visitors see the maintenance page unless they have access.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            ) : (
                <Card className="border-border/50 bg-card/30">
                    <CardContent className="flex flex-col items-center justify-center py-16 text-center">
                        <LayoutDashboard className="mb-3 size-10 text-muted-foreground/40" />
                        <p className="font-medium">Could not load dashboard data</p>
                        <Button
                            className="mt-4"
                            variant="outline"
                            onClick={() => void loadStats()}
                        >
                            Retry
                        </Button>
                    </CardContent>
                </Card>
            )}
        </AdminShell>
    )
}
