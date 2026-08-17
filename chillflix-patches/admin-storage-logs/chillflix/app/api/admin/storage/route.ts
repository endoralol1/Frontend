import { NextRequest, NextResponse } from "next/server"

import { handleAdminAuthError, requireAdminUser } from "@/lib/admin-auth"
import {
    cleanAdminStorageTarget,
    getAdminStorageInventory,
} from "@/lib/admin-storage"
import { getErrorMessage } from "@/lib/api-error"

export async function GET(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const inventory = getAdminStorageInventory()
        return NextResponse.json({
            success: true,
            ...inventory,
            checkedAt: new Date().toISOString(),
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }
        console.error("Admin storage inventory failed:", error)
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to load storage inventory") },
            { status: 500 }
        )
    }
}

export async function POST(request: NextRequest) {
    try {
        await requireAdminUser(request, "owner")
        const body = await request.json().catch(() => ({}))
        const id = typeof body?.id === "string" ? body.id : ""
        if (!id) {
            return NextResponse.json({ error: "Storage id is required." }, { status: 400 })
        }

        const result = cleanAdminStorageTarget(id)
        if ("error" in result) {
            return NextResponse.json({ error: result.error }, { status: result.status })
        }

        return NextResponse.json({
            success: true,
            message: result.message,
            freedBytes: result.freedBytes,
        })
    } catch (error) {
        const authError = handleAdminAuthError(error)
        if (authError) {
            return NextResponse.json({ error: authError.error }, { status: authError.status })
        }
        console.error("Admin storage clean failed:", error)
        return NextResponse.json(
            { error: getErrorMessage(error, "Failed to clean storage target") },
            { status: 500 }
        )
    }
}
