"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { SelectProps } from "@radix-ui/react-select"
import ReactCountryFlag from "react-country-flag"

import {
  LOCALE_LABELS,
  LOCALES,
  getLocaleCountryCode,
  isLocale,
  type Locale,
} from "@/lib/i18n/locales"
import { getMessages } from "@/lib/i18n/messages"
import { createTranslator } from "@/lib/i18n/translate"
import { notifyLibraryUpdated } from "@/hooks/use-user-library"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useToast } from "@/components/ui/use-toast"
import { setLocale } from "@/app/actions"
import { invalidateContinueWatchingRemoteCache } from "@/lib/watch-progress"

function LocaleOption({ locale }: { locale: Locale }) {
  return (
    <div className="flex items-center gap-2">
      <ReactCountryFlag countryCode={getLocaleCountryCode(locale)} svg />
      {LOCALE_LABELS[locale]}
    </div>
  )
}

export const LanguageSelect: React.FC<SelectProps> = ({
  onValueChange,
  value,
  ...props
}) => {
  const router = useRouter()
  const { toast } = useToast()
  const initial = isLocale(value) ? value : "en"
  const [current, setCurrent] = useState<Locale>(initial)

  useEffect(() => {
    if (isLocale(value) && value !== current) {
      setCurrent(value)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value])

  const handleChange = (nextValue: string) => {
    if (!isLocale(nextValue)) return

    setCurrent(nextValue)

    void setLocale(nextValue)
      .then(() => {
        invalidateContinueWatchingRemoteCache()
        notifyLibraryUpdated()
        onValueChange?.(nextValue)
        router.refresh()

        const nextT = createTranslator(getMessages(nextValue))
        toast({
          title: nextT("settings.languageChanged"),
          description: nextT("settings.languageChangedDesc", {
            language: LOCALE_LABELS[nextValue],
          }),
        })
      })
      .catch(() => {
        if (isLocale(value)) setCurrent(value)
      })
  }

  return (
    <Select onValueChange={handleChange} value={current} {...props}>
      <SelectTrigger>
        <SelectValue>
          <LocaleOption locale={current} />
        </SelectValue>
      </SelectTrigger>

      <SelectContent>
        {LOCALES.map((locale) => (
          <SelectItem key={locale} value={locale}>
            <LocaleOption locale={locale} />
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
