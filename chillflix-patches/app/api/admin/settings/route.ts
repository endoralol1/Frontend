import { NextRequest, NextResponse } from "next/server"

import { handleAdminAuthError, requireAdminUser } from "@/lib/admin-auth"
import { getErrorMessage } from "@/lib/api-error"
import { getSiteSettings, updateSiteSettings } from "@/lib/site-settings"

export async function GET(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const settings = await getSiteSettings()
        return NextResponse.json({ success: true, settings })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }

        console.error("Admin settings read failed:", error)
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to load settings") },
            { status: 500 }
        )
    }
}

export async function PATCH(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const body = await request.json()
        const settings = await updateSiteSettings({
            registrationEnabled:
                body.registrationEnabled !== undefined
                    ? Boolean(body.registrationEnabled)
                    : undefined,
            maintenanceMode:
                body.maintenanceMode !== undefined ? Boolean(body.maintenanceMode) : undefined,
            watchPartyEnabled:
                body.watchPartyEnabled !== undefined
                    ? Boolean(body.watchPartyEnabled)
                    : undefined,
            continueWatchingEnabled:
                body.continueWatchingEnabled !== undefined
                    ? Boolean(body.continueWatchingEnabled)
                    : undefined,
            playersEnabled:
                body.playersEnabled !== undefined ? Boolean(body.playersEnabled) : undefined,
            iptvEnabled:
                body.iptvEnabled !== undefined ? Boolean(body.iptvEnabled) : undefined,
            musicEnabled:
                body.musicEnabled !== undefined ? Boolean(body.musicEnabled) : undefined,
            ticketsEnabled:
                body.ticketsEnabled !== undefined ? Boolean(body.ticketsEnabled) : undefined,
            apkDownloadEnabled:
                body.apkDownloadEnabled !== undefined
                    ? Boolean(body.apkDownloadEnabled)
                    : undefined,
            apkCustomUrl:
                body.apkCustomUrl !== undefined
                    ? typeof body.apkCustomUrl === "string"
                      ? body.apkCustomUrl
                      : null
                    : undefined,
            apkVersionLabel:
                body.apkVersionLabel !== undefined
                    ? typeof body.apkVersionLabel === "string"
                      ? body.apkVersionLabel
                      : null
                    : undefined,
            sharePromptEnabled:
                body.sharePromptEnabled !== undefined
                    ? Boolean(body.sharePromptEnabled)
                    : undefined,
            communityPromptEnabled:
                body.communityPromptEnabled !== undefined
                    ? Boolean(body.communityPromptEnabled)
                    : undefined,
            discordInviteUrl:
                body.discordInviteUrl !== undefined
                    ? typeof body.discordInviteUrl === "string"
                      ? body.discordInviteUrl
                      : null
                    : undefined,
            telegramInviteUrl:
                body.telegramInviteUrl !== undefined
                    ? typeof body.telegramInviteUrl === "string"
                      ? body.telegramInviteUrl
                      : null
                    : undefined,
        })
        const { writePublicSiteStatus } = await import("@/lib/site-settings-public")
        writePublicSiteStatus(settings)

        return NextResponse.json({ success: true, settings })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }

        console.error("Admin settings update failed:", error)
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to update settings") },
            { status: 500 }
        )
    }
}
