"use client"

import React, { useEffect, type WheelEventHandler } from "react"
import {
    Maximize,
    Minimize,
    Pause,
    PictureInPicture,
    PictureInPicture2,
    Play,
    RotateCcw,
    RotateCw,
    Volume2,
    VolumeX,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { useTranslations } from "@/lib/i18n/client"

import type { SourceHealthStatus } from "@/hooks/useSourceHealth"
import type { SubtitleListItem } from "@/components/player/utils/subtitle-display"

import type { IntroDbSegmentKind } from "@/lib/intro-db"

import { PlaybackProgressSlider, VolumeHeatSlider } from "./PlaybackProgressSlider"
import { PlayerEpisodes, type TvEpisodeNavigationProps } from "./PlayerEpisodes"
import { PlayerSettings } from "./PlayerSettings"
import { SkipSegmentButton } from "./SkipSegmentButton"
import { formatTime } from "./utils/time"

interface PlayerControlsProps {
    isPlaying: boolean
    /** Startup / buffering — spin ring centered on the play control. */
    isLoading?: boolean
    watchPartyGuest?: boolean
    currentTime: number
    duration: number
    volume: number
    isMuted: boolean
    isFullscreen: boolean
    onTogglePlay: () => void
    onSeek: (time: number[]) => void
    onToggleMute: () => void
    onVolumeChange: (volume: number[]) => void
    onToggleFullscreen: () => void
    onDoubleClick: () => void
    onWheel: WheelEventHandler<HTMLDivElement>
    show: boolean
    isPiP: boolean
    onTogglePiP: () => void
    playbackRate: number
    onPlaybackRateChange: (rate: number) => void
    qualities: {
        index: number
        height: number
        label: string
    }[]
    currentQuality: number
    onQualityChange: (level: number) => void
    sources?: {
        id: string
        label: string
        provider?: string
        quality?: string
    }[]
    currentSourceId?: string
    onSelectSource?: (id: string) => void | Promise<void>
    onRequestProvider?: (providerId: string) => void | Promise<void>
    sourcesLoadingMore?: boolean
    sourceStatusMessage?: string
    onRefetchSources?: () => void
    unavailableProviders?: string[]
    sourceHealth?: Record<string, SourceHealthStatus>
    activeTestingProviderId?: string
    subtitles?: SubtitleListItem[]
    currentSubtitleId?: string
    onSelectSubtitle?: (id?: string) => void
    audioTracks?: {
        id: string
        label: string
    }[]
    currentAudioTrackId?: string
    onSelectAudioTrack?: (id: string) => void
    onSettingsOpenChange?: (open: boolean) => void
    autoplayEnabled?: boolean
    onAutoplayChange?: (enabled: boolean) => void
    autoNextEnabled?: boolean
    onAutoNextChange?: (enabled: boolean) => void
    showAutoNext?: boolean
    onEpisodesOpenChange?: (open: boolean) => void
    tvNavigation?: TvEpisodeNavigationProps
    externalSubtitlesLoading?: boolean
    showRealProviderNames?: boolean
    hiddenProviderIds?: string[]
    /** Extra top padding on mobile (e.g. cinema header with Watch Party). */
    mobileControlsTopInset?: string
    skipSegment?: {
        kind: IntroDbSegmentKind
        onSkip: () => void
    } | null
}

export function PlayerControls({
    isPlaying,
    isLoading = false,
    watchPartyGuest = false,
    currentTime,
    duration,
    volume,
    isMuted,
    isFullscreen,
    onTogglePlay,
    onSeek,
    onToggleMute,
    onVolumeChange,
    onToggleFullscreen,
    show,
    onDoubleClick,
    onWheel,
    isPiP,
    onTogglePiP,
    playbackRate,
    onPlaybackRateChange,
    qualities,
    currentQuality,
    onQualityChange,
    sources,
    currentSourceId,
    onSelectSource,
    onRequestProvider,
    sourcesLoadingMore = false,
    sourceStatusMessage,
    onRefetchSources,
    unavailableProviders = [],
    sourceHealth,
    activeTestingProviderId,
    subtitles,
    currentSubtitleId,
    onSelectSubtitle,
    audioTracks,
    currentAudioTrackId,
    onSelectAudioTrack,
    onSettingsOpenChange,
    autoplayEnabled,
    onAutoplayChange,
    autoNextEnabled,
    onAutoNextChange,
    showAutoNext,
    onEpisodesOpenChange,
    tvNavigation,
    externalSubtitlesLoading = false,
    showRealProviderNames,
    hiddenProviderIds = [],
    mobileControlsTopInset,
    skipSegment = null,
}: PlayerControlsProps) {
    const { t } = useTranslations()
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement
            const tag = target?.tagName?.toLowerCase()

            if (tag === "input" || tag === "textarea" || target?.isContentEditable) {
                return
            }

            if (watchPartyGuest) {
                if (e.code === "ArrowUp" || e.code === "ArrowDown") {
                    e.preventDefault()
                    const delta = e.code === "ArrowUp" ? 0.05 : -0.05
                    onVolumeChange([Math.min(1, Math.max(0, Number((volume + delta).toFixed(2))))])
                }
                return
            }

            switch (e.code) {
                case "Space":
                    e.preventDefault()
                    onTogglePlay()
                    break
                case "ArrowLeft":
                    e.preventDefault()
                    onSeek([Math.max(0, currentTime - 10)])
                    break
                case "ArrowRight":
                    e.preventDefault()
                    onSeek([Math.min(duration, currentTime + 10)])
                    break
                case "ArrowUp":
                    e.preventDefault()
                    onVolumeChange([Math.min(1, Number((volume + 0.05).toFixed(2)))])
                    break
                case "ArrowDown":
                    e.preventDefault()
                    onVolumeChange([Math.max(0, Number((volume - 0.05).toFixed(2)))])
                    break
            }
        }

        window.addEventListener("keydown", handleKeyDown)
        return () => window.removeEventListener("keydown", handleKeyDown)
    }, [currentTime, duration, volume, watchPartyGuest, onTogglePlay, onSeek, onVolumeChange])

    return (
        <>
            {skipSegment ? (
                <div className="pointer-events-none absolute inset-x-0 bottom-28 z-[10060] flex justify-center px-4 sm:bottom-32">
                    <SkipSegmentButton
                        kind={skipSegment.kind}
                        onSkip={skipSegment.onSkip}
                        className="pointer-events-auto shadow-2xl"
                    />
                </div>
            ) : null}

            <div
                className={`absolute inset-0 z-40 pointer-events-none flex flex-col justify-end bg-gradient-to-t from-black/20 via-transparent to-black/15 px-4 pt-1 pb-4 transition-opacity duration-300 ${show ? "opacity-100" : "opacity-0"
                    }`}
                style={{ paddingBottom: "max(1rem, env(safe-area-inset-bottom))" }}
                onDoubleClick={onDoubleClick}
                onWheel={onWheel}
            >
            <div
                className="pointer-events-auto absolute inset-x-4 top-0 flex items-center justify-end gap-1 sm:gap-2 md:hidden"
                style={{
                    paddingTop: mobileControlsTopInset
                        ? `max(${mobileControlsTopInset}, env(safe-area-inset-top))`
                        : "max(0.25rem, env(safe-area-inset-top))",
                }}
                onClick={(e) => e.stopPropagation()}
                onWheel={(e) => e.stopPropagation()}
                onDoubleClick={(e) => e.stopPropagation()}
            >
                {tvNavigation ? (
                    <PlayerEpisodes
                        showId={tvNavigation.showId}
                        showTitle={tvNavigation.showTitle}
                        currentSeason={tvNavigation.currentSeason}
                        currentEpisode={tvNavigation.currentEpisode}
                        onEpisodeSelect={tvNavigation.onEpisodeSelect}
                        onOpenChange={onEpisodesOpenChange}
                    />
                ) : null}

                <PlayerSettings
                    playbackRate={playbackRate}
                    onPlaybackRateChange={onPlaybackRateChange}
                    qualities={qualities}
                    currentQuality={currentQuality}
                    onQualityChange={onQualityChange}
                    sources={sources}
                    currentSourceId={currentSourceId}
                    onSelectSource={onSelectSource}
                    onRequestProvider={onRequestProvider}
                    sourcesLoadingMore={sourcesLoadingMore}
                    sourceStatusMessage={sourceStatusMessage}
                    onRefetchSources={onRefetchSources}
                    unavailableProviders={unavailableProviders}
                    sourceHealth={sourceHealth}
                    activeTestingProviderId={activeTestingProviderId}
                    subtitles={subtitles}
                    currentSubtitleId={currentSubtitleId}
                    onSelectSubtitle={onSelectSubtitle}
                    audioTracks={audioTracks}
                    currentAudioTrackId={currentAudioTrackId}
                    onSelectAudioTrack={onSelectAudioTrack}
                    onOpenChange={onSettingsOpenChange}
                    externalSubtitlesLoading={externalSubtitlesLoading}
                    autoplayEnabled={autoplayEnabled}
                    onAutoplayChange={onAutoplayChange}
                    autoNextEnabled={autoNextEnabled}
                    onAutoNextChange={onAutoNextChange}
                    showAutoNext={showAutoNext}
                    showRealProviderNames={showRealProviderNames}
                    hiddenProviderIds={hiddenProviderIds}
                />

                <Button variant="ghost" size="icon" onClick={onTogglePiP} className="text-white hover:bg-white/20" title={isPiP ? t("player.exitPictureInPicture") : t("player.pictureInPicture")}>
                    {isPiP ? <PictureInPicture2 className="h-5 w-5" /> : <PictureInPicture className="h-5 w-5" />}
                </Button>
            </div>

            {!watchPartyGuest ? (
                <div
                    className="pointer-events-none absolute inset-0 flex items-center justify-center md:hidden"
                    onClick={(e) => e.stopPropagation()}
                    onWheel={(e) => e.stopPropagation()}
                    onDoubleClick={(e) => e.stopPropagation()}
                >
                    <div className="pointer-events-auto flex items-center justify-center gap-2">
                        <Button variant="ghost" size="icon" className="text-white hover:bg-white/20" onClick={() => onSeek([Math.max(0, currentTime - 10)])} title={t("player.back10s")}>
                            <RotateCcw width={20} height={20} />
                        </Button>

                        <div className="relative flex size-11 items-center justify-center">
                            {isLoading && !isPlaying ? (
                                <span
                                    aria-hidden
                                    className="pointer-events-none absolute inset-0 animate-spin rounded-full border-2 border-white/25 border-t-white"
                                />
                            ) : null}
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={onTogglePlay}
                                className="relative z-10 text-white hover:bg-white/20"
                                title={isPlaying ? t("player.pause") : t("player.play")}
                            >
                                {isPlaying ? <Pause className="h-6 w-6 fill-current" /> : <Play className="h-6 w-6 fill-current" />}
                            </Button>
                        </div>

                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => onSeek([Math.min(duration, currentTime + 10)])}
                            className="text-white hover:bg-white/20"
                            title={t("player.forward10s")}
                        >
                            <RotateCw width={20} height={20} />
                        </Button>
                    </div>
                </div>
            ) : null}

            <div className="pointer-events-auto mx-auto w-full" onClick={(e) => e.stopPropagation()} onWheel={(e) => e.stopPropagation()} onDoubleClick={(e) => e.stopPropagation()}>
                <div className="md:hidden">
                    <div className="flex items-center gap-2">
                        <div className="shrink-0 text-xs font-medium text-white/90 tabular-nums">
                            {formatTime(currentTime)} / {formatTime(duration)}
                        </div>

                        <div className="flex-1">
                            <PlaybackProgressSlider
                                currentTime={currentTime}
                                duration={duration}
                                onSeek={onSeek}
                                readOnly={watchPartyGuest}
                                className="cursor-pointer"
                            />
                        </div>

                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onToggleFullscreen}
                            className="text-white hover:bg-white/20"
                            title={isFullscreen ? t("player.exitFullscreen") : t("player.fullscreen")}
                        >
                            {isFullscreen ? <Minimize className="h-5 w-5" /> : <Maximize className="h-5 w-5" />}
                        </Button>
                    </div>
                </div>

                <div className="hidden md:block">
                    <div className="group relative py-2">
                        <PlaybackProgressSlider
                            currentTime={currentTime}
                            duration={duration}
                            onSeek={onSeek}
                            readOnly={watchPartyGuest}
                            className="cursor-pointer"
                        />
                    </div>

                    <div className="flex items-center justify-between gap-4">
                        <div className="flex min-w-0 items-center gap-2">
                            {!watchPartyGuest ? (
                                <>
                                    <Button variant="ghost" size="icon" className="text-white hover:bg-white/20" onClick={() => onSeek([Math.max(0, currentTime - 10)])} title={t("player.back10s")}>
                                        <RotateCcw width={20} height={20} />
                                    </Button>

                                    <div className="relative flex size-11 items-center justify-center">
                                        {isLoading && !isPlaying ? (
                                            <span
                                                aria-hidden
                                                className="pointer-events-none absolute inset-0 animate-spin rounded-full border-2 border-white/25 border-t-white"
                                            />
                                        ) : null}
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={onTogglePlay}
                                            className="relative z-10 text-white hover:bg-white/20"
                                            title={isPlaying ? t("player.pause") : t("player.play")}
                                        >
                                            {isPlaying ? <Pause className="h-6 w-6 fill-current" /> : <Play className="h-6 w-6 fill-current" />}
                                        </Button>
                                    </div>

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => onSeek([Math.min(duration, currentTime + 10)])}
                                        className="text-white hover:bg-white/20"
                                        title={t("player.forward10s")}
                                    >
                                        <RotateCw width={20} height={20} />
                                    </Button>
                                </>
                            ) : null}

                            <div className={`flex min-w-0 items-center gap-2 ${watchPartyGuest ? "" : "ml-4"}`}>
                                <Button variant="ghost" size="icon" onClick={onToggleMute} className="text-white hover:bg-white/20" title={isMuted || volume === 0 ? t("player.unmute") : t("player.mute")}>
                                    {isMuted || volume === 0 ? <VolumeX className="h-5 w-5" /> : <Volume2 className="h-5 w-5" />}
                                </Button>

                                <div className="w-24">
                                    <VolumeHeatSlider
                                        volume={volume}
                                        isMuted={isMuted}
                                        onVolumeChange={onVolumeChange}
                                        className="cursor-pointer"
                                    />
                                </div>
                            </div>

                            <div className="ml-4 text-sm font-medium text-white/90 tabular-nums">
                                {formatTime(currentTime)} / {formatTime(duration)}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            {tvNavigation ? (
                                <PlayerEpisodes
                                    showId={tvNavigation.showId}
                                    showTitle={tvNavigation.showTitle}
                                    currentSeason={tvNavigation.currentSeason}
                                    currentEpisode={tvNavigation.currentEpisode}
                                    onEpisodeSelect={tvNavigation.onEpisodeSelect}
                                    onOpenChange={onEpisodesOpenChange}
                                />
                            ) : null}

                            <PlayerSettings
                                playbackRate={playbackRate}
                                onPlaybackRateChange={onPlaybackRateChange}
                                qualities={qualities}
                                currentQuality={currentQuality}
                                onQualityChange={onQualityChange}
                                sources={sources}
                                currentSourceId={currentSourceId}
                                onSelectSource={onSelectSource}
                                onRequestProvider={onRequestProvider}
                                sourcesLoadingMore={sourcesLoadingMore}
                                sourceStatusMessage={sourceStatusMessage}
                                onRefetchSources={onRefetchSources}
                                unavailableProviders={unavailableProviders}
                                sourceHealth={sourceHealth}
                                subtitles={subtitles}
                                currentSubtitleId={currentSubtitleId}
                                onSelectSubtitle={onSelectSubtitle}
                                audioTracks={audioTracks}
                                currentAudioTrackId={currentAudioTrackId}
                                onSelectAudioTrack={onSelectAudioTrack}
                                onOpenChange={onSettingsOpenChange}
                                externalSubtitlesLoading={externalSubtitlesLoading}
                                autoplayEnabled={autoplayEnabled}
                                onAutoplayChange={onAutoplayChange}
                                autoNextEnabled={autoNextEnabled}
                                onAutoNextChange={onAutoNextChange}
                                showAutoNext={showAutoNext}
                                showRealProviderNames={showRealProviderNames}
                                hiddenProviderIds={hiddenProviderIds}
                            />

                            <Button variant="ghost" size="icon" onClick={onTogglePiP} className="text-white hover:bg-white/20" title={isPiP ? t("player.exitPictureInPicture") : t("player.pictureInPicture")}>
                                {isPiP ? <PictureInPicture2 className="h-5 w-5" /> : <PictureInPicture className="h-5 w-5" />}
                            </Button>

                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={onToggleFullscreen}
                                className="text-white hover:bg-white/20"
                                title={isFullscreen ? t("player.exitFullscreen") : t("player.fullscreen")}
                            >
                                {isFullscreen ? <Minimize className="h-5 w-5" /> : <Maximize className="h-5 w-5" />}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </>
    )
}
