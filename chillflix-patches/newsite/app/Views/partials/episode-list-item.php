<?php
/**
 * Compact latest-episode row for homepage sidebar list.
 * @var array $epItem
 */
$id = (int) ($epItem['id'] ?? 0);
$title = (string) ($epItem['title'] ?? 'Untitled');
$season = (int) ($epItem['season'] ?? 1);
$episode = (int) ($epItem['episode'] ?? 1);
$epName = (string) ($epItem['episode_name'] ?? ('Episode ' . $episode));
$poster = img_url($epItem['poster_path'] ?? $epItem['still_path'] ?? null, 'w185');
$href = media_url('tv', $id, $title) . '?s=' . $season . '&e=' . $episode;
$badge = 'S' . $season . 'E' . $episode;
?>
<a class="movie-item episode-list-item" href="<?= e($href) ?>" data-id="<?= $id ?>" data-type="tv">
    <div class="item-poster">
        <div>
            <img class="lazyload"
                 data-src="<?= e($poster) ?>"
                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='150'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
                 alt="<?= e($title) ?>" loading="lazy">
        </div>
    </div>
    <div class="info">
        <div>
            <span class="rating status-icon"><?= e($badge) ?></span>
        </div>
        <div class="name"><?= e($title) ?></div>
        <div class="episode-list-ep"><?= e($epName) ?></div>
    </div>
</a>
