"use client"

import { useEffect, useState } from "react"
import { useSearchParams } from "next/navigation"
import {
    ArrowDown,
    ArrowUp,
    Layers,
    Play,
    Plus,
    Puzzle,
    Save,
    ShieldBan,
    Trash2,
} from "lucide-react"

import { AdminPlaybackAccess } from "@/components/admin/admin-playback-access"
import { AdminSourceChecker } from "@/components/admin/admin-source-checker"
import {
    AdminPageHeader,
    AdminSectionLabel,
    ADMIN_CARD_CLASS,
    MetricCard,
} from "@/components/admin/admin-metrics"
import { AdminShell } from "@/components/admin/admin-shell"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useToast } from "@/components/ui/use-toast"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import { invalidateStreamSourcesConfigCache } from "@/hooks/useStreamSourcesConfig"
import { notifyStreamSourcesConfigChanged } from "@/lib/stream-sources-client"
import { getTemplateHelpText } from "@/lib/custom-players"
import {
    DEFAULT_PLAYBACK_TIMINGS,
    MAX_HEAD_START_SECONDS,
    MAX_PROVIDER_WAIT_SECONDS,
    MIN_HEAD_START_SECONDS,
    MIN_PROVIDER_WAIT_SECONDS,
    clampProviderWaitSeconds,
    normalizeProviderName,
    sanitizePlaybackTimings,
    type CustomPlayerEntry,
    type CustomPlayerKind,
    type StreamPlaybackTimings,
    type StreamSourceEntry,
} from "@/lib/stream-sources-defaults"

function resolveDefaultTab(searchParams: { get: (key: string) => string | null }) {
    const tab = searchParams.get("tab")
    if (tab === "checker" || tab === "access") return tab
    return "sources"
}

export function AdminStreamSources() {
    const { toast } = useToast()
    const searchParams = useSearchParams()
    const defaultTab = resolveDefaultTab(searchParams)
    const [sources, setSources] = useState<StreamSourceEntry[]>([])
    const [players, setPlayers] = useState<CustomPlayerEntry[]>([])
    const [timings, setTimings] = useState<StreamPlaybackTimings>({
        ...DEFAULT_PLAYBACK_TIMINGS,
    })
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [customId, setCustomId] = useState("")
    const [customName, setCustomName] = useState("")
    const [playerId, setPlayerId] = useState("")
    const [playerName, setPlayerName] = useState("")
    const [playerKind, setPlayerKind] = useState<CustomPlayerKind>("embed")
    const [movieTemplate, setMovieTemplate] = useState("")
    const [tvTemplate, setTvTemplate] = useState("")
    const [responsePath, setResponsePath] = useState("url")

    useEffect(() => {
        fetch("/api/admin/stream-sources")
            .then((res) => res.json())
            .then((data) => {
                if (data.config?.sources) setSources(data.config.sources)
                if (data.config?.players) setPlayers(data.config.players)
                setTimings(
                    sanitizePlaybackTimings(
                        data.config?.timings ?? data.timings ?? DEFAULT_PLAYBACK_TIMINGS
                    )
                )
            })
            .catch(() => {
                toast({ title: "Failed to load stream sources", variant: "destructive" })
            })
            .finally(() => setLoading(false))
    }, [toast])

    const moveItem = <T,>(items: T[], index: number, direction: -1 | 1, setter: (next: T[]) => void) => {
        const nextIndex = index + direction
        if (nextIndex < 0 || nextIndex >= items.length) return

        const next = [...items]
        const [item] = next.splice(index, 1)
        next.splice(nextIndex, 0, item)
        setter(next)
    }

    const toggleSource = (index: number, enabled: boolean) => {
        setSources((current) =>
            current.map((entry, entryIndex) =>
                entryIndex === index ? { ...entry, enabled } : entry
            )
        )
    }

    const setSourceTimeoutSeconds = (index: number, raw: string) => {
        setSources((current) =>
            current.map((entry, entryIndex) => {
                if (entryIndex !== index) return entry
                const trimmed = raw.trim()
                if (trimmed === "") {
                    const next = { ...entry }
                    delete next.timeoutSeconds
                    return next
                }
                const timeoutSeconds = clampProviderWaitSeconds(trimmed)
                if (timeoutSeconds == null) {
                    const next = { ...entry }
                    delete next.timeoutSeconds
                    return next
                }
                return { ...entry, timeoutSeconds }
            })
        )
    }

    const removeSource = (index: number) => {
        const entry = sources[index]
        if (entry?.builtin) return
        setSources((current) => current.filter((_, entryIndex) => entryIndex !== index))
    }

    const addCustomSource = () => {
        const id = normalizeProviderName(customId)
        const name = customName.trim()

        if (!id) {
            toast({ title: "Enter a source id", variant: "destructive" })
            return
        }

        if (!/^[a-z0-9][a-z0-9._-]{0,63}$/.test(id)) {
            toast({
                title: "Invalid id",
                description: "Use lowercase letters, numbers, dots, dashes, or underscores.",
                variant: "destructive",
            })
            return
        }

        if (sources.some((entry) => entry.id === id)) {
            toast({ title: "Source already exists", variant: "destructive" })
            return
        }

        setSources((current) => [...current, { id, name: name || id, enabled: true }])
        setCustomId("")
        setCustomName("")
    }

    const togglePlayer = (index: number, enabled: boolean) => {
        setPlayers((current) =>
            current.map((entry, entryIndex) =>
                entryIndex === index ? { ...entry, enabled } : entry
            )
        )
    }

    const removePlayer = (index: number) => {
        const entry = players[index]
        if (entry?.builtin) return
        setPlayers((current) => current.filter((_, entryIndex) => entryIndex !== index))
    }

    const addCustomPlayer = () => {
        const id = normalizeProviderName(playerId)
        const name = playerName.trim()

        if (!id || !name) {
            toast({ title: "Enter player id and name", variant: "destructive" })
            return
        }

        if (!movieTemplate.trim() && !tvTemplate.trim()) {
            toast({ title: "Add at least one movie or TV URL template", variant: "destructive" })
            return
        }

        if (players.some((entry) => entry.id === id)) {
            toast({ title: "Player already exists", variant: "destructive" })
            return
        }

        setPlayers((current) => [
            ...current,
            {
                id,
                name,
                enabled: true,
                kind: playerKind,
                movieTemplate: movieTemplate.trim(),
                tvTemplate: tvTemplate.trim(),
                responsePath: playerKind === "api" ? responsePath.trim() || "url" : undefined,
            },
        ])

        setPlayerId("")
        setPlayerName("")
        setMovieTemplate("")
        setTvTemplate("")
        setResponsePath("url")
        setPlayerKind("embed")
    }

    const saveConfig = async (reset = false) => {
        setSaving(true)

        try {
            const response = await fetch("/api/admin/stream-sources", {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(
                    reset ? { reset: true } : { sources, players, timings }
                ),
            })
            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.error || "Update failed")
            }

            setSources(data.config?.sources ?? sources)
            setPlayers(data.config?.players ?? players)
            setTimings(
                sanitizePlaybackTimings(
                    data.config?.timings ?? timings
                )
            )
            invalidateStreamSourcesConfigCache()
            notifyStreamSourcesConfigChanged()

            const sync = data.allowlistSync as
                | { synced?: boolean; allowlist?: string; reason?: string }
                | undefined

            if (sync?.synced && sync.allowlist) {
                toast({
                    title: reset ? "Defaults restored" : "Stream settings saved",
                    description: `CinePro scrapers: ${sync.allowlist.replace(/,/g, ", ")}`,
                })
            } else if (sync?.reason === "remote-cinepro") {
                toast({
                    title: reset ? "Defaults restored" : "Stream settings saved",
                    description:
                        "CinePro runs on another host — update its provider allowlist manually.",
                })
            } else if (sync?.synced === false) {
                toast({
                    title: reset ? "Defaults restored" : "Stream settings saved",
                    description:
                        "CinePro scraper sync failed — Source Checker may not match until PM2 is fixed.",
                    variant: "destructive",
                })
            } else {
                toast({ title: reset ? "Defaults restored" : "Stream settings saved" })
            }
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Update failed",
                variant: "destructive",
            })
        } finally {
            setSaving(false)
        }
    }

    const enabledSourceCount = sources.filter((entry) => entry.enabled).length
    const enabledPlayerCount = players.filter((entry) => entry.enabled).length
    const templateHelp = getTemplateHelpText()

    return (
        <AdminShell>
            <AdminPageHeader
                title="Stream sources"
                description="CinePro providers, external players, source health, and proxy access control."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="rounded-xl"
                            disabled={saving}
                            onClick={() => void saveConfig(true)}
                        >
                            Reset defaults
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className="rounded-xl"
                            disabled={saving || loading}
                            onClick={() => void saveConfig()}
                        >
                            <Save className="mr-2 size-4" />
                            {saving ? "Saving…" : "Save changes"}
                        </Button>
                    </div>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <MetricCard
                    label="Providers enabled"
                    value={enabledSourceCount}
                    hint={`${sources.length} total in list`}
                    icon={Layers}
                    accent="sky"
                />
                <MetricCard
                    label="External players"
                    value={enabledPlayerCount}
                    hint={`${players.length} configured`}
                    icon={Puzzle}
                    accent="violet"
                />
                <MetricCard
                    label="Playback guard"
                    value="Active"
                    hint="Off-site scrapers blocked at API"
                    icon={ShieldBan}
                    accent="live"
                    live
                />
            </div>

            <Tabs defaultValue={defaultTab} className="space-y-6">
                <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 rounded-xl bg-muted/40 p-1">
                    <TabsTrigger value="sources" className="rounded-lg gap-1.5">
                        <Layers className="size-3.5" />
                        Providers
                    </TabsTrigger>
                    <TabsTrigger value="players" className="rounded-lg gap-1.5">
                        <Puzzle className="size-3.5" />
                        Players
                    </TabsTrigger>
                    <TabsTrigger value="checker" className="rounded-lg gap-1.5">
                        <Play className="size-3.5" />
                        Checker
                    </TabsTrigger>
                    <TabsTrigger value="access" className="rounded-lg gap-1.5">
                        <ShieldBan className="size-3.5" />
                        Access log
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="sources" className="space-y-6">
                    <Card className={ADMIN_CARD_CLASS}>
                        <CardHeader>
                            <CardTitle>Player timing</CardTitle>
                            <CardDescription>
                                These are the real wait budgets the player uses. Change them and
                                Save — nothing is hidden behind “auto.”
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <label className="space-y-1.5 text-sm">
                                    <span className="font-medium">Head-start</span>
                                    <span className="relative block">
                                        <Input
                                            type="number"
                                            min={MIN_HEAD_START_SECONDS}
                                            max={MAX_HEAD_START_SECONDS}
                                            step={0.1}
                                            className="h-9 rounded-lg pr-8"
                                            value={timings.headStartSeconds}
                                            onChange={(event) =>
                                                setTimings((current) =>
                                                    sanitizePlaybackTimings({
                                                        ...current,
                                                        headStartSeconds: event.target.value,
                                                    })
                                                )
                                            }
                                        />
                                        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                                            s
                                        </span>
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        #1 provider exclusive scan before others compete
                                    </span>
                                </label>

                                <label className="space-y-1.5 text-sm">
                                    <span className="font-medium">Link fetch</span>
                                    <span className="relative block">
                                        <Input
                                            type="number"
                                            min={MIN_PROVIDER_WAIT_SECONDS}
                                            max={MAX_PROVIDER_WAIT_SECONDS}
                                            step={1}
                                            className="h-9 rounded-lg pr-8"
                                            value={timings.linkFetchSeconds}
                                            onChange={(event) =>
                                                setTimings((current) =>
                                                    sanitizePlaybackTimings({
                                                        ...current,
                                                        linkFetchSeconds: event.target.value,
                                                    })
                                                )
                                            }
                                        />
                                        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                                            s
                                        </span>
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        Max wait for a provider to return stream URLs
                                    </span>
                                </label>

                                <label className="space-y-1.5 text-sm">
                                    <span className="font-medium">First play</span>
                                    <span className="relative block">
                                        <Input
                                            type="number"
                                            min={MIN_PROVIDER_WAIT_SECONDS}
                                            max={MAX_PROVIDER_WAIT_SECONDS}
                                            step={1}
                                            className="h-9 rounded-lg pr-8"
                                            value={timings.firstPlaySeconds}
                                            onChange={(event) =>
                                                setTimings((current) =>
                                                    sanitizePlaybackTimings({
                                                        ...current,
                                                        firstPlaySeconds: event.target.value,
                                                    })
                                                )
                                            }
                                        />
                                        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                                            s
                                        </span>
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        Max wait for picture to start before fallback
                                    </span>
                                </label>

                                <label className="space-y-1.5 text-sm">
                                    <span className="font-medium">Metadata</span>
                                    <span className="relative block">
                                        <Input
                                            type="number"
                                            min={MIN_PROVIDER_WAIT_SECONDS}
                                            max={MAX_PROVIDER_WAIT_SECONDS}
                                            step={1}
                                            className="h-9 rounded-lg pr-8"
                                            value={timings.metadataSeconds}
                                            onChange={(event) =>
                                                setTimings((current) =>
                                                    sanitizePlaybackTimings({
                                                        ...current,
                                                        metadataSeconds: event.target.value,
                                                    })
                                                )
                                            }
                                        />
                                        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">
                                            s
                                        </span>
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        Max wait for stream metadata before giving up
                                    </span>
                                </label>
                            </div>
                            <p className="mt-4 text-xs text-muted-foreground">
                                Defaults: head-start {DEFAULT_PLAYBACK_TIMINGS.headStartSeconds}s ·
                                link fetch {DEFAULT_PLAYBACK_TIMINGS.linkFetchSeconds}s · first play{" "}
                                {DEFAULT_PLAYBACK_TIMINGS.firstPlaySeconds}s · metadata{" "}
                                {DEFAULT_PLAYBACK_TIMINGS.metadataSeconds}s. Per-provider First play
                                below overrides the global First play for that source only.
                            </p>
                        </CardContent>
                    </Card>

                    <Card className={ADMIN_CARD_CLASS}>
                        <CardHeader>
                            <CardTitle>CinePro providers</CardTitle>
                            <CardDescription>
                                Enable providers and set failover order. Top = tried first.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {loading ? (
                                <p className="text-sm text-muted-foreground">Loading providers…</p>
                            ) : (
                                <div className="space-y-2">
                                    {sources.map((entry, index) => (
                                        <div
                                            key={entry.id}
                                            className={cn(
                                                "flex flex-wrap items-center gap-3 rounded-xl border px-3 py-3 transition",
                                                entry.enabled
                                                    ? "border-primary/20 bg-primary/5"
                                                    : "border-border/40 bg-background/30"
                                            )}
                                        >
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        moveItem(sources, index, -1, setSources)
                                                    }
                                                >
                                                    <ArrowUp className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg"
                                                    disabled={index === sources.length - 1}
                                                    onClick={() =>
                                                        moveItem(sources, index, 1, setSources)
                                                    }
                                                >
                                                    <ArrowDown className="size-4" />
                                                </Button>
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium">{entry.name}</p>
                                                    {entry.builtin ? (
                                                        <Badge variant="secondary" className="text-[10px]">
                                                            built-in
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="text-[10px]">
                                                            custom
                                                        </Badge>
                                                    )}
                                                    {entry.ownerOnly ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-amber-500/40 bg-amber-500/10 text-[10px] text-amber-700 dark:text-amber-300"
                                                        >
                                                            Owner only
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {entry.id}
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <label className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    <span className="whitespace-nowrap">First play</span>
                                                    <span className="relative inline-flex items-center">
                                                        <Input
                                                            type="number"
                                                            min={MIN_PROVIDER_WAIT_SECONDS}
                                                            max={MAX_PROVIDER_WAIT_SECONDS}
                                                            step={1}
                                                            inputMode="numeric"
                                                            className="h-8 w-[4.5rem] rounded-lg border-border/60 bg-background/80 px-2 pr-6 text-center tabular-nums"
                                                            placeholder={String(timings.firstPlaySeconds)}
                                                            value={entry.timeoutSeconds ?? ""}
                                                            onChange={(event) =>
                                                                setSourceTimeoutSeconds(
                                                                    index,
                                                                    event.target.value
                                                                )
                                                            }
                                                            aria-label={`First play override for ${entry.name}`}
                                                            title={`Override first-play budget for this provider. Blank = global ${timings.firstPlaySeconds}s. Range ${MIN_PROVIDER_WAIT_SECONDS}–${MAX_PROVIDER_WAIT_SECONDS}.`}
                                                        />
                                                        <span className="pointer-events-none absolute right-2 text-[10px] text-muted-foreground/80">
                                                            s
                                                        </span>
                                                    </span>
                                                </label>

                                                <Switch
                                                    checked={entry.enabled}
                                                    onCheckedChange={(checked) =>
                                                        toggleSource(index, checked)
                                                    }
                                                />
                                            </div>

                                            {!entry.builtin ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg text-destructive"
                                                    onClick={() => removeSource(index)}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            ) : null}
                                        </div>
                                    ))}
                                    <p className="px-1 pt-1 text-xs text-muted-foreground">
                                        Per-provider First play blank = use global{" "}
                                        {timings.firstPlaySeconds}s from Player timing above.
                                    </p>
                                </div>
                            )}

                            <div className="rounded-xl border border-dashed border-border/50 bg-muted/10 p-4">
                                <AdminSectionLabel>Add provider</AdminSectionLabel>
                                <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                                    <Input
                                        className="rounded-xl"
                                        value={customId}
                                        onChange={(event) => setCustomId(event.target.value)}
                                        placeholder="Source id, e.g. vidlink"
                                    />
                                    <Input
                                        className="rounded-xl"
                                        value={customName}
                                        onChange={(event) => setCustomName(event.target.value)}
                                        placeholder="Display name"
                                    />
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className="rounded-xl"
                                        onClick={addCustomSource}
                                    >
                                        <Plus className="mr-2 size-4" />
                                        Add
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <TabsContent value="players" className="space-y-6">
                    <Card className={ADMIN_CARD_CLASS}>
                    <CardHeader>
                        <CardTitle>External players</CardTitle>
                        <CardDescription>
                            Add full alternate players shown below the main player. Use embed URLs or
                            API endpoints that return a stream URL.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            {enabledPlayerCount} of {players.length} external players enabled
                        </p>

                        {players.length > 0 ? (
                            <div className="space-y-2">
                                {players.map((entry, index) => (
                                    <div
                                        key={entry.id}
                                        className={cn(
                                            "rounded-xl border px-3 py-3",
                                            entry.enabled
                                                ? "border-primary/20 bg-primary/5"
                                                : "border-border/40 bg-background/30"
                                        )}
                                    >
                                        <div className="flex flex-wrap items-center gap-3">
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    disabled={index === 0}
                                                    onClick={() =>
                                                        moveItem(players, index, -1, setPlayers)
                                                    }
                                                >
                                                    <ArrowUp className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    disabled={index === players.length - 1}
                                                    onClick={() =>
                                                        moveItem(players, index, 1, setPlayers)
                                                    }
                                                >
                                                    <ArrowDown className="size-4" />
                                                </Button>
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium">{entry.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {entry.id} · {entry.kind}
                                                    {entry.builtin ? " · built-in" : ""}
                                                </p>
                                            </div>

                                            <Switch
                                                checked={entry.enabled}
                                                onCheckedChange={(checked) =>
                                                    togglePlayer(index, checked)
                                                }
                                            />

                                            {!entry.builtin ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-destructive"
                                                    onClick={() => removePlayer(index)}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            ) : null}
                                        </div>

                                        <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                                            {entry.movieTemplate ? (
                                                <p>
                                                    <span className="font-medium text-foreground/80">
                                                        Movie:
                                                    </span>{" "}
                                                    {entry.movieTemplate}
                                                </p>
                                            ) : null}
                                            {entry.tvTemplate ? (
                                                <p>
                                                    <span className="font-medium text-foreground/80">
                                                        TV:
                                                    </span>{" "}
                                                    {entry.tvTemplate}
                                                </p>
                                            ) : null}
                                            {entry.kind === "api" && entry.responsePath ? (
                                                <p>
                                                    <span className="font-medium text-foreground/80">
                                                        API path:
                                                    </span>{" "}
                                                    {entry.responsePath}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No external players yet. Add one below.
                            </p>
                        )}

                        <div className="rounded-xl border border-dashed border-border/50 bg-muted/10 p-4">
                            <AdminSectionLabel>Add external player</AdminSectionLabel>
                            <div className="grid gap-3 md:grid-cols-2">
                                <Input
                                    value={playerId}
                                    onChange={(event) => setPlayerId(event.target.value)}
                                    placeholder="Player id, e.g. vidlinkpro"
                                />
                                <Input
                                    value={playerName}
                                    onChange={(event) => setPlayerName(event.target.value)}
                                    placeholder="Display name, e.g. VidLink.pro"
                                />
                                <Select
                                    value={playerKind}
                                    onValueChange={(value) =>
                                        setPlayerKind(value as CustomPlayerKind)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Player type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="embed">Embed (iframe URL)</SelectItem>
                                        <SelectItem value="api">API (fetch stream URL)</SelectItem>
                                    </SelectContent>
                                </Select>
                                {playerKind === "api" ? (
                                    <Input
                                        value={responsePath}
                                        onChange={(event) => setResponsePath(event.target.value)}
                                        placeholder="API response path, e.g. url or sources[0].url"
                                    />
                                ) : (
                                    <div />
                                )}
                                <Input
                                    value={movieTemplate}
                                    onChange={(event) => setMovieTemplate(event.target.value)}
                                    placeholder="Movie template, e.g. https://vidlink.pro/movie/{tmdbId}"
                                />
                                <Input
                                    value={tvTemplate}
                                    onChange={(event) => setTvTemplate(event.target.value)}
                                    placeholder="TV template, e.g. https://vidlink.pro/tv/{tmdbId}/{season}/{episode}"
                                />
                            </div>
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <p className="text-xs text-muted-foreground">{templateHelp}</p>
                                <Button type="button" variant="secondary" onClick={addCustomPlayer}>
                                    <Plus className="mr-2 size-4" />
                                    Add player
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                </TabsContent>

                <TabsContent value="checker">
                    <AdminSourceChecker catalog={sources} />
                </TabsContent>

                <TabsContent value="access">
                    <AdminPlaybackAccess />
                </TabsContent>
            </Tabs>
        </AdminShell>
    )
}
