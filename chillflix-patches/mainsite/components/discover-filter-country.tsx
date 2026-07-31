"use client"

import { regions } from "@/lib"
import { Check, ChevronsUpDown } from "lucide-react"

import { useTranslations } from "@/lib/i18n/client"
import { cn, getCountryName } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command"
import { Label } from "@/components/ui/label"
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover"
import { ScrollArea } from "@/components/ui/scroll-area"

/** Same popular set as /newsite discover country picker, shown first. */
const PRIORITY_COUNTRY_CODES = [
  "US",
  "GB",
  "CA",
  "AU",
  "IN",
  "JP",
  "KR",
  "FR",
  "DE",
  "ES",
  "IT",
  "BR",
  "MX",
  "TR",
  "CN",
  "RU",
  "SE",
  "NO",
  "DK",
] as const

interface DiscoverFilterCountryProps {
  value: string
  onChange: (value: string) => void
}

function parseCountries(value: string) {
  return value
    ? value
        .split("|")
        .map((code) => code.trim().toUpperCase())
        .filter(Boolean)
    : []
}

function countryLabel(code: string) {
  return (
    getCountryName(code) ||
    regions.find((region) => region.iso_3166_1 === code)?.english_name ||
    code
  )
}

export const DiscoverFilterCountry: React.FC<DiscoverFilterCountryProps> = ({
  value,
  onChange,
}) => {
  const { t } = useTranslations()
  const selection = parseCountries(value)

  const selectedLabel = selection.length
    ? selection.map(countryLabel).join(", ")
    : t("discover.selectCountry")

  const toggleCountry = (code: string) => {
    const next = selection.includes(code)
      ? selection.filter((entry) => entry !== code)
      : [...selection, code]
    onChange(next.join("|"))
  }

  const clearSelection = () => onChange("")

  const prioritySet = new Set<string>(PRIORITY_COUNTRY_CODES)
  const priorityRegions = PRIORITY_COUNTRY_CODES.map((code) => {
    const match = regions.find((region) => region.iso_3166_1 === code)
    return (
      match || {
        iso_3166_1: code,
        english_name: countryLabel(code),
        native_name: countryLabel(code),
      }
    )
  })
  const otherRegions = regions.filter(
    (region) => !prioritySet.has(region.iso_3166_1)
  )

  return (
    <div className="space-y-2">
      <Label className="text-sm font-medium text-foreground">
        {t("discover.country")}
      </Label>

      <Popover modal>
        <PopoverTrigger
          className={cn(value ? "text-foreground" : "text-muted-foreground")}
          role="combobox"
          asChild
        >
          <Button
            className="h-10 w-full justify-between rounded-xl border-border/60 bg-background/60 text-left hover:bg-accent/40"
            variant="outline"
          >
            <span className="line-clamp-1">{selectedLabel}</span>
            <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>

        <PopoverContent
          className="w-64 p-0 md:w-80"
          style={{ pointerEvents: "auto" }}
          onWheel={(e) => e.stopPropagation()}
        >
          <Command>
            <CommandInput placeholder={t("discover.searchCountry")} />
            <CommandList>
              <CommandEmpty>{t("common.noResults")}</CommandEmpty>
              <CommandGroup>
                <ScrollArea className="max-h-40 overflow-y-auto">
                  {selection.length > 0 ? (
                    <CommandItem
                      value="__clear__"
                      onSelect={clearSelection}
                      className="text-muted-foreground"
                    >
                      {t("discover.clearSelection")}
                    </CommandItem>
                  ) : null}

                  <CommandItem value="__all__" onSelect={clearSelection}>
                    <Check
                      className={cn(
                        "mr-2 size-4",
                        !selection.length ? "opacity-100" : "opacity-0"
                      )}
                    />
                    {t("discover.all")}
                  </CommandItem>

                  {priorityRegions.map((region) => {
                    const code = region.iso_3166_1
                    const selected = selection.includes(code)
                    return (
                      <CommandItem
                        key={code}
                        value={`${region.english_name} ${code}`}
                        onSelect={() => toggleCountry(code)}
                      >
                        <Check
                          className={cn(
                            "mr-2 size-4",
                            selected ? "opacity-100" : "opacity-0"
                          )}
                        />
                        {region.english_name}
                      </CommandItem>
                    )
                  })}

                  {otherRegions.map((region) => {
                    const code = region.iso_3166_1
                    const selected = selection.includes(code)
                    return (
                      <CommandItem
                        key={code}
                        value={`${region.english_name} ${code}`}
                        onSelect={() => toggleCountry(code)}
                      >
                        <Check
                          className={cn(
                            "mr-2 size-4",
                            selected ? "opacity-100" : "opacity-0"
                          )}
                        />
                        {region.english_name}
                      </CommandItem>
                    )
                  })}
                </ScrollArea>
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}
