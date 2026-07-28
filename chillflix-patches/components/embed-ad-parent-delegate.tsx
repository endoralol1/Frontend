"use client"

import { useEffect } from "react"

import { EMBED_MESSAGE_DELEGATE_POPUP } from "@/lib/embed-ad-delegate"
import { getEmbedChildOrigin } from "@/lib/embed-auth-handoff"
import { openPopupUrl } from "@/lib/popup-open"

/** Opens popunder URLs delegated from chillflix.pw embed iframes on chillflix.lol. */
export function EmbedAdParentDelegate() {
    useEffect(() => {
        const embedOrigin = getEmbedChildOrigin()

        const onMessage = (event: MessageEvent) => {
            if (event.origin !== embedOrigin) return
            if (event.data?.type !== EMBED_MESSAGE_DELEGATE_POPUP) return

            const url = event.data?.url
            if (typeof url !== "string" || !url.trim()) return

            openPopupUrl(url, "_blank")
        }

        window.addEventListener("message", onMessage)
        return () => window.removeEventListener("message", onMessage)
    }, [])

    return null
}
