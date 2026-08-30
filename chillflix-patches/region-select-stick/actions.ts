"use server"

import { revalidatePath } from "next/cache"
import { cookies } from "next/headers"

import { isLocale, LOCALE_COOKIE } from "@/lib/i18n/locales"

const COOKIE_BASE = {
  path: "/",
  sameSite: "lax" as const,
  maxAge: 60 * 60 * 24 * 365,
}

export async function setRegion(region: string) {
  const code = region.trim().toUpperCase()
  if (!/^[A-Z]{2}$/.test(code)) return

  cookies().set("region", code, COOKIE_BASE)
  revalidatePath("/", "layout")
}

export async function setLocale(locale: string) {
  if (!isLocale(locale)) return

  cookies().set(LOCALE_COOKIE, locale, COOKIE_BASE)
  revalidatePath("/", "layout")
}
