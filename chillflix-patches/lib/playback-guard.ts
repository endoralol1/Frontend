import { NextResponse } from "next/server"

import { resolveRequestOrigin } from "@/lib/request-origin"
import { assertIpNotBlocked } from "@/lib/request-security"
import { getSecuritySettingsSync } from "@/lib/security-settings"
import { trackSecurityEvent } from "@/lib/security-event-log"

function normalizeHost(value: string) {
    try {
        const url = new URL(value)
        return url.hostname.toLowerCase()
    } catch {
        return value.toLowerCase()
    }
}

function getAllowedHosts(request: Request) {
    const hosts = new Set<string>()

    const configured = process.env.APP_URL?.trim()
    if (configured) {
        hosts.add(normalizeHost(configured))
    }

    hosts.add(normalizeHost(resolveRequestOrigin(request)))

    const requestHost = request.headers.get("host")?.split(",")[0]?.trim()
    if (requestHost) {
        hosts.add(requestHost.split(":")[0].toLowerCase())
    }

    hosts.add("localhost")
    hosts.add("127.0.0.1")
    hosts.add("www.chillflix.lol")
    hosts.add("chillflix.lol")
    hosts.add("www.chillflix.pw")
    hosts.add("chillflix.pw")

    const configuredHosts = process.env.ALLOWED_PLAYBACK_HOSTS?.split(",") ?? []
    for (const entry of configuredHosts) {
        const trimmed = entry.trim()
        if (trimmed) {
            hosts.add(normalizeHost(trimmed))
        }
    }

    return hosts
}

function hostMatchesAllowed(candidate: string, allowedHosts: Set<string>) {
    const host = normalizeHost(candidate)
    return allowedHosts.has(host)
}

export function isSitePlaybackClient(request: Request) {
    if (process.env.PLAYBACK_GUARD_DISABLED === "true") {
        return true
    }

    if (!getSecuritySettingsSync().playbackGuardEnabled) {
        return true
    }

    const allowedHosts = getAllowedHosts(request)
    const origin = request.headers.get("origin")
    const referer = request.headers.get("referer")

    if (origin && hostMatchesAllowed(origin, allowedHosts)) {
        return true
    }

    if (referer && hostMatchesAllowed(referer, allowedHosts)) {
        return true
    }

    // Same-origin GETs often omit Origin; some mobile browsers also omit Referer.
    // Trust Sec-Fetch-Site from Chromium when the request Host is ours.
    const secFetchSite = request.headers.get("sec-fetch-site")?.toLowerCase()
    const requestHost = request.headers.get("host")?.split(",")[0]?.trim().split(":")[0]
    if (
        requestHost &&
        allowedHosts.has(requestHost.toLowerCase()) &&
        (secFetchSite === "same-origin" || secFetchSite === "same-site")
    ) {
        return true
    }

    return false
}

export function playbackGuardDeniedResponse() {
    return NextResponse.json(
        { error: "Playback is only available inside the Chillflix player." },
        { status: 403 }
    )
}

export function assertSitePlaybackClient(request: Request) {
    const ipDenied = assertIpNotBlocked(request)
    if (ipDenied) return ipDenied

    if (isSitePlaybackClient(request)) {
        return null
    }

    trackSecurityEvent("guardBlocked", { request })
    return playbackGuardDeniedResponse()
}
