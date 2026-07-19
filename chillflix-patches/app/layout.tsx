import "@/styles/globals.css"
import { Metadata, Viewport } from "next"
import { siteConfig } from "@/config"
import { GeistSans } from "geist/font/sans"
import HolyLoader from "holy-loader"

import { cn } from "@/lib/utils"
import { getLocale, getTranslator } from "@/lib/i18n/server"
import { getMessages } from "@/lib/i18n/messages"
import { I18nProvider } from "@/lib/i18n/client"
import { Toaster } from "@/components/ui/toaster"
import { GoogleAnalyticsScripts } from "@/components/google-analytics-scripts"
import { SiteAccessGuard } from "@/components/site-access-guard"
import { AuthProvider } from "@/hooks/use-auth"
import { EmbedAdParentDelegate } from "@/components/embed-ad-parent-delegate"
import { EmbedAuthParentBridge } from "@/components/embed-auth-parent-bridge"
import { ThemeProvider } from "@/components/theme-provider"
import { TrailerProvider } from "@/components/trailer-provider"
import { UserLibraryProvider } from "@/components/user-library-provider"
import { LazyVidifyTurnstileHost } from "@/components/lazy-vidify-turnstile-host"
import { AppParallelRoutes } from "@/components/app-parallel-routes"

import { getSiteOgImageUrl, getSiteUrl } from "@/lib/seo"

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
}

export async function generateMetadata(): Promise<Metadata> {
  const { t } = await getTranslator()

  return {
    metadataBase: new URL(getSiteUrl()),
    title: {
      default: siteConfig.name,
      template: `%s | ${siteConfig.name}`,
    },
    description: t("site.description"),
    applicationName: siteConfig.name,
    openGraph: {
      siteName: siteConfig.name,
      title: t("site.shareTitle"),
      description: t("site.shareDescription"),
      type: "website",
      url: getSiteUrl(),
      images: [
        {
          url: getSiteOgImageUrl(),
          width: 1200,
          height: 630,
          alt: t("site.shareTitle"),
        },
      ],
    },
    twitter: {
      card: "summary_large_image",
      title: t("site.shareTitle"),
      description: t("site.shareDescription"),
      images: [getSiteOgImageUrl()],
    },
    icons: {
      icon: "/favicon.ico",
      shortcut: "/favicon-16x16.png",
      apple: "/apple-touch-icon.png",
    },
  }
}

interface RootLayoutProps {
  children: React.ReactNode
  modal: React.ReactNode
}

export default async function RootLayout({ children, modal }: RootLayoutProps) {
  const locale = await getLocale()
  const messages = getMessages(locale)

  return (
    <html lang={locale} className="dark" suppressHydrationWarning>
      <head />
      <body
        className={cn(
          "min-h-screen bg-background font-sans antialiased",
          GeistSans.variable
        )}
        suppressHydrationWarning
      >
        <HolyLoader color="#ccc" />
        <I18nProvider locale={locale} messages={messages}>
          <ThemeProvider>
            <AuthProvider>
              <EmbedAuthParentBridge />
              <EmbedAdParentDelegate />
              <UserLibraryProvider>
                <TrailerProvider>
                  <SiteAccessGuard>
                    <AppParallelRoutes modal={modal}>{children}</AppParallelRoutes>
                  </SiteAccessGuard>
                </TrailerProvider>
              </UserLibraryProvider>
            </AuthProvider>
          </ThemeProvider>
        </I18nProvider>
        <GoogleAnalyticsScripts />
        <Toaster />
        <LazyVidifyTurnstileHost />
      </body>
    </html>
  )
}
