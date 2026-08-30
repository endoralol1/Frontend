"use client"

import { useEffect, useRef, useState } from "react"
import { useRouter } from "next/navigation"
import { regions } from "@/lib"
import { SelectProps } from "@radix-ui/react-select"
import ReactCountryFlag from "react-country-flag"

import { getCountryName } from "@/lib/utils"
import { useTranslations } from "@/lib/i18n/client"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useToast } from "@/components/ui/use-toast"
import { setRegion } from "@/app/actions"

const REGION_CODES = new Set(regions.map((region) => region.iso_3166_1))
const REGION_COOKIE_MAX_AGE = 60 * 60 * 24 * 365

function writeRegionCookie(code: string) {
  // Client write so refresh keeps the choice even if a proxy strips Set-Cookie.
  const secure =
    typeof window !== "undefined" && window.location.protocol === "https:"
      ? "; Secure"
      : ""
  document.cookie = `region=${encodeURIComponent(code)}; Path=/; Max-Age=${REGION_COOKIE_MAX_AGE}; SameSite=Lax${secure}`
}

async function persistRegion(code: string) {
  // Prefer /api (nginx does not strip Set-Cookie there). Fall back to server action.
  try {
    const res = await fetch("/api/preferences/region", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ region: code }),
      credentials: "same-origin",
      cache: "no-store",
    })
    if (res.ok) return
  } catch {
    // fall through
  }
  await setRegion(code)
}

export const RegionSelect: React.FC<SelectProps> = ({
  onValueChange,
  value,
  defaultValue,
  ...props
}) => {
  const router = useRouter()
  const { t } = useTranslations()
  const { toast } = useToast()
  const initial =
    (typeof value === "string" && value) ||
    (typeof defaultValue === "string" && defaultValue) ||
    "US"
  const [current, setCurrent] = useState(initial)
  const pendingRef = useRef<string | null>(null)

  useEffect(() => {
    if (typeof value !== "string" || !value) return
    if (pendingRef.current && value !== pendingRef.current) {
      return
    }
    pendingRef.current = null
    if (value !== current) setCurrent(value)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  const handleChange = (next: string) => {
    if (!REGION_CODES.has(next)) return

    pendingRef.current = next
    setCurrent(next)
    writeRegionCookie(next)

    void persistRegion(next)
      .then(() => {
        onValueChange?.(next)
        router.refresh()

        setTimeout(() => {
          toast({
            title: t("settings.regionChanged"),
            description: t("settings.regionChangedDesc", {
              region: getCountryName(next) ?? next,
            }),
          })
        }, 500)
      })
      .catch(() => {
        router.refresh()
      })
  }

  return (
    <Select onValueChange={handleChange} value={current} {...props}>
      <SelectTrigger>
        <SelectValue />
      </SelectTrigger>

      <SelectContent>
        {regions.map((region) => (
          <SelectItem key={region.iso_3166_1} value={region.iso_3166_1}>
            <div className="flex items-center gap-2">
              <ReactCountryFlag countryCode={region.iso_3166_1} svg />
              {region.english_name}
            </div>
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
