#!/usr/bin/env python3
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")

FOOTER = """<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer footer--v3">
        <div class="footer-v3-brand">
            <img src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui84" alt="<?= e($site) ?>" loading="lazy" width="280" height="150" decoding="async">
        </div>

        <div class="footer-v3-panel">
            <nav class="footer-v3-cats" aria-label="Browse">
                <a href="<?= e(url('/movies')) ?>">Movies</a>
                <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
                <a href="<?= e(url('/anime')) ?>">Anime</a>
                <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
            </nav>
            <div class="footer-v3-help">
                <a href="<?= e(url('/contact')) ?>">Contact</a>
                <span class="footer-v3-dot" aria-hidden="true"></span>
                <a href="<?= e(url('/request')) ?>">Request</a>
            </div>
            <p class="footer-v3-desc"><?= e($site) ?> — free movie &amp; TV streaming. Browse a huge catalog anytime, no signup required.</p>
        </div>

        <p class="footer-v3-legal">This site does not store any files on our server; we only link to media hosted on 3rd party services.</p>
        <div class="footer-v3-clearance" aria-hidden="true"></div>
    </div>
</div>
"""

EXTRA = """
/* help links sit inside the panel now */
.footer.footer--v3 .footer-v3-panel .footer-v3-help {
  margin: 0.15rem 0 0.85rem;
}
.footer.footer--v3 .footer-v3-panel .footer-v3-desc {
  margin-top: 0;
}
"""


def main() -> None:
    (ROOT / "app/Views/partials/footer.php").write_text(FOOTER)
    print("footer clean")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    if "help links sit inside the panel now" not in css:
        css_path.write_text(css.rstrip() + "\n\n" + EXTRA.strip() + "\n")
        print("panel help css added")
    else:
        print("panel help css already present")


if __name__ == "__main__":
    main()
