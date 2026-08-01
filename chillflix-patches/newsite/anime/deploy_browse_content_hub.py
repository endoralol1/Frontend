#!/usr/bin/env python3
"""Make Browse Content a clear Movies / TV Shows / Anime hub."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui53"


def patch_bottom_nav() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()

    old = """            <section class="browse-section">
                <h3 class="browse-section-title">Content</h3>
                <div class="browse-grid browse-grid-3">
                    <a class="browse-tile" href="<?= e(url('/movies')) ?>">
                        <span class="browse-tile-icon tone-red"><i class="uil uil-clapper-board"></i></span>
                        <span class="browse-tile-label">Movies</span>
                    </a>
                    <a class="browse-tile" href="<?= e(url('/tv-series')) ?>">
                        <span class="browse-tile-icon tone-red"><i class="uil uil-tv-retro"></i></span>
                        <span class="browse-tile-label">TV Shows</span>
                    </a>
                    <a class="browse-tile" href="<?= e(url('/anime')) ?>">
                        <span class="browse-tile-icon tone-orange"><i class="uil uil-star"></i></span>
                        <span class="browse-tile-label">Anime</span>
                    </a>
                </div>
            </section>
"""
    new = """            <section class="browse-section browse-section-content">
                <h3 class="browse-section-title">Content</h3>
                <div class="browse-grid browse-grid-content">
                    <a class="browse-card browse-content-card<?= !empty($navMovies) ? ' is-active' : '' ?>" href="<?= e(url('/movies')) ?>">
                        <span class="browse-card-icon tone-red"><i class="uil uil-clapper-board"></i></span>
                        <span class="browse-card-copy">
                            <strong>Movies</strong>
                            <em>All films</em>
                        </span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </a>
                    <a class="browse-card browse-content-card<?= !empty($navTv) ? ' is-active' : '' ?>" href="<?= e(url('/tv-series')) ?>">
                        <span class="browse-card-icon tone-red"><i class="uil uil-tv-retro"></i></span>
                        <span class="browse-card-copy">
                            <strong>TV Shows</strong>
                            <em>Series &amp; episodes</em>
                        </span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </a>
                    <a class="browse-card browse-content-card<?= !empty($navAnime) ? ' is-active' : '' ?>" href="<?= e(url('/anime')) ?>">
                        <span class="browse-card-icon tone-orange"><i class="uil uil-star"></i></span>
                        <span class="browse-card-copy">
                            <strong>Anime</strong>
                            <em>Japanese anime only</em>
                        </span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </a>
                </div>
            </section>
"""
    if "browse-grid-content" in text and "Japanese anime only" in text:
        print("browse content hub exists")
    elif old in text:
        text = text.replace(old, new, 1)
        print("browse content hub replaced")
    else:
        # looser replace from section title through Features
        m = re.search(
            r'<section class="browse-section">\s*<h3 class="browse-section-title">Content</h3>[\s\S]*?</section>\s*(?=<section class="browse-section">\s*<h3 class="browse-section-title">Features</h3>)',
            text,
        )
        if not m:
            raise SystemExit("Content browse section not found")
        text = text[: m.start()] + new + text[m.end() :]
        print("browse content hub replaced (regex)")

    path.write_text(text)


def patch_footer() -> None:
    path = ROOT / "app/Views/partials/footer.php"
    text = path.read_text()
    if "url('/anime')" in text:
        print("footer anime exists")
        return
    old = '                    <li><a href="<?= e(url(\'/tv-series\')) ?>">TV Shows</a></li>\n'
    # line-based
    lines = text.splitlines(keepends=True)
    out = []
    added = False
    for line in lines:
        out.append(line)
        if (not added) and "TV Shows</a></li>" in line and "url('/tv-series')" in line:
            indent = re.match(r"^(\s*)", line).group(1)
            out.append(f'{indent}<li><a href="<?= e(url(\'/anime\')) ?>">Anime</a></li>\n')
            added = True
    if not added:
        raise SystemExit("footer TV Shows link not found")
    path.write_text("".join(out))
    print("footer anime added")


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* browse-content-hub */"
    body = """.browse-grid-content {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.browse-content-card {
  width: 100%;
}
.browse-content-card > i:last-child {
  color: rgba(255, 255, 255, 0.35);
  font-size: 1.15rem;
  margin-left: auto;
}
.browse-card-icon.tone-red { color: #ef4444; }
.browse-card-icon.tone-orange { color: #db6937; }
.browse-content-card.is-active {
  border-color: rgba(219, 105, 55, 0.45);
  background: rgba(219, 105, 55, 0.12);
}
@media (min-width: 992px) {
  .browse-grid-content {
    gap: 0.35rem;
  }
  .browse-content-card {
    min-height: 2.85rem !important;
    padding: 0.45rem 0.65rem !important;
  }
}
"""
    if marker in text:
        text = re.sub(
            r"/\* browse-content-hub \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("browse-content-hub css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("browse-content-hub css appended")
    css.write_text(text)


def bump_assets() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset version", ASSET_V)


def main() -> None:
    patch_bottom_nav()
    patch_footer()
    patch_css()
    bump_assets()
    print("deploy_browse_content_hub done", ASSET_V)


if __name__ == "__main__":
    main()
