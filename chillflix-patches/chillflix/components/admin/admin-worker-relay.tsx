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
}

type RelayConfig = {
    enabled: boolean
    preferWorker: boolean
    cacheTtlSeconds: number
    workers: WorkerRow[]
    path?: string
}

function newWorker(): WorkerRow {
    return {
        id: `worker-${Math.random().toString(36).slice(2, 8)}`,
        label: "New worker",
        url: "",
        secret: "",
        enabled: true,
    }
}

export function AdminWorkerRelay() {
    const [config, setConfig] = useState<RelayConfig | null>(null)
    const [cacheHours, setCacheHours] = useState("2")
    const [saving, setSaving] = useState(false)
    const [testingId, setTestingId] = useState<string | null>(null)
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
                    // Keep masked secret as blank so server preserves previous.
                    secret: w.secret.includes("…") ? "" : w.secret,
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
            </div>

            <div className="space-y-3">
                {config.workers.map((row, index) => (
                    <div
                        key={row.id}
                        className="grid gap-3 rounded-xl border border-border/60 p-4 md:grid-cols-[1fr_1fr_1fr_auto]"
                    >
                        <div className="space-y-1">
                            <Label>Label</Label>
                            <Input
                                value={row.label}
                                onChange={(e) => {
                                    const workers = [...config.workers]
                                    workers[index] = { ...row, label: e.target.value }
                                    setConfig({ ...config, workers })
                                }}
                            />
                        </div>
                        <div className="space-y-1 md:col-span-2">
                            <Label>Worker URL</Label>
                            <Input
                                value={row.url}
                                placeholder="https://xxxx.workers.dev"
                                onChange={(e) => {
                                    const workers = [...config.workers]
                                    workers[index] = { ...row, url: e.target.value }
                                    setConfig({ ...config, workers })
                                }}
                            />
                        </div>
                        <div className="space-y-1 md:col-span-2">
                            <Label>Secret {row.secretSet ? "(leave masked to keep)" : ""}</Label>
                            <Input
                                value={row.secret}
                                placeholder="YORU_RELAY_SECRET"
                                onChange={(e) => {
                                    const workers = [...config.workers]
                                    workers[index] = { ...row, secret: e.target.value }
                                    setConfig({ ...config, workers })
                                }}
                            />
                        </div>
                        <div className="flex flex-wrap items-end gap-2">
                            <div className="flex items-center gap-2 pb-2">
                                <Switch
                                    checked={row.enabled}
                                    onCheckedChange={(enabled) => {
                                        const workers = [...config.workers]
                                        workers[index] = { ...row, enabled }
                                        setConfig({ ...config, workers })
                                    }}
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
                                        workers: config.workers.filter((w) => w.id !== row.id),
                                    })
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    </div>
                ))}
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

            <div className="rounded-xl border border-border/50 bg-muted/20 p-4 text-sm text-muted-foreground space-y-2">
                <p className="font-medium text-foreground">Second Cloudflare account setup</p>
                <ol className="list-decimal pl-5 space-y-1">
                    <li>Cloudflare → Workers → Create → paste <code>yoru-relay.js</code></li>
                    <li>
                        Settings → Variables → encrypt <code>YORU_RELAY_SECRET</code> (same or
                        different secret)
                    </li>
                    <li>Deploy → copy <code>https://….workers.dev</code></li>
                    <li>Add Worker above → Test → Save</li>
                </ol>
            </div>
        </div>
    )
}
