import { NextResponse } from "next/server"

import { getChatSettings } from "@/lib/chat-settings"
import { getSiteSettings } from "@/lib/site-settings"

export const dynamic = "force-dynamic"

export async function GET() {
    const [settings, chat] = await Promise.all([getSiteSettings(), getChatSettings()])

    return NextResponse.json(
        {
            ...settings,
            chatEnabled: chat.enabled,
        },
        {
            headers: {
                "Cache-Control": "no-store",
            },
        }
    )
}
