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

type Bolt = {
  points: { x: number; y: number }[]
  life: number
  peak: number
  branches: { x: number; y: number }[][]
}

function buildBolt(width: number, height: number): Bolt {
  const startX = width * (0.15 + Math.random() * 0.7)
  const endX = startX + (Math.random() - 0.5) * width * 0.25
  const startY = -20
  const endY = height * (0.35 + Math.random() * 0.35)
  const segments = 10 + Math.floor(Math.random() * 6)
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

    if (i > 2 && i < segments - 1 && Math.random() < 0.35) {
      const branch: { x: number; y: number }[] = [{ x, y }]
      const dir = Math.random() < 0.5 ? -1 : 1
      let bx = x
      let by = y
      const steps = 3 + Math.floor(Math.random() * 3)
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
 * Storm/rain canvas is a known GPU tax on phones.
 * Root cause of choppy player frames: rAF + full-viewport clearRect kept
 * running under the player even when "paused", plus blurred cloud layers.
 */
export const StarfieldBackground = () => {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [motion, setMotion] = useState(false)

  useEffect(() => {
    const lowEnd = isLowEndDevice()
    document.documentElement.dataset.lowEndDevice = lowEnd ? "true" : "false"
    // Low-end / reduced-motion: static sky only — no canvas loop.
    setMotion(!lowEnd && !prefersReducedMotion())
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
    let nextFlash = performance.now() + 1800 + Math.random() * 1800
    const drops: Drop[] = []

    const resize = () => {
      // Cap DPR — 3x phone screens were painting huge canvases every frame.
      const dpr = Math.min(window.devicePixelRatio || 1, 1.25)
      width = window.innerWidth
      height = window.innerHeight
      canvas.width = Math.floor(width * dpr)
      canvas.height = Math.floor(height * dpr)
      canvas.style.width = `${width}px`
      canvas.style.height = `${height}px`
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

      const count = Math.min(
        90,
        Math.max(40, Math.floor((width * height) / 16000))
      )
      drops.length = 0
      for (let i = 0; i < count; i += 1) {
        drops.push({
          x: Math.random() * width,
          y: Math.random() * height,
          len: 10 + Math.random() * 16,
          speed: 7 + Math.random() * 11,
          opacity: 0.12 + Math.random() * 0.22,
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
      if (isPlaybackActive()) return
      bolt = buildBolt(width, height)
      flash = 0.55 + Math.random() * 0.25
      window.setTimeout(() => {
        if (isPlaybackActive() || !running) return
        flash = Math.max(flash, 0.7 + Math.random() * 0.2)
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

      ctx.clearRect(0, 0, width, height)

      ctx.lineCap = "round"
      for (const drop of drops) {
        drop.y += drop.speed
        drop.x += drop.speed * 0.14
        if (drop.y > height + 20) {
          drop.y = -20
          drop.x = Math.random() * width
        }
        if (drop.x > width + 20) drop.x = -10

        ctx.strokeStyle = `rgba(176, 188, 208, ${drop.opacity * 0.85})`
        ctx.lineWidth = 1.1
        ctx.beginPath()
        ctx.moveTo(drop.x, drop.y)
        ctx.lineTo(drop.x - drop.len * 0.16, drop.y + drop.len)
        ctx.stroke()
      }

      if (now >= nextFlash) {
        triggerStrike()
        nextFlash = now + 3500 + Math.random() * 4500
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
        bloom.addColorStop(0, `rgba(210, 222, 240, ${flash * 0.5})`)
        bloom.addColorStop(0.45, `rgba(130, 150, 180, ${flash * 0.18})`)
        bloom.addColorStop(1, "rgba(130, 150, 180, 0)")
        ctx.fillStyle = bloom
        ctx.fillRect(0, 0, width, height)
        ctx.fillStyle = `rgba(190, 205, 225, ${flash * 0.1})`
        ctx.fillRect(0, 0, width, height)
      }

      if (bolt && bolt.life > 0.02) {
        const alpha = bolt.life * bolt.peak
        ctx.save()
        ctx.globalCompositeOperation = "lighter"
        strokePath(
          ctx,
          bolt.points,
          `rgba(170, 190, 220, ${alpha * 0.28})`,
          10
        )
        strokePath(
          ctx,
          bolt.points,
          `rgba(220, 230, 245, ${alpha * 0.65})`,
          3.5
        )
        strokePath(ctx, bolt.points, `rgba(255, 255, 255, ${alpha})`, 1.4)
        for (const branch of bolt.branches) {
          strokePath(
            ctx,
            branch,
            `rgba(200, 214, 235, ${alpha * 0.5})`,
            1.2
          )
        }
        ctx.restore()
        bolt.life *= 0.86
      } else {
        bolt = null
      }

      flash *= 0.88
      raf = requestAnimationFrame(draw)
    }

    const startLoop = () => {
      if (running || isPlaybackActive()) return
      running = true
      raf = requestAnimationFrame(draw)
    }

    resize()
    startLoop()

    const onResize = () => {
      resize()
    }
    window.addEventListener("resize", onResize)

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
      document.removeEventListener("visibilitychange", onVisibility)
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
