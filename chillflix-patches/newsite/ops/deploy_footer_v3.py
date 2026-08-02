#!/usr/bin/env python3
"""Footer v3: refined classic look + Contact/Request clear of mobile bottom nav."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui83"

FOOTER = r"""<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer footer--v3">
        <div class="footer-v3-brand">
            <img src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui83" alt="<?= e($site) ?>" loading="lazy" width="280" height="150" decoding="async">
        </div>

        <div class="footer-v3-panel">
            <nav class="footer-v3-cats" aria-label="Browse">
                <a href="<?= e(url('/movies')) ?>">Movies</a>
                <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
                <a href="<?= e(url('/anime')) ?>">Anime</a>
                <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
            </nav>
            <p class="footer-v3-desc"><?= e($site) ?> — free movie &amp; TV streaming. Browse a huge catalog anytime, no signup required.</p>
        </div>

        <div class="footer-v3-help">
            <a href="<?= e(url('/contact')) ?>">Contact</a>
            <span class="footer-v3-dot" aria-hidden="true"></span>
            <a href="<?= e(url('/request')) ?>">Request</a>
        </div>

        <p class="footer-v3-legal">This site does not store any files on our server; we only link to media hosted on 3rd party services.</p>
        <div class="footer-v3-clearance" aria-hidden="true"></div>
    </div>
</div>
"""

CSS = r"""
/* ——— Footer v3 + mobile bottom-nav clearance (ui83) ——— */
.footer.footer--v3 {
  margin-top: 2rem;
  padding: 0.25rem 0 0;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  text-align: center;
  background: transparent !important;
}

.footer.footer--v3 .footer-v3-brand {
  display: flex;
  justify-content: center;
  padding: 1.15rem 0 0.9rem;
}

.footer.footer--v3 .footer-v3-brand img {
  width: min(13rem, 54vw);
  height: auto;
  display: block;
}

.footer.footer--v3 .footer-v3-panel {
  margin: 0 auto;
  max-width: 40rem;
  padding: 1.15rem 1.15rem 1.2rem;
  border-radius: 1.35rem;
  border: 1px solid rgba(255, 255, 255, 0.09);
  background: rgba(255, 255, 255, 0.035);
}

.footer.footer--v3 .footer-v3-cats {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.35rem 1.15rem;
  margin: 0 0 0.85rem;
}

.footer.footer--v3 .footer-v3-cats a {
  color: rgba(255, 255, 255, 0.88) !important;
  text-decoration: none !important;
  font-size: 0.82rem;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.footer.footer--v3 .footer-v3-cats a:hover {
  color: #ffb89a !important;
}

.footer.footer--v3 .footer-v3-desc {
  margin: 0 auto;
  max-width: 34rem;
  color: rgba(255, 255, 255, 0.5);
  font-size: 0.86rem;
  font-weight: 400;
  line-height: 1.45;
}

.footer.footer--v3 .footer-v3-help {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  margin: 1.05rem 0 0.65rem;
}

.footer.footer--v3 .footer-v3-help a {
  color: #ff8f6b !important;
  text-decoration: none !important;
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.footer.footer--v3 .footer-v3-help a:hover {
  color: #ffd2b8 !important;
}

.footer.footer--v3 .footer-v3-dot {
  width: 0.28rem;
  height: 0.28rem;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.28);
}

.footer.footer--v3 .footer-v3-legal {
  margin: 0 auto;
  max-width: 36rem;
  padding: 0 0.65rem;
  color: rgba(255, 255, 255, 0.34);
  font-size: 0.72rem;
  line-height: 1.35;
}

.footer.footer--v3 .footer-v3-clearance {
  width: 100%;
  height: 1.25rem;
}

@media (max-width: 991.98px) {
  /*
   * Fixed 6-item bottom nav sits over the page end.
   * Body pad + footer clearance must both clear Contact/Request/legal.
   */
  body {
    padding-bottom: calc(8rem + env(safe-area-inset-bottom, 0px)) !important;
  }

  .wrapper > .container .footer,
  .footer.footer--v3 {
    padding-bottom: 0 !important;
    margin-bottom: 0 !important;
  }

  .footer.footer--v3 .footer-v3-clearance {
    height: calc(7.25rem + env(safe-area-inset-bottom, 0px));
  }

  .footer.footer--v3 .footer-v3-panel {
    padding: 1rem 0.9rem 1.05rem;
  }

  .footer.footer--v3 .footer-v3-cats {
    gap: 0.4rem 0.95rem;
  }

  .footer.footer--v3 .footer-v3-cats a {
    font-size: 0.76rem;
  }

  .footer.footer--v3 .footer-v3-help {
    margin-top: 1.15rem;
    gap: 0.9rem;
  }

  .footer.footer--v3 .footer-v3-help a {
    font-size: 1.02rem;
    min-height: 2.4rem;
    display: inline-flex;
    align-items: center;
    padding: 0.2rem 0.35rem;
  }

  /* Classic footer fallback if markup is ever restored */
  .footer .f-bottom {
    flex-direction: column !important;
    align-items: center !important;
    gap: 0.65rem !important;
    margin: 0.85rem 0 0 !important;
    padding-bottom: calc(6.75rem + env(safe-area-inset-bottom, 0px)) !important;
  }
}

@media (min-width: 992px) {
  .footer.footer--v3 .footer-v3-clearance {
    height: 1.5rem;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def strip_marker_blocks(css: str, markers: list[str]) -> str:
    for marker in markers:
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    return css


def main() -> None:
    footer_path = ROOT / "app/Views/partials/footer.php"
    bak = footer_path.with_suffix(
        footer_path.suffix + f".bak-v3-{datetime.now().strftime('%Y%m%d%H%M%S')}"
    )
    shutil.copy2(footer_path, bak)
    print("backup", bak.name)

    footer_path.write_text(FOOTER)
    print("footer v3 written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    css = strip_marker_blocks(
        css,
        [
            "/* ——— Footer clean (ui80) ——— */",
            "/* ——— Footer v2 + mobile clearance (ui82) ——— */",
            "/* ——— Footer v3 + mobile bottom-nav clearance (ui83) ——— */",
        ],
    )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("footer v3 css applied; clean leftovers stripped")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
