import { NextResponse } from "next/server"

import { getCurrentUser } from "@/lib/auth"
import {
    getStreamSourcesConfig,
    toPublicStreamSourcesConfig,
} from "@/lib/stream-sources-config"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function GET(request: Request) {
    try {
        const sessionUser = await getCurrentUser(request)
        const config = await getStreamSourcesConfig()
        return NextResponse.json({
            success: true,
            ...toPublicStreamSourcesConfig(config, sessionUser?.role),
            features: {
                vidrockClientPlaybackTest:
                    process.env.NEXT_PUBLIC_VIDROCK_CLIENT_PLAYBACK_TEST === "true",
            },
        })
    } catch (error) {
        const message = error instanceof Error ? error.message : "Failed to load stream sources"
        return NextResponse.json({ success: false, error: message }, { status: 500 })
    }
}
