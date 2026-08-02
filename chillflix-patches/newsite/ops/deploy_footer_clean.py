#!/usr/bin/env python3
"""Clean up footer: drop grey box, shorter brand closer, pill links. Recoverable."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui80"

FOOTER = r"""<?php $site = (string) config('site_name'); ?>
<div class="container">
    <footer class="footer footer--clean">
        <div class="footer-brand">
            <img class="footer-logo" src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui80" alt="<?= e($site) ?>" loading="lazy" width="220" height="118" decoding="async">
            <p class="footer-tagline">Free movies &amp; shows. No fluff.</p>
        </div>

        <nav class="footer-links" aria-label="Browse">
            <a href="<?= e(url('/movies')) ?>">Movies</a>
            <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
            <a href="<?= e(url('/anime')) ?>">Anime</a>
            <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
        </nav>

        <div class="footer-meta">
            <p class="footer-disclaimer">This site does not store any files on our server — we only link to media hosted on 3rd party services.</p>
            <div class="footer-meta-links">
                <a href="<?= e(url('/contact')) ?>">Contact</a>
                <a href="<?= e(url('/request')) ?>">Request</a>
            </div>
        </div>
    </footer>
</div>
"""

CSS = r"""
/* ——— Footer clean (ui80) ——— */
.footer.footer--clean {
  margin-top: 2.25rem;
  padding: 1.25rem 0 1.5rem;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  text-align: center;
  background: transparent !important;
}

.footer.footer--clean .footer-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
  margin-bottom: 1rem;
}

.footer.footer--clean .footer-logo {
  width: min(11rem, 46vw);
  height: auto;
  display: block;
  opacity: 0.95;
}

.footer.footer--clean .footer-tagline {
  margin: 0;
  color: rgba(255, 255, 255, 0.55);
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.9rem;
  font-weight: 500;
  letter-spacing: 0.01em;
}

.footer.footer--clean .footer-links {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.45rem;
  margin: 0 0 1.15rem;
}

.footer.footer--clean .footer-links a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.15rem;
  padding: 0.4rem 0.9rem;
  border-radius: 999px;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.82) !important;
  text-decoration: none !important;
  font-family: Outfit, Poppins, sans-serif;
  font-size: 0.8rem;
  font-weight: 650;
  letter-spacing: 0.02em;
  transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease, transform 0.16s ease;
}

.footer.footer--clean .footer-links a:hover {
  color: #fff !important;
  border-color: rgba(219, 105, 55, 0.45);
  background: rgba(219, 105, 55, 0.16);
  transform: translateY(-1px);
}

.footer.footer--clean .footer-meta {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.65rem;
  max-width: 36rem;
  margin: 0 auto;
}

.footer.footer--clean .footer-disclaimer {
  margin: 0;
  color: rgba(255, 255, 255, 0.38);
  font-size: 0.72rem;
  font-weight: 400;
  line-height: 1.4;
}

.footer.footer--clean .footer-meta-links {
  display: flex;
  gap: 1rem;
}

.footer.footer--clean .footer-meta-links a {
  color: rgba(255, 176, 137, 0.85) !important;
  text-decoration: none !important;
  font-size: 0.8rem;
  font-weight: 600;
}

.footer.footer--clean .footer-meta-links a:hover {
  color: #ffd2b8 !important;
}

/* Hide old boxed footer styles if any leftover markup */
.footer.footer--clean .f-top,
.footer.footer--clean .f-top-inner {
  background: transparent !important;
  border: 0 !important;
  padding: 0 !important;
}

@media (max-width: 767.98px) {
  /* Bottom nav already covers Movies/TV/Anime — keep Top IMDB + keep row compact */
  .footer.footer--clean {
    margin-top: 1.5rem;
    padding-top: 1rem;
  }
  .footer.footer--clean .footer-links {
    gap: 0.35rem;
  }
  .footer.footer--clean .footer-links a {
    min-height: 2rem;
    padding: 0.35rem 0.75rem;
    font-size: 0.74rem;
  }
}

@media (min-width: 992px) {
  .footer.footer--clean .footer-meta {
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
    max-width: 52rem;
    text-align: left;
    gap: 1.25rem;
  }
  .footer.footer--clean .footer-disclaimer {
    flex: 1;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def main() -> None:
    footer_path = ROOT / "app/Views/partials/footer.php"
    bak = footer_path.with_suffix(
        footer_path.suffix + f".bak-clean-{datetime.now().strftime('%Y%m%d%H%M%S')}"
    )
    shutil.copy2(footer_path, bak)
    print("backup", bak.name)

    footer_path.write_text(FOOTER)
    print("footer rewritten")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Footer clean (ui80) ——— */"
    if marker in css:
        css = re.sub(
            re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
            "",
            css,
            count=1,
        )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("footer css applied")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
