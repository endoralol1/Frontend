/**
 * Run non-critical boot work after first paint so the homepage feels fast
 * on a cold browser cache. Falls back to setTimeout when IdleCallback is missing.
 */
export function scheduleAfterLoad(
  task: () => void,
  options?: { timeoutMs?: number; delayMs?: number }
) {
  if (typeof window === "undefined") return () => undefined

  const timeoutMs = options?.timeoutMs ?? 2_500
  const delayMs = options?.delayMs ?? 0
  let cancelled = false
  let idleId: number | undefined
  let timerId: number | undefined

  const run = () => {
    if (cancelled) return
    task()
  }

  const start = () => {
    if (cancelled) return
    const ric = (
      window as Window & {
        requestIdleCallback?: (
          cb: IdleRequestCallback,
          opts?: IdleRequestOptions
        ) => number
        cancelIdleCallback?: (id: number) => void
      }
    ).requestIdleCallback

    if (typeof ric === "function") {
      idleId = ric(() => run(), { timeout: timeoutMs })
      return
    }

    timerId = window.setTimeout(run, Math.min(timeoutMs, 1_200))
  }

  if (delayMs > 0) {
    timerId = window.setTimeout(start, delayMs)
  } else if (document.readyState === "complete") {
    start()
  } else {
    window.addEventListener("load", start, { once: true })
  }

  return () => {
    cancelled = true
    if (idleId != null) {
      ;(
        window as Window & { cancelIdleCallback?: (id: number) => void }
      ).cancelIdleCallback?.(idleId)
    }
    if (timerId != null) {
      window.clearTimeout(timerId)
    }
  }
}
