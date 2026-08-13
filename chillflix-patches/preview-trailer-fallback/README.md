# Preview trailer fallback

Some card hovers had no trailer because TMDB’s primary YouTube key was deleted/blocked.
- `Tmdb::trailerKeys()` returns ordered YouTube keys (official trailers → trailers → official alts → alts)
- `/api/preview` tries up to 12 keys via yt-dlp until one resolves
- Piped fallback disabled (returned unusable proxy URLs for dead videos)

Example: Minions & Monsters (`1315772`) Final Trailer `V-O-uBaHk3c` unavailable → falls back to a working Featurette.
