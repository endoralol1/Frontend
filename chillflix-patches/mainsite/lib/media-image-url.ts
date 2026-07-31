import {
    MEDIA_IMAGE_VARIANTS,
    type MediaImageVariant,
    normalizeTmdbFileName,
} from "@/lib/media-image-constants"

/** Direct TMDB URL when cache proxy misses or fails. */
export function mediaImageTmdbFallbackUrl(
    variant: MediaImageVariant,
    tmdbPath: string
) {
    const file = normalizeTmdbFileName(tmdbPath)
    const size = MEDIA_IMAGE_VARIANTS[variant].tmdbSize
    return `https://image.tmdb.org/t/p/${size}/${file}`
}

/**
 * Public media image URL.
 * Default: TMDB CDN (keeps sharp/libvips off the Next process).
 * Set MEDIA_IMAGE_VIA_API=true to force legacy /api/img proxy.
 */
export function mediaImagePublicUrl(
    variant: MediaImageVariant,
    tmdbPath: string
) {
    if (process.env.MEDIA_IMAGE_VIA_API === "true") {
        const file = normalizeTmdbFileName(tmdbPath)
        return `/api/img/${variant}/${encodeURIComponent(file)}`
    }
    return mediaImageTmdbFallbackUrl(variant, tmdbPath)
}
