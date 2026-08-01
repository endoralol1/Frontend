#!/usr/bin/env python3
"""Deploy separated newsite /player stack (API + hls.js player + watch wiring)."""
from __future__ import annotations

from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
HELPERS = ROOT / "app/helpers.php"
ROUTES = ROOT / "app/routes.php"
CONFIG = ROOT / "app/config.php"
WATCH = ROOT / "app/Views/pages/watch.php"
LAYOUT_MAIN = ROOT / "app/Views/layouts/main.php"

PLAYER_LAYOUT = ROOT / "app/Views/layouts/player.php"
PLAYER_PAGE = ROOT / "app/Views/pages/player.php"
PLAYER_JS = ROOT / "public/assets/js/player.js"
PLAYER_CSS = ROOT / "public/assets/css/player.css"
PLAYER_API = ROOT / "app/Services/PlayerSources.php"


def write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content)
    print("wrote", path)


def patch_helpers() -> None:
    text = HELPERS.read_text()
    if "function player_url" in text:
        print("helpers already has player_url")
        return
    insert = '''
function player_url(string $type, int|string $id, string $title, int $season = 1, int $episode = 1): string
{
    $prefix = $type === 'tv' ? 'tv' : 'movie';
    $path = '/player/' . $prefix . '/' . slugify($title) . '/' . $id;
    if ($type === 'tv') {
        $path .= '?s=' . max(1, $season) . '&e=' . max(1, $episode);
    }
    return url($path);
}

'''
    # after media_url function
    marker = "function img_url(?string $path, string $size = 'w600_and_h900_bestv2'): string"
    if marker not in text:
        raise SystemExit("img_url marker missing")
    HELPERS.write_text(text.replace(marker, insert + marker, 1))
    print("patched helpers.php")


def patch_config() -> None:
    text = CONFIG.read_text()
    if "player_providers" in text:
        print("config already has player_providers")
        return
    needle = "    'turnstile_site_key' =>"
    # insert after turnstile or lang
    block = """
    // Newsite /player — only these scrapers by default (clean + focused)
    'player_providers' => ['vaplayer', 'huhu'],
    'player_main_api' => 'https://www.chillflix.lol',
"""
    if "'turnstile_site_key'" in text:
        # after turnstile line
        import re
        text2, n = re.subn(
            r"([ \t]*'turnstile_site_key'\s*=>\s*'[^']*',\n)",
            r"\1" + block,
            text,
            count=1,
        )
        if n != 1:
            raise SystemExit("could not insert player config after turnstile")
        CONFIG.write_text(text2)
    else:
        text = text.replace("    'lang'         => 'en',\n", "    'lang'         => 'en',\n" + block, 1)
        CONFIG.write_text(text)
    print("patched config.php")


PLAYER_SOURCES_PHP = r'''<?php
declare(strict_types=1);

/**
 * Thin, clean sources client for newsite /player.
 * Pulls VAPlayer + Huhu from the main Chillflix API and normalizes the payload.
 */
final class PlayerSources
{
    /** @return array{ok:bool,sources?:list<array<string,mixed>>,subtitles?:list<array<string,mixed>>,error?:string,meta?:array<string,mixed>} */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid tmdbId'];
        }

        $providers = config('player_providers', ['vaplayer', 'huhu']);
        if (!is_array($providers) || !$providers) {
            $providers = ['vaplayer', 'huhu'];
        }

        $origin = rtrim((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), '/');
        $merged = [];
        $subtitles = [];
        $diagnostics = [];
        $playbackToken = null;

        foreach ($providers as $provider) {
            $provider = strtolower(trim((string) $provider));
            if ($provider === '') {
                continue;
            }
            $qs = [
                'type' => $type,
                'tmdbId' => (string) $tmdbId,
                'provider' => $provider,
            ];
            if ($type === 'tv') {
                $qs['season'] = (string) max(1, $season);
                $qs['episode'] = (string) max(1, $episode);
            }
            $url = $origin . '/api/cinepro/sources?' . http_build_query($qs);
            $payload = self::httpGetJson($url, $origin);
            if (!$payload) {
                $diagnostics[] = [
                    'code' => 'PROVIDER_FETCH_FAILED',
                    'message' => "Failed to fetch {$provider}",
                    'severity' => 'warning',
                    'provider' => $provider,
                ];
                continue;
            }
            if (!empty($payload['playbackToken']) && is_string($payload['playbackToken'])) {
                $playbackToken = $payload['playbackToken'];
            }
            foreach ($payload['diagnostics'] ?? [] as $d) {
                if (is_array($d)) {
                    $diagnostics[] = $d;
                }
            }
            foreach ($payload['sources'] ?? [] as $src) {
                if (!is_array($src) || empty($src['url'])) {
                    continue;
                }
                $provRaw = $src['provider'] ?? $provider;
                if (is_array($provRaw)) {
                    $pid = strtolower((string) ($provRaw['id'] ?? $provider));
                    $pname = (string) ($provRaw['name'] ?? ucfirst($pid));
                } else {
                    $pid = strtolower((string) ($provRaw ?: $provider));
                    $pname = ucfirst($pid);
                }
                $allowed = array_map('strtolower', $providers);
                if (!in_array($pid, $allowed, true) && !in_array($provider, $allowed, true)) {
                    continue;
                }
                if (!in_array($pid, $allowed, true)) {
                    $pid = $provider;
                }
                $streamUrl = self::absolutizeUrl((string) $src['url'], $origin);
                $key = $pid . '|' . $streamUrl;
                if (isset($merged[$key])) {
                    continue;
                }
                $quality = (string) ($src['quality'] ?? '');
                $name = $pname !== '' ? $pname : ucfirst($pid);
                $label = trim($name . ($quality !== '' ? (' · ' . $quality) : ''));
                $merged[$key] = [
                    'id' => substr(sha1($key), 0, 12),
                    'provider' => $pid,
                    'providerName' => $name,
                    'label' => $label !== '' ? $label : $pid,
                    'quality' => $quality,
                    'type' => (string) ($src['type'] ?? 'hls'),
                    'url' => $streamUrl,
                ];
            }
            foreach ($payload['subtitles'] ?? [] as $sub) {
                if (!is_array($sub) || empty($sub['src'])) {
                    continue;
                }
                $sid = (string) ($sub['id'] ?? sha1((string) $sub['src']));
                $subtitles[$sid] = [
                    'id' => $sid,
                    'label' => (string) ($sub['label'] ?? 'Subtitle'),
                    'language' => (string) ($sub['language'] ?? ''),
                    'kind' => (string) ($sub['kind'] ?? 'subtitles'),
                    'src' => self::absolutizeUrl((string) $sub['src'], $origin),
                ];
            }
        }

        $sources = array_values($merged);
        if (!$sources) {
            return [
                'ok' => false,
                'error' => 'No playable sources from VAPlayer/Huhu right now.',
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }

        return [
            'ok' => true,
            'type' => $type,
            'tmdbId' => $tmdbId,
            'season' => $type === 'tv' ? max(1, $season) : null,
            'episode' => $type === 'tv' ? max(1, $episode) : null,
            'sources' => $sources,
            'subtitles' => array_values($subtitles),
            'playbackToken' => $playbackToken,
            'diagnostics' => array_values($diagnostics),
            'fetchedAt' => gmdate('c'),
        ];
    }

    private static function forceHttps(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }

    private static function absolutizeUrl(string $url, string $origin): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return self::forceHttps('https:' . $url);
        }
        if (str_starts_with($url, '/')) {
            return rtrim($origin, '/') . $url;
        }
        return self::forceHttps($url);
    }

    /** @return array<string,mixed>|null */
    private static function httpGetJson(string $url, string $origin): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 18,
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'User-Agent: ChillflixNewsitePlayer/1.0',
                    'Origin: ' . $origin,
                    'Referer: ' . $origin . '/',
                ]),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            // retry via loopback Host header if public DNS is awkward from VPS
            $loop = preg_replace('@^https?://[^/]+@', 'http://127.0.0.1:3000', $url) ?? $url;
            $ctx2 = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 18,
                    'header' => implode("\r\n", [
                        'Accept: application/json',
                        'Host: www.chillflix.lol',
                        'User-Agent: ChillflixNewsitePlayer/1.0',
                        'Origin: ' . $origin,
                        'Referer: ' . $origin . '/',
                    ]),
                    'ignore_errors' => true,
                ],
            ]);
            $raw = @file_get_contents($loop, false, $ctx2);
        }
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
'''


def patch_bootstrap() -> None:
    boot = ROOT / "app/bootstrap.php"
    text = boot.read_text()
    if "PlayerSources.php" in text:
        print("bootstrap already loads PlayerSources")
        return
    text = text.replace(
        "require_once __DIR__ . '/Services/Tmdb.php';\n",
        "require_once __DIR__ . '/Services/Tmdb.php';\nrequire_once __DIR__ . '/Services/PlayerSources.php';\n",
        1,
    )
    boot.write_text(text)
    print("patched bootstrap.php")


def patch_routes() -> None:
    text = ROUTES.read_text()
    if "/player/movie/" in text and "/api/player/sources" in text:
        print("routes already have player")
        return

    block = r'''
$router->get('/player/movie/{slug}/{id}', function (array $p) use ($tmdb) {
    player_page($tmdb, 'movie', (int) $p['id'], (string) $p['slug']);
});

$router->get('/player/tv/{slug}/{id}', function (array $p) use ($tmdb) {
    player_page($tmdb, 'tv', (int) $p['id'], (string) $p['slug']);
});

$router->get('/api/player/sources', function () {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $result = PlayerSources::fetch($type, $tmdbId, $season, $episode);
    json_response($result, !empty($result['ok']) ? 200 : 502);
});

'''
    marker = "$router->get('/movie/{slug}/{id}', function (array $p) use ($tmdb) {"
    if marker not in text:
        raise SystemExit("movie route marker missing")
    text = text.replace(marker, block + marker, 1)

    # append player_page function near watch_page
    if "function player_page(" not in text:
        fn = r'''
function player_page(Tmdb $tmdb, string $type, int $id, string $slug): void
{
    $item = $tmdb->details($type, $id);
    if (!$item) {
        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Not Found | ' . config('site_name'),
                'description' => 'Title not found.',
                'robots' => 'noindex, follow',
            ],
        ]);
        return;
    }
    $title = title_of($item);
    $canonicalSlug = slugify($title);
    if ($slug !== $canonicalSlug) {
        $season = max(1, (int) ($_GET['s'] ?? 1));
        $episode = max(1, (int) ($_GET['e'] ?? 1));
        $target = '/player/' . ($type === 'tv' ? 'tv' : 'movie') . '/' . $canonicalSlug . '/' . $id;
        if ($type === 'tv') {
            $target .= '?s=' . $season . '&e=' . $episode;
        }
        redirect($target, 301);
    }

    $season = max(1, (int) ($_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['e'] ?? 1));
    $backdrop = img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w1280');
    $poster = img_url($item['poster_path'] ?? null, 'w500');
    $year = year_of(date_of($item));
    $backUrl = media_url($type, $id, $title);
    if ($type === 'tv') {
        $backUrl .= '?s=' . $season . '&e=' . $episode;
    }

    $next = null;
    if ($type === 'tv') {
        $seasonData = $tmdb->get("tv/{$id}/season/{$season}");
        $episodes = $seasonData['episodes'] ?? [];
        foreach ($episodes as $i => $ep) {
            if ((int) ($ep['episode_number'] ?? 0) === $episode) {
                $n = $episodes[$i + 1] ?? null;
                if ($n) {
                    $next = [
                        'season' => $season,
                        'episode' => (int) $n['episode_number'],
                        'title' => (string) ($n['name'] ?? ('Episode ' . $n['episode_number'])),
                        'url' => player_url('tv', $id, $title, $season, (int) $n['episode_number']),
                    ];
                } else {
                    // try next season ep 1
                    $nextSeason = $season + 1;
                    $ns = $tmdb->get("tv/{$id}/season/{$nextSeason}");
                    $ne = $ns['episodes'][0] ?? null;
                    if ($ne) {
                        $next = [
                            'season' => $nextSeason,
                            'episode' => (int) ($ne['episode_number'] ?? 1),
                            'title' => (string) ($ne['name'] ?? 'Episode 1'),
                            'url' => player_url('tv', $id, $title, $nextSeason, (int) ($ne['episode_number'] ?? 1)),
                        ];
                    }
                }
                break;
            }
        }
    }

    view('pages/player', [
        'layout' => 'player',
        'type' => $type,
        'item' => $item,
        'title' => $title,
        'year' => $year,
        'poster' => $poster,
        'backdrop' => $backdrop,
        'backUrl' => $backUrl,
        'season' => $season,
        'episode' => $episode,
        'nextEpisode' => $next,
        'bodyClass' => 'page-player',
        'seo' => [
            'title' => 'Watch ' . $title . ($year ? " ($year)" : '') . ' | ' . config('site_name'),
            'description' => truncate((string) ($item['overview'] ?? ''), 160),
            'canonical' => player_url($type, $id, $title, $season, $episode),
            'image' => $backdrop,
            'robots' => 'noindex, follow',
            'type' => $type === 'tv' ? 'video.tv_show' : 'video.movie',
        ],
    ]);
}

'''
        # append before end of file
        text = text.rstrip() + "\n" + fn
    ROUTES.write_text(text)
    print("patched routes.php")


def patch_view_loader() -> None:
    """Allow pages/player to use layouts/player.php via $layout var."""
    text = HELPERS.read_text()
    old = """    $content = $file;
    $seo = $seo ?? [];
    require __DIR__ . '/Views/layouts/main.php';
}"""
    new = """    $content = $file;
    $seo = $seo ?? [];
    $layoutName = $layout ?? 'main';
    $layoutFile = __DIR__ . '/Views/layouts/' . preg_replace('/[^a-z0-9_-]/i', '', (string) $layoutName) . '.php';
    if (!is_file($layoutFile)) {
        $layoutFile = __DIR__ . '/Views/layouts/main.php';
    }
    require $layoutFile;
}"""
    if "layoutName" in text:
        print("view() already supports layouts")
        return
    if old not in text:
        raise SystemExit("view() block not found")
    HELPERS.write_text(text.replace(old, new, 1))
    print("patched view() for alternate layouts")


PLAYER_LAYOUT_HTML = r'''<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= e($seo['title'] ?? (config('site_name') . ' Player')) ?></title>
    <meta name="robots" content="<?= e($seo['robots'] ?? 'noindex, follow') ?>">
    <meta name="theme-color" content="#07080c">
    <link rel="icon" href="<?= e(asset('img/logo.webp')) ?>" type="image/webp">
    <link rel="stylesheet" href="<?= e(asset('css/player.css')) ?>?v=20260801-player1">
    <script>
        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode((string) config('site_name'), JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), JSON_UNESCAPED_SLASHES) ?>
        };
        window.PLAYER = <?= json_encode([
            'type' => $type,
            'id' => (int) $item['id'],
            'title' => $title,
            'year' => $year ?? '',
            'poster' => $poster ?? '',
            'backdrop' => $backdrop ?? '',
            'backUrl' => $backUrl ?? url('/home'),
            'season' => (int) ($season ?? 1),
            'episode' => (int) ($episode ?? 1),
            'next' => $nextEpisode ?? null,
            'sourcesApi' => url('/api/player/sources'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="page-player">
<?php require $content; ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js" defer></script>
<script src="<?= e(asset('js/player.js')) ?>?v=20260801-player1" defer></script>
</body>
</html>
'''


PLAYER_PAGE_HTML = r'''<?php
/** @var string $type */
/** @var array $item */
/** @var string $title */
/** @var string $backUrl */
/** @var string $backdrop */
/** @var int $season */
/** @var int $episode */
/** @var array|null $nextEpisode */
?>
<div class="np-shell" id="np-shell">
    <div class="np-stage">
        <video id="np-video" class="np-video" playsinline webkit-playsinline></video>
        <div class="np-poster" id="np-poster" style="background-image:url('<?= e($backdrop) ?>')"></div>
        <div class="np-subs" id="np-subs" aria-live="polite"></div>

        <div class="np-top">
            <a class="np-back" href="<?= e($backUrl) ?>" aria-label="Back">
                <i class="np-ico">←</i>
                <span>Details</span>
            </a>
            <div class="np-titleblock">
                <h1 class="np-title"><?= e($title) ?></h1>
                <?php if ($type === 'tv'): ?>
                <p class="np-meta">S<?= (int) $season ?> · E<?= (int) $episode ?></p>
                <?php elseif (!empty($year)): ?>
                <p class="np-meta"><?= e((string) $year) ?></p>
                <?php endif; ?>
            </div>
            <div class="np-top-actions">
                <?php if ($type === 'tv'): ?>
                <button type="button" class="np-chip" id="np-autonext" aria-pressed="true" title="Auto next episode">Auto Next</button>
                <?php endif; ?>
                <button type="button" class="np-chip" id="np-settings-btn" aria-haspopup="dialog">Settings</button>
            </div>
        </div>

        <div class="np-center" id="np-center">
            <button type="button" class="np-playbig" id="np-playbig" aria-label="Play">▶</button>
            <p class="np-status" id="np-status">Loading sources…</p>
        </div>

        <div class="np-bottom" id="np-controls">
            <div class="np-progress-wrap">
                <input type="range" id="np-progress" class="np-progress" min="0" max="1000" value="0" step="1" aria-label="Seek">
            </div>
            <div class="np-bar">
                <div class="np-bar-left">
                    <button type="button" class="np-btn" id="np-play" aria-label="Play/Pause">▶</button>
                    <button type="button" class="np-btn" id="np-mute" aria-label="Mute">🔊</button>
                    <span class="np-time" id="np-time">0:00 / 0:00</span>
                </div>
                <div class="np-bar-right">
                    <?php if ($type === 'tv' && !empty($nextEpisode['url'])): ?>
                    <a class="np-btn" id="np-next" href="<?= e($nextEpisode['url']) ?>" title="Next episode">Next ▶</a>
                    <?php endif; ?>
                    <button type="button" class="np-btn" id="np-source-btn" title="Source">Source</button>
                    <button type="button" class="np-btn" id="np-fs" aria-label="Fullscreen">⛶</button>
                </div>
            </div>
        </div>
    </div>

    <aside class="np-panel" id="np-panel" hidden>
        <div class="np-panel-head">
            <h2>Player settings</h2>
            <button type="button" class="np-btn" id="np-panel-close" aria-label="Close">✕</button>
        </div>
        <div class="np-panel-body">
            <section>
                <h3>Source</h3>
                <div class="np-list" id="np-source-list"></div>
            </section>
            <section>
                <h3>Quality</h3>
                <div class="np-list" id="np-quality-list"></div>
            </section>
            <section>
                <h3>Audio</h3>
                <div class="np-list" id="np-audio-list"></div>
            </section>
            <section>
                <h3>Subtitles</h3>
                <div class="np-list" id="np-sub-list"></div>
                <div class="np-sliders">
                    <label>Delay <span id="np-delay-val">0.0s</span>
                        <input type="range" id="np-delay" min="-10" max="10" step="0.1" value="0">
                    </label>
                    <label>Size <span id="np-size-val">100%</span>
                        <input type="range" id="np-size" min="0.75" max="1.75" step="0.05" value="1">
                    </label>
                    <label>Background <span id="np-bg-val">70%</span>
                        <input type="range" id="np-bg" min="0" max="0.9" step="0.05" value="0.7">
                    </label>
                </div>
            </section>
        </div>
    </aside>
</div>
'''

print("files prepared in script constants")


def patch_watch() -> None:
    text = WATCH.read_text()

    if 'id="btn-watch-now"' not in text:
        old = """                    <div id="movie-managers">
                        <div class="movie-managers-wrap">
                            <div class="movie-manager auto-play no-tooltip" id="btn-autoplay"><i class="uil uil-circle"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span></div>"""
        new = """                    <div id="movie-managers">
                        <div class="movie-managers-wrap">
                            <a class="movie-manager watch-now-btn" id="btn-watch-now"
                               href="<?= e(player_url($type, (int) $item['id'], $title, (int) ($season ?? 1), (int) ($episode ?? 1))) ?>">
                                <i class="uil uil-play"></i><span class="ml-1">Watch Now</span>
                            </a>
                            <div class="movie-manager auto-play no-tooltip" id="btn-autoplay"><i class="uil uil-circle"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span></div>"""
        if old not in text:
            raise SystemExit("movie-managers block not found")
        text = text.replace(old, new, 1)
        print("added Watch Now CTA")
    else:
        print("watch already has Watch Now")

    old_play = """                            <div class="btn-play" id="btn-play" role="button" aria-label="Play trailer" title="Play trailer"><i></i></div>
                            <?php if ($trailer): ?><div class="player-trailer-label">Trailer</div><?php endif; ?>"""
    new_play = """                            <a class="btn-play" id="btn-play" href="<?= e(player_url($type, (int) $item['id'], $title, (int) ($season ?? 1), (int) ($episode ?? 1))) ?>" aria-label="Watch now" title="Watch now"><i></i></a>
                            <?php if ($trailer): ?>
                            <button type="button" class="player-trailer-label" id="btn-trailer">Trailer</button>
                            <?php endif; ?>"""
    if old_play in text:
        text = text.replace(old_play, new_play, 1)
        print("wired main play to /player")
    elif 'id="btn-play"' in text and 'player_url($type' in text and 'btn-play' in text:
        print("main play already wired")
    else:
        raise SystemExit("btn-play block not found")

    old_ep = """                                <button type="button" class="horizontal-episode-play-btn" id="btn-ep-play" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </button>"""
    new_ep = """                                <a class="horizontal-episode-play-btn" id="btn-ep-play" href="<?= e(player_url('tv', (int) $item['id'], $title, (int) $season, (int) $episode)) ?>" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </a>"""
    if old_ep in text:
        text = text.replace(old_ep, new_ep, 1)
        print("wired episode play to /player")
    elif 'btn-ep-play' in text and "player_url('tv'" in text:
        print("episode play already wired")

    WATCH.write_text(text)
    print("patched watch.php")


def patch_app_js() -> None:
    app_js = ROOT / "public/assets/js/app.js"
    text = app_js.read_text()
    if "/* newsite-player-nav */" in text:
        print("app.js already patched for player nav")
        return
    old = """  $(document).on('click', '#btn-play, #btn-trailer', function (e) {
    e.preventDefault();
    playTrailer();
  });"""
    new = """  /* newsite-player-nav */
  // Main play / Watch Now navigate to /player; only Trailer opens YouTube.
  $(document).on('click', '#btn-trailer', function (e) {
    e.preventDefault();
    playTrailer();
  });"""
    if old not in text:
        raise SystemExit("trailer click handler not found")
    text = text.replace(old, new, 1)

    old2 = """  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    e.preventDefault();
    playTrailer();
  });"""
    new2 = """  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    // Let anchor href navigate to /newsite/player/...
    if (this.tagName === 'A' && this.getAttribute('href')) return;
    e.preventDefault();
    var href = this.getAttribute('data-player-url');
    if (href) { location.href = href; return; }
  });"""
    if old2 not in text:
        raise SystemExit("episode play handler not found")
    text = text.replace(old2, new2, 1)
    app_js.write_text(text)
    print("patched app.js trailer/play handlers")


WATCH_CSS = """
/* Watch Now CTA on newsite details */
#movie-managers .watch-now-btn {
  background: linear-gradient(180deg, #e4573d 0%, #dc3545 100%) !important;
  color: #fff !important;
  border-color: transparent !important;
  font-weight: 800;
}
#movie-managers .watch-now-btn:hover {
  filter: brightness(1.05);
}
#movie-player .player-trailer-label {
  cursor: pointer;
  pointer-events: auto;
  border: 0;
  font: inherit;
}
"""


def patch_app_css() -> None:
    css = (ROOT / "public/assets/css/app.css").read_text()
    if "Watch Now CTA on newsite" in css:
        print("app.css already has watch now")
        return
    (ROOT / "public/assets/css/app.css").write_text(css + "\n" + WATCH_CSS)
    print("patched app.css watch CTA")


def bump_layout_assets() -> None:
    text = LAYOUT_MAIN.read_text()
    text2 = text.replace("?v=20260731-browseauth1", "?v=20260801-player1")
    text2 = text2.replace("?v=20260731-herobadges2", "?v=20260801-player1")
    if text2 != text:
        LAYOUT_MAIN.write_text(text2)
        print("bumped main layout assets")
    else:
        print("layout asset bump skipped/already")


# player.css and player.js are large — written below in main
PLAYER_CSS_CONTENT = r"""
:root {
  --np-bg: #07080c;
  --np-panel: rgba(14, 16, 22, 0.94);
  --np-line: rgba(255,255,255,.12);
  --np-text: #f4f4f5;
  --np-muted: rgba(255,255,255,.55);
  --np-accent: #e4573d;
  --np-accent-2: #dc3545;
}
* { box-sizing: border-box; }
html, body.page-player {
  margin: 0;
  height: 100%;
  background: var(--np-bg);
  color: var(--np-text);
  font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  overflow: hidden;
}
.np-shell { position: relative; width: 100%; height: 100%; background: #000; }
.np-stage { position: relative; width: 100%; height: 100%; background: #000; overflow: hidden; }
.np-video, .np-poster {
  position: absolute; inset: 0; width: 100%; height: 100%;
}
.np-video { object-fit: contain; background: #000; z-index: 1; }
.np-poster {
  background-size: cover; background-position: center;
  filter: brightness(.35);
  z-index: 0;
  transition: opacity .25s ease;
}
.np-shell.is-playing .np-poster { opacity: 0; pointer-events: none; }
.np-subs {
  position: absolute; left: 0; right: 0; bottom: 18%;
  z-index: 5; display: flex; justify-content: center; pointer-events: none;
  padding: 0 1rem;
}
.np-subs span {
  max-width: 90%;
  padding: .35rem .7rem;
  border-radius: .45rem;
  background: rgba(0,0,0,.7);
  color: #fff;
  font-size: 1.15rem;
  font-weight: 600;
  text-align: center;
  line-height: 1.35;
  text-shadow: 0 1px 2px rgba(0,0,0,.6);
}
.np-top, .np-bottom, .np-center { z-index: 6; }
.np-top {
  position: absolute; left: 0; right: 0; top: 0;
  display: flex; align-items: center; gap: .75rem;
  padding: max(.85rem, env(safe-area-inset-top)) 1rem .85rem;
  background: linear-gradient(to bottom, rgba(0,0,0,.72), transparent);
}
.np-back {
  display: inline-flex; align-items: center; gap: .35rem;
  color: #fff; text-decoration: none; font-weight: 700; font-size: .9rem;
  padding: .45rem .7rem; border-radius: 999px; background: rgba(255,255,255,.08);
  border: 1px solid var(--np-line);
}
.np-titleblock { min-width: 0; flex: 1; }
.np-title {
  margin: 0; font-size: 1rem; font-weight: 800; letter-spacing: -.02em;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.np-meta { margin: .1rem 0 0; color: var(--np-muted); font-size: .75rem; font-weight: 600; }
.np-top-actions { display: flex; gap: .4rem; }
.np-chip {
  border: 1px solid var(--np-line);
  background: rgba(255,255,255,.08);
  color: #fff; border-radius: 999px;
  padding: .4rem .7rem; font: inherit; font-size: .72rem; font-weight: 800;
  cursor: pointer;
}
.np-chip[aria-pressed="true"] {
  background: linear-gradient(180deg, var(--np-accent), var(--np-accent-2));
  border-color: transparent;
}
.np-center {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: .75rem;
  pointer-events: none;
}
.np-playbig {
  pointer-events: auto;
  width: 4.25rem; height: 4.25rem; border-radius: 999px; border: 0;
  background: rgba(255,255,255,.92); color: #111;
  font-size: 1.4rem; cursor: pointer;
  box-shadow: 0 12px 40px rgba(0,0,0,.45);
}
.np-status {
  margin: 0; color: rgba(255,255,255,.8); font-size: .85rem; font-weight: 600;
  text-shadow: 0 1px 8px rgba(0,0,0,.8);
}
.np-shell.is-playing .np-center { opacity: 0; }
.np-bottom {
  position: absolute; left: 0; right: 0; bottom: 0;
  padding: .5rem 1rem max(.9rem, env(safe-area-inset-bottom));
  background: linear-gradient(to top, rgba(0,0,0,.78), transparent);
  opacity: 1; transition: opacity .2s ease;
}
.np-shell.is-playing:not(.show-controls) .np-bottom,
.np-shell.is-playing:not(.show-controls) .np-top { opacity: 0; pointer-events: none; }
.np-progress { width: 100%; accent-color: var(--np-accent); cursor: pointer; }
.np-bar { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: .35rem; }
.np-bar-left, .np-bar-right { display: flex; align-items: center; gap: .35rem; }
.np-btn {
  border: 0; background: transparent; color: #fff; cursor: pointer;
  font: inherit; font-size: .9rem; font-weight: 700;
  padding: .4rem .55rem; border-radius: .55rem;
}
.np-btn:hover { background: rgba(255,255,255,.1); }
.np-time { font-size: .78rem; font-weight: 600; color: rgba(255,255,255,.85); tabular-nums: ; font-variant-numeric: tabular-nums; }
.np-panel {
  position: absolute; top: 0; right: 0; bottom: 0; width: min(22rem, 92vw);
  z-index: 20; background: var(--np-panel); border-left: 1px solid var(--np-line);
  backdrop-filter: blur(16px); display: flex; flex-direction: column;
  transform: translateX(0); animation: npIn .18s ease;
}
.np-panel[hidden] { display: none !important; }
@keyframes npIn { from { transform: translateX(12px); opacity: .6; } to { transform: none; opacity: 1; } }
.np-panel-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem; border-bottom: 1px solid var(--np-line);
}
.np-panel-head h2 { margin: 0; font-size: 1rem; }
.np-panel-body { overflow: auto; padding: .85rem 1rem 1.25rem; }
.np-panel-body section + section { margin-top: 1.1rem; }
.np-panel-body h3 {
  margin: 0 0 .45rem; font-size: .68rem; letter-spacing: .08em;
  text-transform: uppercase; color: var(--np-muted);
}
.np-list { display: flex; flex-direction: column; gap: .3rem; }
.np-list button {
  text-align: left; border: 1px solid transparent; background: rgba(255,255,255,.04);
  color: #fff; border-radius: .7rem; padding: .65rem .75rem; font: inherit; font-size: .85rem;
  cursor: pointer;
}
.np-list button.is-active {
  border-color: rgba(228,87,61,.55);
  background: rgba(228,87,61,.16);
}
.np-sliders { display: flex; flex-direction: column; gap: .7rem; margin-top: .7rem; }
.np-sliders label {
  display: flex; flex-direction: column; gap: .3rem;
  font-size: .75rem; color: var(--np-muted); font-weight: 700;
}
.np-sliders input[type=range] { width: 100%; accent-color: var(--np-accent); }
@media (min-width: 900px) {
  .np-title { font-size: 1.2rem; }
  .np-subs span { font-size: 1.45rem; }
}
"""


PLAYER_JS_CONTENT = '(function () {\n  "use strict";\n\n  const cfg = window.PLAYER || {};\n  const $ = (sel, root) => (root || document).querySelector(sel);\n\n  const els = {\n    shell: $("#np-shell"),\n    video: $("#np-video"),\n    poster: $("#np-poster"),\n    subs: $("#np-subs"),\n    status: $("#np-status"),\n    center: $("#np-center"),\n    playBig: $("#np-playbig"),\n    play: $("#np-play"),\n    mute: $("#np-mute"),\n    fs: $("#np-fs"),\n    progress: $("#np-progress"),\n    time: $("#np-time"),\n    settingsBtn: $("#np-settings-btn"),\n    sourceBtn: $("#np-source-btn"),\n    panel: $("#np-panel"),\n    panelClose: $("#np-panel-close"),\n    autonext: $("#np-autonext"),\n    sourceList: $("#np-source-list"),\n    qualityList: $("#np-quality-list"),\n    audioList: $("#np-audio-list"),\n    subList: $("#np-sub-list"),\n    delay: $("#np-delay"),\n    delayVal: $("#np-delay-val"),\n    size: $("#np-size"),\n    sizeVal: $("#np-size-val"),\n    bg: $("#np-bg"),\n    bgVal: $("#np-bg-val"),\n  };\n\n  const state = {\n    sources: [],\n    sourceIndex: 0,\n    hls: null,\n    levels: [],\n    levelIndex: -1,\n    audioTracks: [],\n    audioIndex: -1,\n    textTracks: [],\n    textIndex: -1,\n    delaySec: Number(localStorage.getItem("cf_np_sub_delay") || 0),\n    fontScale: Number(localStorage.getItem("cf_np_sub_size") || 1),\n    subBg: Number(localStorage.getItem("cf_np_sub_bg") || 0.7),\n    autoNext: localStorage.getItem("cf_np_autonext") !== "0",\n    autoplay: localStorage.getItem("cf_np_autoplay") !== "0",\n    hideTimer: null,\n    cueBases: new WeakMap(),\n  };\n\n  function fmt(t) {\n    if (!Number.isFinite(t) || t < 0) return "0:00";\n    const h = Math.floor(t / 3600);\n    const m = Math.floor((t % 3600) / 60);\n    const s = Math.floor(t % 60);\n    if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;\n    return `${m}:${String(s).padStart(2, "0")}`;\n  }\n\n  function setStatus(msg) {\n    if (els.status) els.status.textContent = msg || "";\n  }\n\n  function absoluteUrl(url) {\n    if (!url) return "";\n    if (/^https?:\\/\\//i.test(url)) return url;\n    if (url.startsWith("//")) return location.protocol + url;\n    if (url.startsWith("/")) {\n      // Prefer main origin for sealed proxy paths from cinepro/huhu\n      const main = (window.APP && window.APP.mainOrigin) || location.origin;\n      if (url.startsWith("/api/cinepro/") || url.startsWith("/api/huhu/")) {\n        return main.replace(/\\/$/, "") + url;\n      }\n      return location.origin + url;\n    }\n    return url;\n  }\n\n  function destroyHls() {\n    if (state.hls) {\n      try {\n        state.hls.destroy();\n      } catch (_) {}\n      state.hls = null;\n    }\n  }\n\n  function showControls(temp) {\n    els.shell?.classList.add("show-controls");\n    clearTimeout(state.hideTimer);\n    if (temp !== false) {\n      state.hideTimer = setTimeout(() => {\n        if (!els.video?.paused) els.shell?.classList.remove("show-controls");\n      }, 2800);\n    }\n  }\n\n  function renderList(container, items, activeIndex, onPick, labelFn) {\n    if (!container) return;\n    container.innerHTML = "";\n    if (!items.length) {\n      const empty = document.createElement("button");\n      empty.type = "button";\n      empty.disabled = true;\n      empty.textContent = "None available";\n      container.appendChild(empty);\n      return;\n    }\n    items.forEach((item, idx) => {\n      const b = document.createElement("button");\n      b.type = "button";\n      b.className = idx === activeIndex ? "is-active" : "";\n      b.textContent = labelFn(item, idx);\n      b.addEventListener("click", () => onPick(idx));\n      container.appendChild(b);\n    });\n  }\n\n  function refreshMenus() {\n    renderList(\n      els.sourceList,\n      state.sources,\n      state.sourceIndex,\n      (i) => loadSource(i),\n      (s) => s.label || `${s.providerName || s.provider || "source"}${s.quality ? " · " + s.quality : ""}`\n    );\n    const qualities = [{ id: -1, height: 0, name: "Auto" }, ...state.levels];\n    const qActive = state.levelIndex < 0 ? 0 : state.levelIndex + 1;\n    renderList(\n      els.qualityList,\n      qualities,\n      qActive,\n      (i) => setQuality(i === 0 ? -1 : i - 1),\n      (l) => l.name || (l.height ? `${l.height}p` : "Auto")\n    );\n    renderList(\n      els.audioList,\n      state.audioTracks,\n      state.audioIndex,\n      (i) => setAudio(i),\n      (a) => a.name || a.lang || `Audio ${(a.id ?? 0) + 1}`\n    );\n    const subs = [{ name: "Off" }, ...state.textTracks];\n    const sActive = state.textIndex < 0 ? 0 : state.textIndex + 1;\n    renderList(\n      els.subList,\n      subs,\n      sActive,\n      (i) => setSubtitle(i - 1),\n      (t) => t.name || t.lang || "Track"\n    );\n  }\n\n  function syncUi() {\n    const v = els.video;\n    if (!v) return;\n    const playing = !v.paused && !v.ended;\n    if (els.play) els.play.textContent = playing ? "❚❚" : "▶";\n    if (els.playBig) els.playBig.textContent = playing ? "❚❚" : "▶";\n    if (els.mute) els.mute.textContent = v.muted || v.volume === 0 ? "🔇" : "🔊";\n    if (els.time) els.time.textContent = `${fmt(v.currentTime)} / ${fmt(v.duration)}`;\n    if (els.progress && Number.isFinite(v.duration) && v.duration > 0) {\n      els.progress.value = String(Math.round((v.currentTime / v.duration) * 1000));\n    }\n    if (playing) els.shell?.classList.add("is-playing");\n    else els.shell?.classList.remove("is-playing");\n  }\n\n  function setQuality(idx) {\n    state.levelIndex = idx;\n    if (state.hls) state.hls.currentLevel = idx;\n    refreshMenus();\n    setStatus(idx < 0 ? "Quality: Auto" : `Quality: ${state.levels[idx]?.height || ""}p`);\n  }\n\n  function setAudio(idx) {\n    state.audioIndex = idx;\n    if (state.hls && state.hls.audioTracks) state.hls.audioTrack = idx;\n    refreshMenus();\n  }\n\n  function paintCue(text) {\n    if (!els.subs) return;\n    if (!text) {\n      els.subs.innerHTML = "";\n      return;\n    }\n    const span = document.createElement("span");\n    span.textContent = text;\n    span.style.fontSize = `${1.15 * state.fontScale}rem`;\n    span.style.background = `rgba(0,0,0,${state.subBg})`;\n    els.subs.innerHTML = "";\n    els.subs.appendChild(span);\n  }\n\n  function activeTextTrack() {\n    const tracks = Array.from(els.video?.textTracks || []);\n    if (state.textIndex < 0) return null;\n    return tracks[state.textIndex] || null;\n  }\n\n  function onCueChange() {\n    const tt = activeTextTrack();\n    if (!tt) {\n      paintCue("");\n      return;\n    }\n    const cues = tt.activeCues;\n    if (!cues || !cues.length) {\n      paintCue("");\n      return;\n    }\n    const parts = [];\n    for (let i = 0; i < cues.length; i++) {\n      parts.push(cues[i].text || "");\n    }\n    paintCue(parts.join("\\n"));\n  }\n\n  function applySubtitleDelay() {\n    const tracks = Array.from(els.video?.textTracks || []);\n    const delay = state.delaySec;\n    tracks.forEach((tt) => {\n      try {\n        if (!tt.cues) return;\n        for (let i = 0; i < tt.cues.length; i++) {\n          const cue = tt.cues[i];\n          if (!cue) continue;\n          if (!state.cueBases.has(cue)) {\n            state.cueBases.set(cue, { start: cue.startTime, end: cue.endTime });\n          }\n          const base = state.cueBases.get(cue);\n          cue.startTime = Math.max(0, base.start + delay);\n          cue.endTime = Math.max(cue.startTime + 0.05, base.end + delay);\n        }\n      } catch (_) {}\n    });\n  }\n\n  function setSubtitle(idx) {\n    state.textIndex = idx;\n    const tracks = Array.from(els.video?.textTracks || []);\n    tracks.forEach((tt, i) => {\n      try {\n        // hidden keeps cuechange firing for custom overlay\n        tt.mode = i === idx ? "hidden" : "disabled";\n        tt.oncuechange = i === idx ? onCueChange : null;\n      } catch (_) {}\n    });\n    if (state.hls && typeof state.hls.subtitleTrack !== "undefined") {\n      try {\n        state.hls.subtitleTrack = idx;\n      } catch (_) {}\n    }\n    if (idx < 0) paintCue("");\n    setTimeout(applySubtitleDelay, 80);\n    refreshMenus();\n  }\n\n  function attachNativeTracks() {\n    const tracks = Array.from(els.video?.textTracks || []);\n    state.textTracks = tracks.map((t, i) => ({\n      id: i,\n      name: t.label || t.language || `Sub ${i + 1}`,\n      lang: t.language || "",\n    }));\n    if (state.textTracks.length && state.textIndex < 0) {\n      // default off; user can enable\n      state.textIndex = -1;\n    }\n    if (state.textIndex >= 0) setSubtitle(state.textIndex);\n    refreshMenus();\n  }\n\n  function loadExternalSubtitles(payloadSubs, source) {\n    const v = els.video;\n    if (!v) return;\n    Array.from(v.querySelectorAll("track[data-cf]")).forEach((n) => n.remove());\n    const fromSource = Array.isArray(source?.subtitles) ? source.subtitles : [];\n    const globalSubs = Array.isArray(payloadSubs) ? payloadSubs : [];\n    const subs = fromSource.length ? fromSource : globalSubs;\n    subs.forEach((sub, i) => {\n      const url = absoluteUrl(sub.src || sub.url || sub.file);\n      if (!url) return;\n      const tr = document.createElement("track");\n      tr.kind = "subtitles";\n      tr.label = sub.label || sub.language || sub.lang || `Sub ${i + 1}`;\n      tr.srclang = String(sub.language || sub.lang || "en").slice(0, 8);\n      tr.src = url;\n      tr.setAttribute("data-cf", "1");\n      v.appendChild(tr);\n    });\n    setTimeout(attachNativeTracks, 250);\n  }\n\n  function playUrl(url, source, payloadSubs) {\n    const v = els.video;\n    if (!v || !url) return;\n    destroyHls();\n    paintCue("");\n    setStatus("Loading…");\n    showControls(false);\n    const abs = absoluteUrl(url);\n    const isHls =\n      /\\.m3u8(\\?|$)/i.test(abs) ||\n      /hls|m3u8/i.test(String(source?.type || source?.format || "hls"));\n\n    const onReady = () => {\n      setStatus("");\n      if (state.autoplay) {\n        v.play().catch(() => setStatus("Tap play to start"));\n      }\n    };\n\n    if (isHls && window.Hls && window.Hls.isSupported()) {\n      const hls = new window.Hls({\n        enableWorker: true,\n        lowLatencyMode: false,\n        backBufferLength: 30,\n        maxBufferLength: 30,\n        maxMaxBufferLength: 60,\n      });\n      state.hls = hls;\n      hls.loadSource(abs);\n      hls.attachMedia(v);\n      hls.on(window.Hls.Events.MANIFEST_PARSED, (_e, data) => {\n        state.levels = (data.levels || []).map((l, i) => ({\n          id: i,\n          height: l.height,\n          name: l.height ? `${l.height}p` : `Level ${i}`,\n        }));\n        state.levelIndex = -1;\n        hls.currentLevel = -1;\n        state.audioTracks = (hls.audioTracks || []).map((a, i) => ({\n          id: i,\n          name: a.name || a.lang || `Audio ${i + 1}`,\n          lang: a.lang || "",\n        }));\n        state.audioIndex = hls.audioTrack >= 0 ? hls.audioTrack : state.audioTracks.length ? 0 : -1;\n        if (hls.subtitleTracks && hls.subtitleTracks.length) {\n          state.textTracks = hls.subtitleTracks.map((t, i) => ({\n            id: i,\n            name: t.name || t.lang || `Sub ${i + 1}`,\n            lang: t.lang || "",\n          }));\n        }\n        refreshMenus();\n        loadExternalSubtitles(payloadSubs, source);\n        onReady();\n      });\n      hls.on(window.Hls.Events.ERROR, (_e, data) => {\n        if (!data?.fatal) return;\n        if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {\n          setStatus("Network error — retrying…");\n          hls.startLoad();\n        } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {\n          setStatus("Media error — recovering…");\n          hls.recoverMediaError();\n        } else {\n          setStatus("Playback failed — next source…");\n          tryNextSource();\n        }\n      });\n    } else if (v.canPlayType("application/vnd.apple.mpegurl") && isHls) {\n      v.src = abs;\n      loadExternalSubtitles(payloadSubs, source);\n      v.addEventListener("loadedmetadata", onReady, { once: true });\n    } else {\n      v.src = abs;\n      loadExternalSubtitles(payloadSubs, source);\n      v.addEventListener("loadedmetadata", onReady, { once: true });\n    }\n  }\n\n  let payloadSubtitles = [];\n\n  function loadSource(index) {\n    if (!state.sources.length) return;\n    state.sourceIndex = Math.max(0, Math.min(index, state.sources.length - 1));\n    const src = state.sources[state.sourceIndex];\n    if (!src?.url) {\n      setStatus("Source missing URL");\n      return;\n    }\n    refreshMenus();\n    playUrl(src.url, src, payloadSubtitles);\n  }\n\n  function tryNextSource() {\n    if (state.sourceIndex + 1 < state.sources.length) loadSource(state.sourceIndex + 1);\n    else setStatus("No playable sources");\n  }\n\n  async function fetchSources() {\n    setStatus("Fetching sources…");\n    const api = cfg.sourcesApi || ((window.APP && window.APP.baseUrl) || "") + "/api/player/sources";\n    const q = new URLSearchParams({\n      type: cfg.type || "movie",\n      tmdbId: String(cfg.id || ""),\n    });\n    if (cfg.type === "tv") {\n      q.set("season", String(cfg.season || 1));\n      q.set("episode", String(cfg.episode || 1));\n    }\n    try {\n      const res = await fetch(`${api}?${q.toString()}`, {\n        headers: { Accept: "application/json" },\n        credentials: "same-origin",\n      });\n      const data = await res.json();\n      if (!data.ok) throw new Error(data.error || "Sources failed");\n      state.sources = Array.isArray(data.sources) ? data.sources : [];\n      payloadSubtitles = Array.isArray(data.subtitles) ? data.subtitles : [];\n      if (!state.sources.length) {\n        setStatus("No sources found");\n        return;\n      }\n      loadSource(0);\n    } catch (err) {\n      setStatus(err.message || "Failed to load sources");\n    } finally {\n      refreshMenus();\n    }\n  }\n\n  function goNextEpisode() {\n    if (cfg.type !== "tv") return;\n    if (cfg.next && cfg.next.url) {\n      location.href = cfg.next.url;\n      return;\n    }\n    const next = (Number(cfg.episode) || 1) + 1;\n    const slug = encodeURIComponent(String(cfg.title || "show").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "show");\n    location.href = `${(window.APP && window.APP.baseUrl) || "/newsite"}/player/tv/${slug}/${cfg.id}?s=${cfg.season || 1}&e=${next}`;\n  }\n\n  function togglePlay() {\n    const v = els.video;\n    if (!v) return;\n    if (v.paused) v.play().catch(() => {});\n    else v.pause();\n    showControls();\n  }\n\n  function openPanel() {\n    if (els.panel) els.panel.hidden = false;\n    showControls(false);\n  }\n\n  function closePanel() {\n    if (els.panel) els.panel.hidden = true;\n  }\n\n  function bind() {\n    els.play?.addEventListener("click", togglePlay);\n    els.playBig?.addEventListener("click", togglePlay);\n    els.mute?.addEventListener("click", () => {\n      els.video.muted = !els.video.muted;\n      syncUi();\n    });\n    els.fs?.addEventListener("click", () => {\n      if (!document.fullscreenElement) els.shell?.requestFullscreen?.();\n      else document.exitFullscreen?.();\n    });\n    els.progress?.addEventListener("input", () => {\n      const v = els.video;\n      if (!v?.duration) return;\n      v.currentTime = (Number(els.progress.value) / 1000) * v.duration;\n    });\n    els.settingsBtn?.addEventListener("click", openPanel);\n    els.sourceBtn?.addEventListener("click", openPanel);\n    els.panelClose?.addEventListener("click", closePanel);\n\n    if (els.autonext) {\n      els.autonext.setAttribute("aria-pressed", state.autoNext ? "true" : "false");\n      els.autonext.addEventListener("click", () => {\n        state.autoNext = !state.autoNext;\n        localStorage.setItem("cf_np_autonext", state.autoNext ? "1" : "0");\n        els.autonext.setAttribute("aria-pressed", state.autoNext ? "true" : "false");\n        setStatus(state.autoNext ? "Auto Next on" : "Auto Next off");\n      });\n    }\n\n    if (els.delay) {\n      els.delay.value = String(state.delaySec);\n      if (els.delayVal) els.delayVal.textContent = `${state.delaySec.toFixed(1)}s`;\n      els.delay.addEventListener("input", () => {\n        state.delaySec = Number(els.delay.value) || 0;\n        localStorage.setItem("cf_np_sub_delay", String(state.delaySec));\n        if (els.delayVal) els.delayVal.textContent = `${state.delaySec.toFixed(1)}s`;\n        applySubtitleDelay();\n      });\n    }\n    if (els.size) {\n      els.size.value = String(state.fontScale);\n      if (els.sizeVal) els.sizeVal.textContent = `${Math.round(state.fontScale * 100)}%`;\n      els.size.addEventListener("input", () => {\n        state.fontScale = Number(els.size.value) || 1;\n        localStorage.setItem("cf_np_sub_size", String(state.fontScale));\n        if (els.sizeVal) els.sizeVal.textContent = `${Math.round(state.fontScale * 100)}%`;\n        onCueChange();\n      });\n    }\n    if (els.bg) {\n      els.bg.value = String(state.subBg);\n      if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;\n      els.bg.addEventListener("input", () => {\n        state.subBg = Number(els.bg.value) || 0;\n        localStorage.setItem("cf_np_sub_bg", String(state.subBg));\n        if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;\n        onCueChange();\n      });\n    }\n\n    const v = els.video;\n    v.addEventListener("timeupdate", syncUi);\n    v.addEventListener("play", () => {\n      syncUi();\n      showControls();\n    });\n    v.addEventListener("pause", () => {\n      syncUi();\n      showControls(false);\n    });\n    v.addEventListener("volumechange", syncUi);\n    v.addEventListener("ended", () => {\n      if (cfg.type === "tv" && state.autoNext) goNextEpisode();\n    });\n    v.addEventListener("click", togglePlay);\n\n    els.shell?.addEventListener("mousemove", () => showControls());\n    els.shell?.addEventListener("touchstart", () => showControls(), { passive: true });\n\n    document.addEventListener("keydown", (e) => {\n      if (e.target && ["INPUT", "TEXTAREA", "SELECT"].includes(e.target.tagName)) return;\n      if (e.key === " " || e.key === "k") {\n        e.preventDefault();\n        togglePlay();\n      } else if (e.key === "ArrowLeft") {\n        els.video.currentTime = Math.max(0, els.video.currentTime - 10);\n      } else if (e.key === "ArrowRight") {\n        els.video.currentTime = Math.min(els.video.duration || 0, els.video.currentTime + 10);\n      } else if (e.key === "f") els.fs?.click();\n      else if (e.key === "m") els.mute?.click();\n      else if (e.key === "n" && cfg.type === "tv") goNextEpisode();\n      else if (e.key === "Escape") closePanel();\n    });\n  }\n\n  bind();\n  fetchSources();\n})();\n'

def main() -> None:
    write(PLAYER_API, PLAYER_SOURCES_PHP)
    patch_bootstrap()
    patch_helpers()
    patch_view_loader()
    patch_config()
    patch_routes()
    write(PLAYER_LAYOUT, PLAYER_LAYOUT_HTML)
    write(PLAYER_PAGE, PLAYER_PAGE_HTML)
    write(PLAYER_CSS, PLAYER_CSS_CONTENT)
    write(PLAYER_JS, PLAYER_JS_CONTENT)
    patch_watch()
    patch_app_js()
    patch_app_css()
    bump_layout_assets()
    print("newsite /player stack deployed")


if __name__ == "__main__":
    main()
