/** Smart TV / set-top browsers — weaker CPUs and stricter video decoders. */
export function isLikelyTvBrowser() {
  if (typeof navigator === "undefined") return false

  const ua = navigator.userAgent
  return /SmartTV|SMART-TV|Smart-TV|GoogleTV|AppleTV|AFTB|AFTM|AFTT|AFTS|Web0S|webOS|Tizen|BRAVIA|HbbTV|CrKey|Roku|Viera|NetCast|TV Safari|Silk\/|MiTV/i.test(
    ua
  )
}

export function prefersReducedMotion() {
  if (typeof window === "undefined") return false
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches
}

/**
 * Read GPU name via WebGL. Used to catch APUs / iGPUs that have lots of RAM
 * and CPU threads (e.g. Ryzen 5700G) but struggle with full-viewport blur + canvas.
 */
export function getGpuRenderer(): string {
  if (typeof document === "undefined") return ""

  try {
    const canvas = document.createElement("canvas")
    const gl =
      canvas.getContext("webgl", { failIfMajorPerformanceCaveat: false }) ||
      canvas.getContext("experimental-webgl", {
        failIfMajorPerformanceCaveat: false,
      })

    if (!gl || !(gl instanceof WebGLRenderingContext)) return ""

    const info = gl.getExtension("WEBGL_debug_renderer_info")
    if (!info) return ""

    const renderer = String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL) || "")
    return renderer
  } catch {
    return ""
  }
}

/**
 * Integrated / mobile GPUs — strong CPU+RAM can still thrash on storm FX.
 * Discrete markers (RTX/GTX/RX/Arc) are treated as capable.
 */
export function isIntegratedOrWeakGpu() {
  const renderer = getGpuRenderer()
  if (!renderer) return false

  if (
    /GeForce|RTX|GTX|Quadro|Tesla|Radeon RX|Radeon Pro|Arc A\d|NVIDIA/i.test(
      renderer
    )
  ) {
    return false
  }

  return /Intel|UHD|Iris|HD Graphics|AMD Radeon\(TM\) Graphics|Radeon Graphics|Apple GPU|Mali|Adreno|PowerVR|SwiftShader|llvmpipe|Microsoft Basic Render/i.test(
    renderer
  )
}

/** Weak desktops, iGPUs/APUs, save-data, and TV-class browsers. */
export function isLowEndDevice() {
  if (typeof navigator === "undefined") return false
  if (isLikelyTvBrowser()) return true
  if (isIntegratedOrWeakGpu()) return true

  const connection = (
    navigator as Navigator & { connection?: { saveData?: boolean } }
  ).connection
  if (connection?.saveData) return true

  const memory = (navigator as Navigator & { deviceMemory?: number }).deviceMemory
  if (typeof memory === "number" && memory > 0 && memory <= 4) return true

  const cores = navigator.hardwareConcurrency
  if (typeof cores === "number" && cores > 0 && cores <= 4) return true

  return false
}

/**
 * Ambient storm should stay light even on "mid" machines that pass isLowEndDevice.
 * Prefer static/reduced FX when scrolling is expected to compete with the compositor.
 */
export function shouldReduceAmbientEffects() {
  if (typeof window === "undefined") return true
  if (prefersReducedMotion()) return true
  if (isLowEndDevice()) return true
  return false
}

/** Skip YouTube trailer autoplay — static TMDB backdrop only. */
export function shouldSkipAutoplayTrailer() {
  if (typeof navigator === "undefined") return true
  if (isLikelyTvBrowser()) return true
  if (prefersReducedMotion()) return true
  if (isLowEndDevice()) return true
  return false
}

export function isPlaybackAmbientPaused() {
  if (typeof document === "undefined") return false
  return document.documentElement.classList.contains("playback-active")
}
