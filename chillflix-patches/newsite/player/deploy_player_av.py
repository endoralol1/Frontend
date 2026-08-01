#!/usr/bin/env python3
"""Deploy audio/subs/quality fixes + autoplay toggle for newsite player."""
from __future__ import annotations

from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
LOCAL = Path(__file__).resolve().parent
ASSET_V = "20260801-ui9"


def write_files() -> None:
    (ROOT / "app/Services/PlayerSources.php").write_text((LOCAL / "PlayerSources.php").read_text())
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/css/player.css").write_text((LOCAL / "player.css").read_text())
    print("wrote PlayerSources + player assets")


def patch_routes() -> None:
    text = (ROOT / "app/routes.php").read_text()

    # API routes for subtitles + caption proxy
    if "/api/player/subtitles" not in text:
        marker = "$router->get('/api/player/sources', function () {\n"
        idx = text.find(marker)
        if idx < 0:
            raise SystemExit("sources route missing")
        # find end of sources route block
        end = text.find("});\n", idx)
        if end < 0:
            raise SystemExit("sources route end missing")
        end += len("});\n")
        insert = """
$router->get('/api/player/subtitles', function () {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $imdbId = isset($_GET['imdbId']) ? trim((string) $_GET['imdbId']) : null;
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $result = PlayerSources::fetchSubtitles($type, $tmdbId, $imdbId ?: null, $season, $episode);
    json_response($result, !empty($result['ok']) ? 200 : 502);
});

$router->get('/api/player/caption', function () {
    $url = trim((string) ($_GET['url'] ?? ''));
    PlayerSources::proxyCaption($url);
});
"""
        text = text[:end] + insert + text[end:]
        print("added subtitle/caption routes")
    else:
        print("subtitle routes already present")

    # Enrich playerConfig with imdb + subs APIs + cache bust
    old_cfg = """    $playerConfig = [
        'type' => $type,
        'id' => $id,
        'title' => $title,
        'year' => $year,
        'poster' => $poster,
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w1280'),
        'backUrl' => $watchUrl,
        'watchUrl' => $watchUrl,
        'season' => $season,
        'episode' => $episode,
        'next' => $nextEpisode,
        'sourcesApi' => url('/api/player/sources'),
        'embed' => true,
        'autoplay' => true,
    ];"""
    new_cfg = f"""    $imdbId = (string) (($item['external_ids']['imdb_id'] ?? '') ?: ($item['imdb_id'] ?? ''));
    $playerConfig = [
        'type' => $type,
        'id' => $id,
        'imdbId' => $imdbId !== '' ? $imdbId : null,
        'title' => $title,
        'year' => $year,
        'poster' => $poster,
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w1280'),
        'backUrl' => $watchUrl,
        'watchUrl' => $watchUrl,
        'season' => $season,
        'episode' => $episode,
        'next' => $nextEpisode,
        'sourcesApi' => url('/api/player/sources'),
        'subsApi' => url('/api/player/subtitles'),
        'captionApi' => url('/api/player/caption'),
        'embed' => true,
        'autoplay' => true,
    ];"""
    if "'subsApi'" not in text or "'imdbId'" not in text:
        if old_cfg not in text:
            # try looser replace of sourcesApi-only config
            if "'sourcesApi' => url('/api/player/sources')" in text and "'subsApi'" not in text:
                text = text.replace(
                    "'sourcesApi' => url('/api/player/sources'),\n        'embed' => true,\n        'autoplay' => true,",
                    "'sourcesApi' => url('/api/player/sources'),\n        'subsApi' => url('/api/player/subtitles'),\n        'captionApi' => url('/api/player/caption'),\n        'imdbId' => (string) (($item['external_ids']['imdb_id'] ?? '') ?: ($item['imdb_id'] ?? '')) ?: null,\n        'embed' => true,\n        'autoplay' => true,",
                    1,
                )
                print("patched playerConfig fields (loose)")
            else:
                raise SystemExit("playerConfig block not found for patch")
        else:
            text = text.replace(old_cfg, new_cfg, 1)
            print("patched playerConfig with imdb/subs")
    else:
        print("playerConfig already has imdb/subs")

    # bump asset versions in watch_page view
    import re

    text2, n1 = re.subn(
        r"asset\('css/player\.css'\) \. '\?v=[^']+'",
        f"asset('css/player.css') . '?v={ASSET_V}'",
        text,
        count=2,
    )
    text2, n2 = re.subn(
        r"asset\('js/player\.js'\) \. '\?v=[^']+'",
        f"asset('js/player.js') . '?v={ASSET_V}'",
        text2,
        count=2,
    )
    text = text2
    print(f"bumped player assets css={n1} js={n2} -> {ASSET_V}")

    (ROOT / "app/routes.php").write_text(text)
    print("patched routes.php")


def patch_app_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()
    marker = "/* newsite-autoplay-toggle */"
    if marker in text:
        import re

        text = re.sub(
            r"/\* newsite-autoplay-toggle \*/.*?/\* newsite-autoplay-toggle-end \*/\n?",
            "",
            text,
            flags=re.S,
        )

    block = """
  /* newsite-autoplay-toggle */
  function syncWatchAutoplayUi() {
    var on = localStorage.getItem('cf_watch_autoplay') === '1';
    var $btn = $('#btn-autoplay');
    if (!$btn.length) return on;
    $btn.attr('aria-pressed', on ? 'true' : 'false');
    $btn.find('i').attr('class', on ? 'uil uil-check-circle' : 'uil uil-circle');
    return on;
  }
  $(document).on('click', '#btn-autoplay', function (e) {
    e.preventDefault();
    var on = localStorage.getItem('cf_watch_autoplay') === '1';
    on = !on;
    localStorage.setItem('cf_watch_autoplay', on ? '1' : '0');
    syncWatchAutoplayUi();
    // Movies: start player when enabling. TV: also mirrors Auto Next preference.
    if (on) {
      if (window.PLAYER) window.PLAYER.autoplay = true;
      if (!$('#np-shell').length && typeof startInlinePlayer === 'function') {
        startInlinePlayer();
      }
      if (window.PLAYER && window.PLAYER.type === 'tv') {
        localStorage.setItem('cf_np_autonext', '1');
      }
    }
  });
  $(function () {
    var pref = syncWatchAutoplayUi();
    var forced = $('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1';
    if ((pref || forced) && typeof startInlinePlayer === 'function') {
      setTimeout(startInlinePlayer, 80);
    }
  });
  /* newsite-autoplay-toggle-end */
"""

    # Avoid double auto-start from older inline-player block: soften that block
    text = text.replace(
        """  // Auto-start when ?play=1
  if ($('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1') {
    $(function () { setTimeout(startInlinePlayer, 80); });
  }""",
        """  // Auto-start handled by watch autoplay preference + ?play=1
""",
    )

    anchor = "  $(document).on('click', '#btn-share', function () {"
    if anchor not in text:
        raise SystemExit("share handler missing")
    if marker not in text:
        text = text.replace(anchor, block + "\n" + anchor, 1)
        print("inserted app.js autoplay toggle")
    else:
        print("app.js autoplay toggle already present")
    path.write_text(text)


def bump_layout_app_js() -> None:
    path = ROOT / "app/Views/layouts/main.php"
    text = path.read_text()
    import re

    text2, n = re.subn(
        r"(asset\('js/app\.js'\)\) \?>\?v=)[^\"']+",
        rf"\g<1>{ASSET_V}",
        text,
        count=1,
    )
    if n == 0:
        text2, n = re.subn(
            r"js/app\.js'\)\) \?>\?v=[^\"]+",
            f"js/app.js') ?>?v={ASSET_V}",
            text,
            count=1,
        )
    if n == 0:
        # plain pattern in layout
        text2 = text.replace("js/app.js')) ?>?v=20260801-ui6", f"js/app.js')) ?>?v={ASSET_V}")
        text2 = text2.replace("js/app.js')) ?>?v=20260801-ui7", f"js/app.js')) ?>?v={ASSET_V}")
        if text2 == text:
            print("layout app.js bump skipped")
            return
    path.write_text(text2)
    print(f"bumped layout app.js -> {ASSET_V}")


def main() -> None:
    write_files()
    patch_routes()
    patch_app_js()
    bump_layout_app_js()
    print("deploy_player_av done")


if __name__ == "__main__":
    main()
