# Fix: empty “title isn’t available” flash before playback starts

## Problem
Player briefly shows *“This title isn't available on Chillflix…”* and then the
movie/TV stream starts anyway.

## Cause
Background CinePro discovery was treated as finished too early:
- `BACKGROUND_PENDING_CAP_MS` was only **8s**
- Soft `CINEPRO_UNAVAILABLE` was a **terminal** diagnostic, so `isLoadingMore`
  flipped off and the empty state rendered while a late source was still coming

## Fix
1. `playback.ts` — soft timeout no longer terminal; `shouldHoldEmptyStateForDiscovery`
2. `useMediaSources.ts` — hold loading ~28s; use soft-hold for `isLoadingMore`
3. Modal + watch player — keep SourceLoadingOverlay while `sourcesLoadingMore`

## Apply
```bash
bash chillflix-patches/empty-state-race/apply.sh
```
