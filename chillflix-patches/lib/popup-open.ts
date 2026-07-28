/** Best-effort popup / new-tab open for mobile + “Request Desktop Site”. */

export function openPopupUrl(url: string, target = "_blank"): Window | null {
    const href = url.trim()
    if (!href) return null

    try {
        const opened = window.open(href, target, "noopener,noreferrer")
        if (opened) return opened
    } catch {
        // fall through to anchor
    }

    return openViaAnchor(href, target)
}

/** Some mobile browsers accept synthetic <a target=_blank> clicks more reliably than window.open. */
export function openViaAnchor(url: string, target = "_blank"): Window | null {
    try {
        const anchor = document.createElement("a")
        anchor.href = url
        anchor.target = target
        anchor.rel = "noopener noreferrer"
        anchor.style.display = "none"
        document.body.appendChild(anchor)
        anchor.click()
        anchor.remove()
        return null
    } catch {
        return null
    }
}
