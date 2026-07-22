"use client"

import Link from "next/link"
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from "react"
import { createPortal } from "react-dom"
import {
    AlertCircle,
    ArrowLeft,
    LifeBuoy,
    Loader2,
    Plus,
    Send,
    X,
} from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { ScrollArea } from "@/components/ui/scroll-area"
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { isTurnstileConfigured, TurnstileWidget } from "@/components/turnstile-widget"
import { useAuth } from "@/hooks/use-auth"
import { canAccessAdmin } from "@/lib/permissions"
import {
    TICKET_CATEGORIES,
    TICKET_MAX_BODY_LENGTH,
    TICKET_MAX_SUBJECT_LENGTH,
    TICKET_PRIORITIES,
    getTicketCategoryLabel,
    getTicketPriorityLabel,
    getTicketStatusLabel,
    type SupportTicket,
    type TicketCategory,
    type TicketDetail,
    type TicketMessage,
    type TicketPriority,
} from "@/lib/ticket-types"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

const NAVBAR_GAP_PX = 20

type TicketUIContextValue = {
    open: boolean
    attentionCount: number
    toggleTickets: () => void
    triggerRef: RefObject<HTMLButtonElement | null>
}

const TicketUIContext = createContext<TicketUIContextValue | null>(null)

function useTicketUI() {
    const context = useContext(TicketUIContext)
    if (!context) {
        throw new Error("useTicketUI must be used within TicketProvider")
    }
    return context
}

function formatTime(timestamp: number) {
    return new Date(timestamp).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        hour: "numeric",
        minute: "2-digit",
    })
}

function statusBadgeVariant(status: SupportTicket["status"]) {
    switch (status) {
        case "closed":
        case "resolved":
            return "secondary"
        case "waiting_user":
            return "default"
        default:
            return "outline"
    }
}

function priorityColor(priority: TicketPriority) {
    switch (priority) {
        case "urgent":
            return "text-red-400"
        case "high":
            return "text-amber-400"
        case "low":
            return "text-muted-foreground"
        default:
            return "text-foreground"
    }
}

function TicketLauncherButton({
    attentionCount,
    isOpen,
    onClick,
    buttonRef,
    ariaLabel,
}: {
    attentionCount: number
    isOpen: boolean
    onClick: () => void
    buttonRef?: RefObject<HTMLButtonElement | null>
    ariaLabel: string
}) {
    return (
        <div className="relative inline-flex shrink-0 shadow-md shadow-black/25">
            <button
                ref={buttonRef}
                type="button"
                onClick={onClick}
                aria-label={ariaLabel}
                aria-expanded={isOpen}
                className={cn(
                    "flex size-9 items-center justify-center rounded-md border border-border/60 bg-background/90 text-foreground transition hover:bg-accent",
                    isOpen && "bg-accent"
                )}
            >
                <LifeBuoy className="size-4" />
            </button>
            {attentionCount > 0 ? (
                <span
                    className="pointer-events-none absolute -right-1.5 -top-1.5 z-20 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold leading-none text-primary-foreground ring-2 ring-background"
                >
                    {attentionCount > 99 ? "99+" : attentionCount}
                </span>
            ) : null}
        </div>
    )
}

export function TicketNavButton() {
    const { t } = useTranslations()
    const { open, attentionCount, toggleTickets, triggerRef } = useTicketUI()
    return (
        <TicketLauncherButton
            attentionCount={attentionCount}
            isOpen={open}
            onClick={toggleTickets}
            buttonRef={triggerRef}
            ariaLabel={t("tickets.supportTickets")}
        />
    )
}

export function TicketProvider({ children }: { children: ReactNode }) {
    const { t } = useTranslations()
    const { user } = useAuth()
    const [open, setOpen] = useState(false)
    const [mounted, setMounted] = useState(false)
    const [attentionCount, setAttentionCount] = useState(0)
    const [view, setView] = useState<"list" | "create" | "detail">("list")
    const [tickets, setTickets] = useState<SupportTicket[]>([])
    const [selectedId, setSelectedId] = useState<string | null>(null)
    const [detail, setDetail] = useState<TicketDetail | null>(null)
    const [loading, setLoading] = useState(false)
    const [sending, setSending] = useState(false)
    const [error, setError] = useState<string | null>(null)
    const triggerRef = useRef<HTMLButtonElement>(null)
    const [panelStyle, setPanelStyle] = useState<{
        left: number
        bottom?: number
        top?: number
    } | null>(null)

    const [subject, setSubject] = useState("")
    const [category, setCategory] = useState<TicketCategory>("general")
    const [priority, setPriority] = useState<TicketPriority>("normal")
    const [createBody, setCreateBody] = useState("")
    const [replyBody, setReplyBody] = useState("")
    const [turnstileToken, setTurnstileToken] = useState("")
    const [turnstileKey, setTurnstileKey] = useState(0)
    const [turnstileSiteKey, setTurnstileSiteKey] = useState("")
    const turnstileRequired = isTurnstileConfigured(turnstileSiteKey)

    const toggleTickets = useCallback(() => {
        setOpen((value) => !value)
    }, [])

    const loadTickets = useCallback(async () => {
        if (!user) return
        const response = await fetch("/api/tickets")
        const data = await response.json()
        if (!response.ok) throw new Error(data.error || t("tickets.errors.loadFailed"))
        setTickets(data.tickets ?? [])
        setAttentionCount(data.attentionCount ?? 0)
    }, [user])

    const loadDetail = useCallback(async (ticketId: string) => {
        const response = await fetch(`/api/tickets/${ticketId}`)
        const data = await response.json()
        if (!response.ok) throw new Error(data.error || t("tickets.errors.loadTicketFailed"))
        setDetail(data.ticket)
    }, [])

    useEffect(() => {
        setMounted(true)
    }, [])

    useEffect(() => {
        void fetch("/api/turnstile/config")
            .then((response) => response.json())
            .then((data) => {
                if (data.siteKey) {
                    setTurnstileSiteKey(String(data.siteKey))
                }
            })
            .catch(() => undefined)
    }, [])

    useEffect(() => {
        if (!user) {
            setAttentionCount(0)
            return
        }

        const refreshAttention = () => {
            void fetch("/api/tickets")
                .then((res) => res.json())
                .then((data) => {
                    if (typeof data.attentionCount === "number") {
                        setAttentionCount(data.attentionCount)
                    }
                })
                .catch(() => undefined)
        }

        refreshAttention()
        const interval = window.setInterval(refreshAttention, 60_000)
        return () => window.clearInterval(interval)
    }, [user])

    useEffect(() => {
        if (!open || !user) return
        setLoading(true)
        setError(null)
        void loadTickets()
            .catch((err) => setError(err instanceof Error ? err.message : t("tickets.errors.loadFailed")))
            .finally(() => setLoading(false))
    }, [open, user, loadTickets])

    useEffect(() => {
        if (!open || view !== "detail" || !selectedId) return
        setLoading(true)
        setError(null)
        void loadDetail(selectedId)
            .catch((err) => setError(err instanceof Error ? err.message : t("tickets.errors.loadFailed")))
            .finally(() => setLoading(false))
    }, [open, view, selectedId, loadDetail])

    useLayoutEffect(() => {
        if (!open || !triggerRef.current) {
            setPanelStyle(null)
            return
        }

        const update = () => {
            const rect = triggerRef.current?.getBoundingClientRect()
            if (!rect) return
            const panelWidth = Math.min(420, window.innerWidth - 24)
            const left = Math.min(
                Math.max(12, rect.left + rect.width / 2 - panelWidth / 2),
                window.innerWidth - panelWidth - 12
            )
            const isDesktopTopNav = window.matchMedia("(min-width: 1024px)").matches
            setPanelStyle(
                isDesktopTopNav
                    ? {
                          left,
                          top: Math.max(12, rect.bottom + NAVBAR_GAP_PX),
                      }
                    : {
                          left,
                          bottom: window.innerHeight - rect.top + NAVBAR_GAP_PX,
                      }
            )
        }

        update()
        window.addEventListener("resize", update)
        return () => window.removeEventListener("resize", update)
    }, [open])

    async function handleCreate(event: React.FormEvent) {
        event.preventDefault()
        if (!user) return
        if (turnstileRequired && !turnstileToken) {
            setError(t("tickets.errors.captcha"))
            return
        }

        setSending(true)
        setError(null)
        try {
            const response = await fetch("/api/tickets", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    subject,
                    body: createBody,
                    category,
                    priority,
                    turnstileToken: turnstileToken || undefined,
                }),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || t("tickets.errors.createFailed"))
            setSubject("")
            setCreateBody("")
            setCategory("general")
            setPriority("normal")
            setTurnstileToken("")
            setTurnstileKey((key) => key + 1)
            setSelectedId(data.ticket.id)
            setDetail(data.ticket)
            setView("detail")
            await loadTickets()
        } catch (err) {
            setError(err instanceof Error ? err.message : t("tickets.errors.createFailed"))
            setTurnstileToken("")
            setTurnstileKey((key) => key + 1)
        } finally {
            setSending(false)
        }
    }

    async function handleReply(event: React.FormEvent) {
        event.preventDefault()
        if (!user || !selectedId || !replyBody.trim()) return
        setSending(true)
        setError(null)
        try {
            const response = await fetch(`/api/tickets/${selectedId}/messages`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ body: replyBody }),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || t("tickets.errors.replyFailed"))
            setReplyBody("")
            await loadDetail(selectedId)
            await loadTickets()
        } catch (err) {
            setError(err instanceof Error ? err.message : t("tickets.errors.replyFailed"))
        } finally {
            setSending(false)
        }
    }

    async function handleCloseTicket() {
        if (!selectedId) return
        setSending(true)
        setError(null)
        try {
            const response = await fetch(`/api/tickets/${selectedId}`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ status: "closed" }),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || t("tickets.errors.closeFailed"))
            setDetail(data.ticket)
            await loadTickets()
        } catch (err) {
            setError(err instanceof Error ? err.message : t("tickets.errors.closeFailed"))
        } finally {
            setSending(false)
        }
    }

    async function handleReopenTicket() {
        if (!selectedId) return
        setSending(true)
        setError(null)
        try {
            const response = await fetch(`/api/tickets/${selectedId}`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ status: "open" }),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || t("tickets.errors.reopenFailed"))
            setDetail(data.ticket)
            await loadTickets()
        } catch (err) {
            setError(err instanceof Error ? err.message : t("tickets.errors.reopenFailed"))
        } finally {
            setSending(false)
        }
    }

    const panel =
        open && panelStyle
            ? (
                <div
                    className="fixed z-[110] flex w-[min(420px,calc(100vw-24px))] flex-col overflow-hidden rounded-xl border border-border/60 bg-background/95 shadow-2xl backdrop-blur"
                    style={{
                        left: panelStyle.left,
                        ...(panelStyle.top != null
                            ? { top: panelStyle.top }
                            : { bottom: panelStyle.bottom }),
                    }}
                >
                    <div className="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3">
                        <div className="flex items-center gap-2 min-w-0">
                            {view !== "list" ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8 shrink-0"
                                    onClick={() => {
                                        setView("list")
                                        setSelectedId(null)
                                        setDetail(null)
                                        setError(null)
                                    }}
                                >
                                    <ArrowLeft className="size-4" />
                                </Button>
                            ) : (
                                <LifeBuoy className="size-4 shrink-0 text-primary" />
                            )}
                            <div className="min-w-0">
                                <p className="text-sm font-semibold leading-tight">
                                    {view === "create"
                                        ? t("tickets.newTicket")
                                        : view === "detail"
                                          ? detail?.ticketNumber ?? t("tickets.support")
                                          : t("tickets.supportTickets")}
                                </p>
                                <p className="text-xs text-muted-foreground line-clamp-1">
                                    {view === "detail"
                                        ? detail?.subject
                                        : t("tickets.subtitle")}
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 shrink-0"
                            onClick={() => setOpen(false)}
                        >
                            <X className="size-4" />
                        </Button>
                    </div>

                    {error ? (
                        <div className="flex items-start gap-2 border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-xs text-destructive">
                            <AlertCircle className="size-3.5 shrink-0 mt-0.5" />
                            <span>{error}</span>
                        </div>
                    ) : null}

                    {!user ? (
                        <div className="px-4 py-8 text-center text-sm text-muted-foreground">
                            <Link href="/login?from=/" className="text-primary underline">
                                {t("settings.signIn")}
                            </Link>{" "}
                            {t("tickets.signInSuffix")}
                        </div>
                    ) : view === "list" ? (
                        <div className="flex min-h-0 flex-1 flex-col">
                            <div className="flex items-center justify-between gap-2 px-4 py-3">
                                <p className="text-xs text-muted-foreground">
                                    {tickets.length === 1
                                        ? t("tickets.ticketCountOne", { count: tickets.length })
                                        : t("tickets.ticketCount", { count: tickets.length })}
                                </p>
                                <Button size="sm" onClick={() => setView("create")}>
                                    <Plus className="mr-1 size-3.5" />
                                    {t("tickets.newTicketButton")}
                                </Button>
                            </div>
                            <ScrollArea className="max-h-[min(420px,60vh)] px-2">
                                {loading ? (
                                    <div className="flex justify-center py-8">
                                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                                    </div>
                                ) : tickets.length === 0 ? (
                                    <p className="px-2 py-8 text-center text-sm text-muted-foreground">
                                        {t("tickets.empty")}
                                    </p>
                                ) : (
                                    <div className="space-y-2 pb-2">
                                        {tickets.map((ticket) => (
                                            <button
                                                key={ticket.id}
                                                type="button"
                                                onClick={() => {
                                                    setSelectedId(ticket.id)
                                                    setView("detail")
                                                }}
                                                className="w-full rounded-lg border border-border/50 bg-card/40 px-3 py-3 text-left transition hover:border-primary/40 hover:bg-card/70"
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium line-clamp-1">
                                                            {ticket.subject}
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {ticket.ticketNumber} ·{" "}
                                                            {getTicketCategoryLabel(t, ticket.category)}
                                                        </p>
                                                    </div>
                                                    <Badge variant={statusBadgeVariant(ticket.status)}>
                                                        {getTicketStatusLabel(t, ticket.status)}
                                                    </Badge>
                                                </div>
                                                <div className="mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                                                    <span className={priorityColor(ticket.priority)}>
                                                        {getTicketPriorityLabel(t, ticket.priority)}
                                                    </span>
                                                    <span>{formatTime(ticket.updatedAt)}</span>
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </ScrollArea>
                        </div>
                    ) : view === "create" ? (
                        <form className="space-y-3 px-4 py-4" onSubmit={handleCreate}>
                            <div className="space-y-2">
                                <Label htmlFor="ticket-subject">{t("tickets.subject")}</Label>
                                <Input
                                    id="ticket-subject"
                                    value={subject}
                                    onChange={(e) => setSubject(e.target.value)}
                                    maxLength={TICKET_MAX_SUBJECT_LENGTH}
                                    placeholder={t("tickets.subjectPlaceholder")}
                                    required
                                />
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-2">
                                    <Label>{t("tickets.category")}</Label>
                                    <Select
                                        value={category}
                                        onValueChange={(v) => setCategory(v as TicketCategory)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="z-[150]">
                                            {TICKET_CATEGORIES.map((item) => (
                                                <SelectItem key={item} value={item}>
                                                    {getTicketCategoryLabel(t, item)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label>{t("tickets.priority")}</Label>
                                    <Select
                                        value={priority}
                                        onValueChange={(v) => setPriority(v as TicketPriority)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="z-[150]">
                                            {TICKET_PRIORITIES.map((item) => (
                                                <SelectItem key={item} value={item}>
                                                    {getTicketPriorityLabel(t, item)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="ticket-body">{t("tickets.description")}</Label>
                                <Textarea
                                    id="ticket-body"
                                    value={createBody}
                                    onChange={(e) => setCreateBody(e.target.value)}
                                    maxLength={TICKET_MAX_BODY_LENGTH}
                                    rows={5}
                                    placeholder={t("tickets.messagePlaceholder")}
                                    required
                                />
                            </div>
                            {turnstileRequired ? (
                                <TurnstileWidget
                                    key={turnstileKey}
                                    siteKey={turnstileSiteKey}
                                    onToken={setTurnstileToken}
                                    onExpire={() => setTurnstileToken("")}
                                    onError={() => setTurnstileToken("")}
                                />
                            ) : null}
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={
                                    sending || (turnstileRequired && !turnstileToken)
                                }
                            >
                                {sending ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    t("tickets.submitTicketLabel")
                                )}
                            </Button>
                        </form>
                    ) : (
                        <div className="flex min-h-0 flex-1 flex-col">
                            {loading || !detail ? (
                                <div className="flex justify-center py-10">
                                    <Loader2 className="size-5 animate-spin text-muted-foreground" />
                                </div>
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-border/40">
                                        <Badge variant={statusBadgeVariant(detail.status)}>
                                            {getTicketStatusLabel(t, detail.status)}
                                        </Badge>
                                        <Badge variant="outline">
                                            {getTicketPriorityLabel(t, detail.priority)}
                                        </Badge>
                                        <Badge variant="outline">
                                            {getTicketCategoryLabel(t, detail.category)}
                                        </Badge>
                                        {detail.status === "closed" || detail.status === "resolved" ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => void handleReopenTicket()}
                                                disabled={sending}
                                            >
                                                {t("tickets.reopen")}
                                            </Button>
                                        ) : (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => void handleCloseTicket()}
                                                disabled={sending}
                                            >
                                                {t("tickets.close")}
                                            </Button>
                                        )}
                                    </div>
                                    <ScrollArea className="max-h-[min(320px,50vh)] px-4 py-3">
                                        <div className="space-y-3">
                                            {detail.messages.map((message: TicketMessage) => (
                                                <div
                                                    key={message.id}
                                                    className={cn(
                                                        "rounded-lg border px-3 py-2",
                                                        message.isStaffReply
                                                            ? "border-primary/30 bg-primary/5"
                                                            : "border-border/50 bg-muted/20"
                                                    )}
                                                >
                                                    <div className="flex items-center justify-between gap-2 text-[11px] text-muted-foreground">
                                                        <span className="font-medium text-foreground">
                                                            @{message.user.username}
                                                            {message.isStaffReply ? ` · ${t("tickets.staff")}` : ""}
                                                        </span>
                                                        <span>{formatTime(message.createdAt)}</span>
                                                    </div>
                                                    <p className="mt-1 text-sm whitespace-pre-wrap break-words">
                                                        {message.body}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </ScrollArea>
                                    {detail.status !== "closed" ? (
                                        <form
                                            className="flex items-end gap-2 border-t border-border/60 px-4 py-3"
                                            onSubmit={handleReply}
                                        >
                                            <Textarea
                                                value={replyBody}
                                                onChange={(e) => setReplyBody(e.target.value)}
                                                placeholder={t("tickets.replyPlaceholder")}
                                                rows={2}
                                                maxLength={TICKET_MAX_BODY_LENGTH}
                                                className="min-h-[2.5rem]"
                                            />
                                            <Button
                                                type="submit"
                                                size="icon"
                                                disabled={sending || !replyBody.trim()}
                                            >
                                                {sending ? (
                                                    <Loader2 className="size-4 animate-spin" />
                                                ) : (
                                                    <Send className="size-4" />
                                                )}
                                            </Button>
                                        </form>
                                    ) : null}
                                </>
                            )}
                        </div>
                    )}
                </div>
            )
            : null

    return (
        <TicketUIContext.Provider
            value={{ open, attentionCount, toggleTickets, triggerRef }}
        >
            {children}
            {mounted && panel ? createPortal(panel, document.body) : null}
        </TicketUIContext.Provider>
    )
}
