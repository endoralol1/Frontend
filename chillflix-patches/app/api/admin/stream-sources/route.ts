import { NextRequest, NextResponse } from "next/server"

import { handleAdminAuthError, requireAdminUser } from "@/lib/admin-auth"
import { getErrorMessage } from "@/lib/api-error"
import { syncCineproProviderAllowlist } from "@/lib/cinepro-allowlist-sync"
import { clearCineproSourceCache } from "@/lib/cinepro-sources-cache"
import {
    getStreamSourcesConfig,
    toPublicStreamSourcesConfig,
    updateStreamSourcesConfig,
} from "@/lib/stream-sources-config"

export async function GET(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const config = await getStreamSourcesConfig()
        return NextResponse.json({
            success: true,
            config,
            ...toPublicStreamSourcesConfig(config),
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }

        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to load stream sources") },
            { status: 500 }
        )
    }
}

export async function PATCH(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const body = await request.json()
        const config = await updateStreamSourcesConfig(body)
        clearCineproSourceCache()
        const allowlistSync = await syncCineproProviderAllowlist(config)

        return NextResponse.json({
            success: true,
            config,
            allowlistSync,
            ...toPublicStreamSourcesConfig(config),
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }

        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to update stream sources") },
            { status: 400 }
        )
    }
}
