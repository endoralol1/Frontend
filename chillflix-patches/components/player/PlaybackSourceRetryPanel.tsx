"use client"

import { Loader2, RotateCcw } from "lucide-react"

import { Button } from "@/components/ui/button"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

type PlaybackSourceRetryPanelProps = {
    message?: string
    onRetry?: () => void
    retrying?: boolean
    className?: string
    messageClassName?: string
    align?: "center" | "start"
    /** When false, status text only — prefer player settings over Retry. */
    showRetryButton?: boolean
}

export function PlaybackSourceRetryPanel({
    message,
    onRetry,
    retrying = false,
    className,
    messageClassName,
    align = "center",
    showRetryButton = true,
}: PlaybackSourceRetryPanelProps) {
    const { t } = useTranslations()
    const canRetry = Boolean(onRetry) && showRetryButton

    if (!message && !canRetry) {
        return null
    }

    return (
        <div
            className={cn(
                "flex flex-col gap-3",
                align === "center" ? "items-center text-center" : "items-start text-left",
                className
            )}
        >
            {message ? (
                <p className={cn("max-w-md text-xs text-white/60 sm:text-sm", messageClassName)}>
                    {message}
                </p>
            ) : null}
            {canRetry ? (
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    className="h-8 gap-1.5 border-white/15 bg-white/10 px-3 text-xs text-white hover:bg-white/20"
                    onClick={onRetry}
                    disabled={retrying}
                >
                    {retrying ? (
                        <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                        <RotateCcw className="size-3.5" />
                    )}
                    {t("player.retry")}
                </Button>
            ) : null}
        </div>
    )
}
