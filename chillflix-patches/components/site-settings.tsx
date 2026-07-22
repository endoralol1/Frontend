"use client"

import React, { Suspense } from "react"
import dynamic from "next/dynamic"
import { useRouter } from "next/navigation"
import { useMediaQuery } from "@/hooks/use-media-query"
import {
  ArrowLeft,
  Globe2,
  LogIn,
  LogOut,
  Newspaper,
  Palette,
  SettingsIcon,
  Shield,
  UserPlus,
  UserRound,
} from "lucide-react"

import { useTranslations } from "@/lib/i18n/client"
import type { Locale } from "@/lib/i18n/locales"
import { canAccessAdmin } from "@/lib/permissions"
import { cn } from "@/lib/utils"
import { useAuth } from "@/hooks/use-auth"
import { Button, ButtonProps } from "@/components/ui/button"
import {
  Drawer,
  DrawerContent,
  DrawerHeader,
  DrawerTitle,
  DrawerTrigger,
} from "@/components/ui/drawer"
import { Label } from "@/components/ui/label"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { Skeleton } from "@/components/ui/skeleton"
import { InfoTooltip } from "@/components/info-tooltip"
import { SettingsMenuLink } from "@/components/site-settings-menu-link"

const LoginForm = dynamic(() =>
  import("@/components/login-form").then((module) => module.LoginForm)
)
const RegisterForm = dynamic(() =>
  import("@/components/register-form").then((module) => module.RegisterForm)
)
const SettingsUpdatesFooter = dynamic(() =>
  import("@/components/settings-updates-footer").then(
    (module) => module.SettingsUpdatesFooter
  )
)
const LanguageSelect = dynamic(() =>
  import("@/components/language-select").then((module) => module.LanguageSelect)
)
const RegionSelect = dynamic(() =>
  import("@/components/region-select").then((module) => module.RegionSelect)
)
const ThemeToggle = dynamic(() =>
  import("@/components/theme-toggle").then((module) => module.ThemeToggle)
)

type SettingsView = "main" | "login" | "register"

const VIEW_META: Record<
  Exclude<SettingsView, "main">,
  {
    titleKey: string
    subtitleKey: string
    icon: React.ComponentType<{ className?: string }>
  }
> = {
  login: {
    titleKey: "auth.signInTitle",
    subtitleKey: "auth.signInSubtitle",
    icon: LogIn,
  },
  register: {
    titleKey: "auth.registerTitle",
    subtitleKey: "auth.registerSubtitle",
    icon: UserPlus,
  },
}

const SiteSettingsButton = React.forwardRef<HTMLButtonElement, ButtonProps>(
  (
    { variant = "outline", size = "icon", className, children, ...props },
    ref
  ) => {
    const { t } = useTranslations()

    return (
      <Button
        variant={variant}
        size={size}
        className={cn(
          "size-8 shrink-0 rounded-full border-border/50 bg-background/70 shadow-sm transition-all hover:border-primary/35 hover:bg-accent/60 hover:shadow-md sm:size-9",
          className
        )}
        ref={ref}
        {...props}
      >
        <SettingsIcon className="size-3.5 sm:size-4" />
        <span className="sr-only">{t("settings.title")}</span>
      </Button>
    )
  }
)

SiteSettingsButton.displayName = "SiteSettingsButton"

function SettingsSubheader({
  view,
  onBack,
}: {
  view: SettingsView
  onBack: () => void
}) {
  const { t } = useTranslations()

  if (view === "main") {
    return (
      <div className="from-primary/8 border-b border-border/40 bg-gradient-to-b to-transparent px-4 pb-2.5 pt-1">
        <div className="flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-xl bg-primary/15 ring-1 ring-primary/20">
            <SettingsIcon className="size-4 text-primary" />
          </span>
          <div>
            <h5 className="text-sm font-semibold tracking-tight">
              {t("settings.title")}
            </h5>
            <p className="text-xs text-muted-foreground">
              {t("settings.subtitle")}
            </p>
          </div>
        </div>
      </div>
    )
  }

  const meta = VIEW_META[view]
  const Icon = meta.icon

  return (
    <div className="from-primary/8 border-b border-border/40 bg-gradient-to-b to-transparent px-4 pb-3 pt-1">
      <button
        type="button"
        onClick={onBack}
        className="mb-3 flex items-center gap-1.5 rounded-lg py-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t("common.back")}
      </button>
      <div className="flex items-center gap-2.5">
        <span className="flex size-9 items-center justify-center rounded-xl bg-primary/15 ring-1 ring-primary/20">
          <Icon className="size-4 text-primary" />
        </span>
        <div>
          <h5 className="text-sm font-semibold tracking-tight">
            {t(meta.titleKey)}
          </h5>
          <p className="text-xs text-muted-foreground">{t(meta.subtitleKey)}</p>
        </div>
      </div>
    </div>
  )
}

function SettingsSection({
  title,
  icon: Icon,
  tooltip,
  children,
}: {
  title: string
  icon?: React.ComponentType<{ className?: string }>
  tooltip?: string
  children: React.ReactNode
}) {
  return (
    <section className="space-y-2">
      <Label className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        {Icon ? <Icon className="size-3.5 opacity-70" /> : null}
        <span>{title}</span>
        {tooltip ? (
          <InfoTooltip className="w-56 normal-case tracking-normal">
            {tooltip}
          </InfoTooltip>
        ) : null}
      </Label>
      <div className="rounded-xl border border-border/40 bg-muted/20 p-2">
        {children}
      </div>
    </section>
  )
}

interface SiteSettingsProps {
  region?: string
  locale?: Locale
  registrationEnabled?: boolean
  maintenanceMode?: boolean
  turnstileSiteKey?: string
}

export const SiteSettings: React.FC<SiteSettingsProps> = ({
  region = "US",
  locale = "en",
  registrationEnabled = true,
  maintenanceMode = false,
  turnstileSiteKey = "",
}) => {
  const router = useRouter()
  const { user, logout } = useAuth()
  const { t } = useTranslations()
  const isMobile = useMediaQuery("(max-width: 768px)")
  const isDesktopTopNav = useMediaQuery("(min-width: 1024px)")
  const [open, setOpen] = React.useState(false)
  const [view, setView] = React.useState<SettingsView>("main")

  const handleOpenChange = (next: boolean) => {
    setOpen(next)
    if (!next) {
      setView("main")
    }
  }

  const handleBack = () => {
    if (view === "login" || view === "register") {
      setView("main")
    }
  }

  const handleAuthSuccess = () => {
    setOpen(false)
    setView("main")
  }

  const handleLogout = async () => {
    await logout()
    router.push("/")
  }

  const staffLabel =
    user?.role === "owner" ? t("admin.adminPanel") : t("admin.moderatorPanel")

  const authChooser = (
    <>
      <SettingsMenuLink icon={LogIn} onClick={() => setView("login")}>
        {t("auth.signIn")}
      </SettingsMenuLink>
      {registrationEnabled && !maintenanceMode ? (
        <SettingsMenuLink icon={UserPlus} onClick={() => setView("register")}>
          {t("auth.register")}
        </SettingsMenuLink>
      ) : null}
    </>
  )

  const updatesFooter = <SettingsUpdatesFooter />

  const mainSettingsBody = (
    <>
      <SettingsSection title={t("settings.account")} icon={UserRound}>
        <div className="space-y-1">
          {user ? (
            <>
              {canAccessAdmin(user.role) ? (
                <SettingsMenuLink
                  href={user.role === "owner" ? "/admin" : "/admin/analytics"}
                  icon={Shield}
                  variant="admin"
                >
                  {staffLabel}
                </SettingsMenuLink>
              ) : null}
              <SettingsMenuLink href="/profile" icon={UserRound}>
                {user.name}
              </SettingsMenuLink>
              <SettingsMenuLink
                icon={LogOut}
                onClick={() => void handleLogout()}
              >
                {t("settings.signOut")}
              </SettingsMenuLink>
            </>
          ) : (
            authChooser
          )}
        </div>
      </SettingsSection>

      <SettingsSection
        title={t("settings.language")}
        icon={Globe2}
        tooltip={t("settings.languageTooltip")}
      >
        <div onFocusCapture={(e) => e.stopPropagation()}>
          <LanguageSelect value={locale} />
        </div>
      </SettingsSection>

      <SettingsSection
        title={t("settings.region")}
        icon={Globe2}
        tooltip={t("settings.regionTooltip")}
      >
        <div onFocusCapture={(e) => e.stopPropagation()}>
          <RegionSelect value={region} />
        </div>
      </SettingsSection>

      <SettingsSection title={t("settings.theme")} icon={Palette}>
        <ThemeToggle compact />
      </SettingsSection>
    </>
  )

  const panelContent =
    view === "login" ? (
      <Suspense
        fallback={
          <div className="space-y-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        }
      >
        <LoginForm
          variant="embedded"
          registrationEnabled={registrationEnabled}
          maintenanceMode={maintenanceMode}
          turnstileSiteKey={turnstileSiteKey}
          onSuccess={handleAuthSuccess}
          onSwitchToRegister={
            registrationEnabled && !maintenanceMode
              ? () => setView("register")
              : undefined
          }
        />
      </Suspense>
    ) : view === "register" ? (
      <RegisterForm
        variant="embedded"
        turnstileSiteKey={turnstileSiteKey}
        onSuccess={handleAuthSuccess}
        onSwitchToLogin={() => setView("login")}
      />
    ) : (
      mainSettingsBody
    )

  const updatesBar = (
    <div className="shrink-0 border-t border-border/40 bg-muted/10 px-2 py-1.5">
      <p className="mb-1 flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
        <Newspaper className="size-3 opacity-70" />
        {t("settings.updates")}
      </p>
      {updatesFooter}
    </div>
  )

  const body = (
    <div className={cn(view === "main" ? "space-y-3" : "")}>{panelContent}</div>
  )

  if (isMobile) {
    return (
      <Drawer
        open={open}
        onOpenChange={handleOpenChange}
        shouldScaleBackground={false}
      >
        <DrawerTrigger asChild>
          <SiteSettingsButton />
        </DrawerTrigger>

        <DrawerContent className="flex max-h-[92dvh] flex-col rounded-t-2xl border-border/50 p-0">
          {view === "main" ? (
            <DrawerHeader className="from-primary/8 shrink-0 border-b border-border/40 bg-gradient-to-b to-transparent px-4 pb-4 pt-2 text-left">
              <DrawerTitle className="text-base">
                {t("settings.title")}
              </DrawerTitle>
              <p className="text-xs text-muted-foreground">
                {t("settings.subtitle")}
              </p>
            </DrawerHeader>
          ) : (
            <div className="from-primary/8 shrink-0 border-b border-border/40 bg-gradient-to-b to-transparent px-4 pb-4 pt-2 text-left">
              <button
                type="button"
                onClick={handleBack}
                className="mb-3 flex items-center gap-1.5 rounded-lg py-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
              >
                <ArrowLeft className="size-4" />
                {t("common.back")}
              </button>
              <DrawerTitle className="text-base">
                {t(VIEW_META[view].titleKey)}
              </DrawerTitle>
              <p className="text-xs text-muted-foreground">
                {t(VIEW_META[view].subtitleKey)}
              </p>
            </div>
          )}

          <div className="min-h-0 flex-1 overflow-y-auto p-4">
            {body}
          </div>
          {view === "main" ? updatesBar : null}
        </DrawerContent>
      </Drawer>
    )
  }

  return (
    <Popover open={open} onOpenChange={handleOpenChange}>
      <PopoverTrigger asChild>
        <SiteSettingsButton />
      </PopoverTrigger>
      <PopoverContent
        side={isDesktopTopNav ? "bottom" : "top"}
        align="end"
        sideOffset={14}
        className="flex max-h-[calc(100dvh-5.5rem)] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border-border/50 bg-gradient-to-b from-card to-card/95 p-0 shadow-2xl shadow-black/20 backdrop-blur-xl"
      >
        <SettingsSubheader view={view} onBack={handleBack} />
        <div className="min-h-0 flex-1 overflow-y-auto p-3">{body}</div>
        {view === "main" ? updatesBar : null}
      </PopoverContent>
    </Popover>
  )
}
