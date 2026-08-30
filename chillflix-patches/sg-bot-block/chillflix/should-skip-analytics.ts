import "server-only"

import { headers } from "next/headers"

const BOT_UA_PATTERNS = [
    /python/i,
    /curl/i,
    /wget/i,
    /headlesschrome/i,
    /semrush/i,
    /ahrefs/i,
    /scrapy/i,
    /petalbot/i,
    /bytespider/i,
]

/** Countries that inflate GA with desktop browser farms (not real viewers). */
const BLOCKED_ANALYTICS_COUNTRIES = new Set(["SG"])

export function shouldSkipAnalytics(): boolean {
    const h = headers()
    const ua = h.get("user-agent") ?? ""

    if (BOT_UA_PATTERNS.some((pattern) => pattern.test(ua))) {
        return true
    }

    const country = (h.get("cf-ipcountry") || "").toUpperCase()
    if (country && BLOCKED_ANALYTICS_COUNTRIES.has(country)) {
        return true
    }

    if (process.env.NODE_ENV === "production" && !h.get("cf-ray")) {
        return true
    }

    return false
}
