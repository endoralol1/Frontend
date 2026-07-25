import { NextResponse } from "next/server"

import { getChatSettings } from "@/lib/chat-settings"
import { getSiteSettings } from "@/lib/site-settings"

export const dynamic = "force-dynamic"

export async function GET() {
    const settings = await getSiteSettings()
    const chatEnabled = await getChatSettings()
        .then((chat) => chat.enabled)
        .catch(() => false)

    return NextResponse.json(
        {
            ...settings,
            chatEnabled,
        },
        {
            headers: {
                "Cache-Control": "no-store",
            },
        }
    )
}
