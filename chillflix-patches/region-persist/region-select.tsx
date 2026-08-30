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
  // Client write so a full refresh keeps the choice even if the server
  // action Set-Cookie is dropped (CF challenge / action POST quirks).
  const secure =
    typeof window !== "undefined" && window.location.protocol === "https:"
      ? "; Secure"
      : ""
  document.cookie = `region=${encodeURIComponent(code)}; Path=/; Max-Age=${REGION_COOKIE_MAX_AGE}; SameSite=Lax${secure}`
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

  // Sync when the server cookie prop catches up after refresh.
  useEffect(() => {
    if (typeof value !== "string" || !value) return
    if (pendingRef.current && value !== pendingRef.current) {
      // Server still has the old region — keep optimistic selection.
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

    void setRegion(next)
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
        // Client cookie already written — still refresh so RSC reads it.
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
