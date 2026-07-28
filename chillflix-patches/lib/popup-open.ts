/**
 * Best-effort popup / new-tab open for phones using Chrome “Desktop site”.
 *
 * Important: do NOT pass `noopener` as a window.open feature string. On mobile
 * Chrome (including Desktop-site mode) that often returns null and/or blocks the
 * popunder Monetag expects. Use target=_blank only; set rel on <a> fallbacks.
 */

export function openPopupUrl(url: string, target = "_blank"): Window | null {
    const href = url.trim()
    if (!href) return null

    try {
        // No feature string — required for reliable opens on mobile desktop-site.
        const opened = window.open(href, target)
        if (opened) return opened
    } catch {
        // fall through
    }

    return openViaAnchor(href, target) ?? openViaForm(href, target)
}

/** Synthetic <a target=_blank> — often accepted when window.open is blocked. */
export function openViaAnchor(url: string, target = "_blank"): Window | null {
    try {
        const anchor = document.createElement("a")
        anchor.href = url
        anchor.target = target
        // Keep opener for mobile desktop-site popunders; noreferrer alone is fine.
        anchor.rel = "noreferrer"
        anchor.style.display = "none"
        document.body.appendChild(anchor)
        anchor.click()
        anchor.remove()
        return null
    } catch {
        return null
    }
}

/** Form target=_blank submit — last-resort path some mobile browsers still allow. */
export function openViaForm(url: string, target = "_blank"): Window | null {
    try {
        const form = document.createElement("form")
        form.method = "GET"
        form.action = url
        form.target = target
        form.rel = "noreferrer"
        form.style.display = "none"
        document.body.appendChild(form)
        form.submit()
        form.remove()
        return null
    } catch {
        return null
    }
}
