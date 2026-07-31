<?php
/**
 * Sidebar row — matches original .scaff.movie-sidebar.items > a.movie-item
 * @var array $item
 * @var string $type
 * @var int|null $sideRank optional 1–5 corner rank badge
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
$poster = img_url($item['poster_path'] ?? null, 'w185');
$rating = isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null;
$label = $type === 'tv' ? 'TV' : 'Movie';
$rankBadge = isset($sideRank) ? (int) $sideRank : 0;
?>
<a class="movie-item<?= $rankBadge ? ' movie-item--ranked' : '' ?>" href="<?= e($href) ?>" data-id="<?= (int) $item['id'] ?>" data-type="<?= e($type) ?>"<?= $rankBadge ? ' style="--side-i:' . $rankBadge . '"' : '' ?>>
    <?php if ($rankBadge > 0): ?>
    <span class="sidebar-rank" aria-label="Rank <?= $rankBadge ?>"><?= $rankBadge ?></span>
    <?php endif; ?>
    <div class="item-poster" data-tip="<?= (int) $item['id'] ?>" data-media-type="<?= e($type) ?>">
        <div>
            <img class="lazyload"
                 data-src="<?= e($poster) ?>"
                 src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='150'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
                 alt="<?= e($title) ?>"
                 width="100" height="150"
                 loading="lazy"
                 decoding="async">
        </div>
    </div>
    <div class="info">
        <div class="name"><?= e($title) ?></div>
        <div class="meta sidebar-meta">
            <span class="rating status-icon"><?= e($label) ?></span>
            <?php if ($rating !== null && $rating > 0): ?>
            <span class="hero-chip"><i class="uil uil-star"></i> <?= e((string) $rating) ?></span>
            <?php endif; ?>
            <?php if ($year): ?>
            <span class="hero-chip"><i class="uil uil-calender"></i> <?= e($year) ?></span>
            <?php endif; ?>
        </div>
    </div>
</a>
