"use client"

import * as React from "react"
import { MonitorCog, Moon, Sun } from "lucide-react"
import { useTheme } from "next-themes"

import { useTranslations } from "@/lib/i18n/client"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { useToast } from "@/components/ui/use-toast"

const THEME_LABEL_KEYS: Record<string, string> = {
  light: "theme.light",
  dark: "theme.dark",
  system: "theme.system",
}

export function ThemeToggle({ compact = false }: { compact?: boolean }) {
  const { setTheme, theme, themes } = useTheme()
  const { toast } = useToast()
  const { t } = useTranslations()
  const [mounted, setMounted] = React.useState(false)
  React.useEffect(() => {
    setMounted(true)
  }, [])
  const activeTheme = mounted ? theme : "dark"

  const iconProps = {
    className: compact ? "size-4" : "size-4 mr-2",
  }

  function themeLabel(value: string) {
    return t(THEME_LABEL_KEYS[value] ?? value)
  }

  function handleClick(value: string) {
    setTheme(value)
    if (!compact) {
      toast({
        title: t("settings.themeChanged"),
        description: t("settings.themeChangedDesc", { theme: themeLabel(value) }),
      })
    }
  }

  if (compact) {
    return (
      <div className="flex gap-1">
        {themes.map((value) => {
          const active = activeTheme === value
          return (
            <Button
              key={value}
              type="button"
              variant="ghost"
              size="sm"
              title={themeLabel(value)}
              className={cn(
                "h-10 flex-1 flex-col gap-0.5 rounded-lg capitalize",
                active
                  ? "bg-primary/15 text-foreground ring-1 ring-primary/25"
                  : "text-muted-foreground hover:bg-accent/60 hover:text-foreground"
              )}
              onClick={() => handleClick(value)}
            >
              {value === "light" && <Sun {...iconProps} />}
              {value === "dark" && <Moon {...iconProps} />}
              {value === "system" && <MonitorCog {...iconProps} />}
              <span className="text-[10px] leading-none">{themeLabel(value)}</span>
            </Button>
          )
        })}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-2">
      {themes.map((value) => (
        <Button
          key={value}
          variant={activeTheme === value ? "default" : "outline"}
          onClick={() => handleClick(value)}
        >
          {value === "light" && <Sun {...iconProps} />}
          {value === "dark" && <Moon {...iconProps} />}
          {value === "system" && <MonitorCog {...iconProps} />}

          <span>{themeLabel(value)}</span>
        </Button>
      ))}
    </div>
  )
}
