"use client"

import { Suspense, useEffect, useState } from "react"
import { GoogleAnalytics } from "@next/third-parties/google"

import { GoogleAnalyticsPageView } from "@/components/google-analytics-page-view"

const HUMAN_KEY = "cf_bot_shield_v1_human"

/**
 * Boot GA only after a trusted user gesture.
 * Idle-only deferral still fires for headless Chrome farms that never interact —
 * those were inflating Realtime (esp. Singapore desktop bots on CF-cached HTML).
 */
export function DeferredGoogleAnalytics({ gaId }: { gaId: string }) {
  const [ready, setReady] = useState(false)

  useEffect(() => {
    let done = false
    const enable = () => {
      if (done) return
      done = true
      setReady(true)
      detach()
    }

    const onGesture = (e: Event) => {
      if (e && "isTrusted" in e && !(e as { isTrusted?: boolean }).isTrusted) return
      try {
        sessionStorage.setItem(HUMAN_KEY, "1")
      } catch {
        /* ignore */
      }
      enable()
    }

    const detach = () => {
      window.removeEventListener("pointerdown", onGesture)
      window.removeEventListener("keydown", onGesture)
      window.removeEventListener("touchstart", onGesture)
      window.removeEventListener("scroll", onGesture)
    }

    try {
      if (sessionStorage.getItem(HUMAN_KEY) === "1") {
        enable()
        return detach
      }
    } catch {
      /* ignore */
    }

    window.addEventListener("pointerdown", onGesture, { passive: true })
    window.addEventListener("keydown", onGesture, { passive: true })
    window.addEventListener("touchstart", onGesture, { passive: true })
    window.addEventListener("scroll", onGesture, { passive: true })

    return detach
  }, [])

  if (!ready) return null

  return (
    <>
      <GoogleAnalytics gaId={gaId} />
      <Suspense fallback={null}>
        <GoogleAnalyticsPageView gaId={gaId} />
      </Suspense>
    </>
  )
}
