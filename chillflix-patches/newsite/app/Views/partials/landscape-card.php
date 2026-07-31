<?php
/**
 * Landscape media card — same visual language as Latest Episodes.
 * @var array $item
 * @var string $type movie|tv
 */
$type = $type ?? (isset($item['title']) ? 'movie' : 'tv');
if (isset($item['media_type']) && in_array($item['media_type'], ['movie', 'tv'], true)) {
    $type = $item['media_type'];
} elseif (isset($item['name']) && !isset($item['title'])) {
    $type = 'tv';
}
$title = title_of($item);
$year = year_of(date_of($item));
$href = media_url($type, (int) $item['id'], $title);
$still = img_url(
    $item['backdrop_path'] ?? $item['poster_path'] ?? null,
    'w780'
);
$rating = isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null;
$label = $type === 'tv' ? 'TV' : 'Movie';
$overview = truncate((string) ($item['overview'] ?? ''), 72);
?>
<a class="episode-card landscape-card" href="<?= e($href) ?>" data-id="<?= (int) $item['id'] ?>" data-type="<?= e($type) ?>">
    <div class="episode-card-media">
        <img class="lazyload"
             data-src="<?= e($still) ?>"
             src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='320' height='180'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
             alt="<?= e($title) ?>"
             loading="lazy">
        <span class="episode-card-badge"><?= e($label) ?></span>
        <div class="episode-card-meta">
            <div class="episode-card-show"><?= e($title) ?></div>
            <?php if ($overview !== ''): ?>
            <div class="episode-card-ep"><?= e($overview) ?></div>
            <?php elseif ($year): ?>
            <div class="episode-card-ep"><?= e($year) ?></div>
            <?php endif; ?>
            <div class="episode-card-sub">
                <?php if ($year): ?><span><?= e($year) ?></span><?php endif; ?>
                <?php if ($rating !== null && $rating > 0): ?><span><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
</a>
