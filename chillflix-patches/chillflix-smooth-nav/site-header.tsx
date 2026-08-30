import { Suspense } from "react"
import { cookies } from "next/headers"

import { DEFAULT_LOCALE, LOCALE_COOKIE, normalizeLocale } from "@/lib/i18n/locales"
import { getSiteSettings } from "@/lib/site-settings"
import { getTurnstileSiteKey } from "@/lib/turnstile-public"
import { Skeleton } from "@/components/ui/skeleton"
import { HeaderSearchExpandable } from "@/components/header-search-expandable"
import { SiteBottomNav } from "@/components/site-bottom-nav"
import { SiteBrand } from "@/components/site-brand"
import { SiteNav } from "@/components/site-nav"
import { SiteSettings } from "@/components/site-settings"

export const SiteHeader = async () => {
  const region = cookies().get("region")?.value ?? "US"
  const locale = normalizeLocale(cookies().get(LOCALE_COOKIE)?.value ?? DEFAULT_LOCALE)
  const siteSettings = await getSiteSettings()

  const settingsProps = {
    region,
    locale,
    registrationEnabled: siteSettings.registrationEnabled,
    maintenanceMode: siteSettings.maintenanceMode,
    turnstileSiteKey: getTurnstileSiteKey(),
  }

  return (
    <>
      {/* Desktop: compact floating pill */}
      <header className="cf-site-header pointer-events-none fixed inset-x-0 top-0 z-[100] hidden justify-center pt-3 lg:flex">
        <div className="site-header-performance-shell pointer-events-auto mx-4 flex h-11 max-w-fit items-center gap-2 rounded-full border border-white/10 bg-background/70 px-2.5 shadow-[0_10px_30px_-16px_rgba(0,0,0,0.75)] backdrop-blur-xl supports-[backdrop-filter]:bg-background/55 sm:gap-3 sm:px-3">
          <SiteBrand />
          <div className="mx-1 h-4 w-px bg-white/10" />
          <SiteNav />
          <div className="mx-1 h-4 w-px bg-white/10" />
          <Suspense fallback={<Skeleton className="size-8 rounded-full" />}>
            <HeaderSearchExpandable compact />
          </Suspense>
          <SiteSettings {...settingsProps} />
        </div>
      </header>

      {/* Mobile: bottom dock only (no top logo / settings) */}
      <SiteBottomNav settingsProps={settingsProps} />
    </>
  )
}
