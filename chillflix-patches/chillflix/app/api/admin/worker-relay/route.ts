import { NextRequest, NextResponse } from "next/server"

import { handleAdminAuthError, requireAdminUser } from "@/lib/admin-auth"
import { getErrorMessage } from "@/lib/api-error"
import {
    bounceCineproForRelay,
    fetchAllWorkerAnalytics,
    fetchWorkerAnalytics,
    getWorkerRelayConfig,
    readYoruRelayScript,
    testWorkerRelay,
    toPublicWorkerRelayConfig,
    updateWorkerRelayConfig,
} from "@/lib/worker-relay-admin"

export async function GET(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const download = request.nextUrl.searchParams.get("download")
        if (download === "script" || download === "1" || download === "view") {
            const script = await readYoruRelayScript()
            // Open as a plain text page (no download) so admins can Ctrl+A / paste into CF.
            return new NextResponse(script.content, {
                status: 200,
                headers: {
                    "Content-Type": "text/plain; charset=utf-8",
                    "Content-Disposition": 'inline; filename="yoru-relay.js"',
                    "Cache-Control": "no-store",
                    "X-Worker-Script-Path": script.path,
                },
            })
        }
        const config = await getWorkerRelayConfig()
        return NextResponse.json({
            success: true,
            config: toPublicWorkerRelayConfig(config),
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to load worker relay config") },
            { status: 500 }
        )
    }
}

export async function PATCH(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const body = await request.json()
        const config = await updateWorkerRelayConfig(body)
        // JSON is hot-reloaded; restart only refreshes env fallbacks.
        const restart = await bounceCineproForRelay()
        return NextResponse.json({
            success: true,
            config: toPublicWorkerRelayConfig(config),
            restart,
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to save worker relay config") },
            { status: 500 }
        )
    }
}

export async function POST(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const body = (await request.json()) as {
            action?: string
            url?: string
            secret?: string
            id?: string
        }

        if (body.action === "test") {
            const config = await getWorkerRelayConfig()
            let url = String(body.url || "").trim()
            let secret = String(body.secret || "").trim()

            if (body.id) {
                const match = config.workers.find((w) => w.id === body.id)
                if (match) {
                    url = url || match.url
                    if (!secret || secret.includes("…")) secret = match.secret
                }
            }

            const result = await testWorkerRelay({ url, secret })
            return NextResponse.json({ success: true, result })
        }

        if (body.action === "stats") {
            const config = await getWorkerRelayConfig()
            if (body.id) {
                const match = config.workers.find((w) => w.id === body.id)
                if (!match) {
                    return NextResponse.json({ error: "Worker not found" }, { status: 404 })
                }
                const stats = await fetchWorkerAnalytics(match)
                return NextResponse.json({ success: true, stats: [stats] })
            }
            const stats = await fetchAllWorkerAnalytics()
            return NextResponse.json({ success: true, stats })
        }

        return NextResponse.json({ error: "Unknown action" }, { status: 400 })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }
        return NextResponse.json(
            { error: getErrorMessage(error, "Worker relay action failed") },
            { status: 500 }
        )
    }
}
