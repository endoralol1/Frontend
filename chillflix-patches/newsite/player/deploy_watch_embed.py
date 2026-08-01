#!/usr/bin/env python3
"""Embed newsite player on watch page + compact UI polish."""
from __future__ import annotations

from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
WATCH = ROOT / "app/Views/pages/watch.php"
ROUTES = ROOT / "app/routes.php"
APP_JS = ROOT / "public/assets/js/app.js"
LAYOUT = ROOT / "app/Views/layouts/main.php"
LOCAL = Path(__file__).resolve().parent


def write_assets() -> None:
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/css/player.css").write_text((LOCAL / "player.css").read_text())
    if (LOCAL / "player.page.php").exists():
        (ROOT / "app/Views/pages/player.php").write_text((LOCAL / "player.page.php").read_text())
    print("wrote player assets")


def patch_watch() -> None:
    text = WATCH.read_text()

    # PLAYER config + data-play attrs near watch-wrap
    if "window.PLAYER" not in text:
        old = """    <div class="container watch-wrap"
         data-id="<?= (int) $item['id'] ?>"
         data-type="<?= e($type) ?>"
         data-trailer="<?= e((string) $trailer) ?>"
         <?php if ($type === 'tv'): ?>data-season="<?= (int) $season ?>" data-ep="<?= (int) $episode ?>"<?php endif; ?>>"""
        new = """    <script>
        window.PLAYER = <?= json_encode($playerConfig ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <div class="container watch-wrap"
         data-id="<?= (int) $item['id'] ?>"
         data-type="<?= e($type) ?>"
         data-trailer="<?= e((string) $trailer) ?>"
         data-autoplay="<?= !empty($autoPlay) ? '1' : '0' ?>"
         <?php if ($type === 'tv'): ?>data-season="<?= (int) $season ?>" data-ep="<?= (int) $episode ?>"<?php endif; ?>>"""
        if old not in text:
            raise SystemExit("watch-wrap block missing")
        text = text.replace(old, new, 1)
        print("added PLAYER config on watch")

    # Play button -> button (inline)
    old_play = """                            <a class="btn-play" id="btn-play" href="<?= e(player_url($type, (int) $item['id'], $title, (int) ($season ?? 1), (int) ($episode ?? 1))) ?>" aria-label="Watch now" title="Watch now"><i></i></a>"""
    new_play = """                            <button type="button" class="btn-play" id="btn-play" aria-label="Watch now" title="Watch now"><i></i></button>"""
    if old_play in text:
        text = text.replace(old_play, new_play, 1)
        print("play button -> inline")
    elif 'id="btn-play"' in text and 'href="' not in text.split('id="btn-play"', 1)[1][:80]:
        print("play already button")
    else:
        # maybe already button from partial
        if 'id="btn-play"' in text and "<button" in text[text.find('id="btn-play"') - 40 : text.find('id="btn-play"')]:
            print("play already button")
        else:
            raise SystemExit("btn-play not patched")

    old_watch = """                            <a class="movie-manager watch-now-btn" id="btn-watch-now"
                               href="<?= e(player_url($type, (int) $item['id'], $title, (int) ($season ?? 1), (int) ($episode ?? 1))) ?>">
                                <i class="uil uil-play"></i><span class="ml-1">Watch Now</span>
                            </a>"""
    new_watch = """                            <button type="button" class="movie-manager watch-now-btn" id="btn-watch-now">
                                <i class="uil uil-play"></i><span class="ml-1">Watch Now</span>
                            </button>"""
    if old_watch in text:
        text = text.replace(old_watch, new_watch, 1)
        print("watch now -> inline")
    elif 'id="btn-watch-now"' in text and "<button" in text[max(0, text.find('id="btn-watch-now"') - 60) : text.find('id="btn-watch-now"')]:
        print("watch now already button")
    else:
        raise SystemExit("btn-watch-now not patched")

    old_ep = """                                <a class="horizontal-episode-play-btn" id="btn-ep-play" href="<?= e(player_url('tv', (int) $item['id'], $title, (int) $season, (int) $episode)) ?>" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </a>"""
    new_ep = """                                <button type="button" class="horizontal-episode-play-btn" id="btn-ep-play" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </button>"""
    if old_ep in text:
        text = text.replace(old_ep, new_ep, 1)
        print("episode play -> inline")
    elif 'id="btn-ep-play"' in text:
        print("episode play already button/ok")

    WATCH.write_text(text)
    print("patched watch.php")


def patch_routes_watch_page() -> None:
    text = ROUTES.read_text()

    # Inject player config + assets into watch_page view()
    if "'playerConfig'" in text and "extraCss" in text and "autoPlay" in text:
        print("watch_page already has playerConfig")
    else:
        old = """    view('pages/watch', [
        'type' => $type,
        'item' => $item,
        'title' => $title,
        'year' => $year,
        'overview' => $overview,
        'poster' => $poster,
        'backdrop' => $backdrop,
        'trailer' => $trailer,
        'season' => $season,
        'episode' => $episode,
        'episodes' => $episodes,
        'servers' => config('servers'),
        'bodyClass' => 'page-watch',
        'headerClass' => 'absolute',
        'seo' => ["""
        new = """    $autoPlay = isset($_GET['play']) && (string) $_GET['play'] !== '0';
    $watchPath = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id;
    $watchUrl = media_url($type, $id, $title);
    if ($type === 'tv') {
        $watchUrl .= '?s=' . $season . '&e=' . $episode;
    }
    $nextEpisode = null;
    if ($type === 'tv' && $episodes) {
        foreach ($episodes as $i => $ep) {
            if ((int) ($ep['episode_number'] ?? 0) === $episode) {
                $n = $episodes[$i + 1] ?? null;
                if ($n) {
                    $ns = $season;
                    $ne = (int) $n['episode_number'];
                    $nextEpisode = [
                        'season' => $ns,
                        'episode' => $ne,
                        'title' => (string) ($n['name'] ?? ('Episode ' . $ne)),
                        'url' => media_url('tv', $id, $title) . '?s=' . $ns . '&e=' . $ne . '&play=1',
                    ];
                } else {
                    $nextSeason = $season + 1;
                    $nsData = $tmdb->get("tv/{$id}/season/{$nextSeason}");
                    $neEp = $nsData['episodes'][0] ?? null;
                    if ($neEp) {
                        $ne = (int) ($neEp['episode_number'] ?? 1);
                        $nextEpisode = [
                            'season' => $nextSeason,
                            'episode' => $ne,
                            'title' => (string) ($neEp['name'] ?? 'Episode 1'),
                            'url' => media_url('tv', $id, $title) . '?s=' . $nextSeason . '&e=' . $ne . '&play=1',
                        ];
                    }
                }
                break;
            }
        }
    }
    $playerConfig = [
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
    ];

    view('pages/watch', [
        'type' => $type,
        'item' => $item,
        'title' => $title,
        'year' => $year,
        'overview' => $overview,
        'poster' => $poster,
        'backdrop' => $backdrop,
        'trailer' => $trailer,
        'season' => $season,
        'episode' => $episode,
        'episodes' => $episodes,
        'servers' => config('servers'),
        'autoPlay' => $autoPlay,
        'playerConfig' => $playerConfig,
        'nextEpisode' => $nextEpisode,
        'extraCss' => [asset('css/player.css') . '?v=20260801-embed2'],
        'extraJs' => [
            'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js',
            asset('js/player.js') . '?v=20260801-embed2',
        ],
        'bodyClass' => 'page-watch',
        'headerClass' => 'absolute',
        'seo' => ["""
        if old not in text:
            raise SystemExit("watch_page view() block missing")
        text = text.replace(old, new, 1)
        print("patched watch_page playerConfig")

    # Redirect /player pages to watch?play=1
    if "redirect to watch page for inline playback" in text:
        print("player_page already redirects")
    else:
        marker = "function player_page(Tmdb $tmdb, string $type, int $id, string $slug): void\n{\n"
        if marker not in text:
            raise SystemExit("player_page missing")
        insert = """function player_page(Tmdb $tmdb, string $type, int $id, string $slug): void
{
    // redirect to watch page for inline playback
    $item = $tmdb->details($type, $id);
    $title = $item ? title_of($item) : $slug;
    $season = max(1, (int) ($_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['e'] ?? 1));
    $target = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id . '?play=1';
    if ($type === 'tv') {
        $target = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id . '?s=' . $season . '&e=' . $episode . '&play=1';
    }
    redirect($target, 302);
    return;

"""
        # Avoid duplicating if already replaced - check next lines
        idx = text.find(marker)
        after = text[idx:idx+200]
        if "redirect to watch page for inline playback" in after:
            print("player_page already redirects")
        else:
            text = text.replace(marker, insert, 1)
            print("player_page now redirects to watch?play=1")

    ROUTES.write_text(text)
    print("patched routes.php")


def patch_app_js() -> None:
    text = APP_JS.read_text()
    marker = "/* newsite-inline-player */"
    if marker in text:
        print("app.js already has inline player")
        # still refresh block
        import re
        text = re.sub(
            r"/\* newsite-inline-player \*/.*?/\* newsite-inline-player-end \*/",
            "",
            text,
            count=1,
            flags=re.S,
        )

    # Replace trailer/player handlers section
    old = """  /* newsite-player-nav */
  // Main play / Watch Now navigate to /player; only Trailer opens YouTube.
  $(document).on('click', '#btn-trailer', function (e) {
    e.preventDefault();
    playTrailer();
  });"""
    if old not in text and "newsite-inline-player" not in APP_JS.read_text():
        # try find current trailer-only handler
        if "$(document).on('click', '#btn-trailer'" not in text:
            raise SystemExit("trailer handler missing")

    block = """
  /* newsite-inline-player */
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
  }

  $(document).on('click', '#btn-play, #btn-watch-now', function (e) {
    e.preventDefault();
    startInlinePlayer();
  });

  $(document).on('click', '#btn-trailer', function (e) {
    e.preventDefault();
    // if inline player active, tear it down for trailer
    $('#movie-player').removeClass('np-active');
    $('#np-shell').remove();
    playTrailer();
  });

  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    e.preventDefault();
    startInlinePlayer();
  });

  // Auto-start when ?play=1
  if ($('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1') {
    $(function () { setTimeout(startInlinePlayer, 80); });
  }
  /* newsite-inline-player-end */
"""

    # Remove old nav handlers if present
    text = text.replace(old, "", 1)
    old2 = """  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    // Let anchor href navigate to /newsite/player/...
    if (this.tagName === 'A' && this.getAttribute('href')) return;
    e.preventDefault();
    var href = this.getAttribute('data-player-url');
    if (href) { location.href = href; return; }
  });"""
    text = text.replace(old2, "", 1)

    # Also remove duplicate if we already injected once in a previous broken run
    if "/* newsite-inline-player */" in text:
        import re
        text = re.sub(
            r"/\* newsite-inline-player \*/.*?/\* newsite-inline-player-end \*/\n?",
            "",
            text,
            flags=re.S,
        )

    # Insert after playTrailer function / before share
    anchor = "  $(document).on('click', '#btn-share', function () {"
    if anchor not in text:
        raise SystemExit("share handler missing")
    text = text.replace(anchor, block + "\n" + anchor, 1)
    APP_JS.write_text(text)
    print("patched app.js inline player")


def bump_assets() -> None:
    text = LAYOUT.read_text()
    text2 = text.replace("?v=20260801-player1", "?v=20260801-embed2")
    text2 = text2.replace("?v=20260731-browseauth1", "?v=20260801-embed2")
    if text2 != text:
        LAYOUT.write_text(text2)
        print("bumped layout assets")
    else:
        print("layout bump skipped")


def main() -> None:
    write_assets()
    patch_watch()
    patch_routes_watch_page()
    patch_app_js()
    bump_assets()
    print("watch embed deploy done")


if __name__ == "__main__":
    main()
