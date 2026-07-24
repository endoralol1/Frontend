"use client"

import { Suspense, useEffect, useState } from "react"
import { GoogleAnalytics } from "@next/third-parties/google"

import { GoogleAnalyticsPageView } from "@/components/google-analytics-page-view"
import { scheduleAfterLoad } from "@/lib/schedule-after-load"

/**
 * GA competes with hydration on cold loads. Boot it after first paint/idle.
 */
export function DeferredGoogleAnalytics({ gaId }: { gaId: string }) {
  const [ready, setReady] = useState(false)

  useEffect(() => {
    return scheduleAfterLoad(() => setReady(true), {
      timeoutMs: 4_000,
      delayMs: 1_800,
    })
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
