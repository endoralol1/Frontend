# Vidmoly safe re-enable

Vidmoly scrapes HiMovies wrappers over plain HTTP (no Worker needed).
When a wrapper lands on real `vidmoly.*`, it extracts the HLS URL cheaply.

Byse fallback (`gn1r5n`) is **disabled by default** (`VIDMOLY_ALLOW_BYSE_FALLBACK=0`)
because Byse PoW on the VPS caused Cloudflare 502s.

Worker note: Cloudflare Workers help for some relays (e.g. Yoru), but they do **not**
bypass VixSrc's Cloudflare IP ban — Worker egress is still blocked. Vidmoly does not
use a Worker.
