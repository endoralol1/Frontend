import { NextResponse } from "next/server"

/** Monetag/Adcash aclib — proxied first-party so EasyList cannot kill acscdn.com / aclib.js. */
const ACLIB_UPSTREAM = "https://acscdn.com/script/aclib.js"

export const dynamic = "force-dynamic"

export async function GET() {
    try {
        const upstream = await fetch(ACLIB_UPSTREAM, {
            headers: {
                Accept: "*/*",
                "User-Agent":
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
            },
            cache: "no-store",
        })

        if (!upstream.ok) {
            return new NextResponse("/* upstream unavailable */", {
                status: 502,
                headers: {
                    "Content-Type": "application/javascript; charset=utf-8",
                    "Cache-Control": "no-store",
                },
            })
        }

        const body = await upstream.text()

        return new NextResponse(body, {
            status: 200,
            headers: {
                "Content-Type": "application/javascript; charset=utf-8",
                "Cache-Control": "public, max-age=600, s-maxage=1800",
                "X-Content-Type-Options": "nosniff",
            },
        })
    } catch {
        return new NextResponse("/* fetch error */", {
            status: 502,
            headers: {
                "Content-Type": "application/javascript; charset=utf-8",
                "Cache-Control": "no-store",
            },
        })
    }
}
