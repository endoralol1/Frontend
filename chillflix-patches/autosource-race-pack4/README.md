# Auto-source race pack: 4 + patience

## Behavior
- Race first **4** auto sources: Sigma, Mega, Pi, Alpha
- Keep racing / prefer other pack hits for **~45s** before cascading past the pack
- Playback watchdog floor **~30s** for first-pack providers (was ~15–20s)
- DB `auto_wait_sec=30` for those four

## Cache
Watch page must load `player.js?v=20260827-race-patience1` (via `routes.php` extraJs).
