"use client"

import { useCallback, useEffect, useState } from "react"

import { Button } from "@/components/ui/button"
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog"
import { useSiteFeatures } from "@/components/site-features"
import { useTranslations } from "@/lib/i18n/client"

const COMMUNITY_PROMPT_STORAGE_KEY = "chillflix:community-prompt-session"
const COMMUNITY_PROMPT_DELAY_MS = 5000
const SHARE_PROMPT_OPEN_SELECTOR = '[data-chillflix-share-prompt="1"]'

const EXCLUDED_PREFIXES = [
    "/admin",
    "/embed",
    "/login",
    "/register",
    "/maintenance",
    "/player-api",
]

function DiscordIcon({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden className={className} fill="currentColor">
            <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
        </svg>
    )
}

function TelegramIcon({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden className={className} fill="currentColor">
            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />
        </svg>
    )
}

function isExcludedPath(pathname: string) {
    return EXCLUDED_PREFIXES.some((prefix) => pathname.startsWith(prefix))
}

function wasShownThisSession() {
    try {
        return sessionStorage.getItem(COMMUNITY_PROMPT_STORAGE_KEY) === "1"
    } catch {
        return true
    }
}

function markCommunityPromptShown() {
    try {
        sessionStorage.setItem(COMMUNITY_PROMPT_STORAGE_KEY, "1")
    } catch {
        // ignore
    }
}

function isSharePromptOpen() {
    try {
        return Boolean(document.querySelector(SHARE_PROMPT_OPEN_SELECTOR))
    } catch {
        return false
    }
}

export function CommunityInvitePrompt() {
    const { t } = useTranslations()
    const { communityPromptEnabled, discordInviteUrl, telegramInviteUrl } = useSiteFeatures()
    const [open, setOpen] = useState(false)

    const hasDiscord = Boolean(discordInviteUrl)
    const hasTelegram = Boolean(telegramInviteUrl)
    const canShow = communityPromptEnabled && (hasDiscord || hasTelegram)

    useEffect(() => {
        if (!canShow) return

        let cancelled = false
        let retryTimer: number | undefined

        const tryOpen = () => {
            if (cancelled) return
            if (isExcludedPath(window.location.pathname)) return
            if (wasShownThisSession()) return
            // Wait until the daily share dialog is gone so this one is visible.
            if (isSharePromptOpen()) {
                retryTimer = window.setTimeout(tryOpen, 1000)
                return
            }
            setOpen(true)
        }

        const timer = window.setTimeout(tryOpen, COMMUNITY_PROMPT_DELAY_MS)

        return () => {
            cancelled = true
            window.clearTimeout(timer)
            window.clearTimeout(retryTimer)
        }
    }, [canShow])

    const closePrompt = useCallback(() => {
        markCommunityPromptShown()
        setOpen(false)
    }, [])

    if (!canShow) return null

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && open) closePrompt()
            }}
        >
            <DialogContent
                className="max-w-md rounded-2xl border-border/50 bg-card/95 sm:max-w-md"
                data-chillflix-community-prompt="1"
            >
                <DialogHeader>
                    <DialogTitle>{t("communityPrompt.title")}</DialogTitle>
                    <DialogDescription>{t("communityPrompt.description")}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-2">
                    {hasDiscord ? (
                        <Button
                            variant="outline"
                            className="h-auto justify-start gap-3 rounded-xl px-3 py-3"
                            asChild
                        >
                            <a
                                href={discordInviteUrl!}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => closePrompt()}
                            >
                                <span className="flex size-9 items-center justify-center rounded-lg bg-[#5865F2]/15 text-[#5865F2]">
                                    <DiscordIcon className="size-5" />
                                </span>
                                <span className="text-left">
                                    <span className="block text-sm font-medium">
                                        {t("communityPrompt.discord")}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {t("communityPrompt.discordHint")}
                                    </span>
                                </span>
                            </a>
                        </Button>
                    ) : null}
                    {hasTelegram ? (
                        <Button
                            variant="outline"
                            className="h-auto justify-start gap-3 rounded-xl px-3 py-3"
                            asChild
                        >
                            <a
                                href={telegramInviteUrl!}
                                target="_blank"
                                rel="noopener noreferrer"
                                onClick={() => closePrompt()}
                            >
                                <span className="flex size-9 items-center justify-center rounded-lg bg-[#2AABEE]/15 text-[#2AABEE]">
                                    <TelegramIcon className="size-5" />
                                </span>
                                <span className="text-left">
                                    <span className="block text-sm font-medium">
                                        {t("communityPrompt.telegram")}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {t("communityPrompt.telegramHint")}
                                    </span>
                                </span>
                            </a>
                        </Button>
                    ) : null}
                </div>

                <DialogFooter className="sm:justify-stretch">
                    <Button
                        variant="ghost"
                        className="w-full rounded-xl text-muted-foreground"
                        onClick={closePrompt}
                    >
                        {t("communityPrompt.notNow")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    )
}
