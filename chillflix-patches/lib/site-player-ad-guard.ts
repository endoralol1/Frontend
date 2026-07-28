import { openViaAnchor } from "@/lib/popup-open"

let guardDepth = 0

let nativeOpen: typeof window.open | null = null
let nativeAssign: typeof Location.prototype.assign | null = null
let nativeReplace: typeof Location.prototype.replace | null = null
let nativeHrefSet: ((value: string) => void) | null = null

const trackedPopups = new Set<Window>()
let suppressDismissUntil = 0
let popupWatchTimer: number | null = null

function isExternalNavigation(url: string) {
    try {
        return new URL(url, window.location.href).origin !== window.location.origin
    } catch {
        return true
    }
}

function markAdPopupDismissGrace(ms = 600) {
    suppressDismissUntil = Date.now() + ms
}

function trackAdPopupWindow(popup: Window | null) {
    if (!popup || popup.closed) return

    trackedPopups.add(popup)
    markAdPopupDismissGrace()

    if (popupWatchTimer !== null) return

    popupWatchTimer = window.setInterval(() => {
        for (const win of trackedPopups) {
            if (win.closed) {
                trackedPopups.delete(win)
            }
        }

        if (trackedPopups.size === 0) {
            markAdPopupDismissGrace()
            if (popupWatchTimer !== null) {
                window.clearInterval(popupWatchTimer)
                popupWatchTimer = null
            }
        }
    }, 250)
}

/**
 * Open via native window.open, then <a target=_blank> — never call patched window.open
 * (that used to recurse when mobile/desktop-site blocked popups and killed Monetag).
 */
function openDelegatedPopup(url: string) {
    let opened: Window | null = null
    try {
        opened = nativeOpen
            ? nativeOpen(url, "_blank", "noopener,noreferrer")
            : window.open(url, "_blank", "noopener,noreferrer")
    } catch {
        opened = null
    }

    if (!opened) {
        openViaAnchor(url, "_blank")
        markAdPopupDismissGrace()
    } else {
        trackAdPopupWindow(opened)
    }

    scheduleRestorePlayerFocus()
    return opened
}

function scheduleRestorePlayerFocus() {
    requestAnimationFrame(() => {
        window.focus()
    })
}

function normalizePopupTarget(target?: string) {
    if (!target || target === "_self" || target === "_top" || target === "_parent") {
        return "_blank"
    }
    return target
}

/** True while an ad popup is open or briefly after it closes — suppress overlay dismiss. */
export function shouldSuppressPlayerOverlayDismiss() {
    if (Date.now() < suppressDismissUntil) return true
    for (const win of trackedPopups) {
        if (!win.closed) return true
    }
    return false
}

/** Keep chillflix.lol on the player when Monetag popunder/popup scripts fire. */
export function installSitePlayerAdGuard() {
    if (typeof window === "undefined") return

    guardDepth += 1
    if (guardDepth > 1) return

    nativeOpen = window.open.bind(window)
    window.open = function openWithSitePlayerGuard(
        url?: string | URL,
        target?: string,
        features?: string
    ) {
        const href = url?.toString().trim()
        if (!href || href === "about:blank") {
            const blank = nativeOpen!(url, target, features)
            trackAdPopupWindow(blank)
            return blank
        }

        const safeTarget = normalizePopupTarget(target)
        let opened: Window | null = null
        try {
            opened = nativeOpen!(href, safeTarget, features)
        } catch {
            opened = null
        }

        if (!opened && isExternalNavigation(href)) {
            return openDelegatedPopup(href)
        }

        trackAdPopupWindow(opened)
        scheduleRestorePlayerFocus()
        return opened
    }

    nativeAssign = Location.prototype.assign
    Location.prototype.assign = function assignWithSitePlayerGuard(url: string | URL) {
        const href = url.toString()
        if (isExternalNavigation(href)) {
            openDelegatedPopup(href)
            return
        }
        return nativeAssign!.call(this, url)
    }

    nativeReplace = Location.prototype.replace
    Location.prototype.replace = function replaceWithSitePlayerGuard(url: string | URL) {
        const href = url.toString()
        if (isExternalNavigation(href)) {
            openDelegatedPopup(href)
            return
        }
        return nativeReplace!.call(this, url)
    }

    const hrefDescriptor = Object.getOwnPropertyDescriptor(Location.prototype, "href")
    if (hrefDescriptor?.set) {
        nativeHrefSet = hrefDescriptor.set
        Object.defineProperty(Location.prototype, "href", {
            ...hrefDescriptor,
            set(value: string) {
                if (isExternalNavigation(value)) {
                    openDelegatedPopup(value)
                    return
                }
                nativeHrefSet!.call(this, value)
            },
        })
    }
}

export function uninstallSitePlayerAdGuard() {
    if (typeof window === "undefined") return

    guardDepth = Math.max(0, guardDepth - 1)
    if (guardDepth > 0) return

    if (nativeOpen) {
        window.open = nativeOpen
        nativeOpen = null
    }

    if (nativeAssign) {
        Location.prototype.assign = nativeAssign
        nativeAssign = null
    }

    if (nativeReplace) {
        Location.prototype.replace = nativeReplace
        nativeReplace = null
    }

    if (nativeHrefSet) {
        const hrefDescriptor = Object.getOwnPropertyDescriptor(Location.prototype, "href")
        if (hrefDescriptor) {
            Object.defineProperty(Location.prototype, "href", {
                ...hrefDescriptor,
                set: nativeHrefSet,
            })
        }
        nativeHrefSet = null
    }

    trackedPopups.clear()
    suppressDismissUntil = 0

    if (popupWatchTimer !== null) {
        window.clearInterval(popupWatchTimer)
        popupWatchTimer = null
    }
}
