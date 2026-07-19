import { resolveProviderUserLabel } from "@/lib/provider-display"
import { normalizeProviderName } from "@/lib/stream-sources-defaults"

import type { TranslateFn } from "@/lib/i18n/translate"

export function getTitleUnavailableMessage(t: TranslateFn) {
    return t("player.unavailable.default")
}

export function maskPlaybackDiagnosticMessageWithT(
    t: TranslateFn,
    message: string,
    providerOrder: readonly string[],
    showRealProviderNames: boolean
) {
    if (showRealProviderNames || !message) return message

    const colonMatch = message.match(/^([^:]+):\s*(.+)$/)
    if (!colonMatch) return message

    const providerKey = normalizeProviderName(colonMatch[1].trim())
    const detail = colonMatch[2].trim()
    const label = resolveProviderUserLabel(providerKey, colonMatch[1].trim(), providerOrder, false)

    if (detail.toLowerCase().includes("failed to fetch api")) {
        return t("player.unavailable.providerNoTitle", { label })
    }

    if (detail.toLowerCase().includes("blocked by cloudflare")) {
        return t("player.unavailable.providerBlocked", { label })
    }

    if (/no stream|not available|no sources/i.test(detail)) {
        return t("player.unavailable.providerNoTitle", { label })
    }

    return `${label}: ${detail}`
}

export function sanitizePublicPlaybackStatusWithT(
    t: TranslateFn,
    message: string,
    showRealProviderNames: boolean
) {
    if (showRealProviderNames || !message) return message

    if (message === "Resolving CinePro providers…") return t("player.status.findingStream")
    if (message === "Checking other providers…") return t("player.status.checkingOther")
    if (/cinepro/i.test(message)) {
        return t("player.status.couldNotLoad")
    }

    return maskPlaybackDiagnosticMessageWithT(t, message, [], false)
}

/** @deprecated Use getTitleUnavailableMessage(t) for localized UI. */
export const TITLE_NOT_AVAILABLE_MESSAGE =
    "This title isn't available on Chillflix right now. None of our sources returned a stream — try again later or choose another title."

const INFRASTRUCTURE_DIAGNOSTIC_CODES = new Set([
    "CINEPRO_OFFLINE",
    "CINEPRO_UNAVAILABLE",
    "CINEPRO_NOT_CONFIGURED",
    "CINEPRO_SELF_REFERENCE",
    "NO_ENABLED_PROVIDER_STREAMS",
    "ENABLED_PROVIDER_UNAVAILABLE",
])

type PlaybackDiagnostic = {
    code?: string
    message?: string
    severity?: string
}

export function isPlaybackInfrastructureDiagnostic(code?: string) {
    return Boolean(code && INFRASTRUCTURE_DIAGNOSTIC_CODES.has(code))
}

export function findInfrastructurePlaybackMessage(diagnostics: PlaybackDiagnostic[]) {
    return diagnostics.find((item) => isPlaybackInfrastructureDiagnostic(item.code))?.message
}

export function resolveTitleUnavailableMessage(diagnostics: PlaybackDiagnostic[]) {
    const infra = findInfrastructurePlaybackMessage(diagnostics)
    if (infra) return infra

    const configuredEmpty = diagnostics.find(
        (item) =>
            item.code === "NO_ENABLED_PROVIDER_STREAMS" ||
            item.code === "ENABLED_PROVIDER_UNAVAILABLE"
    )?.message
    if (configuredEmpty) return configuredEmpty

    return TITLE_NOT_AVAILABLE_MESSAGE
}

/** Hide scraper names in user-facing provider errors (e.g. "VixSrc: Failed to fetch api"). */
export function maskPlaybackDiagnosticMessage(
    message: string,
    providerOrder: readonly string[],
    showRealProviderNames: boolean
) {
    if (showRealProviderNames || !message) return message

    const colonMatch = message.match(/^([^:]+):\s*(.+)$/)
    if (!colonMatch) return message

    const providerKey = normalizeProviderName(colonMatch[1].trim())
    const detail = colonMatch[2].trim()
    const label = resolveProviderUserLabel(providerKey, colonMatch[1].trim(), providerOrder, false)

    if (detail.toLowerCase().includes("failed to fetch api")) {
        return `${label} doesn't have this title.`
    }

    if (detail.toLowerCase().includes("blocked by cloudflare")) {
        return `${label} is blocked from our server IP. Use another provider or configure an outbound proxy.`
    }

    if (/no stream|not available|no sources/i.test(detail)) {
        return `${label} doesn't have this title.`
    }

    return `${label}: ${detail}`
}

export function pickUserFacingPlaybackWarning(args: {
    runtimeError?: string
    fetchError?: string
    diagnostics: PlaybackDiagnostic[]
    hasPlayableSource: boolean
    sourcesLoading: boolean
    sourcesLoadingMore: boolean
    providerOrder: readonly string[]
    showRealProviderNames: boolean
}) {
    if (args.sourcesLoading || args.sourcesLoadingMore) return undefined

    const infraMessage = findInfrastructurePlaybackMessage(args.diagnostics)
    if (infraMessage) return infraMessage

    if (args.hasPlayableSource) {
        return undefined
    }

    if (args.runtimeError) {
        return maskPlaybackDiagnosticMessage(
            args.runtimeError,
            args.providerOrder,
            args.showRealProviderNames
        )
    }

    if (args.fetchError) {
        return maskPlaybackDiagnosticMessage(
            args.fetchError,
            args.providerOrder,
            args.showRealProviderNames
        )
    }

    const partialWarning = args.diagnostics.find((item) => item.code === "PARTIAL_SCRAPE")
    if (partialWarning?.message && args.hasPlayableSource) {
        return undefined
    }

    return resolveTitleUnavailableMessage(args.diagnostics)
}

/** Hide infrastructure/scraper names in public player UI (embed + non-admin). */
export function sanitizePublicPlaybackStatus(
    message: string,
    showRealProviderNames: boolean
) {
    if (showRealProviderNames || !message) return message

    if (message === "Resolving CinePro providers…") return "Finding a stream…"
    if (message === "Checking other providers…") return "Checking other streams…"
    if (message === "Buffering… reconnecting") return "Reconnecting…"
    if (/cinepro/i.test(message)) {
        return "Couldn't load a stream. Try another source in player settings."
    }

    return maskPlaybackDiagnosticMessage(message, [], false)
}

/**
 * Auto-recovery / scan status — show as a quiet chip, not a Retry CTA.
 * Users should use player settings to pick another source instead.
 */
export function isTransientPlaybackStatusMessage(message?: string) {
    if (!message) return false

    const normalized = message.trim().toLowerCase()
    if (!normalized) return false

    return (
        normalized === "buffering… reconnecting" ||
        normalized === "buffering... reconnecting" ||
        normalized === "reconnecting…" ||
        normalized === "reconnecting..." ||
        normalized === "checking other providers…" ||
        normalized === "checking other providers..." ||
        normalized === "resolving cinepro providers…" ||
        normalized === "resolving cinepro providers..." ||
        normalized.startsWith("trying ") ||
        normalized.startsWith("buffering") ||
        /starting (4k|playback)/i.test(normalized)
    )
}

/** Hard failures where Retry is still useful (infra / empty catalog). */
export function shouldShowPlaybackRetryButton(message?: string) {
    if (!message || isTransientPlaybackStatusMessage(message)) {
        return false
    }

    return true
}
