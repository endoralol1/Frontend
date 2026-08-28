"use client"

import { useRouter, useSearchParams, usePathname } from "next/navigation"
import { NEWS_COUNTRIES } from "@/lib/news/countries"

export function NewsCountryToggle({ current }: { current: string }) {
  const router = useRouter()
  const pathname = usePathname()
  const searchParams = useSearchParams()

  return (
    <label className="sr-only-n24" style={{ display: "contents" }}>
      <span className="sr-only">Country / language</span>
      <select
        className="n24-country"
        aria-label="Country and language"
        value={current}
        onChange={(event) => {
          const params = new URLSearchParams(searchParams?.toString() || "")
          params.set("country", event.target.value)
          router.push(`${pathname}?${params.toString()}`)
        }}
      >
        {NEWS_COUNTRIES.map((country) => (
          <option key={country.code} value={country.code}>
            {country.nativeLabel}
          </option>
        ))}
      </select>
    </label>
  )
}
