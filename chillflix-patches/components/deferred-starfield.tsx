"use client"

import { useEffect, useState } from "react"
import dynamic from "next/dynamic"

import { scheduleAfterLoad } from "@/lib/schedule-after-load"

const StarfieldBackground = dynamic(
  () =>
    import("@/components/starfield-background").then((module) => ({
      default: module.StarfieldBackground,
    })),
  { ssr: false }
)

/**
 * Keep the first paint free of the storm canvas + its JS chunk.
 * CSS sky still shows; motion mounts after load/idle.
 */
export function DeferredStarfield() {
  const [ready, setReady] = useState(false)

  useEffect(() => {
    return scheduleAfterLoad(() => setReady(true), {
      timeoutMs: 2_000,
      delayMs: 400,
    })
  }, [])

  if (!ready) return null
  return <StarfieldBackground />
}
