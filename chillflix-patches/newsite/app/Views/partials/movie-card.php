<?php
/**
 * @var array $item
 * @var string $type movie|tv
 * @var int|null $rank optional 1–10 badge (top-left ribbon)
 */
$rank = isset($rank) ? (int) $rank : null;
if ($rank !== null && ($rank < 1 || $rank > 99)) {
    $rank = null;
}
$type = $type ?? (($item['media_type'] ?? '') === 'tv' || isset($item['first_air_date']) && !isset($item['title']) ? 'tv' : 'movie');
if (isset($item['media_type']) && in_array($item['media_type'], ['movie', 'tv'], true)) {
    $type = $item['media_type'];
} elseif (isset($item['name']) && !isset($item['title'])) {
    $type = 'tv';
}
$title = title_of($item);
$year = year_of(date_of($item));
$href = media_url($type, (int) $item['id'], $title);
$poster = img_url($item['poster_path'] ?? null);
$label = $type === 'tv' ? 'TV' : 'Movie';
$rating = isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null;
$ranked = $rank !== null;
?>
<div class="movie-item media-card<?= $ranked ? ' media-card--ranked' : '' ?>" data-id="<?= (int) $item['id'] ?>" data-type="<?= e($type) ?>"<?= $ranked ? ' data-rank="' . (int) $rank . '"' : '' ?>>
    <div class="inner">
        <a class="item-poster" href="<?= e($href) ?>" data-tip="<?= (int) $item['id'] ?>" data-media-type="<?= e($type) ?>" aria-label="<?= e($title) ?>">
            <?php if ($ranked): ?>
            <span class="card-rank" aria-label="Top <?= (int) $rank ?>">
                <span class="card-rank-label">Top</span>
                <span class="card-rank-num"><?= str_pad((string) $rank, 2, '0', STR_PAD_LEFT) ?></span>
            </span>
            <?php endif; ?>
            <div class="poster-media">
                <img class="lazyload" data-src="<?= e($poster) ?>" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E" alt="<?= e($title) ?>" width="300" height="450" loading="lazy">
            </div>
            <div class="card-caption">
                <span class="card-kicker">
                    <span><?= e($label) ?></span>
                    <?php if ($year): ?><span><?= e($year) ?></span><?php endif; ?>
                    <?php if ($rating !== null && $rating > 0): ?><span class="card-rating">★ <?= e((string) $rating) ?></span><?php endif; ?>
                </span>
                <span class="card-title"><?= e($title) ?></span>
            </div>
        </a>
        <div class="meta">
            <div class="meta-bg">
                <span class="dot"><?= e($label) ?></span>
                <?php if ($year): ?><span class="dot"><?= e($year) ?></span><?php endif; ?>
            </div>
            <a class="name" href="<?= e($href) ?>"><?= e($title) ?></a>
        </div>
    </div>
</div>
