# Preview trailer fallback

Card hover previews pull YouTube streams via yt-dlp.

Fixes:
- Use `youtube:player_client=android` so studio trailers YouTube marks “unavailable” on the default client still resolve (e.g. Minions Final Trailer).
- Prefer Trailer → Teaser → Clip only (skip Featurette/interview talk clips).
- Try up to 6 TMDB YouTube keys until one resolves.

Piped fallback stays disabled (false-positive proxy URLs).
