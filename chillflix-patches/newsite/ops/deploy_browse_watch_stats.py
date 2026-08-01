#!/usr/bin/env python3
"""Browse panel: show guest/user watch stats (movies / TV / favorites)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui58"


STATS_HTML = """
            <section class="browse-section browse-stats-section" id="browse-stats-section" aria-label="Watch stats">
                <div class="browse-stats-card">
                    <div class="browse-stats-top">
                        <p class="browse-stats-kicker">Your activity</p>
                        <span class="browse-stats-who" id="browse-stats-who">Guest</span>
                    </div>
                    <div class="browse-stats-grid">
                        <div class="browse-stat">
                            <strong id="browse-stat-movies">0</strong>
                            <span>Movies</span>
                        </div>
                        <div class="browse-stat">
                            <strong id="browse-stat-tv">0</strong>
                            <span>TV Shows</span>
                        </div>
                        <div class="browse-stat">
                            <strong id="browse-stat-favs">0</strong>
                            <span>Favorites</span>
                        </div>
                    </div>
                    <p class="browse-stats-time" id="browse-stats-time">No watch history yet</p>
                </div>
            </section>
"""


STATS_JS = r"""
/* browse-watch-stats */
(function () {
  function readCwItems() {
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.list === "function") {
        return window.ChillflixContinue.list() || [];
      }
    } catch (e) {}
    try {
      var raw = localStorage.getItem("cf_continue_v1");
      var map = raw ? JSON.parse(raw) : {};
      if (!map || typeof map !== "object") return [];
      return Object.keys(map).map(function (k) { return map[k]; }).filter(Boolean);
    } catch (e2) {
      return [];
    }
  }

  function readFavRaw() {
    try {
      var ls = localStorage.getItem("user_bookmarks");
      if (ls) return ls;
    } catch (e) {}
    try {
      var ss = sessionStorage.getItem("user_bookmarks");
      if (ss) return ss;
    } catch (e2) {}
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_favs_v1=([^;]*)/);
      if (m) return decodeURIComponent(m[1]);
    } catch (e3) {}
    return null;
  }

  function readFavCount() {
    try {
      var raw = readFavRaw();
      if (!raw) return 0;
      var map = JSON.parse(raw) || {};
      return Object.keys(map).length;
    } catch (e) {
      return 0;
    }
  }

  function formatWatchTime(seconds) {
    var s = Math.max(0, Math.floor(Number(seconds) || 0));
    if (s < 60) return "Less than 1m watched";
    var mins = Math.round(s / 60);
    if (mins < 60) return "~" + mins + "m watched";
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    if (h < 24) return "~" + h + "h" + (m ? " " + m + "m" : "") + " watched";
    var d = Math.floor(h / 24);
    var rh = h % 24;
    return "~" + d + "d" + (rh ? " " + rh + "h" : "") + " watched";
  }

  function computeBrowseStats() {
    var items = readCwItems();
    var movies = {};
    var shows = {};
    var watchedSecs = 0;
    items.forEach(function (row) {
      if (!row || !row.id) return;
      var id = String(row.id);
      var t = Number(row.t) || 0;
      if (t > 0) watchedSecs += t;
      if ((row.type || row.mediaType) === "tv") shows[id] = true;
      else movies[id] = true;
    });
    return {
      movies: Object.keys(movies).length,
      tv: Object.keys(shows).length,
      favs: readFavCount(),
      seconds: watchedSecs,
      totalTitles: Object.keys(movies).length + Object.keys(shows).length
    };
  }

  function refreshBrowseStats() {
    var root = document.getElementById("browse-stats-section");
    if (!root) return;
    var stats = computeBrowseStats();
    var m = document.getElementById("browse-stat-movies");
    var tv = document.getElementById("browse-stat-tv");
    var f = document.getElementById("browse-stat-favs");
    var time = document.getElementById("browse-stats-time");
    var who = document.getElementById("browse-stats-who");
    if (m) m.textContent = String(stats.movies);
    if (tv) tv.textContent = String(stats.tv);
    if (f) f.textContent = String(stats.favs);
    if (time) {
      time.textContent = stats.totalTitles
        ? formatWatchTime(stats.seconds)
        : "No watch history yet";
    }
    if (who) {
      var nameEl = document.getElementById("browse-auth-name");
      var userCard = document.getElementById("browse-auth-user");
      var signedIn = userCard && !userCard.hasAttribute("hidden");
      if (signedIn && nameEl && nameEl.textContent && nameEl.textContent !== "Signed in") {
        who.textContent = nameEl.textContent.trim();
      } else if (signedIn) {
        who.textContent = "Member";
      } else {
        who.textContent = "Guest";
      }
    }
  }

  window.refreshBrowseStats = refreshBrowseStats;

  var _open = window.openBrowseSheet;
  if (typeof _open === "function") {
    window.openBrowseSheet = function () {
      var r = _open.apply(this, arguments);
      try { refreshBrowseStats(); } catch (e) {}
      return r;
    };
  }

  // Also hook jQuery-bound open if exposed later
  document.addEventListener("click", function (e) {
    if (e.target && e.target.closest && e.target.closest(".bottom-nav-browse")) {
      setTimeout(function () {
        try { refreshBrowseStats(); } catch (err) {}
      }, 0);
    }
  }, true);

  window.addEventListener("cf:softnav", function () {
    try { refreshBrowseStats(); } catch (e) {}
  });
  window.addEventListener("storage", function (e) {
    if (!e.key || e.key === "cf_continue_v1" || e.key === "user_bookmarks") {
      try { refreshBrowseStats(); } catch (err) {}
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refreshBrowseStats);
  } else {
    refreshBrowseStats();
  }
})();
"""


STATS_CSS = """/* browse-watch-stats */
.browse-stats-section {
  margin: 0 0 0.65rem;
}
.browse-stats-card {
  padding: 0.7rem 0.75rem 0.65rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background:
    linear-gradient(135deg, rgba(219, 105, 55, 0.14), rgba(220, 53, 69, 0.08)),
    rgba(255, 255, 255, 0.03);
}
.browse-stats-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.55rem;
}
.browse-stats-kicker {
  margin: 0;
  color: rgba(255, 255, 255, 0.5);
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.browse-stats-who {
  display: inline-flex;
  align-items: center;
  min-height: 1.35rem;
  padding: 0.12rem 0.5rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #ffd2b8;
  font-size: 0.68rem;
  font-weight: 700;
}
.browse-stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.35rem;
}
.browse-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.12rem;
  min-height: 3.1rem;
  padding: 0.4rem 0.25rem;
  border-radius: 0.75rem;
  background: rgba(0, 0, 0, 0.22);
  border: 1px solid rgba(255, 255, 255, 0.06);
  text-align: center;
}
.browse-stat strong {
  color: #fff;
  font-size: 1.15rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.02em;
}
.browse-stat span {
  color: rgba(255, 255, 255, 0.55);
  font-size: 0.62rem;
  font-weight: 650;
}
.browse-stats-time {
  margin: 0.5rem 0 0;
  color: rgba(255, 255, 255, 0.55);
  font-size: 0.72rem;
  font-weight: 550;
  text-align: center;
}
@media (min-width: 992px) {
  .browse-stats-section {
    margin-bottom: 0.5rem;
  }
  .browse-stats-card {
    padding: 0.6rem 0.65rem 0.55rem;
  }
  .browse-stat {
    min-height: 2.75rem;
  }
  .browse-stat strong {
    font-size: 1.05rem;
  }
}
"""


def patch_bottom_nav() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    if 'id="browse-stats-section"' in text:
        print("stats html exists")
        return
    needle = '<div class="browse-home-view" id="browse-home-view">'
    if needle not in text:
        raise SystemExit("browse-home-view not found")
    # Insert stats as first section inside home view
    text = text.replace(needle, needle + "\n" + STATS_HTML, 1)
    path.write_text(text)
    print("stats html inserted")


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* browse-watch-stats */"
    body = STATS_CSS.split("\n", 1)[1]
    if marker in text:
        text = re.sub(
            r"/\* browse-watch-stats \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("stats css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("stats css appended")
    css.write_text(text)


def patch_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()
    marker = "/* browse-watch-stats */"
    if marker in text:
        text = re.sub(
            r"/\* browse-watch-stats \*/[\s\S]*?(?=\n/\* [a-z]|/\* cf-|\Z)",
            STATS_JS.rstrip() + "\n\n",
            text,
            count=1,
        )
        print("stats js replaced")
    else:
        text = text.rstrip() + "\n\n" + STATS_JS.rstrip() + "\n"
        print("stats js appended")

    # Also refresh stats after applyBrowseAuthUser
    if "refreshBrowseStats" not in text[text.find("function applyBrowseAuthUser"): text.find("function applyBrowseAuthUser") + 500]:
        text2 = re.sub(
            r"(function applyBrowseAuthUser\(user\) \{[\s\S]*?)(\n  \})",
            r"\1\n    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}\2",
            text,
            count=1,
        )
        if text2 != text:
            text = text2
            print("auth refresh hooked")
        else:
            print("WARN auth refresh not hooked")

    # Hook openBrowseSheet body if wrapper approach misses jQuery local function
    if "try { refreshBrowseStats(); } catch (e) {}" not in text.split("function openBrowseSheet")[1][:400]:
        text = text.replace(
            "function openBrowseSheet() {\n    closeSearchSheet();\n    closeFiltersSheet();",
            "function openBrowseSheet() {\n    closeSearchSheet();\n    closeFiltersSheet();\n    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}",
            1,
        )
        print("openBrowseSheet refresh hooked")

    path.write_text(text)


def bump() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


def main() -> None:
    patch_bottom_nav()
    patch_css()
    patch_js()
    bump()
    print("deploy_browse_watch_stats done", ASSET_V)


if __name__ == "__main__":
    main()
