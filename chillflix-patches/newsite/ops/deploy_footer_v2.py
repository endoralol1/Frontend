#!/usr/bin/env python3
"""Footer v2: clearer links, Contact/Request not buried, mobile scroll clearance."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui82"

FOOTER = r"""<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer footer--v2">
        <div class="footer-v2-brand">
            <img src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui82" alt="<?= e($site) ?>" loading="lazy" width="260" height="140" decoding="async">
        </div>

        <div class="footer-v2-panel">
            <nav class="footer-v2-nav" aria-label="Site">
                <a href="<?= e(url('/movies')) ?>">Movies</a>
                <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
                <a href="<?= e(url('/anime')) ?>">Anime</a>
                <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
                <a class="is-accent" href="<?= e(url('/contact')) ?>">Contact</a>
                <a class="is-accent" href="<?= e(url('/request')) ?>">Request</a>
            </nav>
            <p class="footer-v2-desc"><?= e($site) ?> — watch movies and TV online free. Big catalog, no signup required.</p>
        </div>

        <p class="footer-v2-legal">This site does not store any files on our server; we only link to media hosted on 3rd party services.</p>
        <div class="footer-v2-spacer" aria-hidden="true"></div>
    </div>
</div>
"""

CSS = r"""
/* ——— Footer v2 + mobile clearance (ui82) ——— */
.footer.footer--v2 {
  margin-top: 2rem;
  padding: 0.5rem 0 0;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  text-align: center;
  background: transparent !important;
}

.footer.footer--v2 .footer-v2-brand {
  display: flex;
  justify-content: center;
  padding: 1.1rem 0 0.85rem;
}

.footer.footer--v2 .footer-v2-brand img {
  width: min(12.5rem, 52vw);
  height: auto;
  display: block;
}

.footer.footer--v2 .footer-v2-panel {
  margin: 0 auto;
  max-width: 42rem;
  padding: 1rem 1rem 1.1rem;
  border-radius: 1.2rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
}

.footer.footer--v2 .footer-v2-nav {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.4rem;
  margin-bottom: 0.85rem;
}

.footer.footer--v2 .footer-v2-nav a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.1rem;
  padding: 0.35rem 0.85rem;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(0, 0, 0, 0.22);
  color: rgba(255, 255, 255, 0.86) !important;
  text-decoration: none !important;
  font-size: 0.78rem;
  font-weight: 650;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.footer.footer--v2 .footer-v2-nav a.is-accent {
  color: #ffd2b8 !important;
  border-color: rgba(219, 105, 55, 0.4);
  background: rgba(219, 105, 55, 0.14);
}

.footer.footer--v2 .footer-v2-nav a:hover {
  color: #fff !important;
  border-color: rgba(219, 105, 55, 0.5);
  background: rgba(219, 105, 55, 0.2);
}

.footer.footer--v2 .footer-v2-desc {
  margin: 0 auto;
  max-width: 34rem;
  color: rgba(255, 255, 255, 0.52);
  font-size: 0.84rem;
  font-weight: 400;
  line-height: 1.4;
}

.footer.footer--v2 .footer-v2-legal {
  margin: 0.9rem auto 0;
  max-width: 36rem;
  color: rgba(255, 255, 255, 0.34);
  font-size: 0.72rem;
  line-height: 1.35;
  padding: 0 0.5rem;
}

/* Extra room so bottom nav never covers Contact/Request/legal */
.footer.footer--v2 .footer-v2-spacer {
  width: 100%;
  height: 0;
}

@media (max-width: 991.98px) {
  /* Bottom nav is fixed — give the page real end-scroll room */
  body {
    padding-bottom: calc(7.25rem + env(safe-area-inset-bottom, 0px)) !important;
  }

  .wrapper > .container .footer,
  .footer.footer--v2 {
    padding-bottom: 0 !important;
    margin-bottom: 0 !important;
  }

  .footer.footer--v2 .footer-v2-spacer {
    height: calc(6.5rem + env(safe-area-inset-bottom, 0px));
  }

  .footer.footer--v2 .footer-v2-panel {
    padding: 0.9rem 0.8rem 1rem;
  }

  .footer.footer--v2 .footer-v2-nav {
    gap: 0.35rem;
  }

  .footer.footer--v2 .footer-v2-nav a {
    min-height: 2.05rem;
    padding: 0.32rem 0.75rem;
    font-size: 0.72rem;
  }

  /* Old footer bottom clearance if recovery/old markup ever shows */
  .footer .f-bottom {
    padding-bottom: calc(5.5rem + env(safe-area-inset-bottom, 0px)) !important;
    margin-bottom: 0 !important;
    flex-wrap: wrap;
    gap: 0.75rem;
  }
}

@media (min-width: 992px) {
  .footer.footer--v2 .footer-v2-spacer {
    height: 1.25rem;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    footer_path = ROOT / "app/Views/partials/footer.php"
    bak = footer_path.with_suffix(
        footer_path.suffix + f".bak-v2-{datetime.now().strftime('%Y%m%d%H%M%S')}"
    )
    shutil.copy2(footer_path, bak)
    print("backup", bak.name)

    footer_path.write_text(FOOTER)
    print("footer v2 written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    # Remove previous clean footer CSS so it doesn't fight
    for marker in (
        "/* ——— Footer clean (ui80) ——— */",
        "/* ——— Footer v2 + mobile clearance (ui82) ——— */",
    ):
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("footer v2 css applied")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
