/**
 * Cine.su "Glendale" HLS token builder.
 * Port of their player `l0`/`l2`/`l5` helpers (route 4860ac8bfddb).
 */

const LJ = [17, 91, 203, 44, 8, 177, 62, 239, 119, 3, 154, 81, 28, 210, 101, 7]

/** Current stream crypto constants from cine.su player bundle. */
export const GLENDALE_HASH =
    '224eff10e662e9635c9f671cf46351dcd69af42b1edd56f5e5fa21751f44b9c8'
export const GLENDALE_ROUTE = '4860ac8bfddb'
export const GLENDALE_ORIGIN = 'https://glendale-plumbing.com'

function mix(value: number): number {
    let t = value >>> 0
    t ^= t >>> 16
    t = Math.imul(t, 0x7feb352d)
    t ^= t >>> 15
    t = Math.imul(t, 0x846ca68b)
    return (t ^= t >>> 16) >>> 0
}

function expandKey(seed: string, length: number): Uint8Array {
    const encoded = new TextEncoder().encode(seed || 'dev')
    const out = new Uint8Array(Math.max(32, Math.min(128, length + 17)))
    let state = 0x811c9dc5
    for (let i = 0; i < out.length; i += 1) {
        state ^= encoded[i % encoded.length] ?? i
        state = mix(state + LJ[i % LJ.length]! + 0x9e3779b1 * i)
        out[i] = 255 & state
    }
    return out
}

function toBase64Url(bytes: Uint8Array): string {
    const alphabet =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'
    let out = ''
    for (let i = 0; i < bytes.length; i += 3) {
        const a = bytes[i]!
        const b = bytes[i + 1]
        const c = bytes[i + 2]
        out += alphabet[a >>> 2]
        out += alphabet[((3 & a) << 4) | ((b ?? 0) >>> 4)]
        if (b === undefined) break
        out += alphabet[((15 & b) << 2) | ((c ?? 0) >>> 6)]
        if (c === undefined) break
        out += alphabet[63 & c]
    }
    return out
}

export type GlendaleMedia = {
    type: 'movie' | 'show'
    tmdbId: number
    season?: number
    episode?: number
}

export function buildGlendaleToken(
    media: GlendaleMedia,
    routeId = GLENDALE_ROUTE,
    hash = GLENDALE_HASH
): string {
    const season =
        media.type === 'show' ? Math.max(1, Math.floor(media.season || 1)) : 0
    const episode =
        media.type === 'show' ? Math.max(1, Math.floor(media.episode || 1)) : 0
    const key = `${media.type[0]}:${Math.floor(media.tmdbId)}:${season}:${episode}`
    const plain = new TextEncoder().encode(`${routeId}:${key}`)
    const streamKey = expandKey(hash, plain.length)
    const packed = new Uint8Array(plain.length + 2)
    packed[0] = 255 & plain.length
    packed[1] = (plain.length >>> 8) & 255
    let cursor = 0x9e3779b9 ^ plain.length
    for (let i = 0; i < plain.length; i += 1) {
        cursor = mix(
            cursor + streamKey[i % streamKey.length]! + LJ[i % LJ.length]! + i
        )
        packed[i + 2] =
            plain[i]! ^ (255 & cursor) ^ streamKey[(7 * i + 3) % streamKey.length]!
    }
    return toBase64Url(packed)
}

export function buildGlendaleMasterUrl(media: GlendaleMedia): string {
    const token = buildGlendaleToken(media)
    return `${GLENDALE_ORIGIN}/c/v1/${token}/master.m3u8`
}

export type GlendaleVariant = {
    bandwidth: number
    resolution: string
    height: number
    quality: string
    url: string
}

/** Parse #EXT-X-STREAM-INF variants from a master playlist. */
export function parseGlendaleVariants(
    masterBody: string,
    masterUrl: string
): GlendaleVariant[] {
    const lines = masterBody.split(/\r?\n/)
    const variants: GlendaleVariant[] = []

    for (let i = 0; i < lines.length; i += 1) {
        const line = lines[i]?.trim() ?? ''
        if (!line.startsWith('#EXT-X-STREAM-INF:')) continue
        const next = lines[i + 1]?.trim() ?? ''
        if (!next || next.startsWith('#')) continue

        const bandwidth = Number(
            line.match(/BANDWIDTH=(\d+)/i)?.[1] ?? 0
        )
        const resolution = line.match(/RESOLUTION=(\d+x\d+)/i)?.[1] ?? ''
        const width = Number(resolution.split('x')[0] ?? 0)
        const height = Number(resolution.split('x')[1] ?? 0)
        // Prefer ladder token from path (`/800p/`, `/1080p/`) — cinema crops
        // are often 1920x800 (FHD width, sub-720 height).
        const pathQuality = next.match(/\/(\d{3,4})p\//i)?.[1]
        const longEdge = Math.max(width, height)
        let quality = 'Auto'
        if (pathQuality) quality = `${pathQuality}p`
        else if (longEdge >= 3840 || height >= 2160) quality = '2160p'
        else if (longEdge >= 2560 || height >= 1440) quality = '1440p'
        else if (longEdge >= 1920 || height >= 1080) quality = '1080p'
        else if (longEdge >= 1280 || height >= 720) quality = '720p'
        else if (longEdge >= 854 || height >= 480) quality = '480p'
        else if (height > 0) quality = `${height}p`

        let url = next
        try {
            url = new URL(next, masterUrl).toString()
        } catch {
            // keep raw
        }

        variants.push({ bandwidth, resolution, height: longEdge || height, quality, url })
    }

    return variants.sort((a, b) => b.height - a.height || b.bandwidth - a.bandwidth)
}
