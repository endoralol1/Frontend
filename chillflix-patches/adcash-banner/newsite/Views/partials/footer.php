<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer footer--v3">
        <div class="footer-v3-brand">
            <img src="<?= e(logo_url()) ?>?v=20260803-vuflix1" alt="<?= e($site) ?>" loading="lazy" width="280" height="150" decoding="async">
        </div>

        <div class="footer-v3-panel">
            <nav class="footer-v3-cats" aria-label="<?= e(t('footer.browse')) ?>">
                <a href="<?= e(url('/movies')) ?>"><?= e(t('nav.movies')) ?></a>
                <a href="<?= e(url('/tv-series')) ?>"><?= e(t('nav.tv_shows')) ?></a>
                <a href="<?= e(url('/anime')) ?>"><?= e(t('nav.anime')) ?></a>
                <a href="<?= e(url('/top-imdb')) ?>"><?= e(t('nav.top_imdb')) ?></a>
            </nav>
            <p class="footer-v3-desc"><?= e(str_replace('{site}', $site, t('footer.desc_plain'))) ?></p>
        </div>

        <p class="footer-v3-legal"><?= e(t('footer.legal')) ?></p>
        <div class="footer-v3-clearance" aria-hidden="true"></div>
    </div>
</div>
