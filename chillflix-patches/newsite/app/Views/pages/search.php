<?php $headerClass = 'relative'; ?>
<main>
    <div class="container py-4">
        <div class="section">
            <div class="head">
                <div class="start">
                    <h1 class="title gardiently"><?= $q !== '' ? 'Search: ' . e($q) : 'Search' ?></h1>
                </div>
            </div>
            <form action="<?= e(url('/search')) ?>" method="get" class="mb-4">
                <div class="d-flex gap-2">
                    <input class="form-control" type="text" name="keyword" value="<?= e($q) ?>" placeholder="Search movies & TV shows..." required>
                    <button class="btn btn-primary" type="submit">Search</button>
                </div>
            </form>
            <?php if ($q === ''): ?>
                <p class="text-muted">Type a title to search movies and TV shows.</p>
            <?php elseif (!$results): ?>
                <p class="text-muted">No results for “<?= e($q) ?>”.</p>
            <?php else: ?>
                <p class="text-muted mb-3"><?= (int) $total ?> results</p>
                <div class="scaff movies items">
                    <?php foreach ($results as $item) {
                        $type = ($item['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
                        require __DIR__ . '/../partials/movie-card.php';
                    } ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
