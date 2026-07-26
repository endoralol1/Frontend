import { NextResponse } from "next/server"
import { readFile } from "fs/promises"
import path from "path"

/**
 * Mapple-style first-party Monetag anti-adblock library.
 * Prefer locally refreshed file (cron from adbpage.com). Browser never hits adbpage.com
 * because EasyList blocks it — server/cron fetches, we serve from /api/rum.
 */
const LOCAL_LIB = path.join(process.cwd(), "public/cdn/rum.js")
const UPSTREAM = "https://adbpage.com/adblock?v=3&format=js&lnxv=2"

export const dynamic = "force-dynamic"

export async function GET() {
    try {
        try {
            const body = await readFile(LOCAL_LIB, "utf8")
            if (body && body.length > 1000) {
                return new NextResponse(body, {
                    status: 200,
                    headers: {
                        "Content-Type": "application/javascript; charset=utf-8",
                        "Cache-Control": "public, max-age=300, s-maxage=300",
                        "X-Content-Type-Options": "nosniff",
                    },
                })
            }
        } catch {
            // fall through
        }

        const upstream = await fetch(UPSTREAM, {
            headers: { Accept: "*/*", "User-Agent": "Mozilla/5.0" },
            cache: "no-store",
        })
        if (!upstream.ok) {
            return new NextResponse("/* unavailable */", {
                status: 502,
                headers: { "Content-Type": "application/javascript; charset=utf-8" },
            })
        }
        const body = await upstream.text()
        return new NextResponse(body, {
            status: 200,
            headers: {
                "Content-Type": "application/javascript; charset=utf-8",
                "Cache-Control": "public, max-age=300, s-maxage=300",
                "X-Content-Type-Options": "nosniff",
            },
        })
    } catch {
        return new NextResponse("/* error */", {
            status: 502,
            headers: { "Content-Type": "application/javascript; charset=utf-8" },
        })
    }
}
