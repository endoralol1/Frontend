# Episode sidebar fit + cast hover border

## Episodes
Previously forced the episode panel to fill the full sidebar height (`height: 100%` / `flex-grow`), which left a large empty gap when a season had few episodes.

Now the panel **shrink-wraps to content** and only scrolls when it hits `max-height: 100%` of the stretched sidebar (player column height). Live: `watch-player.css?v=20260813-eps-fit5`.

## Cast person cards (watch page)
`.cast-row { overflow-x: auto }` clips hover lift + outline. Watch page now uses an **inset orange border** on the photo (no upward translate) so the hover ring is fully visible.
