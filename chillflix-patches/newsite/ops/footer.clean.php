<?php $site = (string) config('site_name'); ?>
<div class="container">
    <footer class="footer footer--clean">
        <div class="footer-brand">
            <img class="footer-logo" src="<?= e(asset('img/logo.webp')) ?>?v=20260801-ui80" alt="<?= e($site) ?>" loading="lazy" width="220" height="118" decoding="async">
            <p class="footer-tagline">Free movies &amp; shows. No fluff.</p>
        </div>

        <nav class="footer-links" aria-label="Browse">
            <a href="<?= e(url('/movies')) ?>">Movies</a>
            <a href="<?= e(url('/tv-series')) ?>">TV Shows</a>
            <a href="<?= e(url('/anime')) ?>">Anime</a>
            <a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a>
        </nav>

        <div class="footer-meta">
            <p class="footer-disclaimer">This site does not store any files on our server — we only link to media hosted on 3rd party services.</p>
            <div class="footer-meta-links">
                <a href="<?= e(url('/contact')) ?>">Contact</a>
                <a href="<?= e(url('/request')) ?>">Request</a>
            </div>
        </div>
    </footer>
</div>
