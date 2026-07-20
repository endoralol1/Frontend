"use client"

import { useEffect, useRef, useState } from "react"

import { prefersReducedMotion } from "@/lib/device-profile"

type Wisp = {
  x: number
  y: number
  r: number
  vx: number
  vy: number
  a: number
}

export const StarfieldBackground = () => {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [motion, setMotion] = useState(true)

  useEffect(() => {
    // Keep the low-end flag for other UI, but do not gate the sky on it.
    const memory = (navigator as Navigator & { deviceMemory?: number })
      .deviceMemory
    const cores = navigator.hardwareConcurrency
    const lowEnd =
      (typeof memory === "number" && memory > 0 && memory <= 4) ||
      (typeof cores === "number" && cores > 0 && cores <= 4)
    document.documentElement.dataset.lowEndDevice = lowEnd ? "true" : "false"
    setMotion(!prefersReducedMotion())
  }, [])

  useEffect(() => {
    if (!motion) return
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext("2d", { alpha: true })
    if (!ctx) return

    let raf = 0
    let width = 0
    let height = 0
    let flash = 0
    let nextFlash = performance.now() + 6000 + Math.random() * 5000
    const wisps: Wisp[] = []

    const resize = () => {
      const dpr = Math.min(window.devicePixelRatio || 1, 2)
      width = window.innerWidth
      height = window.innerHeight
      canvas.width = Math.floor(width * dpr)
      canvas.height = Math.floor(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

      wisps.length = 0
      const count = Math.min(10, Math.max(6, Math.floor(width / 220)))
      for (let i = 0; i < count; i += 1) {
        wisps.push({
          x: Math.random() * width,
          y: Math.random() * height,
          r: Math.min(width, height) * (0.22 + Math.random() * 0.28),
          vx: (Math.random() - 0.5) * 0.12,
          vy: (Math.random() - 0.5) * 0.07,
          a: 0.045 + Math.random() * 0.05,
        })
      }
    }

    const draw = (now: number) => {
      const paused =
        document.documentElement.classList.contains("playback-active")
      ctx.clearRect(0, 0, width, height)

      // Soft storm wisps — large blurred glows, not particle rain.
      for (const w of wisps) {
        if (!paused) {
          w.x += w.vx
          w.y += w.vy
          if (w.x < -w.r) w.x = width + w.r
          if (w.x > width + w.r) w.x = -w.r
          if (w.y < -w.r) w.y = height + w.r
          if (w.y > height + w.r) w.y = -w.r
        }

        const g = ctx.createRadialGradient(w.x, w.y, 0, w.x, w.y, w.r)
        g.addColorStop(0, `rgba(78, 98, 128, ${w.a})`)
        g.addColorStop(0.45, `rgba(42, 56, 78, ${w.a * 0.55})`)
        g.addColorStop(1, "rgba(42, 56, 78, 0)")
        ctx.fillStyle = g
        ctx.beginPath()
        ctx.arc(w.x, w.y, w.r, 0, Math.PI * 2)
        ctx.fill()
      }

      if (!paused && now >= nextFlash) {
        flash = 0.16 + Math.random() * 0.1
        nextFlash = now + 9000 + Math.random() * 8000
      }

      if (flash > 0.008) {
        const lg = ctx.createRadialGradient(
          width * 0.58,
          height * -0.05,
          0,
          width * 0.5,
          height * 0.25,
          height * 0.55
        )
        lg.addColorStop(0, `rgba(176, 196, 224, ${flash})`)
        lg.addColorStop(1, "rgba(176, 196, 224, 0)")
        ctx.fillStyle = lg
        ctx.fillRect(0, 0, width, height)
        flash = paused ? 0 : flash * 0.9
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

  const staticClass = motion ? undefined : "ambient-static"

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
        className={["ambient-band ambient-band-a", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <div
        className={["ambient-band ambient-band-b", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      <canvas ref={canvasRef} className="absolute inset-0 h-full w-full" />
      <div className="ambient-grain" />
      <div className="ambient-vignette" />
    </div>
  )
}
