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
$castList = array_slice($item['credits']['cast'] ?? [], 0, 5);
$castNames = array_map(static fn ($c) => $c['name'] ?? '', $castList);
$castNames = array_values(array_filter($castNames));

$crew = $item['credits']['crew'] ?? [];
$directors = [];
$creators = [];
foreach ($crew as $c) {
    $job = $c['job'] ?? '';
    if ($job === 'Director') {
        $directors[] = $c['name'] ?? '';
    }
    if (in_array($job, ['Creator', 'Executive Producer', 'Writer', 'Novel'], true) && count($creators) < 3) {
        $creators[] = $c['name'] ?? '';
    }
}
$directors = array_values(array_unique(array_filter($directors)));
$creators = array_values(array_unique(array_filter($creators)));
if ($type === 'tv' && !empty($item['created_by'])) {
    $creators = array_values(array_filter(array_map(static fn ($c) => $c['name'] ?? '', $item['created_by'])));
}

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
$similar = array_slice($item['similar']['results'] ?? $item['recommendations']['results'] ?? [], 0, 8);

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
    <div class="container watch-wrap"
         data-id="<?= (int) $item['id'] ?>"
         data-type="<?= e($type) ?>"
         data-trailer="<?= e((string) $trailer) ?>"
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
                            <div class="btn-play" id="btn-play" role="button" aria-label="Play trailer"><i></i></div>
                            <div id="player-frame" class="d-none"></div>
                        </div>
                    </div>
                    <div id="movie-managers">
                        <div class="movie-managers-wrap">
                            <div class="movie-manager auto-play no-tooltip" id="btn-autoplay"><i class="uil uil-circle"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span></div>
                            <div class="bookmark movie-manager user-bookmark-toggle"
                                 data-id="<?= (int) $item['id'] ?>" data-media-type="<?= e($type) ?>"
                                 data-title="<?= e($title) ?>" data-poster="<?= e(img_url($item['poster_path'] ?? null, 'w185')) ?>"
                                 data-year="<?= e($year) ?>">
                                <i class="uil uil-plus-circle"></i><span class="ml-1">Favorite</span>
                            </div>
                            <div class="movie-manager share-btn" id="btn-share"><i class="uil uil-share-alt"></i><span class="ml-1">Share</span></div>
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
                            <?php if ($genres): ?>
                            <div>
                                <div>Genre:</div>
                                <span><?= e(implode(', ', $genres)) ?></span>
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
                                <span><?= e(implode(', ', $directors)) ?></span>
                            </div>
                            <?php elseif ($type === 'tv' && $creators): ?>
                            <div>
                                <div>Creator:</div>
                                <span><?= e(implode(', ', $creators)) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($companies): ?>
                            <div>
                                <div>Production:</div>
                                <span><?= e(implode(', ', array_slice($companies, 0, 4))) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($castNames): ?>
                            <div>
                                <div>Cast:</div>
                                <span><?= e(implode(', ', $castNames)) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <div class="clearfix"></div>

                <div class="section" id="comment" style="margin-top:20px;">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Comments</h2>
                        </div>
                    </div>
                    <div class="body">
                        <p class="text-muted mb-0">Comments coming soon.</p>
                    </div>
                </div>
            </aside>

            <aside class="sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">You may like</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movie-sidebar items">
                            <?php
                            foreach ($similar as $sim) {
                                $item = $sim;
                                $type = isset($sim['title']) ? 'movie' : 'tv';
                                require __DIR__ . '/../partials/sidebar-item.php';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </aside>
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
