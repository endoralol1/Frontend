# Episode sidebar fill

Fixes TV watch episode list scrolling too early while leaving empty space above "Go to episode".

## Cause
`.episodes` was capped (`max-height: min(42vh, 24rem)` / mobile `22rem`/`58vh` caps) while the sidebar stretched beside the player. The list scrolled inside a short box; dead space sat unused below it.

## Fix (live `?v=20260813-eps-fill2`)
- `#movie-episode` / `.episode-range` / `.episodes` use `flex: 1 1 0%` + `min-height: 0` so the list fills remaining sidebar height
- `max-height: none` on the episode scroller (desktop + stacked)
- Stacked sidebar (`max-width: 1199.98px`) gets `min-height: min(70vh, 36rem)` so the list still has room when not beside the player

## Verify
On `/tv/breaking-bad/1396?s=5&e=7`: `#movie-episode` ~ full sidebar height; `.episodes` `clientHeight === scrollHeight` when episodes fit; scroll only when content exceeds the filled panel.
