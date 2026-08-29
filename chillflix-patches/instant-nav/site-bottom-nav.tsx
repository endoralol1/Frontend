"use client"

import { Suspense, useCallback, useEffect, useRef, useState } from "react"
import {
  ClapperboardIcon,
  HomeIcon,
  LayoutGridIcon,
  SearchIcon,
  SettingsIcon,
  TvIcon,
} from "lucide-react"
import { usePathname } from "next/navigation"

import { pages } from "@/config"
import { useDialog } from "@/hooks"
import type { Locale } from "@/lib/i18n/locales"
import { useTranslations } from "@/lib/i18n/client"
import { cn } from "@/lib/utils"
import { ListPageLink } from "@/components/list-page-link"
import { SearchInput } from "@/components/search-input"
import { SiteMenu } from "@/components/site-menu"
import { SiteSettings } from "@/components/site-settings"

function isActivePath(pathname: string, href: string) {
  if (href === "/") return pathname === "/"
  if (href.startsWith("/movie")) {
    return pathname === href || pathname.startsWith("/movie")
  }
  if (href.startsWith("/tv")) {
    return pathname === href || pathname.startsWith("/tv")
  }
  return pathname === href || pathname.startsWith(href)
}

type SettingsProps = {
  region?: string
  locale?: Locale
  registrationEnabled?: boolean
  maintenanceMode?: boolean
  turnstileSiteKey?: string
}

function BottomNavSearch({
  expanded,
  opening,
  onOpen,
  onCollapse,
  active,
}: {
  expanded: boolean
  opening: boolean
  onOpen: () => void
  onCollapse: () => void
  active: boolean
}) {
  const { t } = useTranslations()
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!expanded) return
    const frame = window.requestAnimationFrame(() => {
      inputRef.current?.focus()
    })
    return () => window.cancelAnimationFrame(frame)
  }, [expanded])

  if (expanded) {
    return (
      <div className="min-w-0 flex-1 px-0.5">
        <SearchInput
          ref={inputRef}
          auto
          compact
          expanded
          opening={opening}
          placeholder={t("common.searchPlaceholder")}
          onDismiss={onCollapse}
          className="h-10 w-full min-w-0 rounded-[0.92rem]"
          onKeyDown={(event) => {
            if (event.key === "Escape") onCollapse()
          }}
        />
      </div>
    )
  }

  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        "flex min-h-[2.7rem] w-full flex-col items-center justify-center gap-1 rounded-[0.92rem] px-0.5 py-1.5 text-muted-foreground transition-colors",
        active && "bg-primary/20 text-foreground"
      )}
      aria-expanded={false}
    >
      <SearchIcon className="size-[1.1rem]" />
      <span className="max-w-full truncate text-[0.58rem] font-semibold tracking-wide">
        {t("common.search")}
      </span>
    </button>
  )
}

export function SiteBottomNav({
  settingsProps,
}: {
  settingsProps: SettingsProps
}) {
  const { t } = useTranslations()
  const pathname = usePathname()
  const [browseOpen, setBrowseOpen] = useDialog()
  const [searchExpanded, setSearchExpanded] = useState(false)
  const [opening, setOpening] = useState(false)
  const dockRef = useRef<HTMLDivElement>(null)

  const collapseSearch = useCallback(() => {
    setSearchExpanded(false)
    setOpening(false)
  }, [])

  const openSearch = useCallback(() => {
    setBrowseOpen(false)
    setSearchExpanded(true)
    setOpening(true)
  }, [setBrowseOpen])

  useEffect(() => {
    if (!opening) return
    const timer = window.setTimeout(() => setOpening(false), 450)
    return () => window.clearTimeout(timer)
  }, [opening])

  const wasOnSearchRef = useRef(false)

  useEffect(() => {
    if (pathname.startsWith("/search")) {
      wasOnSearchRef.current = true
      return
    }
    if (wasOnSearchRef.current && pathname === pages.home.link) {
      wasOnSearchRef.current = false
      collapseSearch()
    }
  }, [pathname, collapseSearch])

  useEffect(() => {
    if (!searchExpanded) return

    const onPointerDown = (event: PointerEvent) => {
      if (!dockRef.current?.contains(event.target as Node)) {
        collapseSearch()
      }
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") collapseSearch()
    }

    document.addEventListener("pointerdown", onPointerDown)
    document.addEventListener("keydown", onKeyDown)

    return () => {
      document.removeEventListener("pointerdown", onPointerDown)
      document.removeEventListener("keydown", onKeyDown)
    }
  }, [searchExpanded, collapseSearch])

  const items = [
    {
      key: "home",
      href: pages.home.link,
      label: t("nav.home"),
      icon: HomeIcon,
    },
    {
      key: "movies",
      href: pages.movie.discover.link,
      label: t("nav.movies"),
      icon: ClapperboardIcon,
    },
    {
      key: "tv",
      href: pages.tv.discover.link,
      label: t("nav.tv"),
      icon: TvIcon,
    },
  ]

  const dockItemClass =
    "flex min-h-[2.7rem] w-full flex-col items-center justify-center gap-1 rounded-[0.92rem] px-0.5 py-1.5 text-muted-foreground transition-colors"

  return (
    <nav
      aria-label="Primary"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] px-2.5 pb-[max(0.45rem,env(safe-area-inset-bottom))] lg:hidden"
    >
      <div
        ref={dockRef}
        className={cn(
          "pointer-events-auto mx-auto flex max-w-[26rem] items-center gap-0.5 rounded-[1.2rem] border border-white/12 bg-background/75 p-1 shadow-[0_14px_36px_rgba(0,0,0,0.48)] ring-1 ring-black/30 backdrop-blur-xl supports-[backdrop-filter]:bg-background/60",
          searchExpanded ? "min-h-[3.35rem]" : "grid grid-cols-6"
        )}
      >
        {searchExpanded ? (
          <Suspense fallback={<div className="h-10 flex-1" />}>
            <BottomNavSearch
              expanded
              opening={opening}
              onOpen={openSearch}
              onCollapse={collapseSearch}
              active={pathname.startsWith("/search")}
            />
          </Suspense>
        ) : (
          <>
            {items.map((item) => {
              const Icon = item.icon
              const active = isActivePath(pathname, item.href)
              return (
                <ListPageLink
                  key={item.key}
                  href={item.href}
                  className={cn(dockItemClass, active && "bg-primary/20 text-foreground")}
                  aria-current={active ? "page" : undefined}
                >
                  <Icon className="size-[1.1rem]" />
                  <span className="max-w-full truncate text-[0.58rem] font-semibold tracking-wide">
                    {item.label}
                  </span>
                </ListPageLink>
              )
            })}

            <Suspense
              fallback={
                <button type="button" className={dockItemClass} disabled>
                  <SearchIcon className="size-[1.1rem]" />
                </button>
              }
            >
              <BottomNavSearch
                expanded={false}
                opening={false}
                onOpen={openSearch}
                onCollapse={collapseSearch}
                active={pathname.startsWith("/search")}
              />
            </Suspense>

            <div className="relative flex min-h-[2.7rem] items-center justify-center">
              <SiteMenu
                dockMode
                open={browseOpen}
                onOpenChange={setBrowseOpen}
                triggerClassName={cn(
                  dockItemClass,
                  browseOpen && "bg-primary/20 text-foreground"
                )}
                triggerLabel={t("nav.browse")}
                triggerIcon={<LayoutGridIcon className="size-[1.1rem]" />}
              />
            </div>

            <div className="relative flex min-h-[2.7rem] items-center justify-center">
              <SiteSettings
                {...settingsProps}
                dockMode
                triggerClassName={dockItemClass}
                triggerLabel={t("settings.title")}
                triggerIcon={<SettingsIcon className="size-[1.1rem]" />}
              />
            </div>
          </>
        )}
      </div>
    </nav>
  )
}
