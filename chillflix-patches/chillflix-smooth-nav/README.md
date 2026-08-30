# Chillflix.lol smooth page transitions

Same feel as vuflix: fade/lift instead of hard blink on Home / Movies / TV.

## Approach
- Movies/TV list routes use **full document navigation** (modal safety). Enable
  cross-document View Transitions via `@view-transition { navigation: auto; }`.
- Soft Next.js routes wrap `router.push` in `document.startViewTransition`.
- Stronger `.cf-page-enter` template animation as fallback.
- Header + bottom dock keep `view-transition-name` so they stay put.
- Accent HolyLoader + progress bar.

## Apply
```bash
bash chillflix-patches/chillflix-smooth-nav/apply.sh
```
