<?php
/** @var list<array> $categories */
/** @var list<array> $channels */
/** @var array|null $initial */
/** @var int $totalAll */
$categories = $categories ?? [];
$channels = $channels ?? [];
$initial = $initial ?? null;
$totalAll = (int) ($totalAll ?? count($channels));
$assetV = '20260801-ui29';
?>
<main class="live-p">
    <div class="live-shell"
         data-api="<?= e(url('/api/live/channels')) ?>"
         data-feed="<?= e(asset('data/live-channels.json')) ?>?v=<?= e($assetV) ?>"
         data-play-api="<?= e(url('/api/live/play')) ?>"
         data-main-play="<?= e(rtrim((string) config('player_main_api', 'https://www.chillflix.lol'), '/')) ?>/api/iptv/live/">
        <header class="live-hero">
            <div class="live-hero-copy">
                <p class="live-kicker"><span class="live-pulse" aria-hidden="true"></span> Live TV</p>
                <h1>Watch live channels</h1>
                <p class="live-lead">Sports, news, and entertainment — categorized and ready to play.</p>
            </div>
            <div class="live-hero-stats" id="live-stats">
                <div><strong id="live-count"><?= (int) $totalAll ?></strong><span>channels</span></div>
                <div><strong id="live-cats"><?= (int) count($categories) ?></strong><span>regions</span></div>
            </div>
        </header>

        <section class="live-stage" aria-label="Live player">
            <div class="live-player-card">
                <div id="live-player" class="live-player is-idle">
                    <div class="live-player-idle">
                        <i class="uil uil-play-circle" aria-hidden="true"></i>
                        <p>Pick a channel to start watching</p>
                    </div>
                    <video id="live-video" playsinline webkit-playsinline></video>
                    <div class="live-player-shade" aria-hidden="true"></div>
                    <div class="live-player-ui">
                        <button type="button" class="live-ctrl" id="live-playpause" aria-label="Play/Pause"><i class="uil uil-play"></i></button>
                        <button type="button" class="live-ctrl" id="live-mute" aria-label="Mute"><i class="uil uil-volume"></i></button>
                        <button type="button" class="live-ctrl" id="live-fs" aria-label="Fullscreen"><i class="uil uil-expand-arrows"></i></button>
                    </div>
                    <div class="live-player-status" id="live-status">Ready</div>
                </div>
                <div class="live-now">
                    <div class="live-now-badge">NOW</div>
                    <div class="live-now-copy">
                        <h2 id="live-now-title"><?= e($initial['name'] ?? 'No channel selected') ?></h2>
                        <p id="live-now-meta"><?= e($initial['epg'] ?? 'Choose a channel from the list') ?></p>
                    </div>
                </div>
            </div>

            <aside class="live-side">
                <div class="live-toolbar">
                    <label class="live-search">
                        <i class="uil uil-search" aria-hidden="true"></i>
                        <input type="search" id="live-q" placeholder="Search channels…" autocomplete="off" spellcheck="false">
                    </label>
                </div>
                <div class="live-cats" id="live-cat-bar" role="tablist" aria-label="Regions">
                    <button type="button" class="live-cat is-on" data-cat="" role="tab" aria-selected="true">All</button>
                    <?php foreach ($categories as $cat): ?>
                    <button type="button" class="live-cat" data-cat="<?= e($cat['id']) ?>" role="tab" aria-selected="false">
                        <?= e($cat['name']) ?>
                        <em><?= (int) ($cat['count'] ?? 0) ?></em>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="live-list" id="live-list" role="listbox" aria-label="Channels">
                    <?php if (!$channels): ?>
                    <div class="live-empty">Loading channels…</div>
                    <?php else: ?>
                    <?php foreach (array_slice($channels, 0, 60) as $i => $ch): ?>
                    <button type="button"
                            class="live-ch<?= $i === 0 && $initial ? ' is-active' : '' ?>"
                            role="option"
                            data-id="<?= e($ch['id']) ?>"
                            data-name="<?= e($ch['name']) ?>"
                            data-group="<?= e($ch['group']) ?>"
                            data-epg="<?= e($ch['epg'] ?? '') ?>"
                            data-cat="<?= e($ch['cat']) ?>">
                        <span class="live-ch-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($ch['name'], 0, 1))) ?></span>
                        <span class="live-ch-copy">
                            <strong><?= e($ch['name']) ?></strong>
                            <small><?= e($ch['group']) ?><?= !empty($ch['epg']) ? ' · ' . e($ch['epg']) : '' ?></small>
                        </span>
                        <span class="live-ch-go" aria-hidden="true"><i class="uil uil-play"></i></span>
                    </button>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>
        </section>
    </div>
</main>

<script>
window.LIVE_BOOT = <?= json_encode([
    'categories' => $categories,
    'channels' => array_slice($channels, 0, 60),
    'initialId' => $initial['id'] ?? null,
    'totalAll' => $totalAll,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
