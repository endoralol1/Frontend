"use client"

import { useEffect, useState } from "react"

import { isLowEndDevice, prefersReducedMotion } from "@/lib/device-profile"

export const StarfieldBackground = () => {
  const [animate, setAnimate] = useState(false)
  const [renderLayers, setRenderLayers] = useState(true)

  useEffect(() => {
    const lowEndDevice = isLowEndDevice()
    document.documentElement.dataset.lowEndDevice = lowEndDevice
      ? "true"
      : "false"
    setAnimate(!lowEndDevice && !prefersReducedMotion())
    setRenderLayers(!lowEndDevice)

    return () => {
      delete document.documentElement.dataset.lowEndDevice
    }
  }, [])

  if (!renderLayers) return null

  const starsClass = animate ? undefined : "stars-layer-static"
  const stormClass = animate ? undefined : "storm-layer-static"

  return (
    <div
      className="site-ambient-sky fixed inset-0 overflow-hidden pointer-events-none"
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="absolute inset-0 bg-[#050608]" />
      <div className={["storm-base", stormClass].filter(Boolean).join(" ")} />
      <div
        className={["stars-layer-1", starsClass].filter(Boolean).join(" ")}
      />
      <div
        className={["stars-layer-2", starsClass].filter(Boolean).join(" ")}
      />
      <div
        className={["stars-layer-3", starsClass].filter(Boolean).join(" ")}
      />
      <div
        className={["storm-clouds storm-clouds-a", stormClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["storm-clouds storm-clouds-b", stormClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div className={["storm-mist", stormClass].filter(Boolean).join(" ")} />
      <div className={["storm-rain", stormClass].filter(Boolean).join(" ")} />
      <div className={["storm-flash", stormClass].filter(Boolean).join(" ")} />
      <div className="storm-vignette" />
    </div>
  )
}
