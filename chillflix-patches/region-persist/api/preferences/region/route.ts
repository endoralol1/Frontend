import { NextResponse } from "next/server"
import { revalidatePath } from "next/cache"

export const dynamic = "force-dynamic"

export async function POST(request: Request) {
  let body: { region?: string } = {}
  try {
    body = await request.json()
  } catch {
    return NextResponse.json({ ok: false, error: "invalid_json" }, { status: 400 })
  }

  const code = String(body.region || "").trim().toUpperCase()
  if (!/^[A-Z]{2}$/.test(code)) {
    return NextResponse.json({ ok: false, error: "invalid_region" }, { status: 400 })
  }

  const res = NextResponse.json({ ok: true, region: code })
  res.cookies.set("region", code, {
    path: "/",
    sameSite: "lax",
    secure: true,
    httpOnly: false,
    maxAge: 60 * 60 * 24 * 365,
  })
  revalidatePath("/", "layout")
  return res
}
