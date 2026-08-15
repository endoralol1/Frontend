# Off-screen card poster lifecycle

Keeps rails in the DOM, but:

1. **`content-visibility: auto`** on rails/cards — browser skips paint/layout for off-screen blocks
2. **Unload posters** when clearly outside the viewport (placeholder + `data-src`); reload via lazysizes when near again
3. **Hover trailers** stop if the active card scrolls off-screen

Live: `card-poster-lifecycle.{js,css}` + tweaks in `card-hover-preview.js`  
Asset bust: `?v=20260813-perf08`
