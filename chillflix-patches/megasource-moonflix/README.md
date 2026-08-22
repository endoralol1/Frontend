# MegaSource + Moonflix / HDGHAR

## MegaSource
Cinemove “Mega” → WatchPlay (`v1.watchplay.shop`). Direct scrape; CDN `*.hclod.qzz.io` works from VPS.

## Moonflix / HDGHAR
Cinemove “Moon” family. Live upstream is **`https://hdghartv.cc`** public API (no auth):

- `GET /api/search?q=…` → match `tmdbId` → `_id`
- `GET /api/movies/public/{id}` → `streamingLinks[]`
- `GET /api/series/public/{id}` → `seasons[].episodes[].streamingLinks[]`
- CDN: `*.streamraiwind.stream` (Referer `hdghartv.cc`)

`HdgharSources` scrapes that. `MoonflixSources` tries Worker VixSrc first, then HDGHAR.
Optional legacy: set `HDGHAR_API_BASE` for Railway-style `/movie/{tmdb}` JSON.
