"use client"

import { useSourceLoadingProgress } from "@/hooks/useSourceLoadingProgress"
import type { SourceHealthStatus } from "@/hooks/useSourceHealth"
import { useShowRealProviderNames } from "@/hooks/use-show-real-provider-names"
import { useStreamSourcesConfig } from "@/hooks/useStreamSourcesConfig"
import { useTranslations } from "@/lib/i18n/client"
import { getOrderedEnabledProviderIds } from "@/components/player/utils/playback"
import {
    isTransientPlaybackStatusMessage,
    shouldShowPlaybackRetryButton,
} from "@/lib/playback-user-messages"
import { cn } from "@/lib/utils"

import { PlaybackSourceRetryPanel } from "./PlaybackSourceRetryPanel"
import { PlayerSettings } from "./PlayerSettings"
import { SourceProbeFlow } from "./SourceProbeFlow"
import { findInfrastructurePlaybackMessage } from "@/lib/playback-user-messages"

interface SourceLoadingOverlayProps {
    active: boolean
    sources: Array<{ provider: { id?: string; name?: string } }>
    diagnostics: Array<{ code?: string; message?: string }>
    cineproOnly?: boolean
    activeTestingProviderId?: string
    sourceHealth?: Record<string, SourceHealthStatus>
    playbackOptions?: Array<{
        id: string
        label?: string
        provider?: string
        providerId?: string
        quality?: string
    }>
    sourcesLoadingMore?: boolean
    sourcesFetching?: boolean
    playbackStarted?: boolean
    scanFailedProviderIds?: string[]
    className?: string
    /** When false, show Alpha/Beta/etc. instead of scraper names. */
    showRealProviderNames?: boolean
    onRefetchSources?: () => void
    sourceStatusMessage?: string
    currentSourceId?: string
    onSelectSource?: (id: string) => void | Promise<void>
    onRequestProvider?: (providerId: string) => void | Promise<void>
    unavailableProviders?: string[]
    hiddenProviderIds?: string[]
}

export function SourceLoadingOverlay({
    active,
    sources,
    diagnostics,
    cineproOnly = false,
    activeTestingProviderId,
    sourceHealth,
    playbackOptions,
    sourcesLoadingMore = false,
    sourcesFetching = false,
    playbackStarted = false,
    scanFailedProviderIds = [],
    className,
    showRealProviderNames: showRealProviderNamesProp,
    onRefetchSources,
    sourceStatusMessage,
    currentSourceId,
    onSelectSource,
    onRequestProvider,
    unavailableProviders = [],
    hiddenProviderIds = [],
}: SourceLoadingOverlayProps) {
    const { t } = useTranslations()
    const { config, order: providerOrder, enabledIds, loading: streamSourcesLoading } =
        useStreamSourcesConfig()
    const orderedEnabledProviderIds = getOrderedEnabledProviderIds(providerOrder, enabledIds)
    const hookShowRealProviderNames = useShowRealProviderNames()
    const showRealProviderNames = showRealProviderNamesProp ?? hookShowRealProviderNames
    const soapyOnlyFailure = diagnostics.find(
        (item) =>
            item.code === "PROVIDER_ERROR" &&
            typeof item.message === "string" &&
            item.message.toLowerCase().includes("soapy") &&
            item.message.toLowerCase().includes("no custom player links")
    )

    const { rows, currentTestingProvider } = useSourceLoadingProgress({
        active,
        sources,
        diagnostics,
        configuredSources: config.sources,
        cineproOnly,
        activeTestingProviderId,
        sourceHealth,
        playbackOptions,
        sourcesLoadingMore,
        sourcesFetching,
        playbackStarted,
        showAllProvidersWhileActive: true,
        showRealProviderNames,
        providerOrder: orderedEnabledProviderIds,
        scanFailedProviderIds,
    })

    const showProbeFlow =
        active && !playbackStarted && !soapyOnlyFailure && !streamSourcesLoading && rows.length > 0

    const infrastructureMessage =
        sourceStatusMessage ?? findInfrastructurePlaybackMessage(diagnostics)
    const showRetryPanel =
        Boolean(infrastructureMessage) &&
        !sourcesLoadingMore &&
        !sourcesFetching &&
        !streamSourcesLoading &&
        shouldShowPlaybackRetryButton(infrastructureMessage) &&
        !isTransientPlaybackStatusMessage(infrastructureMessage)

    const settingsSources =
        playbackOptions?.map((option) => ({
            id: option.id,
            label: option.label ?? option.provider ?? option.id,
            provider: option.provider,
            providerId: option.providerId,
            quality: option.quality,
        })) ?? []

    const showSettings = Boolean(onSelectSource || onRequestProvider || onRefetchSources)

    // When playback already started, never block the player's own chrome/settings.
    if (playbackStarted) {
        return (
            <div
                className={cn(
                    "pointer-events-none absolute inset-x-0 top-16 z-20 flex justify-center px-4",
                    className
                )}
            >
                <span className="rounded-full bg-black/70 px-4 py-2 text-xs text-white/90">
                    {t("player.loading.startingPlayback")}
                </span>
            </div>
        )
    }

    return (
        <div
            className={cn(
                "relative flex h-full overflow-hidden rounded-md border border-white/10 bg-[radial-gradient(circle_at_top,rgba(255,255,255,0.08),transparent_45%),rgba(0,0,0,0.88)] text-white/80",
                className
            )}
        >
            {showSettings ? (
                <div className="absolute left-3 top-14 z-30 sm:left-4 sm:top-16">
                    <PlayerSettings
                        playbackRate={1}
                        onPlaybackRateChange={() => undefined}
                        qualities={[]}
                        currentQuality={-1}
                        onQualityChange={() => undefined}
                        sources={settingsSources}
                        currentSourceId={currentSourceId}
                        onSelectSource={onSelectSource}
                        onRequestProvider={onRequestProvider}
                        sourcesLoadingMore={sourcesLoadingMore || sourcesFetching}
                        sourceStatusMessage={sourceStatusMessage}
                        onRefetchSources={onRefetchSources}
                        unavailableProviders={unavailableProviders}
                        sourceHealth={sourceHealth}
                        activeTestingProviderId={activeTestingProviderId}
                        showRealProviderNames={showRealProviderNames}
                        hiddenProviderIds={hiddenProviderIds}
                    />
                </div>
            ) : null}

            <div className="flex flex-1 flex-col items-center justify-center px-4 py-8 sm:px-8">
                <div className="relative mb-6 flex items-center justify-center">
                    <div className="h-16 w-16 rounded-full border border-white/10 bg-white/[0.03]" />
                    <div className="absolute h-16 w-16 animate-spin rounded-full border-2 border-white/15 border-t-white/80" />
                    <div className="absolute h-8 w-8 rounded-full bg-white/10 blur-sm" />
                </div>

                {soapyOnlyFailure ? (
                    <div className="max-w-sm text-center">
                        <p className="text-sm font-medium text-white">
                            {t("player.soapyNoStream")}
                        </p>
                        <p className="mt-2 text-xs text-white/55">{soapyOnlyFailure.message}</p>
                    </div>
                ) : showProbeFlow ? (
                    <SourceProbeFlow
                        rows={rows}
                        currentTestingProvider={currentTestingProvider}
                        sourcesLoadingMore={sourcesLoadingMore || sourcesFetching}
                    />
                ) : showRetryPanel ? (
                    <div className="flex max-w-sm flex-col items-center gap-3 text-center">
                        <PlaybackSourceRetryPanel
                            message={infrastructureMessage}
                            onRetry={onRefetchSources}
                            messageClassName="text-white/70"
                            showRetryButton
                        />
                        <p className="text-[11px] text-white/45">
                            {t("player.status.couldNotLoad")}
                        </p>
                    </div>
                ) : isTransientPlaybackStatusMessage(infrastructureMessage) ? (
                    <div className="max-w-sm text-center">
                        <p className="text-sm font-medium text-white">{infrastructureMessage}</p>
                        <p className="mt-2 text-xs text-white/55">
                            {t("player.playerSettingsDesc")}
                        </p>
                    </div>
                ) : (
                    <div className="max-w-sm text-center">
                        <p className="text-sm font-medium text-white">{t("player.loading.pleaseWait")}</p>
                        <p className="mt-2 text-xs text-white/55">{t("player.loading.findingFastest")}</p>
                    </div>
                )}
            </div>
        </div>
    )
}
