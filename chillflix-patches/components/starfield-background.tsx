"use client"

import { useEffect, useState } from "react"

import { isLowEndDevice, prefersReducedMotion } from "@/lib/device-profile"

export const StarfieldBackground = () => {
  const [motion, setMotion] = useState(true)
  const [showStars, setShowStars] = useState(true)

  useEffect(() => {
    const lowEndDevice = isLowEndDevice()
    const reduced = prefersReducedMotion()
    document.documentElement.dataset.lowEndDevice = lowEndDevice
      ? "true"
      : "false"
    setMotion(!reduced)
    setShowStars(!lowEndDevice)
  }, [])

  const staticClass = motion ? undefined : "ambient-static"
  const starsClass = motion ? undefined : "stars-layer-static"

  return (
    <div
      className="site-ambient-sky fixed inset-0 overflow-hidden pointer-events-none"
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="ambient-void" />
      <div
        className={["ambient-wash", staticClass].filter(Boolean).join(" ")}
      />
      <div
        className={["ambient-cloud ambient-cloud-far", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["ambient-cloud ambient-cloud-mid", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["ambient-cloud ambient-cloud-near", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      {showStars ? (
        <>
          <div
            className={["stars-layer-1", starsClass].filter(Boolean).join(" ")}
          />
          <div
            className={["stars-layer-2", starsClass].filter(Boolean).join(" ")}
          />
        </>
      ) : null}
      <div
        className={["ambient-sheet", staticClass].filter(Boolean).join(" ")}
      />
      <div className="ambient-grain" />
      <div className="ambient-vignette" />
    </div>
  )
}
