#!/usr/bin/env python3
"""Site-wide soft animated ambient background (Favorites-style atmosphere)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui73"

AMBIENT_HTML = """<div class="cf-ambient" aria-hidden="true">
    <span class="cf-ambient-orb cf-ambient-orb--a"></span>
    <span class="cf-ambient-orb cf-ambient-orb--b"></span>
    <span class="cf-ambient-orb cf-ambient-orb--c"></span>
    <span class="cf-ambient-wash"></span>
</div>
"""

CSS = r"""
/* ——— Site ambient atmosphere (ui73) ——— */
.cf-ambient {
  position: fixed;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  overflow: hidden;
  contain: strict;
}

.cf-ambient-orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(64px);
  opacity: 0.55;
  will-change: transform, opacity;
  transform: translate3d(0, 0, 0);
  background: radial-gradient(circle at 35% 35%, rgba(255, 120, 80, 0.55), rgba(220, 53, 69, 0.12) 48%, transparent 70%);
}

.cf-ambient-orb--a {
  width: min(52vw, 28rem);
  height: min(52vw, 28rem);
  top: -12%;
  left: -8%;
  animation: cfAmbA 22s ease-in-out infinite alternate;
}

.cf-ambient-orb--b {
  width: min(48vw, 26rem);
  height: min(48vw, 26rem);
  top: 8%;
  right: -12%;
  opacity: 0.42;
  background: radial-gradient(circle at 40% 40%, rgba(220, 53, 69, 0.42), rgba(219, 105, 55, 0.1) 50%, transparent 72%);
  animation: cfAmbB 28s ease-in-out infinite alternate;
}

.cf-ambient-orb--c {
  width: min(60vw, 34rem);
  height: min(40vw, 22rem);
  left: 28%;
  bottom: -18%;
  opacity: 0.32;
  background: radial-gradient(circle at 50% 40%, rgba(219, 105, 55, 0.28), rgba(220, 53, 69, 0.08) 55%, transparent 74%);
  animation: cfAmbC 26s ease-in-out infinite alternate;
}

.cf-ambient-wash {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(120% 70% at 50% -10%, rgba(220, 53, 69, 0.1), transparent 55%),
    linear-gradient(180deg, rgba(18, 20, 28, 0.15) 0%, rgba(10, 12, 16, 0.35) 55%, rgba(9, 11, 16, 0.55) 100%);
}

@keyframes cfAmbA {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.5; }
  50%  { transform: translate3d(8%, 10%, 0) scale(1.08); opacity: 0.65; }
  100% { transform: translate3d(3%, 18%, 0) scale(0.96); opacity: 0.45; }
}

@keyframes cfAmbB {
  0%   { transform: translate3d(0, 0, 0) scale(1.05); opacity: 0.38; }
  50%  { transform: translate3d(-10%, 12%, 0) scale(1); opacity: 0.52; }
  100% { transform: translate3d(-4%, 20%, 0) scale(1.1); opacity: 0.34; }
}

@keyframes cfAmbC {
  0%   { transform: translate3d(0, 0, 0) scale(1); opacity: 0.28; }
  50%  { transform: translate3d(-6%, -8%, 0) scale(1.12); opacity: 0.4; }
  100% { transform: translate3d(8%, -4%, 0) scale(0.94); opacity: 0.26; }
}

/* Keep content above ambient */
body > .wrapper {
  position: relative;
  z-index: 1;
}

/* Quieter on watch / player pages so video stays focus */
body.watch-page .cf-ambient,
body.player-page .cf-ambient,
.watch-p ~ .cf-ambient,
main.watch-p ~ .cf-ambient {
  opacity: 0.35;
}

body.watch-page .cf-ambient-orb,
body.player-page .cf-ambient-orb {
  animation-duration: 40s;
  filter: blur(72px);
}

/* Soften on very small / low power — still present, less busy */
@media (max-width: 479.98px) {
  .cf-ambient-orb--c { display: none; }
  .cf-ambient-orb {
    filter: blur(56px);
    opacity: 0.45;
  }
}

@media (prefers-reduced-motion: reduce) {
  .cf-ambient-orb {
    animation: none !important;
    opacity: 0.35 !important;
  }
}

/* Favorites already has its own vibe — keep ambient but don’t fight it */
.favorites-page {
  background: transparent !important;
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_layout() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    if 'class="cf-ambient"' in text:
        print("ambient html already present")
        return
    # Insert ambient as first child of body, before wrapper
    old = '<body class="<?= e($bodyClass) ?>">\n<div class="wrapper">'
    new = '<body class="<?= e($bodyClass) ?>">\n' + AMBIENT_HTML + '<div class="wrapper">'
    if old not in text:
        # try alternate whitespace
        old2 = '<body class="<?= e($bodyClass) ?>">\r\n<div class="wrapper">'
        if old2 in text:
            text = text.replace(old2, '<body class="<?= e($bodyClass) ?>">\n' + AMBIENT_HTML + '<div class="wrapper">', 1)
        else:
            raise SystemExit("body/wrapper marker not found")
    else:
        text = text.replace(old, new, 1)
    path.write_text(text)
    print("ambient html injected")


def patch_watch_body_class() -> None:
    """Tag watch pages so ambient can quiet down."""
    watch = ROOT / "app/Views/pages/watch.php"
    text = watch.read_text()
    if 'class="watch-p"' in text and "watch-page" not in text[:800]:
        # body class is set in routes — check routes
        pass
    routes = ROOT / "app/routes.php"
    rt = routes.read_text()
    if "'bodyClass' => 'watch-page'" in rt or '"bodyClass" => "watch-page"' in rt:
        print("watch bodyClass already set")
        return
    # Try to find watch render and add bodyClass
    # Look for common pattern near watch view
    if "watch.php" in rt and "bodyClass" in rt:
        print("routes have bodyClass usages — skip auto if ambiguous")
    # Soft approach: detect main.watch-p via CSS already handles .watch-p parent? 
    # Ambient is sibling of wrapper, not of main — so need body class.
    # Add JS-less CSS using :has()
    print("will use :has(main.watch-p) in CSS")


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    css = path.read_text()
    # Enhance CSS with :has for watch quiet mode
    css_block = CSS.replace(
        """body.watch-page .cf-ambient,
body.player-page .cf-ambient,
.watch-p ~ .cf-ambient,
main.watch-p ~ .cf-ambient {
  opacity: 0.35;
}

body.watch-page .cf-ambient-orb,
body.player-page .cf-ambient-orb {
  animation-duration: 40s;
  filter: blur(72px);
}""",
        """body.watch-page .cf-ambient,
body.player-page .cf-ambient,
body:has(main.watch-p) .cf-ambient,
body:has(#np-shell) .cf-ambient {
  opacity: 0.28;
}

body.watch-page .cf-ambient-orb,
body.player-page .cf-ambient-orb,
body:has(main.watch-p) .cf-ambient-orb,
body:has(#np-shell) .cf-ambient-orb {
  animation-duration: 40s;
  filter: blur(72px);
}""",
    )
    marker = "/* ——— Site ambient atmosphere (ui73) ——— */"
    if marker in css:
        css = re.sub(
            re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
            "",
            css,
            count=1,
        )
    path.write_text(css.rstrip() + "\n\n" + css_block.strip() + "\n")
    print("ambient css applied")


def main() -> None:
    patch_layout()
    patch_watch_body_class()
    patch_css()
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    # also bump continue-party versions in layout already covered by bump
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
