"use client"

import { useEffect, useState } from "react"

import { siteConfig } from "@/config"
import { useSiteFeatures } from "@/components/site-features"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

function DiscordIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      aria-hidden
      className={className}
      fill="currentColor"
    >
      <path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" />
    </svg>
  )
}

const FIRST_SHOW_MS = 6_000
const SHOW_FOR_MS = 5_500
const REPEAT_EVERY_MS = 55_000

/** Header shortcut to the same Discord invite used in community chat. */
export function DiscordNavButton({ className }: { className?: string }) {
  const { t } = useTranslations()
  const { discordInviteUrl } = useSiteFeatures()
  const href = discordInviteUrl || siteConfig.links.discord
  const [showBubble, setShowBubble] = useState(false)

  useEffect(() => {
    if (!href) return

    let showTimer: number | undefined
    let hideTimer: number | undefined
    let intervalId: number | undefined
    let cancelled = false

    const hide = () => {
      if (!cancelled) setShowBubble(false)
    }

    const show = () => {
      if (cancelled) return
      setShowBubble(true)
      window.clearTimeout(hideTimer)
      hideTimer = window.setTimeout(hide, SHOW_FOR_MS)
    }

    showTimer = window.setTimeout(() => {
      show()
      intervalId = window.setInterval(show, REPEAT_EVERY_MS)
    }, FIRST_SHOW_MS)

    return () => {
      cancelled = true
      window.clearTimeout(showTimer)
      window.clearTimeout(hideTimer)
      window.clearInterval(intervalId)
    }
  }, [href])

  if (!href) return null

  return (
    <div
      className={cn(
        "relative inline-flex shrink-0 overflow-visible shadow-md shadow-black/25",
        className
      )}
    >
      {showBubble ? (
        <div
          className={cn(
            "pointer-events-none absolute right-0 z-30 w-[min(200px,calc(100vw-2rem))]",
            "bottom-full mb-2 animate-in fade-in slide-in-from-bottom-2 duration-300",
            "lg:bottom-auto lg:top-full lg:mb-0 lg:mt-2 lg:slide-in-from-top-2"
          )}
        >
          <div className="rounded-2xl rounded-br-md bg-[#5865F2] px-3 py-2 text-white shadow-lg ring-1 ring-[#5865F2]/40">
            <p className="text-[11px] font-semibold leading-snug">
              {t("chat.joinDiscord")}
            </p>
            <p className="mt-0.5 text-[10px] leading-snug text-white/85">
              {t("chat.joinDiscordHint")}
            </p>
          </div>
        </div>
      ) : null}

      <a
        href={href}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={t("chat.joinDiscord")}
        title={t("chat.joinDiscord")}
        className={cn(
          "flex size-9 items-center justify-center rounded-md border border-border/60 bg-background/90 text-foreground transition hover:bg-accent hover:text-[#5865F2]"
        )}
      >
        <DiscordIcon className="size-4" />
      </a>
    </div>
  )
}
