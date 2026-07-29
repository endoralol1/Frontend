import { normalizeProviderName } from "@/lib/stream-sources-defaults"

export function filterSourcesByEnabledIds<
    T extends { provider: { id: string; name: string } }
>(sources: T[], enabledIds: string[]): T[] {
    if (enabledIds.length === 0) {
        return []
    }

    const enabled = new Set(enabledIds.map((id) => normalizeProviderName(id)))

    return sources.filter((source) =>
        enabled.has(normalizeProviderName(source.provider.id || source.provider.name))
    )
}

export const STREAM_SOURCES_CONFIG_EVENT = "chillflix:stream-sources-config"

export function notifyStreamSourcesConfigChanged() {
    if (typeof window === "undefined") return

    try {
        localStorage.setItem(STREAM_SOURCES_CONFIG_EVENT, String(Date.now()))
    } catch {
        // ignore
    }

    window.dispatchEvent(new Event(STREAM_SOURCES_CONFIG_EVENT))
}
