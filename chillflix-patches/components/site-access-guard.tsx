import dynamic from "next/dynamic"
import { headers } from "next/headers"
import { redirect } from "next/navigation"

import { ChatProvider } from "@/components/chat-widget"
import { SiteFeaturesProvider } from "@/components/site-features"
import { TicketProvider } from "@/components/support-ticket-widget"
import { WatchPartyProvider } from "@/components/watch-party-context"
import { GridBg } from "@/components/grid-bg"
import { ScrollTop } from "@/components/scroll-top"
import { SiteFooter } from "@/components/site-footer"
import { SiteHeader } from "@/components/site-header"
import { StarfieldBackground } from "@/components/starfield-background"
import { TailwindIndicator } from "@/components/tailwind-indicator"
import {
    isMaintenanceExemptPath,
    isStaffFromCookies,
    maintenanceLoginUrl,
} from "@/lib/maintenance-access"
import { getSiteSettings, pickSiteClientFlags } from "@/lib/site-settings"

const ApkDownloadPrompt = dynamic(
    () =>
        import("@/components/apk-download-prompt").then((module) => module.ApkDownloadPrompt),
    { ssr: false }
)
const ShareSitePrompt = dynamic(
    () =>
        import("@/components/share-site-prompt").then((module) => module.ShareSitePrompt),
    { ssr: false }
)

export async function SiteAccessGuard({ children }: { children: React.ReactNode }) {
    const pathname = headers().get("x-pathname") || "/"
    const settings = await getSiteSettings()
    const isStaff = await isStaffFromCookies()
    const maintenanceBlocksPublic = settings.maintenanceMode && !isStaff

    if (maintenanceBlocksPublic) {
        if (pathname === "/register" || pathname === "/maintenance") {
            redirect(maintenanceLoginUrl())
        }

        if (!isMaintenanceExemptPath(pathname)) {
            redirect(maintenanceLoginUrl(pathname))
        }
    }

    if (!settings.iptvEnabled && (pathname === "/iptv" || pathname.startsWith("/iptv/"))) {
        redirect("/")
    }

    if (!settings.musicEnabled && (pathname === "/music" || pathname.startsWith("/music/"))) {
        redirect("/")
    }

    const isEmbedPath = pathname.startsWith("/embed/")
    // Admin routes: keep community chat out of the shell so a chat/poll UI bug
    // cannot take down /admin/* (including /admin/chat).
    const isAdminPath = pathname === "/admin" || pathname.startsWith("/admin/")

    const minimalLoginShell =
        settings.maintenanceMode && !isStaff && pathname === "/login"

    if (isEmbedPath) {
        return (
            <SiteFeaturesProvider initialFeatures={pickSiteClientFlags(settings)}>
                <WatchPartyProvider>{children}</WatchPartyProvider>
            </SiteFeaturesProvider>
        )
    }

    if (isAdminPath) {
        return (
            <SiteFeaturesProvider initialFeatures={pickSiteClientFlags(settings)}>
                {children}
            </SiteFeaturesProvider>
        )
    }

    if (minimalLoginShell) {
        return (
            <div className="relative z-10 flex min-h-screen flex-col items-center justify-center px-4 py-8">
                {children}
            </div>
        )
    }

    return (
            <SiteFeaturesProvider initialFeatures={pickSiteClientFlags(settings)}>
        <ChatProvider>
            <TicketProvider>
            <WatchPartyProvider>
<ApkDownloadPrompt />
                <ShareSitePrompt />
                <StarfieldBackground />
                <div className="relative flex min-h-screen flex-col pb-[5.5rem]" vaul-drawer-wrapper="">
                    <GridBg />
                    <div className="relative z-10 flex-1 py-4">{children}</div>
                    <SiteFooter />
                </div>
                <SiteHeader />
                <TailwindIndicator />
                <ScrollTop />
            </WatchPartyProvider>
            </TicketProvider>
        </ChatProvider>
        </SiteFeaturesProvider>
    )
}
