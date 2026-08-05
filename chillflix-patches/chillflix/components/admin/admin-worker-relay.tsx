"use client"

import { useCallback, useEffect, useState } from "react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Switch } from "@/components/ui/switch"

type WorkerRow = {
    id: string
    label: string
    url: string
    secret: string
    enabled: boolean
    secretSet?: boolean
    cfAccountId?: string
    cfApiToken?: string
    cfApiTokenSet?: boolean
    cfScriptName?: string
}

type RelayConfig = {
    enabled: boolean
    preferWorker: boolean
    cacheTtlSeconds: number
    workers: WorkerRow[]
    path?: string
}

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

function newWorker(): WorkerRow {
    return {
        id: `worker-${Math.random().toString(36).slice(2, 8)}`,
        label: "New worker",
        url: "",
        secret: "",
        enabled: true,
        cfAccountId: "",
        cfApiToken: "",
        cfScriptName: "",
    }
}

function pct(used: number, limit: number) {
    if (!limit) return 0
    return Math.min(100, Math.round((used / limit) * 100))
}

export function AdminWorkerRelay() {
    const [config, setConfig] = useState<RelayConfig | null>(null)
    const [cacheHours, setCacheHours] = useState("2")
    const [saving, setSaving] = useState(false)
    const [testingId, setTestingId] = useState<string | null>(null)
    const [loadingStats, setLoadingStats] = useState(false)
    const [statsById, setStatsById] = useState<Record<string, WorkerStats>>({})
    const [message, setMessage] = useState<string | null>(null)
    const [error, setError] = useState<string | null>(null)

    const load = useCallback(async () => {
        setError(null)
        const res = await fetch("/api/admin/worker-relay", { credentials: "include" })
        const json = await res.json()
        if (!res.ok || !json?.success) {
            setError(json?.error || "Failed to load worker relay config")
            return
        }
        const cfg = json.config as RelayConfig
        setConfig(cfg)
        setCacheHours(String(Math.round((cfg.cacheTtlSeconds || 7200) / 3600)))
    }, [])

    useEffect(() => {
        void load()
    }, [load])

    const patchWorker = (index: number, patch: Partial<WorkerRow>) => {
        if (!config) return
        const workers = [...config.workers]
        workers[index] = { ...workers[index], ...patch }
        setConfig({ ...config, workers })
    }

    const save = async () => {
        if (!config) return
        setSaving(true)
        setMessage(null)
        setError(null)
        try {
            const hours = Math.max(1 / 60, Number(cacheHours) || 2)
            const body = {
                ...config,
                cacheTtlSeconds: Math.round(hours * 3600),
                workers: config.workers.map((w) => ({
                    ...w,
                    secret: w.secret.includes("…") ? "" : w.secret,
                    cfApiToken: (w.cfApiToken || "").includes("…") ? "" : w.cfApiToken || "",
                })),
            }
            const res = await fetch("/api/admin/worker-relay", {
                method: "PATCH",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body),
            })
            const json = await res.json()
            if (!res.ok || !json?.success) {
                setError(json?.error || "Save failed")
                return
            }
            setConfig(json.config)
            setCacheHours(String(Math.round((json.config.cacheTtlSeconds || 7200) / 3600)))
            setMessage(
                json.restart?.restarted
                    ? "Saved. Cinepro restarted."
                    : "Saved. Cinepro will hot-reload the JSON config."
            )
        } catch (e) {
            setError(e instanceof Error ? e.message : "Save failed")
        } finally {
            setSaving(false)
        }
    }

    const copyScript = async () => {
        setMessage(null)
        setError(null)
        try {
            const res = await fetch("/api/admin/worker-relay?download=view", {
                credentials: "include",
            })
            if (!res.ok) {
                const json = await res.json().catch(() => null)
                setError(json?.error || "Failed to load yoru-relay.js")
                return
            }
            const text = await res.text()
            await navigator.clipboard.writeText(text)
            setMessage("yoru-relay.js copied — paste into Cloudflare Worker editor.")
        } catch (e) {
            setError(e instanceof Error ? e.message : "Copy failed")
        }
    }

    const testWorker = async (row: WorkerRow) => {
        setTestingId(row.id)
        setMessage(null)
        setError(null)
        try {
            const res = await fetch("/api/admin/worker-relay", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    action: "test",
                    id: row.id,
                    url: row.url,
                    secret: row.secret.includes("…") ? "" : row.secret,
                }),
            })
            const json = await res.json()
            const result = json?.result
            if (!json?.success) {
                setError(json?.error || "Test failed")
                return
            }
            setMessage(
                result?.ok
                    ? `OK ${row.label || row.id} (HTTP ${result.status}${result.cache ? `, cache ${result.cache}` : ""})`
                    : `Failed ${row.label || row.id}: ${result?.error || "unknown"}`
            )
        } catch (e) {
            setError(e instanceof Error ? e.message : "Test failed")
        } finally {
            setTestingId(null)
        }
    }

    const refreshStats = async () => {
        setLoadingStats(true)
        setMessage(null)
        setError(null)
        try {
            const res = await fetch("/api/admin/worker-relay", {
                method: "POST",
                credentials: "include",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "stats" }),
            })
            const json = await res.json()
            if (!res.ok || !json?.success) {
                setError(json?.error || "Failed to load analytics")
                return
            }
            const next: Record<string, WorkerStats> = {}
            for (const row of (json.stats || []) as WorkerStats[]) {
                next[row.id] = row
            }
            setStatsById(next)
            setMessage("Analytics refreshed from Cloudflare.")
        } catch (e) {
            setError(e instanceof Error ? e.message : "Failed to load analytics")
        } finally {
            setLoadingStats(false)
        }
    }

    if (!config) {
        return <p className="text-sm text-muted-foreground">{error || "Loading worker relay…"}</p>
    }

    return (
        <div className="space-y-6">
            <div className="space-y-2">
                <h3 className="text-lg font-medium">Cloudflare Worker relay</h3>
                <p className="text-sm text-muted-foreground max-w-2xl">
                    Shared by Chillflix + Vuflix via cinepro (Yoru + VAPlayer only — VixSrc is
                    off the Worker). Add multiple Workers and we round-robin. Cache TTL stops
                    repeat resolves from burning free-tier quota. Config file:{" "}
                    <code className="text-xs">{config.path || "/var/www/cinepro/config/worker-relay.json"}</code>
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-2">
                    <Switch
                        checked={config.enabled}
                        onCheckedChange={(enabled) => setConfig({ ...config, enabled })}
                        id="relay-enabled"
                    />
                    <Label htmlFor="relay-enabled">Worker relay enabled</Label>
                </div>
                <div className="flex items-center gap-2">
                    <Switch
                        checked={config.preferWorker}
                        onCheckedChange={(preferWorker) => setConfig({ ...config, preferWorker })}
                        id="relay-prefer"
                        disabled={!config.enabled}
                    />
                    <Label htmlFor="relay-prefer">Prefer Worker first (else direct → Worker fallback)</Label>
                </div>
                <div className="flex items-center gap-2">
                    <Label htmlFor="relay-ttl">Cache hours</Label>
                    <Input
                        id="relay-ttl"
                        className="w-20"
                        value={cacheHours}
                        onChange={(e) => setCacheHours(e.target.value)}
                        inputMode="decimal"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    disabled={loadingStats}
                    onClick={() => void refreshStats()}
                >
                    {loadingStats ? "Loading stats…" : "Refresh CF stats"}
                </Button>
            </div>

            <div className="space-y-3">
                {config.workers.map((row, index) => {
                    const stats = statsById[row.id]
                    const usedPct = stats?.ok
                        ? pct(stats.todayRequests, stats.freeDailyLimit)
                        : 0
                    return (
                        <div
                            key={row.id}
                            className="space-y-3 rounded-xl border border-border/60 p-4"
                        >
                            <div className="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto]">
                                <div className="space-y-1">
                                    <Label>Label</Label>
                                    <Input
                                        value={row.label}
                                        onChange={(e) =>
                                            patchWorker(index, { label: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="space-y-1 md:col-span-2">
                                    <Label>Worker URL</Label>
                                    <Input
                                        value={row.url}
                                        placeholder="https://xxxx.workers.dev"
                                        onChange={(e) =>
                                            patchWorker(index, { url: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="space-y-1 md:col-span-2">
                                    <Label>
                                        Secret {row.secretSet ? "(leave masked to keep)" : ""}
                                    </Label>
                                    <Input
                                        value={row.secret}
                                        placeholder="YORU_RELAY_SECRET"
                                        onChange={(e) =>
                                            patchWorker(index, { secret: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="flex flex-wrap items-end gap-2">
                                    <div className="flex items-center gap-2 pb-2">
                                        <Switch
                                            checked={row.enabled}
                                            onCheckedChange={(enabled) =>
                                                patchWorker(index, { enabled })
                                            }
                                        />
                                        <span className="text-sm">On</span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={testingId === row.id}
                                        onClick={() => void testWorker(row)}
                                    >
                                        {testingId === row.id ? "Testing…" : "Test"}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setConfig({
                                                ...config,
                                                workers: config.workers.filter(
                                                    (w) => w.id !== row.id
                                                ),
                                            })
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>

                            <div className="grid gap-3 md:grid-cols-3">
                                <div className="space-y-1">
                                    <Label>CF Account ID</Label>
                                    <Input
                                        value={row.cfAccountId || ""}
                                        placeholder="32-char account id"
                                        onChange={(e) =>
                                            patchWorker(index, { cfAccountId: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>
                                        CF API token{" "}
                                        {row.cfApiTokenSet ? "(leave masked to keep)" : ""}
                                    </Label>
                                    <Input
                                        value={row.cfApiToken || ""}
                                        placeholder="Analytics Read token"
                                        onChange={(e) =>
                                            patchWorker(index, { cfApiToken: e.target.value })
                                        }
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label>CF script name</Label>
                                    <Input
                                        value={row.cfScriptName || ""}
                                        placeholder="auto from URL"
                                        onChange={(e) =>
                                            patchWorker(index, { cfScriptName: e.target.value })
                                        }
                                    />
                                </div>
                            </div>

                            {stats ? (
                                <div className="rounded-lg bg-muted/30 p-3 text-sm space-y-2">
                                    {stats.ok ? (
                                        <>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1">
                                                <span>
                                                    Today:{" "}
                                                    <strong>
                                                        {stats.todayRequests.toLocaleString()}
                                                    </strong>{" "}
                                                    / {stats.freeDailyLimit.toLocaleString()} (
                                                    {usedPct}%)
                                                </span>
                                                <span>
                                                    24h:{" "}
                                                    <strong>
                                                        {stats.last24hRequests.toLocaleString()}
                                                    </strong>
                                                </span>
                                                <span>
                                                    Errors today:{" "}
                                                    <strong>{stats.todayErrors}</strong>
                                                </span>
                                                <span className="text-muted-foreground">
                                                    script: {stats.scriptName}
                                                </span>
                                            </div>
                                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={`h-full ${
                                                        usedPct >= 90
                                                            ? "bg-destructive"
                                                            : usedPct >= 70
                                                              ? "bg-amber-500"
                                                              : "bg-emerald-500"
                                                    }`}
                                                    style={{ width: `${usedPct}%` }}
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <p className="text-destructive">
                                            {stats.error || "No analytics"}
                                        </p>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    )
                })}
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        setConfig({ ...config, workers: [...config.workers, newWorker()] })
                    }
                >
                    Add worker
                </Button>
                <Button type="button" onClick={() => void save()} disabled={saving}>
                    {saving ? "Saving…" : "Save relay settings"}
                </Button>
            </div>

            {message ? <p className="text-sm text-emerald-500">{message}</p> : null}
            {error ? <p className="text-sm text-destructive">{error}</p> : null}

            <div className="rounded-xl border border-border/50 bg-muted/20 p-4 text-sm text-muted-foreground space-y-3">
                <p className="font-medium text-foreground">Cloudflare analytics setup</p>
                <ol className="list-decimal pl-5 space-y-1">
                    <li>
                        Cloudflare → My Profile → API Tokens → Create Token →{" "}
                        <strong>Account Analytics: Read</strong> (and Account: Read if offered)
                    </li>
                    <li>
                        Account ID: Workers overview URL / right sidebar → paste into each worker
                        row (each CF account has its own)
                    </li>
                    <li>Script name defaults from the workers.dev subdomain — override if needed</li>
                    <li>Save → Refresh CF stats</li>
                </ol>
                <div className="flex flex-wrap gap-2 pt-1">
                    <Button type="button" variant="secondary" asChild>
                        <a
                            href="/api/admin/worker-relay?download=view"
                            target="_blank"
                            rel="noreferrer"
                        >
                            Open yoru-relay.js
                        </a>
                    </Button>
                    <Button type="button" variant="outline" onClick={() => void copyScript()}>
                        Copy script
                    </Button>
                </div>
            </div>
        </div>
    )
}
