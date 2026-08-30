import { NextRequest, NextResponse } from "next/server"

import {
    isMaintenanceExemptPath,
    isStaffSession,
    maintenanceLoginUrl,
} from "@/lib/maintenance-access"

const MAINTENANCE_CACHE_MS = 120_000
let maintenanceCache: { value: boolean; checkedAt: number } | null = null
let maintenanceCheckInFlight: Promise<boolean> | null = null

async function fetchMaintenanceMode(origin: string): Promise<boolean> {
    try {
        const response = await fetch(new URL("/site-status.json", origin), {
            cache: "force-cache",
            signal: AbortSignal.timeout(2_000),
        })
        if (!response.ok) return maintenanceCache?.value ?? false

        const data = (await response.json()) as { maintenanceMode?: boolean }
        const value = Boolean(data.maintenanceMode)
        maintenanceCache = { value, checkedAt: Date.now() }
        return value
    } catch {
        return maintenanceCache?.value ?? false
    }
}

async function isMaintenanceMode(request: NextRequest) {
    const now = Date.now()
    if (maintenanceCache && now - maintenanceCache.checkedAt < MAINTENANCE_CACHE_MS) {
        return maintenanceCache.value
    }

    if (!maintenanceCheckInFlight) {
        maintenanceCheckInFlight = fetchMaintenanceMode(request.nextUrl.origin).finally(() => {
            maintenanceCheckInFlight = null
        })
    }

    return maintenanceCheckInFlight
}

function isStaticAsset(pathname: string) {
    return (
        pathname.startsWith("/_next") ||
        pathname === "/favicon.ico" ||
        pathname.startsWith("/favicon") ||
        pathname === "/site-status.json" ||
        pathname === "/sw.js" ||
        pathname.endsWith("iptv-play-sw.js") ||
        pathname.endsWith("iptv-sw.js") ||
        pathname.startsWith("/iptv-play/") ||
        pathname.startsWith("/api/iptv/live/") ||
        /^\/play\/[^/]+\/index\.m3u8$/i.test(pathname) ||
        pathname === "/iptv-fetch" ||
        pathname === "/api/iptv/live-fetch" ||
        /\.(?:svg|png|jpg|jpeg|gif|webp|ico|woff2?)$/i.test(pathname)
    )
}

export async function middleware(request: NextRequest) {
    const { pathname } = request.nextUrl

    // Singapore desktop bot farm — nginx also enforces; cookie cf_human_ok bypasses.
    const cfCountry = (request.headers.get("cf-ipcountry") || "").toUpperCase()
    const humanOk = request.cookies.get("cf_human_ok")?.value === "1"
    const unlockPath = pathname === "/sg-unlock" || pathname === "/sg-unlock/"
    if (cfCountry === "SG" && !humanOk && !unlockPath && !pathname.startsWith("/api") && !pathname.startsWith("/_next")) {
        if (
            !pathname.startsWith("/embed") &&
            !isStaticAsset(pathname)
        ) {
            return new NextResponse("Forbidden", {
                status: 403,
                headers: { "Cache-Control": "no-store", "CDN-Cache-Control": "no-store" },
            })
        }
    }
    if (unlockPath || request.nextUrl.searchParams.get("unlock_sg") === "1") {
        const res = NextResponse.redirect(new URL("/", request.url))
        res.cookies.set("cf_human_ok", "1", {
            path: "/",
            maxAge: 60 * 60 * 24 * 7,
            secure: true,
            sameSite: "lax",
        })
        return res
    }

    // Soft bot defense: absurd pagination on similar/recommendations (nginx also enforces)
    if (
        /^\/(movie|tv)\/\d+\/(similar|recommendations)\/?$/.test(pathname)
    ) {
        const page = Number(request.nextUrl.searchParams.get("page") || "1")
        if (Number.isFinite(page) && page >= 21) {
            return new NextResponse("Gone", { status: 410 })
        }
    }


    const requestHeaders = new Headers(request.headers)
    requestHeaders.set("x-pathname", pathname)

    const regionCookie = request.cookies.get("region")?.value
    if (regionCookie) {
        requestHeaders.set("x-user-region", regionCookie)
    } else {
        const cfCountry = request.headers.get("cf-ipcountry")
        if (cfCountry && cfCountry !== "XX") {
            requestHeaders.set("x-user-region", cfCountry)
        }
    }

    const livePlayMatch = pathname.match(/^\/play\/([^/]+)\/index\.m3u8$/i)
    if (livePlayMatch) {
        // IMPORTANT: do not clone nextUrl with https + rewrite to localhost.
        // With X-Forwarded-Proto=https (Cloudflare), Next tried
        // https://localhost:3000/... → EPROTO (HTTP port) → 500 for all IPTV.
        const dest = request.nextUrl.clone()
        dest.pathname = `/api/iptv/live/${livePlayMatch[1]}/index.m3u8`
        dest.protocol = "http:"
        dest.host = "127.0.0.1:3000"
        return NextResponse.rewrite(dest, {
            request: { headers: requestHeaders },
        })
    }

    if (pathname === "/people") {
        const url = request.nextUrl.clone()
        url.pathname = "/people/popular"
        return NextResponse.redirect(url)
    }

    if (pathname === "/trending/movie") {
        const url = request.nextUrl.clone()
        url.pathname = "/trending"
        url.searchParams.set("type", "movie")
        return NextResponse.redirect(url)
    }

    if (pathname === "/trending/tv") {
        const url = request.nextUrl.clone()
        url.pathname = "/trending"
        url.searchParams.set("type", "tv")
        return NextResponse.redirect(url)
    }

    if (pathname === "/v1/proxy" || (pathname === "/proxy" && request.nextUrl.search)) {
        const url = request.nextUrl.clone()
        url.pathname = "/api/cinepro/proxy"
        return NextResponse.rewrite(url, {
            request: { headers: requestHeaders },
        })
    }

    if (pathname.startsWith("/admin")) {
        const staff = await isStaffSession(request)
        if (!staff) {
            const loginUrl = new URL("/login", request.url)
            loginUrl.searchParams.set("from", pathname)
            return NextResponse.redirect(loginUrl)
        }
    }

    if (pathname.startsWith("/api/admin")) {
        const staff = await isStaffSession(request)
        if (!staff) {
            return NextResponse.json({ error: "Unauthorized" }, { status: 401 })
        }
    }

    if (pathname.startsWith("/embed/")) {
        const response = NextResponse.next({
            request: { headers: requestHeaders },
        })
        const { resolveEmbedFrameAncestorsCsp } = await import("@/lib/embed-frame-policy")
        response.headers.set(
            "Content-Security-Policy",
            resolveEmbedFrameAncestorsCsp(request.headers.get("host"))
        )
        return response
    }

    if (isStaticAsset(pathname) || pathname.startsWith("/api")) {
        return NextResponse.next({
            request: { headers: requestHeaders },
        })
    }

    const maintenance = await isMaintenanceMode(request)

    if (maintenance) {
        const staff = await isStaffSession(request)

        if (!staff) {
            if (pathname === "/register" || pathname === "/maintenance") {
                return NextResponse.redirect(new URL(maintenanceLoginUrl(), request.url))
            }

            if (!isMaintenanceExemptPath(pathname)) {
                return NextResponse.redirect(new URL(maintenanceLoginUrl(pathname), request.url))
            }
        }
    }

    return NextResponse.next({
        request: { headers: requestHeaders },
    })
}

export const config = {
    matcher: ["/((?!_next/static|_next/image).*)"],
}
