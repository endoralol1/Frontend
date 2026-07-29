<?php
/**
 * Landscape latest-episode card — still fills the card; title sits on the image.
 * @var array $epItem
 */
$id = (int) ($epItem['id'] ?? 0);
$title = (string) ($epItem['title'] ?? 'Untitled');
$season = (int) ($epItem['season'] ?? 1);
$episode = (int) ($epItem['episode'] ?? 1);
$epName = (string) ($epItem['episode_name'] ?? ('Episode ' . $episode));
$airDate = (string) ($epItem['air_date'] ?? '');
$airLabel = $airDate !== '' ? date('M j', strtotime($airDate)) : '';
$rating = isset($epItem['vote_average']) ? round((float) $epItem['vote_average'], 1) : null;
$still = img_url(
    $epItem['still_path'] ?? $epItem['backdrop_path'] ?? $epItem['poster_path'] ?? null,
    'w780'
);
$href = media_url('tv', $id, $title) . '?s=' . $season . '&e=' . $episode;
$badge = 'S' . $season . ' · E' . $episode;
?>
<a class="episode-card" href="<?= e($href) ?>" data-id="<?= $id ?>" data-type="tv">
    <div class="episode-card-media">
        <img class="lazyload"
             data-src="<?= e($still) ?>"
             src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='320' height='180'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
             alt="<?= e($title . ' - ' . $badge) ?>"
             loading="lazy">
        <span class="episode-card-badge"><?= e($badge) ?></span>
        <div class="episode-card-meta">
            <div class="episode-card-show"><?= e($title) ?></div>
            <div class="episode-card-ep"><?= e($epName) ?></div>
            <div class="episode-card-sub">
                <?php if ($airLabel !== ''): ?><span><?= e($airLabel) ?></span><?php endif; ?>
                <?php if ($rating !== null): ?><span><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
</a>
