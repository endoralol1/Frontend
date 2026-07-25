"use client"

import { useCallback, useEffect, useState } from "react"
import {
    Facebook,
    Link2,
    MessageCircle,
    Send,
    Share2,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog"
import { buildShareUrl, SHARE_PLATFORMS } from "@/lib/share-links"
import { useToast } from "@/components/ui/use-toast"
import { useSiteFeatures } from "@/components/site-features"
import { isAndroidBrowser } from "@/components/apk-download-prompt"
import { useTranslations } from "@/lib/i18n/client"
import { isLikelyTvBrowser } from "@/lib/device-profile"
import { isBrowseListPath } from "@/lib/list-page-paths"

const SITE_SHARE_PLATFORMS = SHARE_PLATFORMS.filter(
    (platform) => platform !== "reddit" && platform !== "linkedin" && platform !== "email"
)

const SHARE_PROMPT_STORAGE_KEY = "chillflix:share-prompt-day"
const SHARE_PROMPT_DELAY_MS = 2500

const EXCLUDED_PREFIXES = [
    "/admin",
    "/embed",
    "/login",
    "/register",
    "/maintenance",
    "/player-api",
]

function getLocalDayKey() {
    const now = new Date()
    const year = now.getFullYear()
    const month = String(now.getMonth() + 1).padStart(2, "0")
    const day = String(now.getDate()).padStart(2, "0")
    return `${year}-${month}-${day}`
}

function shouldShowSharePrompt(pathname: string) {
    if (EXCLUDED_PREFIXES.some((prefix) => pathname.startsWith(prefix))) {
        return false
    }

    if (isBrowseListPath(pathname)) {
        return false
    }

    try {
        return localStorage.getItem(SHARE_PROMPT_STORAGE_KEY) !== getLocalDayKey()
    } catch {
        return false
    }
}

function markSharePromptShown() {
    try {
        localStorage.setItem(SHARE_PROMPT_STORAGE_KEY, getLocalDayKey())
    } catch {
        // ignore
    }
}

type ShareButton = {
    id: string
    label: string
    icon: typeof Facebook
    href: string
}

const SITE_PLATFORM_ICONS: Record<string, typeof Facebook> = {
    facebook: Facebook,
    whatsapp: MessageCircle,
    twitter: Send,
    telegram: Send,
}

const SITE_PLATFORM_LABEL_KEYS: Record<string, string> = {
    facebook: "sharePrompt.facebook",
    whatsapp: "sharePrompt.whatsapp",
    twitter: "sharePrompt.twitter",
    telegram: "sharePrompt.telegram",
}

export function ShareSitePrompt() {
    const { t } = useTranslations()
    const { toast } = useToast()
    const { sharePromptEnabled, apkDownloadEnabled, apkDownloadUrl } = useSiteFeatures()
    const [open, setOpen] = useState(false)
    const [hasNativeShare, setHasNativeShare] = useState(false)

    useEffect(() => {
        setHasNativeShare(typeof navigator !== "undefined" && "share" in navigator)
    }, [])

    useEffect(() => {
        if (!sharePromptEnabled || isLikelyTvBrowser()) return
        if (isAndroidBrowser() && apkDownloadEnabled && apkDownloadUrl) return

        const pathname = window.location.pathname
        if (!shouldShowSharePrompt(pathname)) return

        const timer = window.setTimeout(() => {
            if (shouldShowSharePrompt(window.location.pathname)) {
                setOpen(true)
            }
        }, SHARE_PROMPT_DELAY_MS)

        return () => window.clearTimeout(timer)
    }, [sharePromptEnabled, apkDownloadEnabled, apkDownloadUrl])

    const closePrompt = useCallback(() => {
        markSharePromptShown()
        setOpen(false)
    }, [])

    const [siteUrl, setSiteUrl] = useState("")
    useEffect(() => {
        setSiteUrl(window.location.origin)
    }, [])
    const shareText = t("site.shareDescription")

    const shareButtons: ShareButton[] = SITE_SHARE_PLATFORMS.map((platform) => ({
        id: platform,
        label: t(SITE_PLATFORM_LABEL_KEYS[platform]),
        icon: SITE_PLATFORM_ICONS[platform],
        href: buildShareUrl(platform, siteUrl, t("site.shareTitle"), shareText),
    }))

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(siteUrl)
            toast({ title: t("sharePrompt.copied") })
        } catch {
            toast({ title: t("sharePrompt.copyFailed"), variant: "destructive" })
        }
    }

    const tryNativeShare = async () => {
        if (!navigator.share) return false
        try {
            await navigator.share({
                title: t("site.shareTitle"),
                text: shareText,
                url: siteUrl,
            })
            closePrompt()
            return true
        } catch {
            return false
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) closePrompt()
                else setOpen(true)
            }}
        >
            <DialogContent className="max-w-md rounded-2xl border-border/50 bg-card/95 sm:max-w-md" data-chillflix-share-prompt="1">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Share2 className="size-5 text-primary" />
                        {t("sharePrompt.title")}
                    </DialogTitle>
                    <DialogDescription>{t("sharePrompt.description")}</DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-2 gap-2">
                    {shareButtons.map((button) => {
                        const Icon = button.icon
                        return (
                            <Button
                                key={button.id}
                                variant="outline"
                                className="h-auto justify-start gap-2 rounded-xl px-3 py-2.5"
                                asChild
                            >
                                <a
                                    href={button.href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={() => closePrompt()}
                                >
                                    <Icon className="size-4 shrink-0" />
                                    <span className="text-sm">{button.label}</span>
                                </a>
                            </Button>
                        )
                    })}
                </div>

                <DialogFooter className="flex-col gap-2 sm:flex-col sm:space-x-0">
                    {hasNativeShare ? (
                        <Button
                            className="w-full rounded-xl"
                            onClick={() => void tryNativeShare()}
                        >
                            <Share2 className="mr-2 size-4" />
                            {t("sharePrompt.shareNative")}
                        </Button>
                    ) : null}
                    <Button
                        variant="outline"
                        className="w-full rounded-xl"
                        onClick={() => void copyLink()}
                    >
                        <Link2 className="mr-2 size-4" />
                        {t("sharePrompt.copyLink")}
                    </Button>
                    <Button
                        variant="ghost"
                        className="w-full rounded-xl text-muted-foreground"
                        onClick={closePrompt}
                    >
                        {t("sharePrompt.notNow")}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    )
}
