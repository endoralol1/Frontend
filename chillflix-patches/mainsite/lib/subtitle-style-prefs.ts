export type SubtitleStylePrefs = {
  /** Seconds; negative = earlier, positive = later */
  delaySec: number
  fontScale: number
  backgroundOpacity: number
}

const STORAGE_KEY = "chillflix_subtitle_style_v1"

const DEFAULTS: SubtitleStylePrefs = {
  delaySec: 0,
  fontScale: 1,
  backgroundOpacity: 0.7,
}

function clamp(n: number, min: number, max: number) {
  return Math.min(max, Math.max(min, n))
}

export function getDefaultSubtitleStylePrefs(): SubtitleStylePrefs {
  return { ...DEFAULTS }
}

export function readSubtitleStylePrefs(): SubtitleStylePrefs {
  if (typeof window === "undefined") return getDefaultSubtitleStylePrefs()
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return getDefaultSubtitleStylePrefs()
    const parsed = JSON.parse(raw) as Partial<SubtitleStylePrefs>
    return {
      delaySec: clamp(Number(parsed.delaySec) || 0, -10, 10),
      fontScale: clamp(Number(parsed.fontScale) || 1, 0.75, 1.75),
      backgroundOpacity: clamp(
        Number(parsed.backgroundOpacity ?? DEFAULTS.backgroundOpacity),
        0,
        0.9
      ),
    }
  } catch {
    return getDefaultSubtitleStylePrefs()
  }
}

export function writeSubtitleStylePrefs(prefs: SubtitleStylePrefs) {
  if (typeof window === "undefined") return
  const next: SubtitleStylePrefs = {
    delaySec: clamp(prefs.delaySec, -10, 10),
    fontScale: clamp(prefs.fontScale, 0.75, 1.75),
    backgroundOpacity: clamp(prefs.backgroundOpacity, 0, 0.9),
  }
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next))
}

export function formatSubtitleDelay(delaySec: number): string {
  if (Math.abs(delaySec) < 0.05) return "0.0s"
  const sign = delaySec > 0 ? "+" : ""
  return `${sign}${delaySec.toFixed(1)}s`
}
