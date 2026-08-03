<?php
$headerClass = 'relative';

$rating = isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null;
$voteCount = (int) ($item['vote_count'] ?? 0);
$runtimeLabel = '';
if ($type === 'movie' && !empty($item['runtime'])) {
    $runtimeLabel = (int) $item['runtime'] . ' min';
} elseif ($type === 'tv' && !empty($item['number_of_seasons'])) {
    $runtimeLabel = (int) $item['number_of_seasons'] . ' Seasons';
}

$genres = array_map(static fn ($g) => $g['name'] ?? '', $item['genres'] ?? []);
$genres = array_values(array_filter($genres));
$countries = array_map(static fn ($c) => $c['name'] ?? ($c['iso_3166_1'] ?? ''), $item['production_countries'] ?? []);
$countries = array_values(array_filter($countries));
$companies = array_map(static fn ($c) => $c['name'] ?? '', $item['production_companies'] ?? []);
$companies = array_values(array_filter($companies));
$castList = array_values(array_filter(
    array_slice($item['credits']['cast'] ?? [], 0, 12),
    static fn ($c) => !empty($c['name'])
));
$castNames = array_values(array_filter(array_map(static fn ($c) => $c['name'] ?? '', array_slice($castList, 0, 5))));
$discoverPath = $type === 'tv' ? '/tv-series' : '/movies';
$watchItem = $item;
$watchType = $type;
$similar = array_values(array_filter(
    array_slice($item['similar']['results'] ?? $item['recommendations']['results'] ?? [], 0, 12),
    static fn ($s) => !empty($s['id'])
));

$crew = $item['credits']['crew'] ?? [];
$directors = [];
$creators = [];
$seenDirector = [];
$seenCreator = [];
foreach ($crew as $c) {
    $job = $c['job'] ?? '';
    $cname = (string) ($c['name'] ?? '');
    $cid = (int) ($c['id'] ?? 0);
    if ($cname === '') {
        continue;
    }
    if ($job === 'Director' && !isset($seenDirector[$cid ?: $cname])) {
        $seenDirector[$cid ?: $cname] = true;
        $directors[] = ['id' => $cid, 'name' => $cname];
    }
    if (in_array($job, ['Creator', 'Executive Producer', 'Writer', 'Novel'], true) && count($creators) < 3 && !isset($seenCreator[$cid ?: $cname])) {
        $seenCreator[$cid ?: $cname] = true;
        $creators[] = ['id' => $cid, 'name' => $cname];
    }
}
if ($type === 'tv' && !empty($item['created_by'])) {
    $creators = [];
    $seenCreator = [];
    foreach ($item['created_by'] as $c) {
        $cname = (string) ($c['name'] ?? '');
        $cid = (int) ($c['id'] ?? 0);
        if ($cname === '' || isset($seenCreator[$cid ?: $cname])) {
            continue;
        }
        $seenCreator[$cid ?: $cname] = true;
        $creators[] = ['id' => $cid, 'name' => $cname];
    }
}

$personLinkHtml = static function (array $people): string {
    $parts = [];
    foreach ($people as $p) {
        $pname = (string) ($p['name'] ?? '');
        if ($pname === '') {
            continue;
        }
        $pid = (int) ($p['id'] ?? 0);
        if ($pid > 0) {
            $parts[] = '<a class="person-inline-link" href="' . e(person_url($pid, $pname)) . '">' . e($pname) . '</a>';
        } else {
            $parts[] = e($pname);
        }
    }
    return implode(', ', $parts);
};

// Certification / content rating
$cert = $type === 'tv' ? 'TV' : 'Movie';
if ($type === 'movie') {
    foreach ($item['release_dates']['results'] ?? [] as $rd) {
        if (($rd['iso_3166_1'] ?? '') !== 'US') {
            continue;
        }
        foreach ($rd['release_dates'] ?? [] as $entry) {
            if (!empty($entry['certification'])) {
                $cert = $entry['certification'];
                break 2;
            }
        }
    }
} else {
    foreach ($item['content_ratings']['results'] ?? [] as $cr) {
        if (($cr['iso_3166_1'] ?? '') === 'US' && !empty($cr['rating'])) {
            $cert = $cr['rating'];
            break;
        }
    }
}

$releaseRaw = date_of($item);
$releaseNice = $releaseRaw ? date('M d, Y', strtotime($releaseRaw)) : '';
// Current episode (TV)
$currentEp = null;
$prevEp = null;
$nextEp = null;
if ($type === 'tv' && $episodes) {
    foreach ($episodes as $i => $ep) {
        if ((int) ($ep['episode_number'] ?? 0) === $episode) {
            $currentEp = $ep;
            $prevEp = $episodes[$i - 1] ?? null;
            $nextEp = $episodes[$i + 1] ?? null;
            break;
        }
    }
    if (!$currentEp) {
        $currentEp = $episodes[0] ?? null;
        $nextEp = $episodes[1] ?? null;
    }
}
$epStill = $currentEp['still_path'] ?? null;
$epBackdrop = $epStill ? img_url($epStill, 'original') : $backdrop;
$epRuntime = !empty($currentEp['runtime']) ? ((int) $currentEp['runtime'] . ' min') : '';
$epAir = !empty($currentEp['air_date']) ? date('F j, Y', strtotime($currentEp['air_date'])) : '';
$epVotes = isset($currentEp['vote_count']) ? number_format((int) $currentEp['vote_count']) . ' votes' : '';
$epMeta = implode(' • ', array_filter([$epAir, $epRuntime, $epVotes]));
$starFill = $rating !== null ? min(5, max(0, (int) round($rating / 2))) : 0;
?>
<main class="watch-p">
    <script>
        window.PLAYER = <?= json_encode($playerConfig ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script>
        /* cw-seed-on-watch-page: persist Continue Watching without waiting for player.js */
        (function () {
          try {
            try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (ePref) {}
            var cfg = window.PLAYER;
            if (!cfg || !cfg.id) return;
            var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
            var map = {};
            try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
            if (Array.isArray(map)) {
              var tmp = {};
              map.forEach(function (row) {
                if (!row || !row.id) return;
                var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
                tmp[k] = row;
              });
              map = tmp;
            }
            // Merge cookie mirror BEFORE writing so a new title cannot wipe history
            try {
              var cm = document.cookie.match(/(?:^|;\s*)cf_continue_v1=([^;]+)/);
              if (cm) {
                var carr = JSON.parse(decodeURIComponent(cm[1]));
                if (Array.isArray(carr)) {
                  carr.forEach(function (row) {
                    if (!row || !row.id) return;
                    var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
                    if (!map[k]) map[k] = row;
                  });
                }
              }
            } catch (eC) {}
            var prev = map[key] || {};
            map[key] = {
              id: Number(cfg.id) || 0,
              type: cfg.type === 'tv' ? 'tv' : 'movie',
              title: cfg.title || prev.title || 'Untitled',
              poster: cfg.poster || prev.poster || '',
              backdrop: cfg.backdrop || prev.backdrop || '',
              year: cfg.year || prev.year || '',
              season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
              episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
              t: Math.max(1, Number(prev.t) || 0),
              d: Number(prev.d) || 0,
              updated: Date.now(),
              url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
            };
            // keep newest 36
            var keys = Object.keys(map).sort(function (a, b) {
              return (map[b].updated || 0) - (map[a].updated || 0);
            });
            keys.slice(5).forEach(function (k) { delete map[k]; });
            try { localStorage.setItem('cf_continue_v1', JSON.stringify(map)); } catch (eLs) {}
            // cookie mirror (compact) so home can recover if LS quirks
            try {
              var compact = keys.slice(0, 5).map(function (k) { return map[k]; });
              document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
                + ';path=/;max-age=31536000;SameSite=Lax';
            } catch (e2) {}
            // Persist for logged-in users (player page may not load app.js)
            try {
              var base = (window.APP && APP.baseUrl) || '';
              fetch(base + '/api/user/continue', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(Object.assign({ key: key }, map[key])),
                keepalive: true
              }).catch(function () {});
            } catch (ePush) {}
          } catch (err) {}
        })();
    </script>
    <script>
    /* newsite-autoplay-inline */
    (function () {
      function readPref() {
        try { if (localStorage.getItem('cf_watch_autoplay') === '1') return true; } catch (e) {}
        return /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
      }
      function writePref(on) {
        try { localStorage.setItem('cf_watch_autoplay', on ? '1' : '0'); } catch (e) {}
        document.cookie = 'cf_watch_autoplay=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
      }
      function sync() {
        var on = readPref();
        var btn = document.getElementById('btn-autoplay');
        if (!btn) return on;
        btn.classList.toggle('is-on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        var icon = btn.querySelector('i');
        if (icon) icon.className = on ? 'uil uil-check-circle' : 'uil uil-circle';
        return on;
      }
      function startIfNeeded() {
        if (!window.PLAYER) return;
        window.PLAYER.autoplay = true;
        if (document.getElementById('np-shell')) return;
        if (window.ChillflixPlayer && typeof window.ChillflixPlayer.startOnWatch === 'function') {
          window.ChillflixPlayer.startOnWatch(window.PLAYER);
          return;
        }
        // player.js may still be loading (defer)
        var tries = 0;
        var t = setInterval(function () {
          tries += 1;
          if (window.ChillflixPlayer && typeof window.ChillflixPlayer.startOnWatch === 'function') {
            clearInterval(t);
            window.ChillflixPlayer.startOnWatch(window.PLAYER);
          } else if (tries > 40) {
            clearInterval(t);
          }
        }, 100);
      }
      function toggle(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var on = !readPref();
        writePref(on);
        sync();
        if (on) startIfNeeded();
      }
      function bind() {
        var btn = document.getElementById('btn-autoplay');
        if (!btn || btn.dataset.cfBound === '1') return;
        btn.dataset.cfBound = '1';
        btn.addEventListener('click', toggle);
        sync();
        // If preference already on, auto-start after assets load
        var forced = false;
        var wrap = document.querySelector('.watch-wrap');
        if (wrap) forced = wrap.getAttribute('data-autoplay') === '1';
        if (readPref() || forced) {
          setTimeout(startIfNeeded, 150);
        }
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
      else bind();
      window.__cfSyncWatchAutoplay = sync;
      window.__cfStartWatchAutoplay = startIfNeeded;
    })();
    </script>
    <div class="container watch-wrap"
         data-id="<?= (int) $item['id'] ?>"
         data-type="<?= e($type) ?>"
         data-trailer="<?= e((string) $trailer) ?>"
         data-autoplay="<?= !empty($autoPlay) ? '1' : '0' ?>"
         <?php if ($type === 'tv'): ?>data-season="<?= (int) $season ?>" data-ep="<?= (int) $episode ?>"<?php endif; ?>>

        <div class="aside-wrapper swap">
            <aside class="main">
                <div id="movie-player" class="no-player playable" style="position:relative;">
                    <div class="movie-player-wrap">
                        <div class="player-bg" style="background-image:url('<?= e($backdrop) ?>');"></div>
                        <div id="player">
                            <div class="message d-none">
                                <i class="uil uil-exclamation-triangle"></i>
                                <div>Unable to load trailer. Please try again.</div>
                            </div>
                            <button type="button" class="btn-play" id="btn-play" aria-label="Watch now" title="Watch now"><i></i></button>
                            <?php if ($trailer): ?>
                            <button type="button" class="player-trailer-label js-play-trailer">Trailer</button>
                            <?php endif; ?>
                            <div id="player-frame" class="d-none"></div>
                        </div>
                    </div>
                    <div id="cf-party-chat" class="cf-party-chat" hidden>
                        <div class="cf-party-chat-head">
                            <div class="cf-party-chat-title">
                                <span class="cf-party-chat-dot" aria-hidden="true"></span>
                                <strong>Party chat</strong>
                                <em class="cf-party-chat-code"></em>
                            </div>
                            <div class="cf-party-chat-host" hidden>
                                <button type="button" class="cf-party-chat-lock" id="cf-party-chat-lock" aria-pressed="false">Mute guests</button>
                            </div>
                        </div>
                        <div class="cf-party-chat-log" id="cf-party-chat-log" aria-live="polite"></div>
                        <p class="cf-party-chat-note" id="cf-party-chat-note" hidden></p>
                        <div class="cf-party-chat-login" id="cf-party-chat-login" hidden>
                            <p>Log in to join the party chat.</p>
                            <button type="button" class="cf-party-chat-login-btn" data-browse-auth="login">Log in</button>
                        </div>
                        <form class="cf-party-chat-form" id="cf-party-chat-form" autocomplete="off" hidden>
                            <div class="cf-party-chat-user" id="cf-party-chat-user"></div>
                            <input type="text" id="cf-party-chat-input" class="cf-party-chat-input" maxlength="200" placeholder="Say something…" aria-label="Chat message">
                            <button type="submit" class="cf-party-chat-send" id="cf-party-chat-send">Send</button>
                        </form>
                    </div>
                    <div id="movie-managers">
                        <div class="movie-managers-wrap">
                            <?php if (!empty($trailer)): ?>
                            <button type="button" class="movie-manager trailer no-tooltip" id="btn-trailer">
                                <i class="uil uil-youtube" aria-hidden="true"></i><span class="ml-1">Trailer</span>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="movie-manager watch-now-btn" id="btn-watch-now">
                                <i class="uil uil-play"></i><span class="ml-1">Watch Now</span>
                            </button>
                            <button type="button" class="movie-manager auto-play no-tooltip no-progress" id="btn-autoplay" aria-pressed="false">
                                <i class="uil uil-circle" aria-hidden="true"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span>
                            </button>
                            <button type="button" class="bookmark movie-manager user-bookmark-toggle"
                                 data-id="<?= (int) $item['id'] ?>" data-media-type="<?= e($type) ?>"
                                 data-title="<?= e($title) ?>" data-poster="<?= e(img_url($item['poster_path'] ?? null, 'w185')) ?>"
                                 data-year="<?= e($year) ?>" aria-pressed="false" aria-label="Add to favorites">
                                <i class="uil uil-plus-circle" aria-hidden="true"></i><span class="ml-1 fav-label">Favorite</span>
                            </button>
                            <button type="button" class="movie-manager share-btn no-tooltip" id="btn-share">
                                <i class="uil uil-share-alt" aria-hidden="true"></i><span class="ml-1">Share</span>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($type === 'tv' && $currentEp): ?>
                <div class="horizontal-episode-card-container section">
                    <div class="horizontal-episode-card" data-episode-number="<?= (int) $episode ?>" data-available="true">
                        <div class="horizontal-episode-backdrop">
                            <div class="horizontal-episode-backdrop-inner" style="background-image:url('<?= e($epBackdrop) ?>')">
                                <button type="button" class="horizontal-episode-play-btn" id="btn-ep-play" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </button>
                            </div>
                        </div>
                        <div class="horizontal-episode-details">
                            <div>
                                <h3 class="horizontal-episode-title">S<?= (int) $season ?>E<?= (int) $episode ?> - <?= e($currentEp['name'] ?? ('Episode ' . $episode)) ?></h3>
                                <p class="horizontal-episode-overview"><?= e(truncate((string) ($currentEp['overview'] ?? $overview), 160)) ?></p>
                            </div>
                            <div class="horizontal-episode-footer">
                                <div class="horizontal-episode-air-date"><?= e($epMeta) ?></div>
                                <div class="horizontal-episode-navigation">
                                    <?php if ($prevEp): ?>
                                    <a class="horizontal-episode-nav-btn prev-episode" href="?s=<?= (int) $season ?>&e=<?= (int) $prevEp['episode_number'] ?>" aria-label="Previous episode">
                                        <i class="uil uil-angle-left"></i>
                                    </a>
                                    <?php else: ?>
                                    <button type="button" class="horizontal-episode-nav-btn prev-episode" disabled aria-label="Previous episode"><i class="uil uil-angle-left"></i></button>
                                    <?php endif; ?>
                                    <?php if ($nextEp): ?>
                                    <a class="horizontal-episode-nav-btn next-episode" href="?s=<?= (int) $season ?>&e=<?= (int) $nextEp['episode_number'] ?>" aria-label="Next episode">
                                        <i class="uil uil-angle-right"></i>
                                    </a>
                                    <?php else: ?>
                                    <button type="button" class="horizontal-episode-nav-btn next-episode" disabled aria-label="Next episode"><i class="uil uil-angle-right"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </aside>

            <div class="clearfix"></div>

            <?php if ($type === 'tv'): ?>
            <aside class="sidebar">
                <div id="movie-episode" class="tv">
                    <div class="episode-top">
                        <div class="dropdown">
                            <button type="button" class="btn dropdown-toggle" data-toggle="dropdown">
                                <i class="uil uil-cell mr-1"></i>
                                <span class="season-view">Season <?= (int) $season ?></span>
                            </button>
                            <div class="dropdown-menu">
                                <?php foreach ($item['seasons'] ?? [] as $s):
                                    if (($s['season_number'] ?? 0) < 1) continue;
                                    $sn = (int) $s['season_number'];
                                ?>
                                <a class="dropdown-item season-item <?= $sn === $season ? 'active' : '' ?>" href="?s=<?= $sn ?>&e=1">
                                    Season <?= $sn ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="episode-range">
                        <div class="episodes" data-season="<?= (int) $season ?>">
                            <?php foreach ($episodes as $ep):
                                $en = (int) ($ep['episode_number'] ?? 0);
                                $active = $en === $episode;
                            ?>
                            <div>
                                <a href="?s=<?= (int) $season ?>&e=<?= $en ?>" class="<?= $active ? 'active' : '' ?>" title="<?= e($ep['air_date'] ?? '') ?>">
                                    <span class="num">Episode <?= $en ?>:</span>
                                    <span><?= e($ep['name'] ?? ('Episode ' . $en)) ?></span>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="episode-bottom">
                        <span>Go to episode</span>
                        <form method="get" autocomplete="off" class="no-validate">
                            <input type="hidden" name="s" value="<?= (int) $season ?>">
                            <input class="form-control episode-input" type="text" name="e" placeholder="<?= (int) $episode ?>" inputmode="numeric">
                            <button class="btn btn-primary" type="submit"><i class="uil uil-angle-double-right"></i></button>
                        </form>
                    </div>
                </div>
            </aside>
            <?php endif; ?>
        </div>

        <div class="aside-wrapper swap mt-4">
            <aside class="main">
                <section id="w-info">
                    <div class="rating-box" data-score="<?= e((string) ($item['vote_average'] ?? 0)) ?>" data-id="<?= (int) $item['id'] ?>">
                        <div class="stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <svg class="<?= $i <= $starFill ? 'active' : 'empty' ?>">
                                <use xlink:href="#<?= $i <= $starFill ? 'icon-fstar' : 'icon-star' ?>"></use>
                            </svg>
                            <?php endfor; ?>
                        </div>
                        <div class="score live-label">
                            <?php if ($rating !== null): ?>
                            <b><?= e((string) $rating) ?></b> of <b>10</b>
                            <?php if ($voteCount): ?>
                            <b class="vcount"><?= e(number_format($voteCount)) ?></b> reviews
                            <?php endif; ?>
                            <?php else: ?>
                            No rating
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="item-poster">
                        <img src="<?= e($poster) ?>" alt="<?= e($title) ?>" loading="lazy">
                    </div>
                    <div class="info">
                        <div class="meta">
                            <span class="rating status-icon"><?= e($cert) ?></span>
                            <?php if ($rating !== null): ?><span><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($year): ?><span class="year"><i class="uil uil-calender"></i> <?= e($year) ?></span><?php endif; ?>
                            <?php if ($runtimeLabel): ?><span dir="ltr"><i class="uil uil-<?= $type === 'movie' ? 'stopwatch' : 'clock' ?>"></i> <?= e($runtimeLabel) ?></span><?php endif; ?>
                        </div>
                        <h1 class="name"><?= e($title) ?></h1>
                        <div class="w-desc cts-wrapper"><?= e($overview) ?></div>
                        <div class="detail">
                            <div>
                                <div>Type:</div>
                                <span><?= $type === 'tv' ? 'TV Series' : 'Movie' ?></span>
                            </div>
                            <?php if ($countries): ?>
                            <div>
                                <div>Country:</div>
                                <span><?= e(implode(', ', $countries)) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($item['genres'])): ?>
                            <div>
                                <div>Genre:</div>
                                <span class="genre-links">
                                    <?php
                                    $genreParts = [];
                                    foreach ($item['genres'] as $g) {
                                        $gid = (int) ($g['id'] ?? 0);
                                        $gname = (string) ($g['name'] ?? '');
                                        if ($gname === '') {
                                            continue;
                                        }
                                        if ($gid > 0) {
                                            $genreParts[] = '<a href="' . e(url($discoverPath) . '?with_genres[]=' . $gid) . '">' . e($gname) . '</a>';
                                        } else {
                                            $genreParts[] = e($gname);
                                        }
                                    }
                                    echo implode(', ', $genreParts);
                                    ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if ($releaseNice): ?>
                            <div>
                                <div>Release:</div>
                                <span><?= e($releaseNice) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($type === 'movie' && $directors): ?>
                            <div>
                                <div>Director:</div>
                                <span class="person-inline-links"><?= $personLinkHtml($directors) ?></span>
                            </div>
                            <?php elseif ($type === 'tv' && $creators): ?>
                            <div>
                                <div>Creator:</div>
                                <span class="person-inline-links"><?= $personLinkHtml($creators) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($companies): ?>
                            <div>
                                <div>Production:</div>
                                <span><?= e(implode(', ', array_slice($companies, 0, 4))) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($castList): ?>
                            <div>
                                <div>Cast:</div>
                                <span class="person-inline-links"><?= $personLinkHtml(array_map(static fn ($c) => ['id' => (int) ($c['id'] ?? 0), 'name' => (string) ($c['name'] ?? '')], array_slice($castList, 0, 5))) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <div class="clearfix"></div>

                <?php if ($castList): ?>
                <div class="section watch-cast" style="margin-top:20px;">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Cast</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="cast-row">
                            <?php foreach ($castList as $cast):
                                $cname = (string) ($cast['name'] ?? '');
                                if ($cname === '') {
                                    continue;
                                }
                                $cid = (int) ($cast['id'] ?? 0);
                                $crole = (string) ($cast['character'] ?? '');
                                $cimg = img_url($cast['profile_path'] ?? null, 'w185');
                                $chref = $cid > 0 ? person_url($cid, $cname) : '';
                            ?>
                            <?php if ($chref !== ''): ?>
                            <a class="cast-item cast-item-link" href="<?= e($chref) ?>" title="<?= e($cname) ?>">
                                <img class="lazyload" data-src="<?= e($cimg) ?>" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='140'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E" alt="<?= e($cname) ?>" width="100" height="140" loading="lazy" decoding="async">
                                <div class="name"><?= e($cname) ?></div>
                                <?php if ($crole !== ''): ?><div class="character text-muted"><?= e($crole) ?></div><?php endif; ?>
                            </a>
                            <?php else: ?>
                            <div class="cast-item">
                                <img class="lazyload" data-src="<?= e($cimg) ?>" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='140'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E" alt="<?= e($cname) ?>" width="100" height="140" loading="lazy" decoding="async">
                                <div class="name"><?= e($cname) ?></div>
                                <?php if ($crole !== ''): ?><div class="character text-muted"><?= e($crole) ?></div><?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="section watch-report" style="margin-top:20px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#report">
                        <i class="uil uil-exclamation-triangle"></i> Report an issue
                    </button>
                </div>
            </aside>

            <?php if ($similar): ?>
            <aside class="sidebar">
                <div class="section watch-similar">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">You may also like</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movie-sidebar items">
                            <?php
                            $sideRank = 0;
                            foreach (array_slice($similar, 0, 8) as $sideItem) {
                                $item = $sideItem;
                                $type = $watchType;
                                if (isset($sideItem['media_type']) && in_array($sideItem['media_type'], ['movie', 'tv'], true)) {
                                    $type = $sideItem['media_type'];
                                } elseif (isset($sideItem['name']) && !isset($sideItem['title'])) {
                                    $type = 'tv';
                                }
                                $sideRank++;
                                require __DIR__ . '/../partials/sidebar-item.php';
                            }
                            $item = $watchItem;
                            $type = $watchType;
                            unset($sideRank, $sideItem);
                            ?>
                        </div>
                    </div>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</main>

<svg class="d-none" aria-hidden="true">
    <symbol id="icon-star" fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20.1 19.4">
        <path d="M20.1,7.4c-0.1-0.4-0.5-0.6-0.9-0.7l-5.7-0.8L11,0.7C10.7,0.2,10.1,0,9.6,0.2C9.4,0.3,9.2,0.5,9.2,0.7L6.6,5.9 L0.9,6.7C0.5,6.8,0.2,7,0.1,7.4c-0.1,0.4,0,0.7,0.2,1l4.1,4l-1,5.7c-0.1,0.4,0.1,0.8,0.4,1c0.3,0.2,0.7,0.2,1,0.1l5.1-2.7l5.1,2.7 c0.1,0.1,0.3,0.1,0.5,0.1c0.2,0,0.4-0.1,0.6-0.2c0.3-0.2,0.5-0.6,0.4-1l-1-5.7l4.1-4C20,8.1,20.1,7.8,20.1,7.4z M13.9,11.4 c-0.2,0.2-0.3,0.6-0.3,0.9l0.7,4.2l-3.8-2c-0.3-0.2-0.6-0.2-0.9,0l-3.8,2l0.7-4.2c0.1-0.3-0.1-0.7-0.3-0.9l-3-3l4.2-0.6 c0.3,0,0.6-0.3,0.8-0.6l1.8-3.8l1.9,3.8c0.1,0.3,0.4,0.5,0.8,0.6l4.2,0.6L13.9,11.4z"></path>
    </symbol>
    <symbol id="icon-fstar" fill="currentColor" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20.1 19.4">
        <path d="M20.1,7.4c-0.1-0.4-0.5-0.6-0.9-0.7l-5.7-0.8L11,0.7C10.7,0.2,10.1,0,9.6,0.2C9.4,0.3,9.2,0.5,9.2,0.7L6.6,5.9 L0.9,6.7C0.5,6.8,0.2,7,0.1,7.4c-0.1,0.4,0,0.7,0.2,1l4.1,4l-1,5.7c-0.1,0.4,0.1,0.8,0.4,1c0.3,0.2,0.7,0.2,1,0.1l5.1-2.7l5.1,2.7 c0.1,0.1,0.3,0.1,0.5,0.1c0.2,0,0.4-0.1,0.6-0.2c0.3-0.2,0.5-0.6,0.4-1l-1-5.7l4.1-4C20,8.1,20.1,7.8,20.1,7.4z"></path>
    </symbol>
</svg>

<div class="modal fade" id="report" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header">
                <h5 class="modal-title">Report</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p>Thanks — reporting helps us improve. Describe the issue:</p>
                <textarea class="form-control" rows="4" placeholder="Broken player, wrong title..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" data-dismiss="modal">Submit</button>
            </div>
        </div>
    </div>
</div>
