# Chillflix.lol smooth page transitions

Same feel as vuflix: fade/lift instead of hard blink on Home / Movies / TV.

## Approach
- Primary tabs / list routes (`/movie/discover`, `/tv/discover`, …) use **soft
  Next.js navigation** + `startViewTransition` (instant, like vuflix).
- `@modal/(.)movie|tv/discover` already returns null (list bypass), so soft-nav
  correctly updates the underlay.
- Title/person/collection **detail** URLs still hard-navigate (no intercept overlay).
- Cross-document View Transitions remain as a fallback for hard detail loads.
- Stronger `.cf-page-enter` template animation; dock/header stay put.

## Apply
```bash
bash chillflix-patches/chillflix-smooth-nav/apply.sh
```
