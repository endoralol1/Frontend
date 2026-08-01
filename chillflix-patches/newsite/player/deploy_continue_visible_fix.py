#!/usr/bin/env python3
"""Make Continue Watching seed on Watch Now + move rail above Top 10."""
from __future__ import annotations

import re
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui18"

CW_RAIL = """                <div class="section section-rail" id="continue-watching" hidden>
                    <div class="head">
                        <div class="start">
                            <h2 class="title">Continue Watching</h2>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items"></div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

"""


def sync_assets() -> None:
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/js/continue-party.js").write_text((LOCAL / "continue-party.js").read_text())
    if (LOCAL / "continue-party.css").exists():
        (ROOT / "public/assets/css/continue-party.css").write_text(
            (LOCAL / "continue-party.css").read_text()
        )
    print("synced assets")


def bump_versions() -> None:
    for rel in ("app/Views/layouts/main.php", "app/routes.php"):
        path = ROOT / rel
        text = path.read_text()
        text2 = re.sub(r"20260801-ui\d+", ASSET_V, text)
        text2 = re.sub(
            r"asset\('js/player\.js'\) \. '\?v=[^']+'",
            f"asset('js/player.js') . '?v={ASSET_V}'",
            text2,
        )
        text2 = re.sub(
            r"asset\('css/player\.css'\) \. '\?v=[^']+'",
            f"asset('css/player.css') . '?v={ASSET_V}'",
            text2,
        )
        path.write_text(text2)
        print("bumped", rel)


def move_continue_above_top10() -> None:
    home = ROOT / "app/Views/pages/home.php"
    text = home.read_text()
    start = text.find('<div class="section section-rail" id="continue-watching"')
    if start >= 0:
        # strip the whole section by tracking nested div depth
        i = text.find(">", start) + 1
        depth = 1
        while i < len(text) and depth:
            nxt_open = text.find("<div", i)
            nxt_close = text.find("</div>", i)
            if nxt_close < 0:
                raise SystemExit("continue rail unclosed")
            if nxt_open >= 0 and nxt_open < nxt_close:
                depth += 1
                i = nxt_open + 4
            else:
                depth -= 1
                i = nxt_close + len("</div>")
        # include surrounding blank lines
        a = start
        while a > 0 and text[a - 1] in " \t":
            a -= 1
        if a > 0 and text[a - 1] == "\n":
            a -= 1
        b = i
        while b < len(text) and text[b] in " \t":
            b += 1
        if b < len(text) and text[b] == "\n":
            b += 1
        text = text[:a] + "\n" + text[b:]
        print("removed old continue rail")
    elif 'id="continue-watching"' in text:
        raise SystemExit("continue id found but section open tag missing")

    marker = '                <div class="section section-top10">'
    if marker not in text:
        raise SystemExit("top10 marker missing")
    if 'id="continue-watching"' in text:
        raise SystemExit("continue rail still present after strip")
    text = text.replace(marker, CW_RAIL + marker, 1)
    home.write_text(text)
    print("moved continue-watching above Top 10")


def patch_app_seed_on_watch_now() -> None:
    """Belt-and-suspenders: seed localStorage from PLAYER when Watch Now is clicked."""
    app = ROOT / "public/assets/js/app.js"
    text = app.read_text()
    if "/* cw-seed-on-watch-now */" in text:
        print("app.js cw seed already present")
        # still bump player asset ref inside ensurePlayerAssets if present
        text = re.sub(
            r"(base \+ '/assets/js/player\.js\?v=)20260801-ui\d+",
            r"\g<1>" + ASSET_V,
            text,
        )
        app.write_text(text)
        return

    seed_fn = r"""
  /* cw-seed-on-watch-now */
  function seedContinueFromPlayer() {
    try {
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;
      var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
      if (cfg.type === 'tv') key += ':s' + (cfg.season || 1) + 'e' + (cfg.episode || 1);
      var map = {};
      try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
      var prev = map[key] || {};
      map[key] = {
        id: Number(cfg.id) || 0,
        type: cfg.type === 'tv' ? 'tv' : 'movie',
        title: cfg.title || prev.title || 'Untitled',
        poster: cfg.poster || prev.poster || '',
        year: cfg.year || prev.year || '',
        season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
        episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
        t: Math.max(1, Number(prev.t) || 0),
        d: Number(prev.d) || 0,
        updated: Date.now(),
        url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
      };
      localStorage.setItem('cf_continue_v1', JSON.stringify(map));
    } catch (e) {}
  }
"""

    # Insert before newsite-inline-player block
    marker = "  /* newsite-inline-player */"
    if marker not in text:
        raise SystemExit("newsite-inline-player marker missing")
    text = text.replace(marker, seed_fn + "\n" + marker, 1)

    # Call seed inside startInlinePlayer go() / start
    if "seedContinueFromPlayer();" not in text:
        # after ensure go starts player
        text = text.replace(
            "      window.ChillflixPlayer.startOnWatch(window.PLAYER);\n",
            "      seedContinueFromPlayer();\n"
            "      window.ChillflixPlayer.startOnWatch(window.PLAYER);\n",
            1,
        )
        # also for older startInlinePlayer shape
        if "seedContinueFromPlayer();" not in text:
            text = text.replace(
            "    window.ChillflixPlayer.startOnWatch(window.PLAYER);\n  }",
            "    seedContinueFromPlayer();\n"
            "    window.ChillflixPlayer.startOnWatch(window.PLAYER);\n  }",
            1,
        )

    text = re.sub(
        r"(base \+ '/assets/js/player\.js\?v=)20260801-ui\d+",
        r"\g<1>" + ASSET_V,
        text,
    )
    app.write_text(text)
    print("patched app.js seed on Watch Now")


def main() -> None:
    sync_assets()
    bump_versions()
    move_continue_above_top10()
    patch_app_seed_on_watch_now()
    # verify
    home = (ROOT / "app/Views/pages/home.php").read_text()
    i_cw = home.find('id="continue-watching"')
    i_top = home.find('section-top10')
    print("order ok" if 0 <= i_cw < i_top else "ORDER BAD", i_cw, i_top)
    print("deploy_continue_visible_fix done", ASSET_V)


if __name__ == "__main__":
    main()
