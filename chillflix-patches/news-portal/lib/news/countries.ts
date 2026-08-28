import type { NewsCountry } from "./types"

/** Global default + popular country/language editions (Google News RSS params). */
export const NEWS_COUNTRIES: NewsCountry[] = [
  {
    code: "GLOBAL",
    label: "World",
    nativeLabel: "World",
    lang: "en",
    hl: "en-US",
    gl: "US",
    ceid: "US:en",
  },
  {
    code: "US",
    label: "United States",
    nativeLabel: "United States",
    lang: "en",
    hl: "en-US",
    gl: "US",
    ceid: "US:en",
  },
  {
    code: "GB",
    label: "United Kingdom",
    nativeLabel: "United Kingdom",
    lang: "en",
    hl: "en-GB",
    gl: "GB",
    ceid: "GB:en",
  },
  {
    code: "HR",
    label: "Croatia",
    nativeLabel: "Hrvatska",
    lang: "hr",
    hl: "hr",
    gl: "HR",
    ceid: "HR:hr",
  },
  {
    code: "DE",
    label: "Germany",
    nativeLabel: "Deutschland",
    lang: "de",
    hl: "de",
    gl: "DE",
    ceid: "DE:de",
  },
  {
    code: "FR",
    label: "France",
    nativeLabel: "France",
    lang: "fr",
    hl: "fr",
    gl: "FR",
    ceid: "FR:fr",
  },
  {
    code: "ES",
    label: "Spain",
    nativeLabel: "España",
    lang: "es",
    hl: "es",
    gl: "ES",
    ceid: "ES:es",
  },
  {
    code: "IT",
    label: "Italy",
    nativeLabel: "Italia",
    lang: "it",
    hl: "it",
    gl: "IT",
    ceid: "IT:it",
  },
  {
    code: "BR",
    label: "Brazil",
    nativeLabel: "Brasil",
    lang: "pt",
    hl: "pt-BR",
    gl: "BR",
    ceid: "BR:pt-419",
  },
  {
    code: "IN",
    label: "India",
    nativeLabel: "India",
    lang: "en",
    hl: "en-IN",
    gl: "IN",
    ceid: "IN:en",
  },
  {
    code: "JP",
    label: "Japan",
    nativeLabel: "日本",
    lang: "ja",
    hl: "ja",
    gl: "JP",
    ceid: "JP:ja",
  },
  {
    code: "TR",
    label: "Turkey",
    nativeLabel: "Türkiye",
    lang: "tr",
    hl: "tr",
    gl: "TR",
    ceid: "TR:tr",
  },
]

export const NEWS_CATEGORIES = [
  { id: "top", label: "News" },
  { id: "world", label: "World" },
  { id: "business", label: "Business" },
  { id: "technology", label: "Sci/Tech" },
  { id: "sports", label: "Sport" },
  { id: "entertainment", label: "Show" },
  { id: "health", label: "Life" },
] as const

export type NewsCategoryId = (typeof NEWS_CATEGORIES)[number]["id"]

export function getNewsCountry(code?: string | null): NewsCountry {
  const normalized = (code || "GLOBAL").toUpperCase()
  return (
    NEWS_COUNTRIES.find((c) => c.code === normalized) ?? NEWS_COUNTRIES[0]
  )
}
