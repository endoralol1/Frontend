"use client"

import { useCallback, useEffect, useState } from "react"
import { BarChart3, Loader2, Plus, Trash2, X } from "lucide-react"

import { AdminPageHeader, ADMIN_CARD_CLASS } from "@/components/admin/admin-metrics"
import { AdminShell } from "@/components/admin/admin-shell"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { ScrollArea } from "@/components/ui/scroll-area"
import { Switch } from "@/components/ui/switch"
import { useToast } from "@/components/ui/use-toast"
import type { ChatMessage } from "@/lib/chat-types"
import type { MessageBotTarget } from "@/lib/chat-message-bot"
import type { ChatSettings } from "@/lib/chat-settings"
import { SITE_FEATURES_UPDATED_EVENT } from "@/components/site-features"
import { cn } from "@/lib/utils"

function formatTime(timestamp: number) {
    return new Date(timestamp).toLocaleString()
}

export function AdminChat() {
    const { toast } = useToast()
    const [settings, setSettings] = useState<ChatSettings | null>(null)
    const [messages, setMessages] = useState<ChatMessage[]>([])
    const [messageCount, setMessageCount] = useState(0)
    const [loading, setLoading] = useState(true)
    const [saving, setSaving] = useState(false)
    const [clearing, setClearing] = useState(false)

    const [selectedId, setSelectedId] = useState<string | null>(null)
    const [botTarget, setBotTarget] = useState<MessageBotTarget | null>(null)
    const [loadingMessage, setLoadingMessage] = useState(false)
    const [messageSaving, setMessageSaving] = useState(false)

    const [instantLikes, setInstantLikes] = useState("0")
    const [instantDislikes, setInstantDislikes] = useState("0")
    const [botLikesEnabled, setBotLikesEnabled] = useState(false)
    const [botLikesTarget, setBotLikesTarget] = useState("0")
    const [botDislikesEnabled, setBotDislikesEnabled] = useState(false)
    const [botDislikesTarget, setBotDislikesTarget] = useState("0")
    const [botPeriodHours, setBotPeriodHours] = useState("24")

    const [pollQuestion, setPollQuestion] = useState("")
    const [pollOptions, setPollOptions] = useState(["", ""])
    const [pollAllowMultiple, setPollAllowMultiple] = useState(false)
    const [pollPosting, setPollPosting] = useState(false)

    const selectedMessage = messages.find((message) => message.id === selectedId) ?? null

    const load = useCallback(async () => {
        const response = await fetch("/api/admin/chat")
        const data = await response.json()
        if (!response.ok) throw new Error(data.error || "Failed to load chat")
        setSettings(data.settings)
        setMessages(data.messages ?? [])
        setMessageCount(data.messageCount ?? 0)
    }, [])

    const loadMessageDetail = useCallback(async (messageId: string) => {
        setLoadingMessage(true)
        try {
            const response = await fetch(`/api/admin/chat/messages/${messageId}`)
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Failed to load message")

            setMessages((prev) =>
                prev.map((message) =>
                    message.id === messageId ? { ...message, ...data.message } : message
                )
            )

            const target = data.botTarget as MessageBotTarget | null
            setBotTarget(target)
            setBotLikesEnabled(target?.likesEnabled ?? false)
            setBotLikesTarget(String(target?.likesTarget ?? 0))
            setBotDislikesEnabled(target?.dislikesEnabled ?? false)
            setBotDislikesTarget(String(target?.dislikesTarget ?? 0))
            setBotPeriodHours(String(target?.periodHours ?? 24))
            setInstantLikes("0")
            setInstantDislikes("0")
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Failed to load message",
                variant: "destructive",
            })
        } finally {
            setLoadingMessage(false)
        }
    }, [toast])

    useEffect(() => {
        void load()
            .catch((err) =>
                toast({
                    title: err instanceof Error ? err.message : "Failed to load",
                    variant: "destructive",
                })
            )
            .finally(() => setLoading(false))
    }, [load, toast])

    useEffect(() => {
        if (selectedId) {
            void loadMessageDetail(selectedId)
        } else {
            setBotTarget(null)
        }
    }, [selectedId, loadMessageDetail])

    async function saveSettings(updates: Partial<ChatSettings>) {
        if (!settings) return
        setSaving(true)
        const next = { ...settings, ...updates }
        setSettings(next)

        try {
            const response = await fetch("/api/admin/chat", {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(updates),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Update failed")
            setSettings(data.settings)
            window.dispatchEvent(new Event(SITE_FEATURES_UPDATED_EVENT))
            toast({ title: "Chat settings saved" })
        } catch (error) {
            setSettings(settings)
            toast({
                title: error instanceof Error ? error.message : "Update failed",
                variant: "destructive",
            })
        } finally {
            setSaving(false)
        }
    }

    async function applyMessageUpdate(body: Record<string, unknown>) {
        if (!selectedId) return

        setMessageSaving(true)
        try {
            const response = await fetch(`/api/admin/chat/messages/${selectedId}`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(body),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Update failed")

            setMessages((prev) =>
                prev.map((message) =>
                    message.id === selectedId ? { ...message, ...data.message } : message
                )
            )

            const target = data.botTarget as MessageBotTarget | null
            setBotTarget(target)
            if (target) {
                setBotLikesEnabled(target.likesEnabled)
                setBotLikesTarget(String(target.likesTarget))
                setBotDislikesEnabled(target.dislikesEnabled)
                setBotDislikesTarget(String(target.dislikesTarget))
                setBotPeriodHours(String(target.periodHours))
            }

            toast({ title: "Message updated" })
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Update failed",
                variant: "destructive",
            })
        } finally {
            setMessageSaving(false)
        }
    }

    async function deleteMessage(id: string) {
        try {
            const response = await fetch(`/api/admin/chat/messages/${id}`, { method: "DELETE" })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Delete failed")
            setMessages((prev) => prev.filter((message) => message.id !== id))
            setMessageCount((count) => Math.max(0, count - 1))
            if (selectedId === id) {
                setSelectedId(null)
            }
            toast({ title: "Message deleted" })
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Delete failed",
                variant: "destructive",
            })
        }
    }

    async function clearChat() {
        if (!window.confirm("Delete all chat messages permanently? This cannot be undone.")) {
            return
        }

        setClearing(true)
        try {
            const response = await fetch("/api/admin/chat/messages", { method: "DELETE" })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || "Clear failed")
            setMessages([])
            setMessageCount(0)
            setSelectedId(null)
            toast({ title: "Chat cleared" })
        } catch (error) {
            toast({
                title: error instanceof Error ? error.message : "Clear failed",
                variant: "destructive",
            })
        } finally {
            setClearing(false)
        }
    }

    if (loading) {
        return (
            <AdminShell>
                <div className="flex items-center justify-center py-20 text-muted-foreground">
                    <Loader2 className="mr-2 size-5 animate-spin" />
                    Loading chat…
                </div>
            </AdminShell>
        )
    }

    return (
        <AdminShell>
            <div className="space-y-6">
                <AdminPageHeader
                    title="Community chat"
                    description={`${messageCount} active messages · Select a message to manage reactions`}
                    actions={
                        <Button
                            variant="destructive"
                            size="sm"
                            className="rounded-xl"
                            disabled={clearing || messageCount === 0}
                            onClick={() => void clearChat()}
                        >
                            {clearing ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                "Clear all"
                            )}
                        </Button>
                    }
                />

                <Card className={ADMIN_CARD_CLASS}>
                    <CardHeader>
                        <CardTitle>Visibility</CardTitle>
                        <CardDescription>
                            When disabled, chat is hidden from the navbar and users cannot send
                            messages.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                            <div>
                                <p className="font-medium">Chat enabled</p>
                                <p className="text-sm text-muted-foreground">
                                    Show chat button and allow messaging
                                </p>
                            </div>
                            <Switch
                                checked={settings?.enabled ?? true}
                                disabled={saving}
                                onCheckedChange={(checked) => void saveSettings({ enabled: checked })}
                            />
                        </div>
                    </CardContent>
                </Card>


                <Card className={ADMIN_CARD_CLASS}>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="size-5" />
                            Create poll
                        </CardTitle>
                        <CardDescription>
                            Post a poll to community chat. Users can vote with single or multiple
                            choice.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="poll-question">Question</Label>
                            <Textarea
                                id="poll-question"
                                value={pollQuestion}
                                onChange={(event) => setPollQuestion(event.target.value)}
                                placeholder="What should we watch next?"
                                rows={2}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label>Options</Label>
                            <div className="space-y-2">
                                {pollOptions.map((option, index) => (
                                    <div key={index} className="flex items-center gap-2">
                                        <Input
                                            value={option}
                                            onChange={(event) =>
                                                setPollOptions((prev) =>
                                                    prev.map((value, optionIndex) =>
                                                        optionIndex === index
                                                            ? event.target.value
                                                            : value
                                                    )
                                                )
                                            }
                                            placeholder={`Option ${index + 1}`}
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-9 shrink-0"
                                            disabled={pollOptions.length <= 2}
                                            onClick={() =>
                                                setPollOptions((prev) =>
                                                    prev.filter((_, optionIndex) => optionIndex !== index)
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="rounded-xl"
                                disabled={pollOptions.length >= 10}
                                onClick={() => setPollOptions((prev) => [...prev, ""])}
                            >
                                <Plus className="mr-2 size-4" />
                                Add option
                            </Button>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-lg border px-4 py-3">
                            <div>
                                <p className="font-medium">Allow multiple choices</p>
                                <p className="text-sm text-muted-foreground">
                                    Let users select more than one answer
                                </p>
                            </div>
                            <Switch
                                checked={pollAllowMultiple}
                                onCheckedChange={(checked) => setPollAllowMultiple(checked === true)}
                            />
                        </div>

                        <Button
                            className="w-full rounded-xl"
                            disabled={pollPosting || !settings?.enabled}
                            onClick={async () => {
                                setPollPosting(true)
                                try {
                                    const response = await fetch("/api/admin/chat/polls", {
                                        method: "POST",
                                        headers: { "Content-Type": "application/json" },
                                        body: JSON.stringify({
                                            question: pollQuestion,
                                            options: pollOptions,
                                            allowMultiple: pollAllowMultiple,
                                        }),
                                    })
                                    const data = await response.json()
                                    if (!response.ok) {
                                        throw new Error(data.error || "Failed to post poll")
                                    }
                                    setPollQuestion("")
                                    setPollOptions(["", ""])
                                    setPollAllowMultiple(false)
                                    await load()
                                    toast({ title: "Poll posted to chat" })
                                } catch (error) {
                                    toast({
                                        title:
                                            error instanceof Error
                                                ? error.message
                                                : "Failed to post poll",
                                        variant: "destructive",
                                    })
                                } finally {
                                    setPollPosting(false)
                                }
                            }}
                        >
                            {pollPosting ? (
                                <Loader2 className="mr-2 size-4 animate-spin" />
                            ) : (
                                <BarChart3 className="mr-2 size-4" />
                            )}
                            Post poll to chat
                        </Button>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,380px)]">
                    <Card className={ADMIN_CARD_CLASS}>
                        <CardHeader>
                            <CardTitle>Recent messages</CardTitle>
                            <CardDescription>
                                Click a message to manage reactions for that message only.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ScrollArea className="h-[520px] pr-3">
                                <div className="space-y-2">
                                    {messages.length === 0 ? (
                                        <p className="py-8 text-center text-sm text-muted-foreground">
                                            No messages
                                        </p>
                                    ) : (
                                        messages.map((message) => (
                                            <div
                                                key={message.id}
                                                role="button"
                                                tabIndex={0}
                                                className={cn(
                                                    "flex items-start gap-3 rounded-lg border px-3 py-2 transition-colors",
                                                    selectedId === message.id
                                                        ? "border-primary bg-primary/10"
                                                        : "border-border/50 hover:border-border"
                                                )}
                                                onClick={() => setSelectedId(message.id)}
                                                onKeyDown={(event) => {
                                                    if (event.key === "Enter" || event.key === " ") {
                                                        event.preventDefault()
                                                        setSelectedId(message.id)
                                                    }
                                                }}
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-medium">
                                                            {message.user.name ||
                                                                message.user.username}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {formatTime(message.createdAt)}
                                                        </span>
                                                        <Badge variant="outline" className="text-[10px]">
                                                            👍 {message.likeCount}
                                                        </Badge>
                                                        <Badge variant="outline" className="text-[10px]">
                                                            👎 {message.dislikeCount}
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-1 text-sm text-foreground/90">
                                                        {message.messageType === "poll" || message.poll ? (
                                                            <span className="inline-flex items-center gap-1">
                                                                <BarChart3 className="size-3.5" />
                                                                {message.poll?.question || message.body}
                                                            </span>
                                                        ) : (
                                                            message.body
                                                        )}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 shrink-0 text-destructive"
                                                    onClick={(event) => {
                                                        event.stopPropagation()
                                                        void deleteMessage(message.id)
                                                    }}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </ScrollArea>
                        </CardContent>
                    </Card>

                    <Card className={ADMIN_CARD_CLASS}>
                        <CardHeader>
                            <CardTitle>Message reactions</CardTitle>
                            <CardDescription>
                                Apply likes and dislikes to the selected message.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {!selectedMessage ? (
                                <p className="text-sm text-muted-foreground">
                                    Select a message from the list to add reactions.
                                </p>
                            ) : loadingMessage ? (
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" />
                                    Loading message…
                                </div>
                            ) : (
                                <>
                                    <div className="rounded-lg border border-border/50 px-3 py-2">
                                        <p className="text-xs text-muted-foreground">
                                            {selectedMessage.user.name ||
                                                selectedMessage.user.username}
                                            · {formatTime(selectedMessage.createdAt)}
                                        </p>
                                        <p className="mt-1 text-sm">{selectedMessage.body}</p>
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Current: 👍 {selectedMessage.likeCount} · 👎{" "}
                                            {selectedMessage.dislikeCount}
                                        </p>
                                    </div>

                                    <div className="space-y-3">
                                        <p className="text-sm font-medium">Add instantly</p>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-2">
                                                <Label>Add likes</Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={instantLikes}
                                                    onChange={(e) => setInstantLikes(e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Add dislikes</Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={instantDislikes}
                                                    onChange={(e) =>
                                                        setInstantDislikes(e.target.value)
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            size="sm"
                                            disabled={messageSaving}
                                            onClick={() =>
                                                void applyMessageUpdate({
                                                    addLikes: Number(instantLikes),
                                                    addDislikes: Number(instantDislikes),
                                                })
                                            }
                                        >
                                            {messageSaving ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                "Apply now"
                                            )}
                                        </Button>
                                    </div>

                                    <div className="space-y-3 border-t border-border/50 pt-4">
                                        <p className="text-sm font-medium">
                                            Bot schedule for this message
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Spread synthetic reactions on this message only over the
                                            chosen period.
                                        </p>

                                        <div className="space-y-3 rounded-lg border px-3 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <Label>Bot likes</Label>
                                                <Switch
                                                    checked={botLikesEnabled}
                                                    onCheckedChange={setBotLikesEnabled}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Total likes over period</Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={botLikesTarget}
                                                    onChange={(e) =>
                                                        setBotLikesTarget(e.target.value)
                                                    }
                                                />
                                            </div>
                                            {botTarget?.likesEnabled ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Progress: {botTarget.likesGiven}/
                                                    {botTarget.likesTarget}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-3 rounded-lg border px-3 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <Label>Bot dislikes</Label>
                                                <Switch
                                                    checked={botDislikesEnabled}
                                                    onCheckedChange={setBotDislikesEnabled}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Total dislikes over period</Label>
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={botDislikesTarget}
                                                    onChange={(e) =>
                                                        setBotDislikesTarget(e.target.value)
                                                    }
                                                />
                                            </div>
                                            {botTarget?.dislikesEnabled ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Progress: {botTarget.dislikesGiven}/
                                                    {botTarget.dislikesTarget}
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Period (hours)</Label>
                                            <Input
                                                type="number"
                                                min={1}
                                                value={botPeriodHours}
                                                onChange={(e) => setBotPeriodHours(e.target.value)}
                                            />
                                        </div>

                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={messageSaving}
                                            onClick={() =>
                                                void applyMessageUpdate({
                                                    botLikesEnabled,
                                                    botLikesTarget: Number(botLikesTarget),
                                                    botDislikesEnabled,
                                                    botDislikesTarget: Number(botDislikesTarget),
                                                    botPeriodHours: Number(botPeriodHours),
                                                })
                                            }
                                        >
                                            {messageSaving ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                "Save bot schedule"
                                            )}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminShell>
    )
}
