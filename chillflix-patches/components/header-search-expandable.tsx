"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import dynamic from "next/dynamic"
import { Search } from "lucide-react"

import { useCommunityReady } from "@/components/deferred-community-shell"
import { useSiteFeatures } from "@/components/site-features"
import { SearchInput } from "@/components/search-input"
import { SiteShellErrorBoundary } from "@/components/site-shell-error-boundary"
import { WatchPartyNavButton } from "@/components/watch-party-nav"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"

const ChatNavButton = dynamic(
  () =>
    import("@/components/chat-widget").then((module) => module.ChatNavButton),
  { ssr: false, loading: () => <span className="inline-block size-9 shrink-0" /> }
)

const TicketNavButton = dynamic(
  () =>
    import("@/components/support-ticket-widget").then(
      (module) => module.TicketNavButton
    ),
  { ssr: false, loading: () => <span className="inline-block size-9 shrink-0" /> }
)

/** Collapsed: icon + "Search" label only */
const SEARCH_COLLAPSED_CLASS = "w-[5.75rem]"

/** Expanded — comfortable typing width */
const SEARCH_EXPANDED_CLASS = "w-56 lg:w-64"

export function HeaderSearchExpandable() {
  const { t } = useTranslations()
  const [expanded, setExpanded] = useState(false)
  const [opening, setOpening] = useState(false)
  const communityReady = useCommunityReady()
  const { watchPartyEnabled, ticketsEnabled, chatEnabled } = useSiteFeatures()
  const clusterRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const collapse = useCallback(() => {
    setExpanded(false)
    setOpening(false)
  }, [])

  const openSearch = useCallback(() => {
    setExpanded(true)
    setOpening(true)
  }, [])

  useEffect(() => {
    if (!opening) return
    const timer = window.setTimeout(() => setOpening(false), 550)
    return () => window.clearTimeout(timer)
  }, [opening])

  useEffect(() => {
    if (!expanded) return

    const frame = window.requestAnimationFrame(() => {
      inputRef.current?.focus()
    })

    const onPointerDown = (event: PointerEvent) => {
      if (!clusterRef.current?.contains(event.target as Node)) {
        collapse()
      }
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        collapse()
      }
    }

    document.addEventListener("pointerdown", onPointerDown)
    document.addEventListener("keydown", onKeyDown)

    return () => {
      window.cancelAnimationFrame(frame)
      document.removeEventListener("pointerdown", onPointerDown)
      document.removeEventListener("keydown", onKeyDown)
    }
  }, [expanded, collapse])

  return (
    <div
      ref={clusterRef}
      className="flex shrink-0 items-center gap-3 max-lg:flex-none"
    >
      <div
        className={cn(
          "h-9 shrink-0 origin-left will-change-[width,transform]",
          "transition-[width,transform] duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]",
          expanded ? SEARCH_EXPANDED_CLASS : SEARCH_COLLAPSED_CLASS
        )}
      >
        {expanded ? (
          <SearchInput
            ref={inputRef}
            placeholder={t("common.searchPlaceholder")}
            compact
            expanded={expanded}
            opening={opening}
            onDismiss={collapse}
            className="h-9 w-full min-w-0"
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                collapse()
              }
            }}
          />
        ) : (
          <button
            type="button"
            onClick={openSearch}
            className={cn(
              "relative flex h-9 w-full items-center rounded-md p-px",
              "bg-gradient-to-r from-border/70 via-primary/20 to-border/70",
              "shadow-[0_0_12px_-4px_hsl(var(--primary)/0.18)]",
              "transition-[box-shadow,background] duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]",
              "hover:from-primary/25 hover:via-primary/45 hover:to-primary/25",
              "hover:shadow-[0_0_16px_-3px_hsl(var(--primary)/0.3)]"
            )}
          >
            <span className="flex h-full w-full items-center gap-1.5 rounded-[calc(0.375rem-1px)] bg-background pl-2.5 pr-2 text-sm text-muted-foreground">
              <Search className="size-4 shrink-0" />
              <span className="truncate">{t("common.search")}</span>
            </span>
          </button>
        )}
      </div>

      <div
        className={cn(
          "flex shrink-0 items-center gap-3",
          "transition-[max-width,opacity,transform,margin] duration-500 ease-[cubic-bezier(0.16,1,0.3,1)]",
          expanded
            ? "pointer-events-none ml-0 max-w-0 overflow-hidden opacity-0 -translate-x-3 scale-95 gap-0"
            : "ml-0 max-w-[12rem] overflow-visible opacity-100 translate-x-0 scale-100"
        )}
      >
        {watchPartyEnabled ? (
          <SiteShellErrorBoundary name="watch-party-nav" fallback={null}>
            <WatchPartyNavButton />
          </SiteShellErrorBoundary>
        ) : null}
        {communityReady && ticketsEnabled ? (
          <SiteShellErrorBoundary name="ticket-nav" fallback={null}>
            <TicketNavButton />
          </SiteShellErrorBoundary>
        ) : null}
        {communityReady && chatEnabled ? (
          <SiteShellErrorBoundary name="chat-nav" fallback={null}>
            <ChatNavButton />
          </SiteShellErrorBoundary>
        ) : null}
      </div>
    </div>
  )
}
