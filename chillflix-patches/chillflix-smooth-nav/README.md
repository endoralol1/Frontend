# Chillflix.lol smooth page transitions

Fade/lift on navigations without breaking clicks.

## Approach
- Home / Movies / TV **list** routes (`/movie/discover`, `/tv/discover`, …),
  search, and trending use **full document navigation** (required — soft client
  push + `@modal` intercept left clicks stuck on the wrong page).
- Title/person/collection **detail** URLs also hard-navigate (no intercept overlay).
- Cross-document `@view-transition { navigation: auto }` + `.cf-page-enter`
  give a soft fade on those loads.
- `SmoothClientNav` only clears nav flags — it does **not** intercept clicks.

## Apply
```bash
bash chillflix-patches/chillflix-smooth-nav/apply.sh
```
