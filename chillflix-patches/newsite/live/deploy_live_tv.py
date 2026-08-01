#!/usr/bin/env python3
"""Deploy Huhu Live TV page + API on newsite."""
from __future__ import annotations

import re
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui34"


def write_files() -> None:
    (ROOT / "app/Services/HuhuLiveTv.php").write_text((LOCAL / "HuhuLiveTv.php").read_text())
    (ROOT / "app/Views/pages/live.php").write_text((LOCAL / "live.php").read_text())
    (ROOT / "public/assets/css/live.css").write_text((LOCAL / "live.css").read_text())
    (ROOT / "public/assets/js/live.js").write_text((LOCAL / "live.js").read_text())
    # fix asset version inside view
    live = ROOT / "app/Views/pages/live.php"
    text = live.read_text()
    text = re.sub(r"\$assetV = '20260801-ui\d+';", f"$assetV = '{ASSET_V}';", text)
    # live.php doesn't use $assetV for links — routes inject extraCss/Js
    live.write_text(text)
    cache = ROOT / "storage/cache"
    cache.mkdir(parents=True, exist_ok=True)
    try:
        import pwd, os

        uid = pwd.getpwnam("www-data").pw_uid
        gid = pwd.getpwnam("www-data").pw_gid
        os.chown(cache, uid, gid)
    except Exception:
        pass
    print("wrote live tv files")


def patch_bootstrap() -> None:
    boot = ROOT / "app/bootstrap.php"
    text = boot.read_text()
    if "HuhuLiveTv.php" in text:
        print("bootstrap ok")
        return
    text = text.replace(
        "require_once __DIR__ . '/Services/WatchParty.php';\n",
        "require_once __DIR__ . '/Services/WatchParty.php';\nrequire_once __DIR__ . '/Services/HuhuLiveTv.php';\n",
    )
    boot.write_text(text)
    print("bootstrap patched")


def patch_routes() -> None:
    routes = ROOT / "app/routes.php"
    text = routes.read_text()

    live_block = '''
$router->get('/live', function () {
    $pack = HuhuLiveTv::catalog();
    $channels = $pack['ok'] ? array_slice($pack['channels'] ?? [], 0, 60) : [];
    $categories = $pack['ok'] ? ($pack['categories'] ?? []) : [];
    view('pages/live', [
        'categories' => $categories,
        'channels' => $channels,
        'initial' => $channels[0] ?? null,
        'totalAll' => (int) ($pack['totalAll'] ?? count($channels)),
        'extraCss' => [
            asset('css/live.css') . '?v=''' + ASSET_V + '''',
            asset('css/player.css') . '?v=''' + ASSET_V + '''',
        ],
        'extraJs' => [
            'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js',
            asset('js/live.js') . '?v=''' + ASSET_V + '''',
        ],
        'bodyClass' => 'page-live',
        'seo' => [
            'title' => 'Live TV — Watch Channels Online | ' . config('site_name'),
            'description' => 'Watch live TV channels online — sports, news, and entertainment on ' . config('site_name') . '.',
            'canonical' => url('/live'),
        ],
    ]);
});

$router->get('/api/live/channels', function () {
    header('Cache-Control: public, max-age=60');
    $search = isset($_GET['q']) ? (string) $_GET['q'] : (isset($_GET['search']) ? (string) $_GET['search'] : null);
    $cat = isset($_GET['cat']) ? (string) $_GET['cat'] : (isset($_GET['category']) ? (string) $_GET['category'] : null);
    json_response(HuhuLiveTv::catalog($search, $cat));
});

$router->get('/api/live/play', function () {
    $id = (string) ($_GET['id'] ?? '');
    json_response(HuhuLiveTv::play($id));
});

$router->get('/api/live/refresh', function () {
    json_response(HuhuLiveTv::refresh());
});

'''
    if "$router->get('/live'" in text or '$router->get("/live"' in text:
        text = re.sub(
            r"\$router->get\('/live'[\s\S]*?\$router->get\('/api/live/refresh'[\s\S]*?\}\);\n",
            live_block.lstrip("\n"),
            text,
            count=1,
        )
        print("routes: live block replaced")
    else:
        marker = "$router->get('/movies'"
        if marker not in text:
            raise SystemExit("movies route marker missing")
        text = text.replace(marker, live_block + marker, 1)
        print("routes: live endpoints added")

    text = re.sub(r"20260801-ui\d+", ASSET_V, text)
    routes.write_text(text)


def patch_browse() -> None:
    nav = ROOT / "app/Views/partials/bottom-nav.php"
    text = nav.read_text()
    old = """                    <button type="button" class="browse-tile" data-browse-mock="channels">
                        <span class="browse-tile-icon tone-blue"><i class="uil uil-rss-alt"></i></span>
                        <span class="browse-tile-label">Channels</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>"""
    new = """                    <a class="browse-tile" href="<?= e(url('/live')) ?>">
                        <span class="browse-tile-icon tone-blue"><i class="uil uil-rss-alt"></i></span>
                        <span class="browse-tile-label">Live TV</span>
                        <span class="browse-tile-badge">Live</span>
                    </a>"""
    if old in text:
        text = text.replace(old, new, 1)
        nav.write_text(text)
        print("browse: Channels -> Live TV")
    elif 'url(\'/live\')' in text or 'url("/live")' in text:
        print("browse already has Live TV")
    else:
        print("WARN: browse channels tile not found")


def patch_softnav() -> None:
    app = ROOT / "public/assets/js/app.js"
    text = app.read_text()
    # force hard navigation for /live (needs hls + live.js)
    if "isLiveTvUrl" not in text:
        helper = """  function isLiveTvUrl(href) {
    try {
      var path = new URL(absoluteUrl(href)).pathname || '';
      return /\\/live\\/?$/.test(path) || /\\/live\\/(?!party)/.test(path);
    } catch (e) { return false; }
  }

"""
        text = text.replace("  function isWatchMediaUrl(href) {", helper + "  function isWatchMediaUrl(href) {", 1)
        text = text.replace(
            "      if (isWatchMediaUrl(href)) return false;",
            "      if (isWatchMediaUrl(href) || isLiveTvUrl(href)) return false;",
            1,
        )
        print("soft-nav: live hard-nav")
    text = re.sub(
        r"(base \+ '/assets/js/player\.js\?v=)20260801-ui\d+",
        r"\g<1>" + ASSET_V,
        text,
    )
    app.write_text(text)


def bump_layout() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    text = re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text())
    layout.write_text(text)
    print("layout bumped", ASSET_V)


def warm_cache() -> None:
    # best-effort warm via php cli
    cmd = (
        "cd /var/www/chillflix-newsite && php -r "
        "\"require 'app/helpers.php'; require 'app/Services/HuhuLiveTv.php'; "
        "echo json_encode(HuhuLiveTv::refresh());\""
    )
    import subprocess

    try:
        out = subprocess.check_output(cmd, shell=True, stderr=subprocess.STDOUT, timeout=120)
        print("cache warm:", out.decode()[:300])
    except Exception as e:
        print("cache warm skipped:", e)


def main() -> None:
    write_files()
    patch_bootstrap()
    patch_routes()
    patch_browse()
    patch_softnav()
    bump_layout()
    warm_cache()
    print("deploy_live_tv done", ASSET_V)


if __name__ == "__main__":
    main()
