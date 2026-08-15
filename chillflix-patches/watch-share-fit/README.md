# Watch managers full-width fix

Root cause: `#movie-managers` is flex; `.movie-managers-wrap` was content-sized so buttons stayed left.
Fix: wrap `flex: 1 1 auto; width: 100%` then children grow to fill; Share icon-only at end.

Live `?v=20260814-mgr-fullwidth1`.
