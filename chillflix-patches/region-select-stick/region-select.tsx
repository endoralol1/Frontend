"use client"

import { useEffect, useState } from "react"
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

  // Sync when the server cookie prop catches up after refresh.
  useEffect(() => {
    if (typeof value === "string" && value && value !== current) {
      setCurrent(value)
    }
    // Only follow prop changes from the server — not our optimistic writes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  const handleChange = (next: string) => {
    if (!REGION_CODES.has(next)) return

    // Optimistic — Radix controlled `value` otherwise snaps back to Spain
    // before the cookie + router.refresh() finish.
    setCurrent(next)

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
        // Revert if the server action failed.
        if (typeof value === "string" && value) {
          setCurrent(value)
        }
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
