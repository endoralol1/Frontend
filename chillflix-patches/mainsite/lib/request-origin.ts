export function resolveRequestOrigin(request: Request) {
    const { origin: fallbackOrigin } = new URL(request.url)
    const forwardedProto = request.headers.get("x-forwarded-proto")?.split(",")[0]?.trim()
    const forwardedHost = request.headers.get("x-forwarded-host")?.split(",")[0]?.trim()
    const host = request.headers.get("host")?.split(",")[0]?.trim()
    const effectiveHost = forwardedHost ?? host

    // Prefer the live request host so proxy URLs match www vs apex (APP_URL alone
    // caused chillflix.lol links on www.chillflix.lol pages → apex 301 CORS failures).
    if (effectiveHost) {
        const isLocal =
            effectiveHost.startsWith("localhost") ||
            effectiveHost.startsWith("127.0.0.1") ||
            effectiveHost.startsWith("[::1]")

        // Internal middleware rewrites use 127.0.0.1 — never emit that to HLS clients.
        if (isLocal) {
            const configured = process.env.APP_URL?.trim() || process.env.NEXT_PUBLIC_SITE_URL?.trim()
            if (configured) {
                return configured.replace(/\/$/, "")
            }
            return "https://www.chillflix.lol"
        }

        const proto = forwardedProto ?? "https"
        return `${proto}://${effectiveHost}`
    }

    const configured = process.env.APP_URL?.trim()
    if (configured) {
        return configured.replace(/\/$/, "")
    }

    return fallbackOrigin
}
