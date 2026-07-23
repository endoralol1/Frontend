"use client"

import { useCallback, useEffect, useMemo, useRef, useState } from "react"

import { playbackUrlWithoutToken } from "@/components/player/utils/playback"
import { CLIENT_SOURCE_PROBE_TIMEOUT_MS } from "@/lib/source-probe-constants"

export type SourceHealthStatus = "idle" | "checking" | "ready" | "failed"

type ProbeSource = {
    id: string
    url: string
    type: string
}

export type PlaybackProbeContext = {
    type: "movie" | "tv"
    tmdbId: string
    season?: string
    episode?: string
}

interface UseSourceHealthOptions {
    enabled?: boolean
    /** Probe one source at a time in list order, cycling through the list. */
    sequential?: boolean
    /** Re-test failed sources after a full pass (with backoff). */
    continuous?: boolean
    /** Stop probing after the first source becomes ready. */
    stopOnFirstReady?: boolean
    /** Trust server-resolved sources and skip network health probes. */
    skipProbe?: boolean
    /** Source IDs to mark ready when skipProbe is true (defaults to all sources). */
    trustedSourceIds?: string[]
    playbackContext?: PlaybackProbeContext
    /** Probe only primarySourceIds during this window, then parallel secondary probes. */
    headStartMs?: number
    primarySourceIds?: readonly string[]
}

const CYCLE_PAUSE_MS = 100
const FAILED_RETRY_MS = 5 * 60 * 1000
const RETRY_PASS_PAUSE_MS = 30_000
const MAX_RETRY_PASSES = 1
const CLIENT_PROBE_TIMEOUT_MS = CLIENT_SOURCE_PROBE_TIMEOUT_MS

async function probeSourceUrlOnce(
    url: string,
    playbackContext: PlaybackProbeContext | undefined,
    signal: AbortSignal
) {
    const params = new URLSearchParams({ url })

    if (playbackContext) {
        params.set("type", playbackContext.type)
        params.set("tmdbId", playbackContext.tmdbId)
        if (playbackContext.season) {
            params.set("season", playbackContext.season)
        }
        if (playbackContext.episode) {
            params.set("episode", playbackContext.episode)
        }
    }

    const response = await fetch(`/api/cinepro/probe?${params}`, {
        cache: "no-store",
        signal,
    })
    const data = await response.json().catch(() => ({}))
    return {
        ok: Boolean(data.ok),
        status: typeof data.status === "number" ? data.status : response.status,
        transient:
            response.status === 429 ||
            data.status === 429 ||
            data.status === 502 ||
            data.status === 503,
    }
}

async function probeSourceUrl(
    url: string,
    playbackContext?: PlaybackProbeContext,
    parentSignal?: AbortSignal
) {
    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), CLIENT_PROBE_TIMEOUT_MS)
    const onParentAbort = () => controller.abort()
    if (parentSignal) {
        if (parentSignal.aborted) {
            controller.abort()
        } else {
            parentSignal.addEventListener("abort", onParentAbort)
        }
    }

    try {
        const first = await probeSourceUrlOnce(url, playbackContext, controller.signal)
        if (first.ok || !first.transient || controller.signal.aborted) {
            return first.ok
        }

        // CDN / proxy rate-limits are often brief — one short backoff before failing the source.
        await sleep(600)
        if (controller.signal.aborted || parentSignal?.aborted) {
            return false
        }

        const second = await probeSourceUrlOnce(url, playbackContext, controller.signal)
        return second.ok
    } finally {
        clearTimeout(timeout)
        if (parentSignal) {
            parentSignal.removeEventListener("abort", onParentAbort)
        }
    }
}

function sleep(ms: number) {
    return new Promise<void>((resolve) => window.setTimeout(resolve, ms))
}

function buildSourceSignature(sources: ProbeSource[]) {
    return sources
        .map((source) => `${source.id}:${source.type}:${playbackUrlWithoutToken(source.url)}`)
        .join("|")
}

function hasAnyReadySource(
    sources: ProbeSource[],
    health: Record<string, SourceHealthStatus>
) {
    return sources.some((source) => health[source.id] === "ready")
}

export function useSourceHealth(
    sources: ProbeSource[],
    enabledOrOptions: boolean | UseSourceHealthOptions = true
) {
    const options =
        typeof enabledOrOptions === "boolean"
            ? {
                  enabled: enabledOrOptions,
                  sequential: true,
                  continuous: false,
                  stopOnFirstReady: true,
              }
            : {
                  enabled: enabledOrOptions.enabled ?? true,
                  sequential: enabledOrOptions.sequential ?? true,
                  continuous: enabledOrOptions.continuous ?? false,
                  stopOnFirstReady: enabledOrOptions.stopOnFirstReady ?? true,
                  skipProbe: enabledOrOptions.skipProbe ?? false,
                  trustedSourceIds: enabledOrOptions.trustedSourceIds,
                  playbackContext: enabledOrOptions.playbackContext,
                  headStartMs: enabledOrOptions.headStartMs,
                  primarySourceIds: enabledOrOptions.primarySourceIds,
              }

    const [health, setHealth] = useState<Record<string, SourceHealthStatus>>({})
    const healthRef = useRef(health)
    const sourcesRef = useRef(sources)
    const playbackContextRef = useRef(options.playbackContext)
    const sourceUrlsRef = useRef<Record<string, string>>({})
    const failedAtRef = useRef<Record<string, number>>({})
    const cycleIndexRef = useRef(0)
    const retryPassRef = useRef(0)
    const probeStartedAtRef = useRef<number | undefined>(undefined)
    const parallelSecondaryStartedRef = useRef(false)
    const primarySourceIdSetRef = useRef<Set<string>>(new Set())
    const pumpTaskRef = useRef<Promise<void> | null>(null)
    const cancelledRef = useRef(false)
    const activeProbeAbortRef = useRef<AbortController | null>(null)
    const activeProbeAbortsRef = useRef<Set<AbortController>>(new Set())
    const firstReadySourceIdRef = useRef<string | undefined>(undefined)
    const [firstReadySourceId, setFirstReadySourceId] = useState<string | undefined>(undefined)

    const sourceSignature = useMemo(() => buildSourceSignature(sources), [sources])

    useEffect(() => {
        healthRef.current = health
    }, [health])

    useEffect(() => {
        sourcesRef.current = sources
    }, [sources])

    useEffect(() => {
        playbackContextRef.current = options.playbackContext
    }, [options.playbackContext])

    const stopProbing = useCallback(() => {
        cancelledRef.current = true
        activeProbeAbortRef.current?.abort()
        activeProbeAbortRef.current = null
        for (const controller of activeProbeAbortsRef.current) {
            controller.abort()
        }
        activeProbeAbortsRef.current.clear()
    }, [])

    useEffect(() => {
        const nextUrls: Record<string, string> = {}
        for (const source of sources) {
            nextUrls[source.id] = source.url
        }

        const previousUrls = sourceUrlsRef.current
        sourceUrlsRef.current = nextUrls

        const changedIds = sources
            .filter((source) => {
                const previousUrl = previousUrls[source.id]
                if (!previousUrl) return false
                return (
                    playbackUrlWithoutToken(previousUrl) !== playbackUrlWithoutToken(source.url)
                )
            })
            .map((source) => source.id)

        if (changedIds.length === 0) {
            return
        }

        setHealth((previous) => {
            const next = { ...previous }
            for (const id of changedIds) {
                if (next[id] === "ready") {
                    continue
                }
                delete next[id]
                delete failedAtRef.current[id]
            }
            return next
        })
    }, [sourceSignature, sources])

    const runProbe = useCallback(async (source: ProbeSource) => {
        if (cancelledRef.current) {
            return false
        }

        const current = healthRef.current[source.id]
        if (current === "checking" || current === "ready") {
            return current === "ready"
        }

        setHealth((previous) => ({ ...previous, [source.id]: "checking" }))

        const controller = new AbortController()
        activeProbeAbortRef.current = controller
        activeProbeAbortsRef.current.add(controller)

        try {
            const ok = await probeSourceUrl(
                source.url,
                playbackContextRef.current,
                controller.signal
            )

            if (controller.signal.aborted || cancelledRef.current) {
                setHealth((previous) => {
                    if (previous[source.id] !== "checking") {
                        return previous
                    }

                    const next = { ...previous }
                    delete next[source.id]
                    return next
                })
                return false
            }

            setHealth((previous) => ({
                ...previous,
                [source.id]: ok ? "ready" : "failed",
            }))
            if (!ok) {
                failedAtRef.current[source.id] = Date.now()
            } else {
                delete failedAtRef.current[source.id]
                if (!firstReadySourceIdRef.current) {
                    firstReadySourceIdRef.current = source.id
                    setFirstReadySourceId(source.id)
                }
                if (options.stopOnFirstReady) {
                    stopProbing()
                }
            }
            return ok
        } catch (error) {
            if (controller.signal.aborted || cancelledRef.current) {
                setHealth((previous) => {
                    if (previous[source.id] !== "checking") {
                        return previous
                    }

                    const next = { ...previous }
                    delete next[source.id]
                    return next
                })
                return false
            }

            setHealth((previous) => ({ ...previous, [source.id]: "failed" }))
            failedAtRef.current[source.id] = Date.now()
            return false
        } finally {
            activeProbeAbortsRef.current.delete(controller)
            if (activeProbeAbortRef.current === controller) {
                activeProbeAbortRef.current = null
            }
        }
    }, [options.stopOnFirstReady, stopProbing])

    const canRetryFailedSource = useCallback((sourceId: string) => {
        const failedAt = failedAtRef.current[sourceId]
        if (!failedAt) {
            return true
        }

        return Date.now() - failedAt >= FAILED_RETRY_MS
    }, [])

    useEffect(() => {
        primarySourceIdSetRef.current = new Set(options.primarySourceIds ?? [])
    }, [options.primarySourceIds])

    const inPrimaryHeadStart = useCallback(() => {
        const headStartMs = options.headStartMs ?? 0
        if (!headStartMs || !probeStartedAtRef.current) {
            return false
        }

        return Date.now() - probeStartedAtRef.current < headStartMs
    }, [options.headStartMs])

    const kickParallelSecondaryProbes = useCallback(() => {
        if (parallelSecondaryStartedRef.current) {
            return
        }

        const primarySources = sourcesRef.current.filter((source) =>
            primarySourceIdSetRef.current.has(source.id)
        )

        if (primarySources.length > 0) {
            const anyPrimaryReady = primarySources.some(
                (source) => healthRef.current[source.id] === "ready"
            )
            if (anyPrimaryReady) {
                return
            }

            const anyPrimaryPending = primarySources.some((source) => {
                const status = healthRef.current[source.id]
                return status !== "ready" && status !== "failed"
            })
            if (anyPrimaryPending) {
                return
            }
        }

        parallelSecondaryStartedRef.current = true

        for (const source of sourcesRef.current) {
            if (primarySourceIdSetRef.current.has(source.id)) {
                continue
            }

            const status = healthRef.current[source.id]
            if (status === "ready" || status === "checking" || status === "failed") {
                continue
            }

            void runProbe(source)
        }
    }, [runProbe])

    const pickNextSequentialSource = useCallback(() => {
        const ordered = sourcesRef.current
        if (ordered.length === 0) return undefined

        const headStartActive = inPrimaryHeadStart()
        const primaryOnly = headStartActive && primarySourceIdSetRef.current.size > 0

        for (let offset = 0; offset < ordered.length; offset += 1) {
            const index = (cycleIndexRef.current + offset) % ordered.length
            const source = ordered[index]

            if (primaryOnly && !primarySourceIdSetRef.current.has(source.id)) {
                continue
            }

            const status = healthRef.current[source.id]

            if (status === "checking") {
                return undefined
            }

            if (status === "ready") {
                continue
            }

            if (status === "failed") {
                if (!options.continuous || !canRetryFailedSource(source.id)) {
                    continue
                }
            }

            cycleIndexRef.current = (index + 1) % ordered.length
            return source
        }

        return undefined
    }, [canRetryFailedSource, inPrimaryHeadStart, options.continuous])

    const allSourcesResolved = useCallback(() => {
        const ordered = sourcesRef.current
        if (ordered.length === 0) return true

        return ordered.every((source) => {
            const status = healthRef.current[source.id]
            return status === "ready" || status === "failed"
        })
    }, [])

    const shouldStopOnReady = useCallback(() => {
        return (
            options.stopOnFirstReady &&
            hasAnyReadySource(sourcesRef.current, healthRef.current)
        )
    }, [options.stopOnFirstReady])

    const pumpSequential = useCallback(async () => {
        if (!options.enabled || !options.sequential) return

        while (!cancelledRef.current) {
            if (!inPrimaryHeadStart()) {
                kickParallelSecondaryProbes()
            }

            if (shouldStopOnReady()) {
                stopProbing()
                break
            }

            const next = pickNextSequentialSource()
            if (!next) {
                if (shouldStopOnReady()) {
                    stopProbing()
                    break
                }

                if (!options.continuous || retryPassRef.current >= MAX_RETRY_PASSES) {
                    break
                }

                if (allSourcesResolved()) {
                    retryPassRef.current += 1
                    if (retryPassRef.current > MAX_RETRY_PASSES) {
                        break
                    }
                    await sleep(RETRY_PASS_PAUSE_MS)
                    continue
                }

                await sleep(CYCLE_PAUSE_MS * 2)
                continue
            }

            const ok = await runProbe(next)

            if (options.stopOnFirstReady && ok) {
                stopProbing()
                break
            }

            if (shouldStopOnReady()) {
                stopProbing()
                break
            }

            await sleep(CYCLE_PAUSE_MS)
        }
    }, [
        allSourcesResolved,
        inPrimaryHeadStart,
        kickParallelSecondaryProbes,
        options.continuous,
        options.enabled,
        options.sequential,
        options.stopOnFirstReady,
        pickNextSequentialSource,
        runProbe,
        shouldStopOnReady,
        stopProbing,
    ])

    const startSequentialPump = useCallback(() => {
        if (pumpTaskRef.current) return
        if (shouldStopOnReady()) return

        cancelledRef.current = false
        pumpTaskRef.current = pumpSequential().finally(() => {
            pumpTaskRef.current = null
        })
    }, [pumpSequential, shouldStopOnReady])

    const enqueueProbe = useCallback(
        (sourceId: string) => {
            if (shouldStopOnReady()) return

            const source = sourcesRef.current.find((item) => item.id === sourceId)
            if (!source) return

            const status = healthRef.current[sourceId]
            if (status === "ready" || status === "checking") {
                return
            }

            if (options.sequential) {
                startSequentialPump()
                return
            }

            void runProbe(source)
        },
        [options.sequential, runProbe, shouldStopOnReady, startSequentialPump]
    )

    const applyTrustedSources = useCallback(() => {
        const trustedIds =
            options.trustedSourceIds && options.trustedSourceIds.length > 0
                ? options.trustedSourceIds
                : sourcesRef.current.map((source) => source.id)

        if (trustedIds.length === 0) {
            return
        }

        const ready: Record<string, SourceHealthStatus> = {}
        for (const id of trustedIds) {
            ready[id] = "ready"
        }

        setHealth(ready)
        if (!firstReadySourceIdRef.current) {
            firstReadySourceIdRef.current = trustedIds[0]
            setFirstReadySourceId(trustedIds[0])
        }
    }, [options.trustedSourceIds])

    useEffect(() => {
        if (!options.enabled || sources.length === 0) {
            return () => {
                stopProbing()
            }
        }

        if (options.skipProbe) {
            applyTrustedSources()
            return () => {
                stopProbing()
            }
        }

        if (options.trustedSourceIds?.length) {
            setHealth((previous) => {
                const next = { ...previous }
                let changed = false

                for (const id of options.trustedSourceIds!) {
                    if (!sources.some((source) => source.id === id)) continue
                    if (next[id] === "ready") continue
                    next[id] = "ready"
                    changed = true
                }

                return changed ? next : previous
            })

            const firstTrusted = options.trustedSourceIds.find((id) =>
                sources.some((source) => source.id === id)
            )
            if (firstTrusted && !firstReadySourceIdRef.current) {
                firstReadySourceIdRef.current = firstTrusted
                setFirstReadySourceId(firstTrusted)
            }
        }

        if (
            options.stopOnFirstReady &&
            hasAnyReadySource(sources, healthRef.current)
        ) {
            return () => {
                stopProbing()
            }
        }

        cancelledRef.current = false
        cycleIndexRef.current = 0
        retryPassRef.current = 0
        probeStartedAtRef.current = Date.now()
        parallelSecondaryStartedRef.current = false
        const hasTrustedSources = Boolean(
            options.trustedSourceIds?.some((id) => sources.some((source) => source.id === id))
        )
        if (!hasAnyReadySource(sources, healthRef.current) && !hasTrustedSources) {
            firstReadySourceIdRef.current = undefined
            setFirstReadySourceId(undefined)
        }

        if (options.sequential) {
            startSequentialPump()
        } else {
            for (const source of sources) {
                if (shouldStopOnReady()) break
                void runProbe(source)
            }
        }

        return () => {
            stopProbing()
        }
    }, [
        applyTrustedSources,
        options.enabled,
        options.sequential,
        options.skipProbe,
        options.stopOnFirstReady,
        options.trustedSourceIds,
        runProbe,
        shouldStopOnReady,
        sourceSignature,
        sources,
        startSequentialPump,
        stopProbing,
    ])

    const ensureProbed = useCallback(
        async (sourceId: string, force = false) => {
            const source = sourcesRef.current.find((item) => item.id === sourceId)
            if (!source) return false

            const status = healthRef.current[sourceId]
            if (status === "ready") return true

            if (!force && shouldStopOnReady()) {
                return false
            }

            if (status === "failed") {
                delete failedAtRef.current[sourceId]
                setHealth((previous) => ({ ...previous, [sourceId]: "idle" }))
            }

            return runProbe(source)
        },
        [runProbe, shouldStopOnReady]
    )

    const markSourceReady = useCallback((sourceId: string) => {
        if (!sourceId) return

        setHealth((previous) => {
            if (previous[sourceId] === "ready") return previous
            return { ...previous, [sourceId]: "ready" }
        })

        if (!firstReadySourceIdRef.current) {
            firstReadySourceIdRef.current = sourceId
            setFirstReadySourceId(sourceId)
        }
    }, [])

    const markSourceFailed = useCallback((sourceId: string) => {
        if (!sourceId) return

        failedAtRef.current[sourceId] = Date.now()
        setHealth((previous) => {
            if (previous[sourceId] === "failed") return previous
            return { ...previous, [sourceId]: "failed" }
        })

        if (firstReadySourceIdRef.current === sourceId) {
            firstReadySourceIdRef.current = ""
            setFirstReadySourceId(undefined)
        }
    }, [])

    const readySourceIds = useMemo(
        () =>
            sources
                .filter((source) => health[source.id] === "ready")
                .map((source) => source.id),
        [health, sources]
    )

    const activeProbeId = useMemo(
        () => sources.find((source) => health[source.id] === "checking")?.id,
        [health, sources]
    )
    const isProbing = Boolean(activeProbeId)
    const hasReadySource = readySourceIds.length > 0

    return {
        health,
        ensureProbed,
        enqueueProbe,
        markSourceReady,
        markSourceFailed,
        readySourceIds,
        firstReadySourceId: firstReadySourceId ?? readySourceIds[0],
        activeProbeId,
        isProbing,
        hasReadySource,
    }
}
