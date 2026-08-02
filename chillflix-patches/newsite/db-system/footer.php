<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer footer--v3">
        <div class="footer-v3-brand">
            <img src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui84" alt="<?= e($site) ?>" loading="lazy" width="280" height="150" decoding="async">
        </div>

        <div class="footer-v3-panel">
            <nav class="footer-v3-cats" aria-label="Browse">
                <a href="<?= e(url('/movies')) ?>">Movies</a>
                <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
                <a href="<?= e(url('/anime')) ?>">Anime</a>
                <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
            </nav>
            <p class="footer-v3-desc"><?= e($site) ?> — free movie &amp; TV streaming. Browse a huge catalog anytime, no signup required.</p>
        </div>

        <p class="footer-v3-legal">This site does not store any files on our server; we only link to media hosted on 3rd party services.</p>
        <div class="footer-v3-clearance" aria-hidden="true"></div>
    </div>
</div>
