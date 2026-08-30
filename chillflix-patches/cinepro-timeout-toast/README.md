# Fix: CinePro timeout toast over playing video

## Problem
VidLink (or another source) starts playback immediately while CinePro keeps
scanning in the background. When that background scan hits the 25s timeout,
the API stamps `CINEPRO_UNAVAILABLE` ("CinePro did not return extra sources…")
into diagnostics. The player showed that as a Retry banner **on top of working
video**.

## Fix
1. `route.ts` — do not add `CINEPRO_UNAVAILABLE` when the payload already has sources
2. `playback-user-messages.ts` — treat that code as a soft timeout; ignore it when
   `hasPlayableSource`
3. `media-player-modal.tsx` + `cinepro-watch-player.tsx` — same skip for the
   `sourceStatusMessage` passed into MediaPlayer
4. `MediaPlayer.tsx` — hide status/Retry banner once playback has progress

## Apply
```bash
bash chillflix-patches/cinepro-timeout-toast/apply.sh
```
