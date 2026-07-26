import {
    EMBED_ADS_ACLIB_FIRSTPARTY_PATH,
    EMBED_ADS_ANTIBLOCK_BOOT_PATH,
    EMBED_ADS_JNBHI_TAG_SCRIPT_SRC,
    EMBED_ADS_LLVPN_TAG_SCRIPT_SRC,
    EMBED_ADS_TAG_ON_ERROR,
    EMBED_ADS_TAG_ON_LOAD,
    EMBED_ADS_VIGNETTE_SCRIPT_SRC,
    type VignetteAdConfig,
} from "@/lib/embed-ads-shared"

export type EmbedAdIntegration = "llvpn" | "monetag-antiblock" | "aclib-firstparty"

export type EmbedAdScriptBundle = {
    integration: EmbedAdIntegration
    zone: string
    llvpnTag?: string
    antiAdblockBoot?: string
    jnbhiTag?: string
    aclibSrc?: string
    tagOnError?: string
    tagOnLoad?: string
}

type AclibWindow = Window & {
    aclib?: {
        runPop?: (options: { zoneId: string }) => void
    }
}

function appendScript(script: HTMLScriptElement) {
    const mountTarget = [document.documentElement, document.body].filter(Boolean).pop()
    mountTarget?.appendChild(script)
}

function injectLlvpnTag(scripts: EmbedAdScriptBundle) {
    if (document.getElementById("chillflix-embed-ads-llvpn-tag")) return

    const tag = document.createElement("script")
    tag.id = "chillflix-embed-ads-llvpn-tag"
    tag.dataset.zone = scripts.zone
    tag.src = scripts.llvpnTag ?? EMBED_ADS_LLVPN_TAG_SCRIPT_SRC
    tag.async = true
    tag.setAttribute("data-cfasync", "false")
    appendScript(tag)
}

/** Mapple-style: first-party anti-adblock lib (/api/rum) + runPop(zone 11200416). */
function injectAclibFirstParty(scripts: EmbedAdScriptBundle) {
    if (document.getElementById("chillflix-aclib-rum")) return

    const zoneId = String(scripts.zone)

    const tryRunPop = () => {
        const aclib = (window as AclibWindow).aclib
        if (typeof aclib?.runPop !== "function") return false
        try {
            aclib.runPop({ zoneId })
            return true
        } catch {
            return false
        }
    }

    const runWithRetry = () => {
        if (tryRunPop()) return
        let attempts = 0
        const timer = window.setInterval(() => {
            attempts += 1
            if (tryRunPop() || attempts >= 24) {
                window.clearInterval(timer)
            }
        }, 250)
    }

    const tag = document.createElement("script")
    tag.id = "chillflix-aclib-rum"
    tag.src = scripts.aclibSrc ?? EMBED_ADS_ACLIB_FIRSTPARTY_PATH
    tag.async = true
    tag.setAttribute("data-cfasync", "false")
    tag.dataset.zone = zoneId
    tag.onload = runWithRetry
    tag.onerror = () => {
        // fail closed — keep old revenue path unused here on purpose
    }
    appendScript(tag)
}

function injectMonetagAntiblock(scripts: EmbedAdScriptBundle) {
    if (document.getElementById("chillflix-embed-ads-jnbhi-tag")) return

    const injectTag = () => {
        if (document.getElementById("chillflix-embed-ads-jnbhi-tag")) return

        const rawSrc = scripts.jnbhiTag ?? EMBED_ADS_JNBHI_TAG_SCRIPT_SRC
        const tag = document.createElement("script")
        tag.id = "chillflix-embed-ads-jnbhi-tag"
        tag.src = rawSrc.startsWith("//") ? `https:${rawSrc}` : rawSrc
        tag.async = true
        tag.setAttribute("data-cfasync", "false")
        tag.dataset.zone = scripts.zone

        const onError = scripts.tagOnError ?? EMBED_ADS_TAG_ON_ERROR
        const onLoad = scripts.tagOnLoad ?? EMBED_ADS_TAG_ON_LOAD
        tag.onerror = () => {
            const handler = (window as Window & { [key: string]: (() => void) | undefined })[onError]
            handler?.()
        }
        tag.onload = () => {
            const handler = (window as Window & { [key: string]: (() => void) | undefined })[onLoad]
            handler?.()
        }

        appendScript(tag)
    }

    const existingBoot = document.getElementById(
        "chillflix-embed-ads-antiblock-boot"
    ) as HTMLScriptElement | null
    if (existingBoot) {
        if (existingBoot.dataset.loaded === "1") {
            injectTag()
        } else {
            existingBoot.addEventListener("load", injectTag, { once: true })
        }
        return
    }

    const boot = document.createElement("script")
    boot.id = "chillflix-embed-ads-antiblock-boot"
    boot.type = "text/javascript"
    boot.setAttribute("data-cfasync", "false")
    boot.src = scripts.antiAdblockBoot ?? EMBED_ADS_ANTIBLOCK_BOOT_PATH
    boot.addEventListener(
        "load",
        () => {
            boot.dataset.loaded = "1"
            injectTag()
        },
        { once: true }
    )
    boot.addEventListener("error", injectTag, { once: true })
    document.head.appendChild(boot)
}

export function injectEmbedAdScripts(scripts: EmbedAdScriptBundle) {
    if (typeof document === "undefined") return

    if (scripts.integration === "aclib-firstparty") {
        injectAclibFirstParty(scripts)
        return
    }

    if (scripts.integration === "monetag-antiblock") {
        injectMonetagAntiblock(scripts)
        return
    }

    injectLlvpnTag(scripts)
}

export function injectVignetteAd(config: VignetteAdConfig) {
    if (typeof document === "undefined") return
    if (document.getElementById("chillflix-vignette-ad")) return

    const mountTarget = [document.documentElement, document.body].filter(Boolean).pop()
    if (!mountTarget) return

    const tag = document.createElement("script")
    tag.id = "chillflix-vignette-ad"
    tag.dataset.zone = config.zone
    tag.src = config.src || EMBED_ADS_VIGNETTE_SCRIPT_SRC
    mountTarget.appendChild(tag)
}
