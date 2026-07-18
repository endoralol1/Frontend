export type HlsSessionMode = "remux" | "transcode"

/**
 * Next.js only inlines NEXT_PUBLIC_* when accessed with a static property path
 * (process.env.NEXT_PUBLIC_FOO). Dynamic process.env[key] is always undefined
 * in the browser bundle — which previously made isFfmpegAvailable() always false
 * and dropped every MKV 4K stream after a successful /api/4k/resolve.
 */
function publicFourKFlag(name: "HLS_ENABLED" | "HLS_DISABLED" | "REMUX_ENABLED" | "REMUX_DISABLED") {
  switch (name) {
    case "HLS_ENABLED":
      return (
        process.env.NEXT_PUBLIC_FOUR_K_HLS_ENABLED === "true" ||
        process.env.FOUR_K_HLS_ENABLED === "true"
      )
    case "HLS_DISABLED":
      return (
        process.env.NEXT_PUBLIC_FOUR_K_HLS_DISABLED === "true" ||
        process.env.FOUR_K_HLS_DISABLED === "true"
      )
    case "REMUX_ENABLED":
      return (
        process.env.NEXT_PUBLIC_FOUR_K_REMUX_ENABLED === "true" ||
        process.env.FOUR_K_REMUX_ENABLED === "true"
      )
    case "REMUX_DISABLED":
      return (
        process.env.NEXT_PUBLIC_FOUR_K_REMUX_DISABLED === "true" ||
        process.env.FOUR_K_REMUX_DISABLED === "true"
      )
  }
}

export function isFfmpegRemuxAvailable() {
  if (publicFourKFlag("HLS_DISABLED") || publicFourKFlag("REMUX_DISABLED")) return false
  return publicFourKFlag("REMUX_ENABLED")
}

export function isFfmpegTranscodeAvailable() {
  if (publicFourKFlag("HLS_DISABLED")) return false
  return publicFourKFlag("HLS_ENABLED")
}

export function isFfmpegAvailable(mode: HlsSessionMode = "transcode") {
  return mode === "remux" ? isFfmpegRemuxAvailable() : isFfmpegTranscodeAvailable()
}
