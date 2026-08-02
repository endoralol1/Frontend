#!/usr/bin/env python3
"""Polish Browse watch stats UI to match the sheet better."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui60"

NEW_HTML = """
            <section class="browse-section browse-stats-section" id="browse-stats-section" aria-label="Watch stats">
                <div class="browse-stats-head">
                    <h3 class="browse-section-title">Your activity</h3>
                    <span class="browse-stats-who" id="browse-stats-who">Guest</span>
                </div>
                <div class="browse-stats-card">
                    <div class="browse-stats-grid" role="list">
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-clapper-board" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-movies">0</strong>
                                <span>Movies</span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-tv-retro" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-tv">0</strong>
                                <span>TV Shows</span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-heart" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-favs">0</strong>
                                <span>Favorites</span>
                            </div>
                        </div>
                    </div>
                    <div class="browse-stats-foot">
                        <i class="uil uil-clock" aria-hidden="true"></i>
                        <p class="browse-stats-time" id="browse-stats-time">No watch history yet</p>
                    </div>
                </div>
            </section>
"""

NEW_CSS = """/* browse-watch-stats */
.browse-stats-section {
  margin: 0 0 0.85rem;
}
.browse-stats-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.55rem;
  margin-bottom: 0.45rem;
}
.browse-stats-head .browse-section-title {
  margin: 0;
}
.browse-stats-who {
  display: inline-flex;
  align-items: center;
  min-height: 1.4rem;
  padding: 0.14rem 0.55rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.browse-stats-card {
  border-radius: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
  overflow: hidden;
}
.browse-stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
}
.browse-stat {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 4.15rem;
  padding: 0.65rem 0.35rem 0.55rem;
  text-align: center;
}
.browse-stat + .browse-stat::before {
  content: "";
  position: absolute;
  left: 0;
  top: 18%;
  bottom: 18%;
  width: 1px;
  background: rgba(255, 255, 255, 0.08);
}
.browse-stat > i {
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 0.65rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1.5px solid rgba(219, 105, 55, 0.55);
  background: rgba(219, 105, 55, 0.08);
  color: #ffb089;
  font-size: 0.95rem;
  line-height: 1;
}
.browse-stat-copy {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.12rem;
  min-width: 0;
}
.browse-stat strong {
  color: #fff;
  font-size: 1.05rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.02em;
}
.browse-stat span {
  color: rgba(255, 255, 255, 0.5);
  font-size: 0.62rem;
  font-weight: 650;
  line-height: 1.1;
}
.browse-stats-foot {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.45rem 0.65rem 0.55rem;
  border-top: 1px solid rgba(255, 255, 255, 0.07);
  background: rgba(0, 0, 0, 0.12);
}
.browse-stats-foot > i {
  color: #ffb089;
  font-size: 0.9rem;
  line-height: 1;
}
.browse-stats-time {
  margin: 0;
  color: rgba(255, 255, 255, 0.62);
  font-size: 0.72rem;
  font-weight: 600;
}
@media (min-width: 992px) {
  .browse-stats-section {
    margin-bottom: 0.55rem;
  }
  .browse-stat {
    min-height: 3.55rem;
    padding: 0.5rem 0.3rem 0.45rem;
    gap: 0.28rem;
  }
  .browse-stat > i {
    width: 1.65rem;
    height: 1.65rem;
    border-radius: 0.55rem;
    font-size: 0.88rem;
  }
  .browse-stat strong {
    font-size: 0.95rem;
  }
  .browse-stats-foot {
    padding: 0.38rem 0.55rem 0.45rem;
  }
}
"""


def patch_html() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    pattern = re.compile(
        r'\s*<section class="browse-section browse-stats-section" id="browse-stats-section"[\s\S]*?</section>\s*',
        re.M,
    )
    if not pattern.search(text):
        raise SystemExit("stats section not found")
    text = pattern.sub("\n" + NEW_HTML + "\n", text, count=1)
    path.write_text(text)
    print("stats html polished")


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* browse-watch-stats */"
    body = NEW_CSS.split("\n", 1)[1]
    if marker not in text:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("stats css appended")
    else:
        text = re.sub(
            r"/\* browse-watch-stats \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("stats css replaced")
    css.write_text(text)


def bump() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


def main() -> None:
    patch_html()
    patch_css()
    bump()
    print("done", ASSET_V)


if __name__ == "__main__":
    main()
