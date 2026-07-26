/** Vignette zone (dd133.com) for embed + detail pages. */
export const EMBED_ADS_VIGNETTE_ZONE = "11256487"
export const EMBED_ADS_VIGNETTE_SCRIPT_SRC = "https://dd133.com/vignette.min.js"

export type VignetteAdConfig = {
    zone: string
    src: string
}

/** Monetag zone for chillflix.lol site player (first-party). */
export const EMBED_ADS_SITE_TAG_ZONE = "11200416"
/** Monetag popunder zone for chillflix.pw embeds (jnbhi tag data-zone). */
export const EMBED_ADS_EMBED_TAG_ZONE = "11196334"
/** Secondary zone baked into the embed anti-adblock boot script. */
export const EMBED_ADS_EMBED_BOOT_ZONE = "11196335"

export const EMBED_ADS_LLVPN_TAG_SCRIPT_SRC = "https://llvpn.com/tag.min.js"
export const EMBED_ADS_JNBHI_TAG_SCRIPT_SRC = "//jnbhi.com/tag.min.js"
export const EMBED_ADS_ANTIBLOCK_BOOT_PATH = "/embed-ads/monetag-antiblock-embed.js"
/** First-party Monetag aclib proxy (Mapple-style). Do not name this *aclib.js* — EasyList blocks that path. */
export const EMBED_ADS_ACLIB_FIRSTPARTY_PATH = "/api/rum"

export const EMBED_ADS_TAG_ON_ERROR = "_iiyxy"
export const EMBED_ADS_TAG_ON_LOAD = "_uhmyrwn"

/** @deprecated Use EMBED_ADS_SITE_TAG_ZONE */
export const EMBED_ADS_TAG_ZONE = EMBED_ADS_SITE_TAG_ZONE
