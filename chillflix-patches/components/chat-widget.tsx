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
    ChevronDown,
    CornerDownRight,
    Loader2,
    MessagesSquare,
    MoreHorizontal,
    Pencil,
    Send,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    X,
} from "lucide-react"


import { siteConfig } from "@/config/site"
import { ChatPollCard } from "@/components/chat-poll-card"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { useAuth } from "@/hooks/use-auth"
import { CHAT_MAX_BODY_LENGTH, type ChatMessage, type ChatMessagePreview } from "@/lib/chat-types"
import type { ChatSseEvent } from "@/lib/chat-sse-events"
import {
    applyMentionToDraft,
    getActiveMentionQuery,
    splitChatMessageParts,
    type MentionUser,
} from "@/lib/chat-mentions"
import { canAccessAdmin, roleLabelWithT } from "@/lib/permissions"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

function ChatMessageBody({ body }: { body: string }) {
    const parts = splitChatMessageParts(body)

    return (
        <p className="mt-0.5 whitespace-pre-wrap break-words text-sm text-foreground/90">
            {parts.map((part, index) =>
                part.type === "mention" ? (
                    <span key={index} className="font-semibold text-primary">
                        @{part.value}
                    </span>
                ) : (
                    <span key={index}>{part.value}</span>
                )
            )}
        </p>
    )
}

function initials(name: string) {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? "")
        .join("")
}

const CHAT_LAST_READ_KEY = "chillflix-chat-last-read"
const NAVBAR_GAP_PX = 12
/** Compact chat — fixed height; messages scroll inside. */
const CHAT_PANEL_HEIGHT_PX = 560

function formatTime(timestamp: number) {
    return new Date(timestamp).toLocaleTimeString(undefined, {
        hour: "numeric",
        minute: "2-digit",
    })
}

function readLastReadAt() {
    if (typeof window === "undefined") return 0
    const stored = window.localStorage.getItem(CHAT_LAST_READ_KEY)
    const parsed = stored ? Number(stored) : NaN
    return Number.isFinite(parsed) ? parsed : 0
}

function writeLastReadAt(timestamp: number) {
    if (typeof window === "undefined") return
    window.localStorage.setItem(CHAT_LAST_READ_KEY, String(timestamp))
}

function countUnreadFromOthers(
    items: ChatMessage[],
    lastReadAt: number,
    currentUserId?: string
) {
    return items.filter(
        (message) =>
            message.createdAt > lastReadAt &&
            (!currentUserId || message.user.id !== currentUserId)
    ).length
}

function formatUnreadCount(count: number) {
    if (count > 99) return "99+"
    return String(count)
}

function DiscordIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden
            className={className}
            fill="currentColor"
        >
            <path
                d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"
            />
        </svg>
    )
}

function DiscordCommunityLink({
    compact = false,
    className,
}: {
    compact?: boolean
    className?: string
}) {
    const { t } = useTranslations()

    if (compact) {
        return (
            <Button
                variant="outline"
                size="sm"
                className={cn("rounded-xl gap-2 border-border/50 bg-background/40", className)}
                asChild
            >
                <a
                    href={siteConfig.links.discord}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <span className="flex size-7 items-center justify-center rounded-lg bg-primary/15 text-primary ring-1 ring-primary/20">
                        <DiscordIcon className="size-4" />
                    </span>
                    {t("chat.joinDiscord")}
                </a>
            </Button>
        )
    }

    return (
        <a
            href={siteConfig.links.discord}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
                "group flex shrink-0 items-center gap-2.5 border-b border-border/60",
                "bg-gradient-to-b from-primary/8 to-transparent px-4 py-2.5 text-sm font-medium",
                "transition-colors hover:from-primary/12 hover:bg-muted/30",
                className
            )}
        >
            <span
                className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary ring-1 ring-primary/25 transition-colors group-hover:bg-primary/20"
            >
                <DiscordIcon className="size-[18px]" />
            </span>
            <span className="min-w-0 flex-1 leading-snug">
                <span className="block text-foreground">{t("chat.joinDiscord")}</span>
                <span className="block text-xs font-normal text-muted-foreground">
                    {t("chat.joinDiscordHint")}
                </span>
            </span>
            <ChevronDown
                className="size-4 shrink-0 -rotate-90 text-muted-foreground opacity-60 transition group-hover:translate-x-0.5 group-hover:opacity-100"
            />
        </a>
    )
}

type ChatUIContextValue = {
    open: boolean
    unreadCount: number
    chatEnabled: boolean
    preview: ChatMessagePreview | null
    toggleChat: () => void
    triggerRef: RefObject<HTMLButtonElement | null>
}

const ChatUIContext = createContext<ChatUIContextValue | null>(null)

function useChatUI() {
    const context = useContext(ChatUIContext)
    if (!context) {
        throw new Error("useChatUI must be used within ChatProvider")
    }
    return context
}

export function useChatEnabled() {
    const context = useContext(ChatUIContext)
    return context?.chatEnabled ?? false
}

function ChatLauncherButton({
    unreadCount,
    isOpen,
    preview,
    onClick,
    className,
    buttonRef,
}: {
    unreadCount: number
    isOpen: boolean
    preview: ChatMessagePreview | null
    onClick: () => void
    className?: string
    buttonRef?: RefObject<HTMLButtonElement | null>
}) {
    const { t } = useTranslations()
    const hasUnread = unreadCount > 0

    return (
        <div className={cn("relative inline-flex shrink-0 overflow-visible", className)}>
            {preview && !isOpen ? (
                <div
                    className={cn(
                        "pointer-events-none absolute bottom-full right-0 z-30 mb-2 w-[min(220px,calc(100vw-2rem))]",
                        "animate-in fade-in slide-in-from-bottom-2 duration-300"
                    )}
                >
                    <div className="rounded-2xl rounded-br-md bg-primary px-3 py-2 text-primary-foreground shadow-lg ring-1 ring-primary/20">
                        <p className="text-[11px] font-semibold opacity-90">{preview.userName}</p>
                        <p className="mt-0.5 line-clamp-2 text-xs leading-snug">{preview.body}</p>
                    </div>
                </div>
            ) : null}

            <div className="relative shrink-0">
                <div className="chat-launcher-snake shadow-md shadow-black/25">
                    <div className="chat-launcher-snake-glow" aria-hidden />
                    <button
                        ref={buttonRef}
                        type="button"
                        onClick={onClick}
                        aria-label={
                            isOpen
                                ? t("chat.closeChat")
                                : hasUnread
                                  ? t("chat.openChatUnread", { count: unreadCount })
                                  : t("chat.openChat")
                        }
                        aria-expanded={isOpen}
                        className={cn(
                            "group relative z-10 flex size-9 items-center justify-center rounded-none",
                            "bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/90",
                            "transition-all duration-300 hover:scale-[1.04]",
                            "active:scale-95 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary/35 focus-visible:ring-offset-0",
                            isOpen && "bg-muted/80"
                        )}
                    >
                        <MessagesSquare
                            className={cn(
                                "relative size-4 text-muted-foreground transition-colors duration-300",
                                "group-hover:text-foreground",
                                (hasUnread || isOpen) && "text-foreground"
                            )}
                            strokeWidth={2}
                        />
                    </button>
                </div>

                {hasUnread && !isOpen ? (
                    <span
                        className={cn(
                            "pointer-events-none absolute right-0 top-0 z-30 flex -translate-y-1/2 translate-x-1/2 items-center justify-center",
                            "rounded-full bg-destructive text-destructive-foreground shadow-sm ring-2 ring-background",
                            "animate-in zoom-in duration-200",
                            unreadCount > 9
                                ? "h-4 min-w-[1.125rem] px-1 text-[9px] font-bold leading-none"
                                : "size-4 text-[10px] font-bold leading-none"
                        )}
                    >
                        {formatUnreadCount(unreadCount)}
                    </span>
                ) : null}
            </div>
        </div>
    )
}

function ChatAvatar({ message }: { message: ChatMessage }) {
    const [failed, setFailed] = useState(false)
    const label = message.user.name || message.user.username

    if (message.user.avatarUrl && !failed) {
        return (
            // eslint-disable-next-line @next/next/no-img-element
            <img
                src={message.user.avatarUrl}
                alt={label}
                className="size-8 shrink-0 rounded-full object-cover ring-1 ring-border/60"
                onError={() => setFailed(true)}
            />
        )
    }

    return (
        <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xs font-semibold text-primary ring-1 ring-border/60">
            {initials(label) || "?"}
        </div>
    )
}

export function ChatNavButton() {
    const { open, unreadCount, chatEnabled, preview, toggleChat, triggerRef } = useChatUI()

    if (!chatEnabled) {
        return null
    }

    return (
        <ChatLauncherButton
            unreadCount={unreadCount}
            isOpen={open}
            preview={preview}
            onClick={toggleChat}
            className="shrink-0"
            buttonRef={triggerRef}
        />
    )
}

export function ChatProvider({ children }: { children: ReactNode }) {
    const { t } = useTranslations()
    const { user, loading: authLoading } = useAuth()
    const triggerRef = useRef<HTMLButtonElement | null>(null)
    const [open, setOpen] = useState(false)
    const [mounted, setMounted] = useState(false)
    const [panelStyle, setPanelStyle] = useState<{
        bottom: number
        right: number
        left?: number
    }>({
        bottom: 80,
        right: 16,
    })
    const [messages, setMessages] = useState<ChatMessage[]>([])
    const [loading, setLoading] = useState(false)
    const [sending, setSending] = useState(false)
    const [draft, setDraft] = useState("")
    const [error, setError] = useState("")
    const [editingId, setEditingId] = useState<string | null>(null)
    const [editDraft, setEditDraft] = useState("")
    const [menuId, setMenuId] = useState<string | null>(null)
    const [unreadCount, setUnreadCount] = useState(0)
    const [chatEnabled, setChatEnabled] = useState(true)
    const [liveSync, setLiveSync] = useState(false)
    // CHAT_UNREAD_BADGE_FIX: after user opens chat once, keep sync so new message badges still appear
    const [keepSync, setKeepSync] = useState(false)
    const [preview, setPreview] = useState<ChatMessagePreview | null>(null)
    const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
    const [pollVotingId, setPollVotingId] = useState<string | null>(null)
    const [mentionRange, setMentionRange] = useState<{
        start: number
        end: number
        query: string
    } | null>(null)
    const [mentionUsers, setMentionUsers] = useState<MentionUser[]>([])
    const bottomRef = useRef<HTMLDivElement>(null)
    const draftInputRef = useRef<HTMLInputElement>(null)
    const scrollRootRef = useRef<HTMLDivElement>(null)
    const lastReadAtRef = useRef(0)
    const latestMessageAtRef = useRef(0)
    const previewTimeoutRef = useRef<number | null>(null)
    const chatEngagedRef = useRef(false)
    const unreadCountRef = useRef(0)
    const openRef = useRef(false)
    const userIdRef = useRef<string | undefined>(undefined)

    const clearPreviewTimeout = useCallback(() => {
        if (previewTimeoutRef.current) {
            window.clearTimeout(previewTimeoutRef.current)
            previewTimeoutRef.current = null
        }
    }, [])

    const showPreviewFromLatest = useCallback(
        (preview: ChatMessagePreview | null) => {
            if (open || !preview) return

            setPreview({
                body: preview.body,
                userName: preview.userName,
                createdAt: preview.createdAt,
            })

            clearPreviewTimeout()
            previewTimeoutRef.current = window.setTimeout(() => {
                setPreview(null)
                previewTimeoutRef.current = null
            }, 6000)
        },
        [clearPreviewTimeout, open]
    )

    const showPreview = useCallback(
        (incoming: ChatMessage[]) => {
            if (open) return

            const fromOthers = incoming.filter((message) => message.user.id !== user?.id)
            const latest = fromOthers[fromOthers.length - 1]
            if (!latest) return

            showPreviewFromLatest({
                body: latest.body,
                userName: latest.user.name || latest.user.username,
                createdAt: latest.createdAt,
            })
        },
        [open, showPreviewFromLatest, user?.id]
    )

    const scrollToBottom = useCallback((behavior: ScrollBehavior = "smooth") => {
        const viewport = scrollRootRef.current
        if (viewport) {
            viewport.scrollTo({ top: viewport.scrollHeight, behavior })
            return
        }

        bottomRef.current?.scrollIntoView({ behavior })
    }, [])

    const scrollToMessage = useCallback((messageId: string) => {
        const target = scrollRootRef.current?.querySelector(
            `[data-message-id="${messageId}"]`
        )
        target?.scrollIntoView({ behavior: "smooth", block: "center" })
    }, [])

    const updatePanelPosition = useCallback(() => {
        const trigger = triggerRef.current
        if (!trigger) return

        const triggerRect = trigger.getBoundingClientRect()
        const isMobile = window.matchMedia("(max-width: 639px)").matches
        // Anchor above the chat launcher (navbar is fixed at bottom of screen).
        const bottom = Math.max(
            16,
            window.innerHeight - triggerRect.top + NAVBAR_GAP_PX
        )

        if (isMobile) {
            setPanelStyle({
                bottom,
                left: 16,
                right: 16,
            })
            return
        }

        setPanelStyle({
            bottom,
            right: Math.max(16, window.innerWidth - triggerRect.right),
        })
    }, [])

    const mergeMessages = useCallback((incoming: ChatMessage[]) => {
        if (incoming.length === 0) return

        setMessages((prev) => {
            const map = new Map(prev.map((message) => [message.id, message]))
            for (const message of incoming) {
                map.set(message.id, message)
            }
            return Array.from(map.values()).sort((a, b) => a.createdAt - b.createdAt)
        })
    }, [])

    const mergeMessagesPreservingReaction = useCallback((incoming: ChatMessage[]) => {
        if (incoming.length === 0) return

        setMessages((prev) => {
            const map = new Map(prev.map((message) => [message.id, message]))
            for (const message of incoming) {
                const existing = map.get(message.id)
                if (
                    existing &&
                    message.userReaction === null &&
                    existing.userReaction !== null
                ) {
                    map.set(message.id, { ...message, userReaction: existing.userReaction })
                } else {
                    map.set(message.id, message)
                }
            }
            return Array.from(map.values()).sort((a, b) => a.createdAt - b.createdAt)
        })
    }, [])

    const markAsRead = useCallback((items: ChatMessage[]) => {
        const latest = items.reduce((max, message) => Math.max(max, message.createdAt), 0)
        if (latest > 0) {
            lastReadAtRef.current = latest
            latestMessageAtRef.current = latest
            writeLastReadAt(latest)
        }
        setUnreadCount(0)
    }, [])

    const refreshUnreadCount = useCallback(
        async (options?: { since?: number; force?: boolean }) => {
            const since = options?.since ?? lastReadAtRef.current
            const params = new URLSearchParams()
            if (since > 0) {
                params.set("since", String(since))
            }

            const response = await fetch(`/api/chat/activity?${params.toString()}`)
            const data = await response.json()
            if (!response.ok) return []
            if (data.enabled === false) {
                setChatEnabled(false)
                return []
            }

            const latestMessageAt = Number(data.latestMessageAt ?? 0)
            if (latestMessageAt > 0) {
                latestMessageAtRef.current = Math.max(latestMessageAtRef.current, latestMessageAt)
            }

            const unreadCount = Number(data.unreadCount ?? 0)
            if (!open || options?.force) {
                setUnreadCount(unreadCount)
                if (unreadCount > 0 && data.latestPreview) {
                    showPreviewFromLatest(data.latestPreview as ChatMessagePreview)
                }
            }

            return []
        },
        [open, showPreviewFromLatest]
    )

    const loadMessages = useCallback(
        async (options?: { since?: number; initial?: boolean }) => {
            const params = new URLSearchParams({
                limit: options?.since ? "10" : "50",
            })
            if (options?.since) {
                params.set("since", String(options.since))
            }

            const response = await fetch(`/api/chat/messages?${params.toString()}`)
            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.error || t("chat.errors.loadFailed"))
            }

            if (data.enabled === false) {
                setChatEnabled(false)
                return
            }

            const incoming = Array.isArray(data.messages) ? (data.messages as ChatMessage[]) : []

            if (options?.initial) {
                setMessages(incoming)
                markAsRead(incoming)
                return
            }

            if (incoming.length > 0) {
                mergeMessages(incoming)
                const latest = incoming[incoming.length - 1]?.createdAt ?? 0
                latestMessageAtRef.current = Math.max(latestMessageAtRef.current, latest)

                if (open) {
                    markAsRead(incoming)
                } else {
                    setUnreadCount((prev) =>
                        prev +
                        countUnreadFromOthers(incoming, lastReadAtRef.current, user?.id)
                    )
                    showPreview(incoming)
                }
            }
        },
        [markAsRead, mergeMessages, open, user?.id, showPreview]
    )

    useEffect(() => {
        openRef.current = open
    }, [open])

    useEffect(() => {
        userIdRef.current = user?.id
    }, [user?.id])

    useEffect(() => {
        unreadCountRef.current = unreadCount
        if (unreadCount > 0) {
            chatEngagedRef.current = true
        }
    }, [unreadCount])

    useEffect(() => {
        setMounted(true)
        void fetch("/api/chat/status")
            .then((res) => res.json())
            .then((data) => setChatEnabled(Boolean(data.enabled)))
            .catch(() => setChatEnabled(true))
    }, [])

    useEffect(() => {
        if (!chatEnabled || !mounted) return
        // Restore last-read, then always paint the unread badge on load.
        lastReadAtRef.current = readLastReadAt()
        void refreshUnreadCount({ force: true }).catch(() => undefined)
    }, [chatEnabled, mounted, refreshUnreadCount])

    useEffect(() => {
        if (!chatEnabled || !mounted) return
        // Show badge numbers and keep receiving new-message events.
        setLiveSync(open || keepSync || unreadCount > 0)
    }, [chatEnabled, mounted, open, keepSync, unreadCount])

    useEffect(() => {
        if (!chatEnabled || !mounted || !liveSync) return

        let eventSource: EventSource | null = null
        let reconnectTimer: number | undefined
        let fallbackTimer: number | undefined
        let reconnectDelayMs = 2_000
        let sseConnected = false

        const stopFallbackPolling = () => {
            if (fallbackTimer) {
                window.clearInterval(fallbackTimer)
                fallbackTimer = undefined
            }
        }

        const startFallbackPolling = () => {
            if (fallbackTimer) return
            fallbackTimer = window.setInterval(() => {
                if (document.visibilityState !== "visible") return
                if (openRef.current) {
                    void loadMessages({ since: latestMessageAtRef.current }).catch(() => undefined)
                    return
                }
                void refreshUnreadCount({ since: lastReadAtRef.current }).catch(() => undefined)
            }, 90_000)
        }

        const applyActivitySummary = (activity: {
            unreadCount: number
            latestMessageAt: number
            latestPreview: ChatMessagePreview | null
        }) => {
            if (activity.latestMessageAt > 0) {
                latestMessageAtRef.current = Math.max(
                    latestMessageAtRef.current,
                    activity.latestMessageAt
                )
            }

            if (!openRef.current) {
                setUnreadCount(activity.unreadCount)
                if (activity.unreadCount > 0 && activity.latestPreview) {
                    showPreviewFromLatest(activity.latestPreview)
                }
            }
        }

        const handleIncomingMessage = (message: ChatMessage) => {
            latestMessageAtRef.current = Math.max(latestMessageAtRef.current, message.createdAt)

            if (openRef.current) {
                mergeMessagesPreservingReaction([message])
                lastReadAtRef.current = Math.max(lastReadAtRef.current, message.createdAt)
                writeLastReadAt(lastReadAtRef.current)
                setUnreadCount(0)
                return
            }

            const viewerId = userIdRef.current
            if (message.user.id !== viewerId && message.createdAt > lastReadAtRef.current) {
                chatEngagedRef.current = true
                setUnreadCount((prev) => prev + 1)
                showPreviewFromLatest({
                    body: message.body,
                    userName: message.user.name || message.user.username,
                    createdAt: message.createdAt,
                })
            }
        }

        const handleSseEvent = (event: ChatSseEvent) => {
            switch (event.type) {
                case "hello":
                    if (!event.enabled) {
                        setChatEnabled(false)
                        return
                    }
                    if (event.activity) {
                        applyActivitySummary(event.activity)
                    }
                    break
                case "message":
                    handleIncomingMessage(event.message)
                    break
                case "message_updated":
                    mergeMessagesPreservingReaction([event.message])
                    break
                case "message_deleted":
                    setMessages((prev) => prev.filter((message) => message.id !== event.id))
                    break
                case "chat_cleared":
                    setMessages([])
                    setUnreadCount(0)
                    setPreview(null)
                    clearPreviewTimeout()
                    break
                case "ping":
                    break
            }
        }

        const connect = () => {
            if (eventSource) {
                eventSource.close()
                eventSource = null
            }

            const since = lastReadAtRef.current
            const url =
                since > 0 ? `/api/chat/stream?since=${encodeURIComponent(String(since))}` : "/api/chat/stream"

            eventSource = new EventSource(url)

            eventSource.onopen = () => {
                sseConnected = true
                reconnectDelayMs = 2_000
                stopFallbackPolling()
            }

            eventSource.onmessage = (messageEvent) => {
                try {
                    handleSseEvent(JSON.parse(messageEvent.data) as ChatSseEvent)
                } catch {
                    // Ignore malformed SSE payloads.
                }
            }

            eventSource.onerror = () => {
                sseConnected = false
                eventSource?.close()
                eventSource = null
                startFallbackPolling()
                if (reconnectTimer) {
                    window.clearTimeout(reconnectTimer)
                }
                reconnectTimer = window.setTimeout(() => {
                    if (document.visibilityState === "visible") {
                        connect()
                    }
                }, reconnectDelayMs)
                reconnectDelayMs = Math.min(Math.round(reconnectDelayMs * 1.5), 30_000)
            }
        }

        const onVisibilityChange = () => {
            if (document.visibilityState === "hidden") {
                sseConnected = false
                eventSource?.close()
                eventSource = null
                startFallbackPolling()
                return
            }

            if (!sseConnected) {
                connect()
            }
        }

        connect()
        document.addEventListener("visibilitychange", onVisibilityChange)

        return () => {
            eventSource?.close()
            if (reconnectTimer) window.clearTimeout(reconnectTimer)
            stopFallbackPolling()
            document.removeEventListener("visibilitychange", onVisibilityChange)
        }
    }, [
        chatEnabled,
        liveSync,
        mounted,
        loadMessages,
        mergeMessagesPreservingReaction,
        refreshUnreadCount,
        showPreviewFromLatest,
    ])

    useLayoutEffect(() => {
        if (!open || !chatEnabled) return
        updatePanelPosition()
        window.addEventListener("resize", updatePanelPosition)
        window.addEventListener("scroll", updatePanelPosition, true)
        return () => {
            window.removeEventListener("resize", updatePanelPosition)
            window.removeEventListener("scroll", updatePanelPosition, true)
        }
    }, [open, chatEnabled, updatePanelPosition])

    useEffect(() => {
        if (!open) return

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === "Escape") {
                setOpen(false)
                setMenuId(null)
                setEditingId(null)
            }
        }

        window.addEventListener("keydown", onKeyDown)
        return () => window.removeEventListener("keydown", onKeyDown)
    }, [open])

    useEffect(() => {
        if (!open || !chatEnabled) return

        setLoading(true)
        setError("")
        loadMessages({ initial: true })
            .catch((err) => setError(err instanceof Error ? err.message : t("chat.errors.loadFailed")))
            .finally(() => setLoading(false))
    }, [open, chatEnabled, loadMessages])

    useEffect(() => {
        if (!open) return
        scrollToBottom(messages.length > 1 ? "smooth" : "auto")
    }, [open, messages.length, scrollToBottom])

    useEffect(() => {
        if (!mentionRange) {
            setMentionUsers([])
            return
        }

        const timer = window.setTimeout(() => {
            void fetch(
                `/api/chat/mention-suggestions?q=${encodeURIComponent(mentionRange.query)}`
            )
                .then((response) => response.json())
                .then((data) => {
                    setMentionUsers(Array.isArray(data.users) ? data.users : [])
                })
                .catch(() => setMentionUsers([]))
        }, 120)

        return () => window.clearTimeout(timer)
    }, [mentionRange])

    const handleOpen = () => {
        chatEngagedRef.current = true
        setKeepSync(true)
        setOpen(true)
        setUnreadCount(0)
        setPreview(null)
        clearPreviewTimeout()
    }

    const handleClose = () => {
        setOpen(false)
        setMenuId(null)
        setEditingId(null)
        setReplyTo(null)
        setMentionRange(null)
        void refreshUnreadCount({ force: true }).catch(() => undefined)
    }


    const votePoll = useCallback(
        async (pollId: string, optionIds: string[]) => {
            if (!user) return
            setPollVotingId(pollId)
            setError(null)
            try {
                const response = await fetch(`/api/chat/polls/${pollId}/vote`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ optionIds }),
                })
                const data = await response.json()
                if (!response.ok) {
                    throw new Error(data.error || t("chat.poll.voteFailed"))
                }
                mergeMessages([data.message as ChatMessage])
            } catch (err) {
                setError(err instanceof Error ? err.message : t("chat.poll.voteFailed"))
            } finally {
                setPollVotingId(null)
            }
        },
        [mergeMessages, t, user]
    )

    const startReply = (message: ChatMessage) => {
        setReplyTo(message)
        setMenuId(null)
        draftInputRef.current?.focus()
    }

    const handleDraftChange = (value: string, cursor: number) => {
        setDraft(value)
        setMentionRange(getActiveMentionQuery(value, cursor))
    }

    const selectMention = (username: string) => {
        if (!mentionRange) return
        const next = applyMentionToDraft(draft, mentionRange.start, mentionRange.end, username)
        setDraft(next)
        setMentionRange(null)
        setMentionUsers([])
        draftInputRef.current?.focus()
    }

    const toggleChat = () => {
        if (open) {
            handleClose()
            return
        }
        handleOpen()
    }

    const sendMessage = async () => {
        const body = draft.trim()
        if (!body || sending) return

        setSending(true)
        setError("")

        try {
            const response = await fetch("/api/chat/messages", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    body,
                    replyToMessageId: replyTo?.id,
                }),
            })
            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.error || t("chat.errors.sendFailed"))
            }

            if (data.message) {
                const message = data.message as ChatMessage
                mergeMessages([message])
                if (open) {
                    lastReadAtRef.current = Math.max(lastReadAtRef.current, message.createdAt)
                    latestMessageAtRef.current = Math.max(latestMessageAtRef.current, message.createdAt)
                    writeLastReadAt(lastReadAtRef.current)
                }
            }
            setDraft("")
            setReplyTo(null)
            setMentionRange(null)
            scrollToBottom()
        } catch (err) {
            setError(err instanceof Error ? err.message : t("chat.errors.sendFailed"))
        } finally {
            setSending(false)
        }
    }

    const saveEdit = async (messageId: string) => {
        const body = editDraft.trim()
        if (!body) return

        setSending(true)
        setError("")

        try {
            const response = await fetch(`/api/chat/messages/${messageId}`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ body }),
            })
            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.error || t("chat.errors.editFailed"))
            }

            if (data.message) {
                mergeMessages([data.message as ChatMessage])
            }
            setEditingId(null)
            setEditDraft("")
            setMenuId(null)
        } catch (err) {
            setError(err instanceof Error ? err.message : t("chat.errors.editFailed"))
        } finally {
            setSending(false)
        }
    }

    const toggleReaction = async (messageId: string, reaction: "like" | "dislike") => {
        if (!user) return

        setError("")
        try {
            const response = await fetch(`/api/chat/messages/${messageId}/reaction`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ reaction }),
            })
            const data = await response.json()
            if (!response.ok) {
                throw new Error(data.error || t("chat.errors.reactionFailed"))
            }
            if (data.message) {
                mergeMessages([data.message as ChatMessage])
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : t("chat.errors.reactionFailed"))
        }
    }

    const removeMessage = async (messageId: string) => {
        setSending(true)
        setError("")

        try {
            const response = await fetch(`/api/chat/messages/${messageId}`, {
                method: "DELETE",
            })
            const data = await response.json()

            if (!response.ok) {
                throw new Error(data.error || t("chat.errors.deleteFailed"))
            }

            setMessages((prev) => prev.filter((message) => message.id !== messageId))
            setMenuId(null)
            setEditingId(null)
        } catch (err) {
            setError(err instanceof Error ? err.message : t("chat.errors.deleteFailed"))
        } finally {
            setSending(false)
        }
    }

    const canModerate = user ? canAccessAdmin(user.role) : false

    const chatPanel = open && chatEnabled ? (
        <>
            <button
                type="button"
                aria-label={t("chat.closeChat")}
                className="fixed inset-0 z-[110]"
                onClick={handleClose}
            />
            <div
                role="dialog"
                aria-label={t("chat.communityChat")}
                className={cn(
                    "fixed z-[140] flex w-[min(100vw-1.25rem,28rem)] flex-col overflow-hidden rounded-2xl border border-border/60",
                    "bg-background/95 shadow-2xl shadow-black/30 backdrop-blur supports-[backdrop-filter]:bg-background/90",
                    "animate-in slide-in-from-bottom-4 fade-in duration-200"
                )}
                style={{
                    bottom: panelStyle.bottom,
                    height: CHAT_PANEL_HEIGHT_PX,
                    maxHeight: `min(${CHAT_PANEL_HEIGHT_PX}px, calc(100dvh - 5rem))`,
                    ...(panelStyle.left != null
                        ? { left: panelStyle.left, right: panelStyle.right }
                        : { right: panelStyle.right }),
                }}
            >
                <div className="flex shrink-0 items-center justify-between border-b border-border/60 px-4 py-3">
                    <div>
                        <p className="font-semibold">{t("chat.communityChat")}</p>
                        <p className="text-xs text-muted-foreground">
                            {user
                                ? t("chat.loggedInAs", { name: user.name || user.username })
                                : t("chat.anyoneCanRead")}
                        </p>
                    </div>
                    <Button variant="ghost" size="icon" className="size-8" onClick={handleClose}>
                        <ChevronDown className="size-4" />
                    </Button>
                </div>

                <DiscordCommunityLink />

                <div
                    ref={scrollRootRef}
                    className="custom-scrollbar min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3 touch-pan-y"
                    style={{ WebkitOverflowScrolling: "touch" }}
                >
                    {loading ? (
                        <div className="flex items-center justify-center py-10 text-muted-foreground">
                            <Loader2 className="mr-2 size-4 animate-spin" />
                            {t("chat.loadingChat")}
                        </div>
                    ) : messages.length === 0 ? (
                        <div className="space-y-3 py-6 text-center">
                            <p className="text-sm text-muted-foreground">
                                {t("chat.noMessages")}
                            </p>
                            <DiscordCommunityLink compact className="mx-auto w-fit" />
                        </div>
                    ) : (
                        <div className="space-y-3 pr-2">
                            {messages.map((message) => {
                                const isOwn = user?.id === message.user.id
                                const showModActions = canModerate && !isOwn
                                const showOwnActions = isOwn
                                const isEditing = editingId === message.id

                                return (
                                    <div
                                        key={message.id}
                                        data-message-id={message.id}
                                        className="group relative flex gap-2 rounded-lg px-1 py-1 hover:bg-muted/30"
                                    >
                                        <ChatAvatar message={message} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                <span className="text-sm font-medium">
                                                    {message.user.name || message.user.username}
                                                </span>
                                                {canAccessAdmin(message.user.role) ? (
                                                    <span className="rounded-full bg-primary/15 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-primary">
                                                        {roleLabelWithT(t, message.user.role)}
                                                    </span>
                                                ) : null}
                                                <span className="text-[11px] text-muted-foreground">
                                                    {formatTime(message.createdAt)}
                                                    {message.editedAt ? t("chat.edited") : ""}
                                                </span>
                                            </div>

                                            {isEditing ? (
                                                <div className="mt-1 space-y-2">
                                                    <Input
                                                        value={editDraft}
                                                        maxLength={CHAT_MAX_BODY_LENGTH}
                                                        onChange={(event) =>
                                                            setEditDraft(event.target.value)
                                                        }
                                                        onKeyDown={(event) => {
                                                            if (event.key === "Enter") {
                                                                event.preventDefault()
                                                                void saveEdit(message.id)
                                                            }
                                                            if (event.key === "Escape") {
                                                                setEditingId(null)
                                                            }
                                                        }}
                                                    />
                                                    <div className="flex gap-2">
                                                        <Button
                                                            size="sm"
                                                            onClick={() => void saveEdit(message.id)}
                                                            disabled={sending}
                                                        >
                                                            {t("chat.save")}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => setEditingId(null)}
                                                        >
                                                            {t("chat.cancel")}
                                                        </Button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <>
                                                    {message.replyTo ? (
                                                        <button
                                                            type="button"
                                                            className="mt-1 flex w-full items-start gap-1.5 rounded-md border border-border/50 bg-muted/40 px-2 py-1.5 text-left transition-colors hover:bg-muted/60"
                                                            onClick={() =>
                                                                scrollToMessage(message.replyTo!.id)
                                                            }
                                                        >
                                                            <CornerDownRight
                                                                className="mt-0.5 size-3 shrink-0 text-muted-foreground"
                                                            />
                                                            <div className="min-w-0">
                                                                <p className="text-[11px] font-medium text-muted-foreground">
                                                                    {message.replyTo.userName}
                                                                </p>
                                                                <p className="line-clamp-2 text-xs text-foreground/80">
                                                                    {message.replyTo.body}
                                                                </p>
                                                            </div>
                                                        </button>
                                                    ) : null}
                                                    {message.messageType === "poll" && message.poll ? (
                                                        <ChatPollCard
                                                            poll={message.poll}
                                                            canVote={Boolean(user)}
                                                            voting={pollVotingId === message.poll.id}
                                                            onVote={(optionIds) =>
                                                                void votePoll(message.poll!.id, optionIds)
                                                            }
                                                        />
                                                    ) : (
                                                        <ChatMessageBody body={message.body} />
                                                    )}
                                                    {user && message.messageType !== "poll" ? (
                                                        <div className="mt-1.5 flex items-center gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 gap-1 px-2 text-xs"
                                                                onClick={() => startReply(message)}
                                                            >
                                                                <CornerDownRight className="size-3.5" />
                                                                {t("chat.reply")}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className={cn(
                                                                    "h-7 gap-1 px-2 text-xs",
                                                                    message.userReaction === "like" &&
                                                                        "bg-primary/15 text-primary"
                                                                )}
                                                                onClick={() =>
                                                                    void toggleReaction(message.id, "like")
                                                                }
                                                            >
                                                                <ThumbsUp className="size-3.5" />
                                                                {message.likeCount > 0
                                                                    ? message.likeCount
                                                                    : null}
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className={cn(
                                                                    "h-7 gap-1 px-2 text-xs",
                                                                    message.userReaction === "dislike" &&
                                                                        "bg-destructive/15 text-destructive"
                                                                )}
                                                                onClick={() =>
                                                                    void toggleReaction(
                                                                        message.id,
                                                                        "dislike"
                                                                    )
                                                                }
                                                            >
                                                                <ThumbsDown className="size-3.5" />
                                                                {message.dislikeCount > 0
                                                                    ? message.dislikeCount
                                                                    : null}
                                                            </Button>
                                                        </div>
                                                    ) : (
                                                        <div className="mt-1 flex items-center gap-2 text-[11px] text-muted-foreground">
                                                            {message.likeCount > 0 ? (
                                                                <span>👍 {message.likeCount}</span>
                                                            ) : null}
                                                            {message.dislikeCount > 0 ? (
                                                                <span>👎 {message.dislikeCount}</span>
                                                            ) : null}
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                        </div>

                                        {!isEditing && (showOwnActions || showModActions) ? (
                                            <div className="relative shrink-0">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-7 opacity-0 transition-opacity group-hover:opacity-100 data-[state=open]:opacity-100"
                                                    data-state={menuId === message.id ? "open" : "closed"}
                                                    onClick={() =>
                                                        setMenuId((current) =>
                                                            current === message.id ? null : message.id
                                                        )
                                                    }
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                                {menuId === message.id ? (
                                                    <div className="absolute right-0 top-8 z-10 min-w-[8rem] rounded-lg border bg-popover p-1 shadow-lg">
                                                        {showOwnActions && message.messageType !== "poll" ? (
                                                            <button
                                                                type="button"
                                                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted"
                                                                onClick={() => {
                                                                    setEditingId(message.id)
                                                                    setEditDraft(message.body)
                                                                    setMenuId(null)
                                                                }}
                                                            >
                                                                <Pencil className="size-3.5" />
                                                                {t("chat.edit")}
                                                            </button>
                                                        ) : null}
                                                        <button
                                                            type="button"
                                                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-destructive hover:bg-destructive/10"
                                                            onClick={() => void removeMessage(message.id)}
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                            {showModActions ? t("chat.remove") : t("chat.delete")}
                                                        </button>
                                                    </div>
                                                ) : null}
                                            </div>
                                        ) : null}
                                    </div>
                                )
                            })}
                            <div ref={bottomRef} />
                        </div>
                    )}
                </div>

                <div className="shrink-0 border-t border-border/60 p-3">
                    {error ? (
                        <p className="mb-2 text-xs text-destructive">{error}</p>
                    ) : null}

                    {authLoading ? (
                        <p className="text-center text-sm text-muted-foreground">{t("chat.loading")}</p>
                    ) : user ? (
                        <form
                            className="relative space-y-2"
                            onSubmit={(event) => {
                                event.preventDefault()
                                void sendMessage()
                            }}
                        >
                            {replyTo ? (
                                <div className="flex items-start gap-2 rounded-lg border border-border/50 bg-muted/40 px-2 py-1.5">
                                    <CornerDownRight className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[11px] font-medium text-muted-foreground">
                                            {t("chat.replyingTo", {
                                                name: replyTo.user.name || replyTo.user.username,
                                            })}
                                        </p>
                                        <p className="line-clamp-2 text-xs text-foreground/80">
                                            {replyTo.body}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-6 shrink-0"
                                        onClick={() => setReplyTo(null)}
                                        aria-label={t("chat.cancelReply")}
                                    >
                                        <X className="size-3.5" />
                                    </Button>
                                </div>
                            ) : null}

                            {mentionUsers.length > 0 && mentionRange ? (
                                <div
                                    className="absolute bottom-full left-0 right-10 z-20 mb-1 overflow-hidden rounded-lg border border-border/60 bg-popover shadow-lg"
                                >
                                    {mentionUsers.map((mentionUser) => (
                                        <button
                                            key={mentionUser.id}
                                            type="button"
                                            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted"
                                            onMouseDown={(event) => {
                                                event.preventDefault()
                                                selectMention(mentionUser.username)
                                            }}
                                        >
                                            <span className="font-medium">
                                                {mentionUser.name || mentionUser.username}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                @{mentionUser.username}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            ) : null}

                            <div className="flex items-center gap-2">
                            <Input
                                ref={draftInputRef}
                                value={draft}
                                onChange={(event) =>
                                    handleDraftChange(
                                        event.target.value,
                                        event.target.selectionStart ?? event.target.value.length
                                    )
                                }
                                onClick={(event) =>
                                    handleDraftChange(
                                        draft,
                                        event.currentTarget.selectionStart ??
                                            draft.length
                                    )
                                }
                                onKeyUp={(event) =>
                                    handleDraftChange(
                                        draft,
                                        event.currentTarget.selectionStart ?? draft.length
                                    )
                                }
                                placeholder={t("chat.placeholder")}
                                maxLength={CHAT_MAX_BODY_LENGTH}
                                disabled={sending}
                            />
                            <Button type="submit" size="icon" disabled={sending || !draft.trim()}>
                                {sending ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Send className="size-4" />
                                )}
                            </Button>
                            </div>
                        </form>
                    ) : (
                        <p className="text-center text-sm text-muted-foreground">
                            <Link href="/login?from=/" className="text-primary underline">
                                {t("chat.signInLink")}
                            </Link>{" "}
                            {t("chat.signInSuffix")}
                        </p>
                    )}
                </div>
            </div>
        </>
    ) : null

    return (
        <ChatUIContext.Provider
            value={{ open, unreadCount, chatEnabled, preview, toggleChat, triggerRef }}
        >
            {children}
            {mounted && chatPanel ? createPortal(chatPanel, document.body) : null}
        </ChatUIContext.Provider>
    )
}

/** @deprecated Use ChatProvider + ChatNavButton */
export function ChatWidget() {
    return <ChatProvider>{null}</ChatProvider>
}
