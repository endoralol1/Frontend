# Watch managers row (ui13)

Order: **Trailer → Watch Now → Auto Play → Favorite** … **Share** (right).

- Trailer only when `$trailer` is set (`#btn-trailer`)
- Overlay badge keeps `.player-trailer-label.js-play-trailer` (no duplicate id)
- Share uses `margin-left: auto` on the flex row
- Mobile: force trailer visible (overrides style.css hide)