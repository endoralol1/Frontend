# You May Also Like under episodes

On TV watch pages, YMAL lived in a second row beside the info block, so it stayed low even when the episode list was short.

## Fix (live `watch-player.css?v=20260813-ymal-stack1` + `watch.php`)
- Move YMAL into `.watch-episode-sidebar` under `#movie-episode`
- Movies still show YMAL in the info row
- Cap episode panel `max-height: min(36rem, 62vh)` so short seasons leave room and YMAL rises into that space
