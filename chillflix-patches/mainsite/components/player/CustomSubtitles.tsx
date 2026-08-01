"use client"

import { useEffect, useRef, useState, type CSSProperties, type RefObject } from "react"

import { parseSubtitleText } from "./utils/subtitle"

interface CustomSubtitlesProps {
  url: string
  videoRef: RefObject<HTMLVideoElement | null>
  /** Seconds; negative shows cues earlier */
  delaySec?: number
  fontScale?: number
  backgroundOpacity?: number
  onLoadError?: (url: string, reason: string) => void
  onLoadSuccess?: (url: string) => void
}

function isSubtitlePayload(text: string, contentType: string | null) {
  const trimmed = text.trimStart()
  const preview = trimmed.slice(0, 512).toLowerCase()

  if (contentType?.toLowerCase().includes("text/html")) return false
  if (preview.startsWith("<!doctype html") || preview.startsWith("<html") || preview.includes("<body")) {
    return false
  }

  if (trimmed.startsWith("WEBVTT")) return true
  return /(?:\d{2}:)?\d{2}:\d{2}[\.,]\d{3}\s*-->\s*(?:\d{2}:)?\d{2}:\d{2}[\.,]\d{3}/.test(text)
}

function stripMarkup(input: string) {
  return input.replace(/<[^>]+>/g, "")
}

export function CustomSubtitles({
  url,
  videoRef,
  delaySec = 0,
  fontScale = 1,
  backgroundOpacity = 0.7,
  onLoadError,
  onLoadSuccess,
}: CustomSubtitlesProps) {
  const cuesRef = useRef<Array<{ start: number; end: number; text: string }>>([])
  const delayRef = useRef(delaySec)
  const [activeCueText, setActiveCueText] = useState<string | null>(null)
  const onLoadErrorRef = useRef(onLoadError)
  const onLoadSuccessRef = useRef(onLoadSuccess)

  useEffect(() => {
    onLoadErrorRef.current = onLoadError
  }, [onLoadError])

  useEffect(() => {
    onLoadSuccessRef.current = onLoadSuccess
  }, [onLoadSuccess])

  useEffect(() => {
    delayRef.current = delaySec
  }, [delaySec])

  useEffect(() => {
    if (!url) {
      cuesRef.current = []
      setActiveCueText(null)
      return
    }

    const controller = new AbortController()

    const loadSubtitles = async () => {
      try {
        const response = await fetch(url, { signal: controller.signal })
        if (!response.ok) {
          throw new Error(`Subtitle request failed with status ${response.status}`)
        }

        const text = await response.text()
        const contentType = response.headers.get("content-type")

        if (!isSubtitlePayload(text, contentType)) {
          throw new Error("Subtitle payload is not valid subtitle content")
        }

        const parsed = parseSubtitleText(text)
        if (parsed.length === 0) {
          throw new Error("Subtitle payload contains no cues")
        }

        cuesRef.current = parsed
        setActiveCueText(null)
        onLoadSuccessRef.current?.(url)
      } catch (error) {
        if (controller.signal.aborted) return

        const reason = error instanceof Error ? error.message : "Unknown subtitle load error"
        cuesRef.current = []
        setActiveCueText(null)
        onLoadErrorRef.current?.(url, reason)
      }
    }

    void loadSubtitles()

    return () => controller.abort()
  }, [url])

  useEffect(() => {
    let rafId = 0

    const tick = () => {
      const video = videoRef.current
      const currentTime = (video?.currentTime ?? 0) - delayRef.current
      const cue = cuesRef.current.find(
        (entry) => currentTime >= entry.start && currentTime <= entry.end
      )
      const nextText = cue ? stripMarkup(cue.text) : null

      setActiveCueText((prev) => (prev === nextText ? prev : nextText))
      rafId = window.requestAnimationFrame(tick)
    }

    rafId = window.requestAnimationFrame(tick)
    return () => window.cancelAnimationFrame(rafId)
  }, [url, videoRef])

  const cueStyle: CSSProperties = {
    fontSize: `calc(1.125rem * ${fontScale})`,
    backgroundColor: `rgba(0, 0, 0, ${backgroundOpacity})`,
  }

  if (!activeCueText) {
    return (
      <div
        className="pointer-events-none absolute bottom-[15%] left-0 right-0 flex justify-center px-4"
        aria-hidden
      />
    )
  }

  return (
    <div className="pointer-events-none absolute bottom-[15%] left-0 right-0 flex justify-center px-4 md:bottom-[16%]">
      <div
        className="max-w-[90%] rounded px-3 py-1 text-center font-medium leading-snug text-white shadow-lg md:text-[1.5rem]"
        style={cueStyle}
      >
        {activeCueText}
      </div>
    </div>
  )
}
