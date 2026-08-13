# Baked starfield

Stars are drawn **once** at 1600×900, converted to a WebP/PNG blob, and set as a CSS `background-image` on `.cf-bgfx-stars` with `background-size: cover`.

No resize redraw, no RAF loop. Canvas is cleared/hidden after bake.

Live via `app.docksearch-1228.js` (`?v=20260813-perf11`).
