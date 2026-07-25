"use client"

import { useEffect, useState } from "react"

import { AdminPageHeader, ADMIN_CARD_CLASS } from "@/components/admin/admin-metrics"
import { AdminShell } from "@/components/admin/admin-shell"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/ui/use-toast"
import { SITE_FEATURES_UPDATED_EVENT } from "@/components/site-features"
import type { SiteSettings } from "@/lib/site-settings"

const FEATURE_TOGGLES: {
    key: keyof Pick<
        SiteSettings,
        | "watchPartyEnabled"
        | "continueWatchingEnabled"
        | "playersEnabled"
        | "iptvEnabled"
        | "musicEnabled"
        | "ticketsEnabled"
    >
    label: string
    description: string
}[] = [
    {
        key: "watchPartyEnabled",
        label: "Watch Party",
        description: "Show watch party controls in the navbar and player",
    },
    {
        key: "continueWatchingEnabled",
        label: "Continue Watching",
        description: "Show the continue watching section on the home page",
    },
    {
        key: "playersEnabled",
        label: "Players / Watch button",
        description: "Show Watch buttons on movie and TV detail pages",
    },
    {
        key: "iptvEnabled",
        label: "IPTV",
        description: "Show IPTV in navigation and allow access to IPTV pages",
    },
    {
        key: "musicEnabled",
        label: "Music",
        description: "Show Music in the site navigation",
    },
    {
        key: "ticketsEnabled",
        label: "Tickets",
        description: "Show support tickets in the navbar and admin pulse",
    },
]

export function AdminSettings() {
    const { toast } = useToast()
    const [settings, setSettings] = useState<SiteSettings | null>(null)
    const [saving, setSaving] = useState<string | null>(null)
    const [uploadingApk, setUploadingApk] = useState(false)

    useEffect(() => {
        fetch("/api/admin/settings")
            .then((res) => res.json())
            .then((data) => {
                if (data.settings) setSettings(data.settings)
            })
            .catch(() => {
                toast({ title: "Failed to load settings", variant: "destructive" })
            })
    }, [toast])

    const updateSetting = async (key: keyof SiteSettings, value: boolean | string | null) => {
        if (!settings) return
        setSaving(key)
        const next = { ...settings, [key]: value }
        setSettings(next)

        try {
            const response = await fetch("/api/admin/settings", {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(next),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Update failed")
            setSettings(data.settings)
            window.dispatchEvent(new Event(SITE_FEATURES_UPDATED_EVENT))
            toast({ title: "Settings saved" })
        } catch (error) {
            setSettings(settings)
            toast({
                title: error instanceof Error ? error.message : "Update failed",
                variant: "destructive",
            })
        } finally {
            setSaving(null)
        }
    }

    const uploadApk = async (file: File) => {
        setUploadingApk(true)
        try {
            const formData = new FormData()
            formData.append("file", file)

            const response = await fetch("/api/admin/apk", {
                method: "POST",
                body: formData,
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Upload failed")

            setSettings(data.settings)
            window.dispatchEvent(new Event(SITE_FEATURES_UPDATED_EVENT))
            toast({ title: "APK uploaded and download enabled" })
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Upload failed",
                variant: "destructive",
            })
        } finally {
            setUploadingApk(false)
        }
    }

    return (
        <AdminShell>
            <AdminPageHeader
                title="Site settings"
                description="Control registration, maintenance mode, and site features."
            />

            <Card className={ADMIN_CARD_CLASS}>
                <CardHeader>
                    <CardTitle>Public site flags</CardTitle>
                    <CardDescription>Changes apply immediately for all visitors.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <div>
                            <p className="font-medium">Open registration</p>
                            <p className="text-sm text-muted-foreground">
                                Allow new users to sign up
                            </p>
                        </div>
                        <Switch
                            checked={settings?.registrationEnabled ?? true}
                            disabled={!settings || saving === "registrationEnabled"}
                            onCheckedChange={(checked) =>
                                void updateSetting("registrationEnabled", checked)
                            }
                        />
                    </div>
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <div>
                            <p className="font-medium">Maintenance mode</p>
                            <p className="text-sm text-muted-foreground">
                                Block public access; staff can still use the site and admin
                            </p>
                        </div>
                        <Switch
                            checked={settings?.maintenanceMode ?? false}
                            disabled={!settings || saving === "maintenanceMode"}
                            onCheckedChange={(checked) =>
                                void updateSetting("maintenanceMode", checked)
                            }
                        />
                    </div>
                    {settings?.maintenanceMode ? (
                        <p className="text-xs text-amber-400">
                            Visitors see the maintenance page unless they have access.
                        </p>
                    ) : null}
                </CardContent>
            </Card>

            <Card className={`${ADMIN_CARD_CLASS} mt-4`}>
                <CardHeader>
                    <CardTitle>Feature toggles</CardTitle>
                    <CardDescription>
                        Enable or disable site features for all visitors.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    {FEATURE_TOGGLES.map((toggle) => (
                        <div
                            key={toggle.key}
                            className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3"
                        >
                            <div>
                                <p className="font-medium">{toggle.label}</p>
                                <p className="text-sm text-muted-foreground">
                                    {toggle.description}
                                </p>
                            </div>
                            <Switch
                                checked={settings?.[toggle.key] ?? true}
                                disabled={!settings || saving === toggle.key}
                                onCheckedChange={(checked) =>
                                    void updateSetting(toggle.key, checked)
                                }
                            />
                        </div>
                    ))}
                </CardContent>
            </Card>

            <Card className={`${ADMIN_CARD_CLASS} mt-4`}>
                <CardHeader>
                    <CardTitle>Android app & sharing</CardTitle>
                    <CardDescription>
                        APK download, daily share prompt, and community invite links.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <div>
                            <p className="font-medium">APK download</p>
                            <p className="text-sm text-muted-foreground">
                                Show download in settings, footer, and a once-per-day install
                                prompt on Android when a file or URL is configured
                            </p>
                        </div>
                        <Switch
                            checked={settings?.apkDownloadEnabled ?? false}
                            disabled={!settings || saving === "apkDownloadEnabled"}
                            onCheckedChange={(checked) =>
                                void updateSetting("apkDownloadEnabled", checked)
                            }
                        />
                    </div>
                    <div className="space-y-2 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <p className="text-sm font-medium">APK version label</p>
                        <Input
                            value={settings?.apkVersionLabel ?? ""}
                            placeholder="e.g. 1.0.0"
                            disabled={!settings || saving === "apkVersionLabel"}
                            onChange={(event) =>
                                setSettings((current) =>
                                    current
                                        ? { ...current, apkVersionLabel: event.target.value }
                                        : current
                                )
                            }
                            onBlur={(event) =>
                                void updateSetting("apkVersionLabel", event.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-2 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <p className="text-sm font-medium">Custom APK URL (optional)</p>
                        <Input
                            value={settings?.apkCustomUrl ?? ""}
                            placeholder="https://… or leave empty for hosted download"
                            disabled={!settings || saving === "apkCustomUrl"}
                            onChange={(event) =>
                                setSettings((current) =>
                                    current
                                        ? { ...current, apkCustomUrl: event.target.value }
                                        : current
                                )
                            }
                            onBlur={(event) =>
                                void updateSetting("apkCustomUrl", event.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Upload the file below, or paste an external download URL. Uploaded
                            files are stored at{" "}
                            <code className="rounded bg-muted px-1">data/chillflix.apk</code> and
                            survive deploys.
                        </p>
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <Input
                                type="file"
                                accept=".apk,application/vnd.android.package-archive"
                                disabled={uploadingApk}
                                className="cursor-pointer"
                                onChange={(event) => {
                                    const file = event.target.files?.[0]
                                    if (file) void uploadApk(file)
                                    event.target.value = ""
                                }}
                            />
                            {uploadingApk ? (
                                <span className="text-xs text-muted-foreground">Uploading…</span>
                            ) : null}
                        </div>
                        {settings?.apkDownloadUrl ? (
                            <p className="text-xs text-emerald-500">
                                Active link: {settings.apkDownloadUrl}
                            </p>
                        ) : settings?.apkDownloadEnabled ? (
                            <p className="text-xs text-amber-500">
                                Enabled but no APK file or URL configured yet.
                            </p>
                        ) : null}
                    </div>
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <div>
                            <p className="font-medium">Daily share prompt</p>
                            <p className="text-sm text-muted-foreground">
                                Once per day, ask visitors to share Chillflix on social media
                            </p>
                        </div>
                        <Switch
                            checked={settings?.sharePromptEnabled ?? true}
                            disabled={!settings || saving === "sharePromptEnabled"}
                            onCheckedChange={(checked) =>
                                void updateSetting("sharePromptEnabled", checked)
                            }
                        />
                    </div>
                    <div className="flex items-center justify-between gap-4 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <div>
                            <p className="font-medium">Community invite prompt</p>
                            <p className="text-sm text-muted-foreground">
                                Once per browser session, invite visitors to Discord and Telegram
                            </p>
                        </div>
                        <Switch
                            checked={settings?.communityPromptEnabled ?? true}
                            disabled={!settings || saving === "communityPromptEnabled"}
                            onCheckedChange={(checked) =>
                                void updateSetting("communityPromptEnabled", checked)
                            }
                        />
                    </div>
                    <div className="space-y-2 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <p className="text-sm font-medium">Discord invite URL</p>
                        <Input
                            value={settings?.discordInviteUrl ?? ""}
                            placeholder="https://discord.gg/6r5KTZgqXV"
                            disabled={!settings || saving === "discordInviteUrl"}
                            onChange={(event) =>
                                setSettings((current) =>
                                    current
                                        ? { ...current, discordInviteUrl: event.target.value }
                                        : current
                                )
                            }
                            onBlur={(event) =>
                                void updateSetting("discordInviteUrl", event.target.value)
                            }
                        />
                    </div>
                    <div className="space-y-2 rounded-xl border border-border/40 bg-background/30 px-4 py-3">
                        <p className="text-sm font-medium">Telegram invite URL</p>
                        <Input
                            value={settings?.telegramInviteUrl ?? ""}
                            placeholder="https://t.me/chillflixlol"
                            disabled={!settings || saving === "telegramInviteUrl"}
                            onChange={(event) =>
                                setSettings((current) =>
                                    current
                                        ? { ...current, telegramInviteUrl: event.target.value }
                                        : current
                                )
                            }
                            onBlur={(event) =>
                                void updateSetting("telegramInviteUrl", event.target.value)
                            }
                        />
                    </div>
                </CardContent>
            </Card>
        </AdminShell>
    )
}
