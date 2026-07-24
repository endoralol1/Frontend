"use client"

import { useEffect, useRef, useState } from "react"

import {
  prefersReducedMotion,
  shouldReduceAmbientEffects,
} from "@/lib/device-profile"

type Drop = {
  x: number
  y: number
  len: number
  speed: number
  opacity: number
}

type Bolt = {
  points: { x: number; y: number }[]
  life: number
  peak: number
  branches: { x: number; y: number }[][]
}

/** Cap storm paint rate — 60fps full-viewport clear is pure GPU waste on scroll. */
const TARGET_FRAME_MS = 1000 / 20

function buildBolt(width: number, height: number): Bolt {
  const startX = width * (0.15 + Math.random() * 0.7)
  const endX = startX + (Math.random() - 0.5) * width * 0.25
  const startY = -20
  const endY = height * (0.35 + Math.random() * 0.35)
  const segments = 8 + Math.floor(Math.random() * 4)
  const points: { x: number; y: number }[] = []
  const branches: { x: number; y: number }[][] = []

  for (let i = 0; i <= segments; i += 1) {
    const t = i / segments
    const x =
      startX +
      (endX - startX) * t +
      (Math.random() - 0.5) * (28 + t * 40)
    const y = startY + (endY - startY) * t
    points.push({ x, y })

    if (i > 2 && i < segments - 1 && Math.random() < 0.22) {
      const branch: { x: number; y: number }[] = [{ x, y }]
      const dir = Math.random() < 0.5 ? -1 : 1
      let bx = x
      let by = y
      const steps = 2 + Math.floor(Math.random() * 2)
      for (let s = 0; s < steps; s += 1) {
        bx += dir * (12 + Math.random() * 22)
        by += 18 + Math.random() * 28
        branch.push({ x: bx, y: by })
      }
      branches.push(branch)
    }
  }

  return {
    points,
    branches,
    life: 1,
    peak: 0.85 + Math.random() * 0.15,
  }
}

function strokePath(
  ctx: CanvasRenderingContext2D,
  points: { x: number; y: number }[],
  color: string,
  width: number
) {
  if (points.length < 2) return
  ctx.strokeStyle = color
  ctx.lineWidth = width
  ctx.lineCap = "round"
  ctx.lineJoin = "round"
  ctx.beginPath()
  ctx.moveTo(points[0].x, points[0].y)
  for (let i = 1; i < points.length; i += 1) {
    ctx.lineTo(points[i].x, points[i].y)
  }
  ctx.stroke()
}

function isPlaybackActive() {
  return document.documentElement.classList.contains("playback-active")
}

/**
 * Storm/rain canvas is a known GPU tax — especially on APUs / iGPUs
 * (e.g. Ryzen 5700G) that pass RAM/core checks but choke on full-viewport
 * blur + continuous rAF while the homepage scrolls.
 */
export const StarfieldBackground = () => {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [motion, setMotion] = useState(false)

  useEffect(() => {
    const reduced = shouldReduceAmbientEffects()
    document.documentElement.dataset.lowEndDevice = reduced ? "true" : "false"
    document.documentElement.dataset.reducedAmbient = reduced ? "true" : "false"
    // Reduced / reduced-motion: static sky only — no canvas loop.
    // Delay storm rAF so cold first paint isn't competing with hydration/images.
    if (reduced || prefersReducedMotion()) {
      setMotion(false)
      return
    }
    const timer = window.setTimeout(() => setMotion(true), 600)
    return () => window.clearTimeout(timer)
  }, [])

  useEffect(() => {
    if (!motion) return
    const canvas = canvasRef.current
    if (!canvas) return
    const ctx = canvas.getContext("2d", {
      alpha: true,
      desynchronized: true,
    })
    if (!ctx) return

    let raf = 0
    let running = false
    let width = 0
    let height = 0
    let flash = 0
    let bolt: Bolt | null = null
    let nextFlash = performance.now() + 2200 + Math.random() * 2200
    let lastPaint = 0
    let scrollIdleTimer = 0
    let pausedForScroll = false
    const drops: Drop[] = []

    const resize = () => {
      // Cap DPR hard — retina phones were painting huge canvases every frame.
      const dpr = Math.min(window.devicePixelRatio || 1, 1)
      width = window.innerWidth
      height = window.innerHeight
      canvas.width = Math.floor(width * dpr)
      canvas.height = Math.floor(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

      const count = Math.min(
        48,
        Math.max(24, Math.floor((width * height) / 28000))
      )
      drops.length = 0
      for (let i = 0; i < count; i += 1) {
        drops.push({
          x: Math.random() * width,
          y: Math.random() * height,
          len: 10 + Math.random() * 14,
          speed: 7 + Math.random() * 10,
          opacity: 0.1 + Math.random() * 0.18,
        })
      }
    }

    const clearCanvas = () => {
      ctx.clearRect(0, 0, width, height)
      flash = 0
      bolt = null
    }

    const stopLoop = () => {
      running = false
      if (raf) {
        cancelAnimationFrame(raf)
        raf = 0
      }
      clearCanvas()
    }

    const triggerStrike = () => {
      if (isPlaybackActive() || pausedForScroll) return
      bolt = buildBolt(width, height)
      flash = 0.45 + Math.random() * 0.2
      window.setTimeout(() => {
        if (isPlaybackActive() || !running || pausedForScroll) return
        flash = Math.max(flash, 0.55 + Math.random() * 0.18)
        if (bolt) bolt.life = 1
      }, 70 + Math.random() * 90)
    }

    const draw = (now: number) => {
      if (!running) return
      // Hard stop — do not clear/paint while watching video.
      if (isPlaybackActive()) {
        stopLoop()
        return
      }

      raf = requestAnimationFrame(draw)

      // Free the compositor while the user is actively scrolling.
      if (pausedForScroll) return

      if (now - lastPaint < TARGET_FRAME_MS) return
      lastPaint = now

      ctx.clearRect(0, 0, width, height)

      ctx.lineCap = "round"
      for (const drop of drops) {
        drop.y += drop.speed * 1.4
        drop.x += drop.speed * 0.14
        if (drop.y > height + 20) {
          drop.y = -20
          drop.x = Math.random() * width
        }
        if (drop.x > width + 20) drop.x = -10

        ctx.strokeStyle = `rgba(176, 188, 208, ${drop.opacity * 0.85})`
        ctx.lineWidth = 1
        ctx.beginPath()
        ctx.moveTo(drop.x, drop.y)
        ctx.lineTo(drop.x - drop.len * 0.16, drop.y + drop.len)
        ctx.stroke()
      }

      if (now >= nextFlash) {
        triggerStrike()
        nextFlash = now + 4500 + Math.random() * 5500
      }

      if (flash > 0.01) {
        const bloom = ctx.createRadialGradient(
          width * 0.5,
          height * 0.05,
          0,
          width * 0.5,
          height * 0.45,
          Math.max(width, height) * 0.7
        )
        bloom.addColorStop(0, `rgba(210, 222, 240, ${flash * 0.42})`)
        bloom.addColorStop(0.45, `rgba(130, 150, 180, ${flash * 0.14})`)
        bloom.addColorStop(1, "rgba(130, 150, 180, 0)")
        ctx.fillStyle = bloom
        ctx.fillRect(0, 0, width, height)
      }

      if (bolt && bolt.life > 0.02) {
        const alpha = bolt.life * bolt.peak
        ctx.save()
        ctx.globalCompositeOperation = "lighter"
        strokePath(
          ctx,
          bolt.points,
          `rgba(220, 230, 245, ${alpha * 0.55})`,
          2.8
        )
        strokePath(ctx, bolt.points, `rgba(255, 255, 255, ${alpha})`, 1.2)
        for (const branch of bolt.branches) {
          strokePath(
            ctx,
            branch,
            `rgba(200, 214, 235, ${alpha * 0.45})`,
            1
          )
        }
        ctx.restore()
        bolt.life *= 0.84
      } else {
        bolt = null
      }

      flash *= 0.86
    }

    const startLoop = () => {
      if (running || isPlaybackActive()) return
      running = true
      lastPaint = 0
      raf = requestAnimationFrame(draw)
    }

    const pauseForScroll = () => {
      pausedForScroll = true
      clearCanvas()
      if (scrollIdleTimer) window.clearTimeout(scrollIdleTimer)
      scrollIdleTimer = window.setTimeout(() => {
        pausedForScroll = false
      }, 180)
    }

    resize()
    startLoop()

    const onResize = () => {
      resize()
    }
    window.addEventListener("resize", onResize)
    window.addEventListener("scroll", pauseForScroll, {
      passive: true,
      capture: true,
    })

    // Resume/stop when player opens or closes (class toggled by hook).
    const observer = new MutationObserver(() => {
      if (isPlaybackActive()) {
        stopLoop()
      } else {
        startLoop()
      }
    })
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["class"],
    })

    // Page hidden (tab/background) — free the GPU.
    const onVisibility = () => {
      if (document.hidden) {
        stopLoop()
      } else if (!isPlaybackActive()) {
        startLoop()
      }
    }
    document.addEventListener("visibilitychange", onVisibility)

    return () => {
      stopLoop()
      observer.disconnect()
      window.removeEventListener("resize", onResize)
      window.removeEventListener("scroll", pauseForScroll, true)
      document.removeEventListener("visibilitychange", onVisibility)
      if (scrollIdleTimer) window.clearTimeout(scrollIdleTimer)
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
      <div className="ambient-grain" />
      <div className="ambient-vignette" />
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
      <div
        className={["ambient-band ambient-band-c", staticClass]
          .filter(Boolean)
          .join(" ")}
      />
      {motion ? (
        <canvas ref={canvasRef} className="absolute inset-0 h-full w-full" />
      ) : null}
    </div>
  )
}
