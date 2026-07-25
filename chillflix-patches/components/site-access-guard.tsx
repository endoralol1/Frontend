import dynamic from "next/dynamic"
import { headers } from "next/headers"
import { redirect } from "next/navigation"

import { DeferredCommunityShell } from "@/components/deferred-community-shell"
import { DeferredStarfield } from "@/components/deferred-starfield"
import { SiteFeaturesProvider } from "@/components/site-features"
import { WatchPartyProvider } from "@/components/watch-party-context"
import { GridBg } from "@/components/grid-bg"
import { ScrollTop } from "@/components/scroll-top"
import { SiteFooter } from "@/components/site-footer"
import { SiteHeader } from "@/components/site-header"
import { TailwindIndicator } from "@/components/tailwind-indicator"
import { getChatSettings } from "@/lib/chat-settings"
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
const CommunityInvitePrompt = dynamic(
    () =>
        import("@/components/community-invite-prompt").then(
            (module) => module.CommunityInvitePrompt
        ),
    { ssr: false }
)

export async function SiteAccessGuard({ children }: { children: React.ReactNode }) {
    const pathname = headers().get("x-pathname") || "/"
    // Soft-fail chat settings so a DB blip cannot take down every page (global-error).
    const [settings, chatEnabled] = await Promise.all([
        getSiteSettings(),
        getChatSettings()
            .then((chat) => chat.enabled)
            .catch(() => false),
    ])
    const clientFeatures = {
        ...pickSiteClientFlags(settings),
        chatEnabled,
    }
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
            <SiteFeaturesProvider initialFeatures={clientFeatures}>
                <WatchPartyProvider>{children}</WatchPartyProvider>
            </SiteFeaturesProvider>
        )
    }

    if (isAdminPath) {
        return (
            <SiteFeaturesProvider initialFeatures={clientFeatures}>
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
        <SiteFeaturesProvider initialFeatures={clientFeatures}>
            <WatchPartyProvider>
                <ApkDownloadPrompt />
                <ShareSitePrompt />
                <CommunityInvitePrompt />
                <DeferredStarfield />
                <div
                    className="relative flex min-h-screen flex-col pb-[5.5rem] lg:pb-0 lg:pt-[5.25rem]"
                    vaul-drawer-wrapper=""
                >
                    <GridBg />
                    <div className="relative z-10 flex-1 pt-2 pb-4 md:py-4 lg:pt-2">
                        {children}
                    </div>
                    <SiteFooter />
                </div>
                {/* Chat/tickets only wrap the header — skips chunks when admin-disabled. */}
                <DeferredCommunityShell>
                    <SiteHeader />
                </DeferredCommunityShell>
                <TailwindIndicator />
                <ScrollTop />
            </WatchPartyProvider>
        </SiteFeaturesProvider>
    )
}
