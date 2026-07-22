import { Suspense } from "react"
import { cookies } from "next/headers"

import { DEFAULT_LOCALE, LOCALE_COOKIE, normalizeLocale } from "@/lib/i18n/locales"
import { getSiteSettings } from "@/lib/site-settings"
import { getTurnstileSiteKey } from "@/lib/turnstile-public"
import { Skeleton } from "@/components/ui/skeleton"
import { HeaderSearchExpandable } from "@/components/header-search-expandable"
import { SiteMenu } from "@/components/site-menu"
import { SiteNav } from "@/components/site-nav"
import { SiteSettings } from "@/components/site-settings"

export const SiteHeader = async () => {
  const region = cookies().get("region")?.value ?? "US"
  const locale = normalizeLocale(cookies().get(LOCALE_COOKIE)?.value ?? DEFAULT_LOCALE)
  const siteSettings = await getSiteSettings()

  return (
    <header className="fixed bottom-0 left-0 right-0 z-[100] flex justify-center overflow-visible lg:bottom-auto lg:top-0">
      <div className="site-header-performance-shell mx-3 my-3 overflow-visible rounded-full border border-border/40 bg-background/90 px-3 shadow-lg ring-1 ring-white/5 backdrop-blur-md supports-[backdrop-filter]:bg-background/85 transition-all duration-500 hover:border-primary/25 hover:shadow-xl hover:shadow-primary/10 sm:mx-4 sm:px-4 lg:mx-4 lg:max-w-[min(100%-2rem,90rem)]">
        <div className="relative z-10 flex h-11 min-w-0 items-center gap-2 overflow-visible sm:h-12 sm:gap-3">
          <div className="hidden min-w-0 shrink lg:flex">
            <SiteNav />
          </div>

          <div className="flex shrink-0 items-center gap-1.5 lg:hidden">
            <SiteMenu />
          </div>

          <Suspense fallback={<Skeleton className="size-9 shrink-0 rounded-md" />}>
            <HeaderSearchExpandable />
          </Suspense>

          <SiteSettings
            region={region}
            locale={locale}
            registrationEnabled={siteSettings.registrationEnabled}
            maintenanceMode={siteSettings.maintenanceMode}
            turnstileSiteKey={getTurnstileSiteKey()}
          />
        </div>
      </div>
    </header>
  )
}
