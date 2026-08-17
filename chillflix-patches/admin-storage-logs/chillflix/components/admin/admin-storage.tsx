"use client"

import { useCallback, useEffect, useState } from "react"
import { HardDrive, RefreshCw, Trash2 } from "lucide-react"

import {
    AdminPageHeader,
    ADMIN_CARD_CLASS,
    MetricCard,
} from "@/components/admin/admin-metrics"
import { AdminShell } from "@/components/admin/admin-shell"
import { Button } from "@/components/ui/button"
import { useToast } from "@/components/ui/use-toast"

type StorageItem = {
    id: string
    label: string
    path: string
    bytes: number
    count: number
    safe: boolean
    note: string
}

type Inventory = {
    disk: {
        totalBytes: number
        usedBytes: number
        availableBytes: number
        usedPercent: number
    }
    items: StorageItem[]
    skipped: Array<{ label: string; reason: string }>
}

function formatBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`
}

export function AdminStorage() {
    const { toast } = useToast()
    const [data, setData] = useState<Inventory | null>(null)
    const [loading, setLoading] = useState(true)
    const [busyId, setBusyId] = useState<string | null>(null)

    const load = useCallback(async () => {
        setLoading(true)
        try {
            const res = await fetch("/api/admin/storage")
            const json = await res.json()
            if (!res.ok) throw new Error(json.error || "Failed to load storage")
            setData(json)
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Failed to load storage",
                variant: "destructive",
            })
            setData(null)
        } finally {
            setLoading(false)
        }
    }, [toast])

    useEffect(() => {
        void load()
    }, [load])

    const clean = async (id: string, label: string) => {
        if (!confirm(`Clean "${label}"?\n\nSafe leftovers / caches only.`)) return
        setBusyId(id)
        try {
            const res = await fetch("/api/admin/storage", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id }),
            })
            const json = await res.json().catch(() => ({}))
            if (!res.ok) throw new Error(json.error || `Clean failed (HTTP ${res.status})`)
            const title = json.message || "Cleaned"
            const desc = json.freedBytes ? `Freed ${formatBytes(json.freedBytes)}` : undefined
            toast({ title, description: desc })
            window.alert(desc ? `${title}\n${desc}` : title)
            await load()
        } catch (error) {
            const message = error instanceof Error ? error.message : "Clean failed"
            toast({ title: message, variant: "destructive" })
            window.alert(`Clean failed:\n${message}`)
        } finally {
            setBusyId(null)
        }
    }

    return (
        <AdminShell>
            <AdminPageHeader
                title="Storage"
                description="Safe disk cleanup. Live .next is never touched. Stream temp (cfhls) is cleaned from Vuflix admin."
                actions={
                    <Button variant="outline" size="sm" onClick={() => void load()} disabled={loading}>
                        <RefreshCw className="mr-2 size-4" />
                        Refresh
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <MetricCard
                    label="Disk used"
                    value={data ? `${data.disk.usedPercent}%` : "—"}
                    hint={data ? `${formatBytes(data.disk.usedBytes)} / ${formatBytes(data.disk.totalBytes)}` : undefined}
                    icon={HardDrive}
                />
                <MetricCard
                    label="Free"
                    value={data ? formatBytes(data.disk.availableBytes) : "—"}
                    icon={HardDrive}
                    accent="sky"
                />
                <MetricCard
                    label="Cleanable targets"
                    value={data?.items.length ?? "—"}
                    icon={Trash2}
                />
            </div>

            <div className={`${ADMIN_CARD_CLASS} divide-y divide-border/60`}>
                {(data?.items ?? []).map((item) => (
                    <div
                        key={item.id}
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div className="min-w-0 space-y-1">
                            <div className="font-medium">{item.label}</div>
                            <p className="text-sm text-muted-foreground">{item.note}</p>
                            <p className="text-xs text-muted-foreground">
                                {formatBytes(item.bytes)}
                                {item.count ? ` · ${item.count} file(s)` : ""} ·{" "}
                                <code className="text-[11px]">{item.path}</code>
                            </p>
                        </div>
                        <Button
                            size="sm"
                            disabled={!item.safe || busyId === item.id || loading}
                            onClick={() => void clean(item.id, item.label)}
                        >
                            <Trash2 className="mr-2 size-4" />
                            {busyId === item.id ? "Cleaning…" : "Clean"}
                        </Button>
                    </div>
                ))}
                {loading && !data ? (
                    <div className="p-4 text-sm text-muted-foreground">Loading storage…</div>
                ) : null}
            </div>

            {(data?.skipped?.length ?? 0) > 0 ? (
                <div className={`${ADMIN_CARD_CLASS} mt-6 space-y-3 p-4`}>
                    <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        Left alone on purpose
                    </h3>
                    {data!.skipped.map((row) => (
                        <div key={row.label}>
                            <div className="font-medium">{row.label}</div>
                            <p className="text-sm text-muted-foreground">{row.reason}</p>
                        </div>
                    ))}
                </div>
            ) : null}

            <p className="mt-4 text-sm text-muted-foreground">
                For nginx live log viewing / truncate, use{" "}
                <a className="underline underline-offset-2" href="/admin/logs">
                    Admin → Logs
                </a>
                .
            </p>
        </AdminShell>
    )
}
