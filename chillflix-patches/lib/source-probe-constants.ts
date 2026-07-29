/**
 * Per-stream health probe budget for one upstream fetch.
 * CDN + stream-proxy cold paths often need >3s under load / 429 backoff.
 */
export const SOURCE_PROBE_TIMEOUT_MS = 8_000

/**
 * Client abort for /api/cinepro/probe. Server probes can nest
 * (manifest → variant → first segment), so this must outlast SOURCE_PROBE_TIMEOUT_MS.
 */
export const CLIENT_SOURCE_PROBE_TIMEOUT_MS = 20_000

/**
 * Wait for HAVE_METADATA on a newly selected source before declaring it dead.
 * Proxied HLS (VAPlayer via /api/cinepro/proxy) regularly exceeds 3s on first hit.
 */
export const SOURCE_METADATA_TIMEOUT_MS = 12_000

/** Cold 4K remux manifest (ffmpeg startup on VPS) can take 30–90s. */
export const FOUR_K_HLS_STARTUP_TIMEOUT_MS = 120_000

/** 2160p HEVC transcode cold start can take 1–3 min (libx264 encode on VPS). */
export const FOUR_K_HLS_TRANSCODE_STARTUP_TIMEOUT_MS = 180_000

/**
 * Client wait per provider when scanning CinePro. Admin source checker allows 90s;
 * the player must still outlast slow scrapers (e.g. VidNest often 5–8s on VPS).
 */
export const PROVIDER_SOURCE_FETCH_TIMEOUT_MS = 45_000

/** Chillflix API → CinePro single-provider scrape (server-side). */
export const CINEPRO_SINGLE_PROVIDER_FETCH_TIMEOUT_MS = 28_000

/** First /api/cinepro/sources request — CinePro can take 15–25s on a cold title. */
export const INITIAL_SOURCES_FETCH_TIMEOUT_MS = 30_000

/** Background merge wait before showing “no sources” while CinePro is still scraping. */
export const MERGED_SOURCES_BACKGROUND_WAIT_MS = 15_000

/** Server-side cache for successful /api/cinepro/sources responses. */
export const CINEPRO_SOURCES_CACHE_TTL_MS = 15 * 60 * 1000

/** Bulk CinePro scrape from Chillflix API (background + retry paths). */
export const CINEPRO_BULK_FETCH_TIMEOUT_MS = 25_000

/** Cinemacity-only config — signed CDN URLs need a longer scrape window. */
export const CINEMACITY_CINEPRO_FETCH_TIMEOUT_MS = 90_000

/**
 * #1 provider gets this short exclusive window before secondaries are scanned.
 * Keep low: resolve URLs ≠ playable streams; waiting 2.5s+ made startup feel broken.
 */
export const PRIMARY_PROVIDER_HEAD_START_MS = 800

/** Re-test #1 provider while lower-ranked streams play if it had not returned yet. */
export const PRIMARY_PROVIDER_RETRY_MS = 6_000

/**
 * Cold start: if the selected source never advances past metadata / keeps waiting
 * with almost no progress, fail it so auto-fallback can try the next provider.
 * Must outlast proxy 429 backoff + first HLS segment under CDN pressure.
 */
export const STARTUP_PLAYBACK_FAIL_MS = 15_000
