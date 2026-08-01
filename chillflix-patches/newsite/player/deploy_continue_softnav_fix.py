#!/usr/bin/env python3
"""Fix Continue Watching: watch pages must full-load (soft-nav skipped player.js)."""
from __future__ import annotations

import re
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui17"


def sync_assets() -> None:
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/js/continue-party.js").write_text((LOCAL / "continue-party.js").read_text())
    if (LOCAL / "continue-party.css").exists():
        (ROOT / "public/assets/css/continue-party.css").write_text(
            (LOCAL / "continue-party.css").read_text()
        )
    print("synced player/continue assets")


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
        if text2 != text:
            path.write_text(text2)
            print("bumped", rel)
        else:
            print("no version bump needed", rel)


def patch_soft_nav() -> None:
    app = ROOT / "public/assets/js/app.js"
    text = app.read_text()

    # 1) Exclude watch media URLs from soft navigation
    if "isWatchMediaUrl" not in text:
        needle = "  function isInternalSoftUrl(href) {"
        if needle not in text:
            raise SystemExit("isInternalSoftUrl missing")
        helper = """  function isWatchMediaUrl(href) {
    try {
      var path = new URL(absoluteUrl(href)).pathname || '';
      // /movie|/tv|/player/* need a full page load so hls.js + player.js + inline boot run
      return /\\/(movie|tv|player)\\//.test(path);
    } catch (e) {
      return false;
    }
  }

"""
        text = text.replace(needle, helper + needle, 1)
        print("added isWatchMediaUrl")

    # Insert early return inside isInternalSoftUrl
    if "if (isWatchMediaUrl(href)) return false;" not in text:
        text2, n = re.subn(
            r"(function isInternalSoftUrl\(href\) \{\s*try \{\s*var u = new URL\(absoluteUrl\(href\)\);\s*if \(u\.origin !== window\.location\.origin\) return false;\s*var path = u\.pathname \|\| '';\s*)",
            r"\1// Watch pages excluded — soft-nav only swaps .wrapper and never loads extraJs\n"
            r"      if (isWatchMediaUrl(href)) return false;\n      ",
            text,
            count=1,
        )
        if not n:
            raise SystemExit("failed to insert isWatchMediaUrl check")
        text = text2
        print("inserted watch exclusion check")

    # Remove movie/tv from soft-url allowlist so they never soft-nav
    text2 = text.replace(
        "return /(home|movies|tv-series|movie\\/|tv\\/|search|favorites|top-imdb|filters|contact|request)(\\/|$|\\?)/.test(path)",
        "return /(home|movies|tv-series|search|favorites|top-imdb|filters|contact|request)(\\/|$|\\?)/.test(path)",
    )
    if text2 != text:
        text = text2
        print("removed movie/tv from soft-nav allowlist")
    else:
        print("soft-nav allowlist already without movie/tv (or pattern differed)")

    # 2) Lazy-load player assets when Watch Now is clicked without player.js
    if "ensurePlayerAssets" not in text:
        old = """  /* newsite-inline-player */
  function startInlinePlayer() {
    if (!window.PLAYER || !window.ChillflixPlayer) {
      console.warn('Player not ready');
      return;
    }
    // stop trailer iframe if open
    var $frame = $('#player-frame');
    if ($frame.length) {
      $frame.addClass('d-none').empty();
    }
    window.ChillflixPlayer.startOnWatch(window.PLAYER);
  }"""
        new = f"""  /* newsite-inline-player */
  function loadScriptOnce(src) {{
    return new Promise(function (resolve, reject) {{
      var found = null;
      document.querySelectorAll('script[src]').forEach(function (el) {{
        if (!found && el.getAttribute('src') && el.getAttribute('src').indexOf(src.split('?')[0]) >= 0) found = el;
      }});
      if (found) {{
        if (found.getAttribute('data-loaded') === '1') {{ resolve(); return; }}
        found.addEventListener('load', function () {{ resolve(); }}, {{ once: true }});
        found.addEventListener('error', function () {{ reject(new Error('script')); }}, {{ once: true }});
        return;
      }}
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () {{ s.setAttribute('data-loaded', '1'); resolve(); }};
      s.onerror = function () {{ reject(new Error('script load failed')); }};
      document.head.appendChild(s);
    }});
  }}

  function ensurePlayerAssets() {{
    var base = (window.APP && APP.baseUrl) ? APP.baseUrl : '';
    var hls = 'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js';
    var player = base + '/assets/js/player.js?v={ASSET_V}';
    var chain = Promise.resolve();
    if (typeof window.Hls === 'undefined') chain = chain.then(function () {{ return loadScriptOnce(hls); }});
    if (!window.ChillflixPlayer) chain = chain.then(function () {{ return loadScriptOnce(player); }});
    return chain;
  }}

  function startInlinePlayer() {{
    if (!window.PLAYER) {{
      console.warn('Player config missing');
      return;
    }}
    function go() {{
      if (!window.ChillflixPlayer) {{
        console.warn('Player not ready');
        return;
      }}
      var $frame = $('#player-frame');
      if ($frame.length) {{
        $frame.addClass('d-none').empty();
      }}
      window.ChillflixPlayer.startOnWatch(window.PLAYER);
    }}
    if (window.ChillflixPlayer) {{
      go();
      return;
    }}
    ensurePlayerAssets().then(go).catch(function () {{
      console.warn('Player assets failed to load');
    }});
  }}"""
        if old not in text:
            raise SystemExit("startInlinePlayer block missing")
        text = text.replace(old, new, 1)
        print("added lazy player asset loader")
    else:
        print("lazy player loader already present")

    # 3) Re-run inline scripts after soft-nav (PLAYER bootstrap / autoplay)
    if "/* softnav-run-scripts */" not in text:
        marker = "    curWrapper.innerHTML = nextWrapper.innerHTML;\n    if (push) {"
        inject = """    curWrapper.innerHTML = nextWrapper.innerHTML;
    /* softnav-run-scripts */
    try {
      var scripts = curWrapper.querySelectorAll('script');
      scripts.forEach(function (old) {
        var s = document.createElement('script');
        if (old.src) {
          s.src = old.src;
          s.async = false;
        } else {
          s.textContent = old.textContent;
        }
        old.parentNode.replaceChild(s, old);
      });
    } catch (e) {}
    if (push) {"""
        if marker not in text:
            print("WARN: applySoftNavHtml marker missing")
        else:
            text = text.replace(marker, inject, 1)
            print("soft-nav now re-runs inline scripts")

    app.write_text(text)
    print("app.js soft-nav fix written")


def main() -> None:
    sync_assets()
    bump_versions()
    patch_soft_nav()
    print("deploy_continue_softnav_fix done", ASSET_V)


if __name__ == "__main__":
    main()
