import { NextResponse } from "next/server"
import { revalidatePath } from "next/cache"
import { isLocale, LOCALE_COOKIE } from "@/lib/i18n/locales"

export const dynamic = "force-dynamic"

export async function POST(request: Request) {
  let body: { locale?: string } = {}
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ ok: false, error: "invalid_json" }, { status: 400 })
  }

  const locale = String(body.locale || "")
  if (!isLocale(locale)) {
    return NextResponse.json({ ok: false, error: "invalid_locale" }, { status: 400 })
  }

  const res = NextResponse.json({ ok: true, locale })
  res.cookies.set(LOCALE_COOKIE, locale, {
    path: "/",
    sameSite: "lax",
    secure: true,
    httpOnly: false,
    maxAge: 60 * 60 * 24 * 365,
  })
  revalidatePath("/", "layout")
  return res
}
