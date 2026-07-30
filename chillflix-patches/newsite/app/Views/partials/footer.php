<?php $site = (string) config('site_name'); ?>
<div class="container">
    <div class="footer">
        <div class="logo w-100 d-flex justify-content-center py-4">
            <img src="<?= e(asset('img/logo.webp')) ?>?v=20260730-visible9" alt="<?= e($site) ?>" loading="lazy" width="388" height="208" decoding="async">
        </div>
        <div class="f-top">
            <div class="f-top-inner">
                <ul>
                    <li><a href="<?= e(url('/movies')) ?>">Movies</a></li>
                    <li><a href="<?= e(url('/tv-series')) ?>">TV Shows</a></li>
                    <li><a href="<?= e(url('/top-imdb')) ?>">Top IMDB</a></li>
                </ul>
                <div class="f-wrapper">
                    <p class="f-desc"><?= e($site) ?> - Your destination for free movie streaming online. Watch movies online for free, anytime, anywhere. Explore our vast collection and experience cinematic wonders at your fingertips.</p>
                </div>
            </div>
        </div>
        <div class="f-bottom">
            <div class="text-absolute">This site does not store any files on our server, we only linked to the media which is hosted on 3rd party services.</div>
            <ul>
                <li><a href="<?= e(url('/contact')) ?>">Contact</a></li>
                <li><a href="<?= e(url('/request')) ?>">Request</a></li>
            </ul>
        </div>
    </div>
</div>
