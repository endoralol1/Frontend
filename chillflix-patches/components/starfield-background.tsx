"use client"

import { useEffect, useRef, useState } from "react"

import { isLowEndDevice, prefersReducedMotion } from "@/lib/device-profile"

type Drop = {
  x: number
  y: number
  len: number
  speed: number
  opacity: number
}

export const StarfieldBackground = () => {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  // Animate by default so storm is visible immediately; only stop for reduced-motion.
  const [motion, setMotion] = useState(true)
  const [showStars, setShowStars] = useState(true)

  useEffect(() => {
    const lowEndDevice = isLowEndDevice()
    const reduced = prefersReducedMotion()
    document.documentElement.dataset.lowEndDevice = lowEndDevice
      ? "true"
      : "false"
    setMotion(!reduced)
    // Keep stars off on weak devices; storm rain/clouds still run.
    setShowStars(!lowEndDevice)
  }, [])

  useEffect(() => {
    if (!motion) return

    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext("2d", { alpha: true })
    if (!ctx) return

    let raf = 0
    let flash = 0
    let nextFlash = performance.now() + 2500 + Math.random() * 2500
    const drops: Drop[] = []

    const resize = () => {
      const dpr = Math.min(window.devicePixelRatio || 1, 2)
      const width = window.innerWidth
      const height = window.innerHeight
      canvas.width = Math.floor(width * dpr)
      canvas.height = Math.floor(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

      const count = Math.min(
        220,
        Math.max(90, Math.floor((width * height) / 7000))
      )
      drops.length = 0
      for (let i = 0; i < count; i += 1) {
        drops.push({
          x: Math.random() * width,
          y: Math.random() * height,
          len: 14 + Math.random() * 22,
          speed: 11 + Math.random() * 18,
          opacity: 0.22 + Math.random() * 0.4,
        })
      }
    }

    const draw = (now: number) => {
      const paused =
        document.documentElement.classList.contains("playback-active")
      const width = window.innerWidth
      const height = window.innerHeight

      ctx.clearRect(0, 0, width, height)

      if (!paused) {
        ctx.lineWidth = 1.4
        ctx.lineCap = "round"
        for (const drop of drops) {
          drop.y += drop.speed
          drop.x += drop.speed * 0.22
          if (drop.y > height + 24) {
            drop.y = -24
            drop.x = Math.random() * width
          }
          if (drop.x > width + 24) drop.x = -12

          ctx.strokeStyle = `rgba(198, 216, 235, ${drop.opacity})`
          ctx.beginPath()
          ctx.moveTo(drop.x, drop.y)
          ctx.lineTo(drop.x - drop.len * 0.2, drop.y + drop.len)
          ctx.stroke()
        }

        if (now >= nextFlash) {
          flash = 0.42 + Math.random() * 0.28
          nextFlash = now + 3800 + Math.random() * 5200
        }
      }

      if (flash > 0.012) {
        ctx.fillStyle = `rgba(214, 228, 245, ${flash})`
        ctx.fillRect(0, 0, width, height)
        if (!paused) flash *= 0.78
        else flash = 0
      }

      raf = requestAnimationFrame(draw)
    }

    resize()
    window.addEventListener("resize", resize)
    raf = requestAnimationFrame(draw)

    return () => {
      cancelAnimationFrame(raf)
      window.removeEventListener("resize", resize)
    }
  }, [motion])

  const staticClass = motion ? undefined : "storm-layer-static"
  const starsClass = motion ? undefined : "stars-layer-static"

  return (
    <div
      className="site-ambient-sky fixed inset-0 overflow-hidden pointer-events-none"
      style={{ zIndex: 0 }}
      aria-hidden
    >
      <div className="absolute inset-0 bg-[#04060a]" />
      <div className={["storm-base", staticClass].filter(Boolean).join(" ")} />
      {showStars ? (
        <>
          <div
            className={["stars-layer-1", starsClass].filter(Boolean).join(" ")}
          />
          <div
            className={["stars-layer-2", starsClass].filter(Boolean).join(" ")}
          />
          <div
            className={["stars-layer-3", starsClass].filter(Boolean).join(" ")}
          />
        </>
      ) : null}
      <div
        className={["storm-clouds storm-clouds-a", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["storm-clouds storm-clouds-b", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["storm-clouds storm-clouds-c", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <canvas ref={canvasRef} className="absolute inset-0 h-full w-full" />
      <div className="storm-vignette" />
    </div>
  )
}
