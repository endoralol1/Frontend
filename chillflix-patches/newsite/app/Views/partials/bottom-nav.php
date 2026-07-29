<?php
$path = current_path();
$navHome = is_active('/home') || $path === '/' || $path === '';
$navMovies = is_active('/movies') || (bool) preg_match('#^/movie(/|$)#', $path);
$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);
?>
<nav class="bottom-nav" aria-label="Primary">
    <div class="bottom-nav-inner">
        <a href="<?= e(url('/home')) ?>" class="bottom-nav-item<?= $navHome ? ' active' : '' ?>"<?= $navHome ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-estate" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Home</span>
        </a>
        <a href="<?= e(url('/movies')) ?>" class="bottom-nav-item<?= $navMovies ? ' active' : '' ?>"<?= $navMovies ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-clapper-board" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Movies</span>
        </a>
        <a href="<?= e(url('/tv-series')) ?>" class="bottom-nav-item<?= $navTv ? ' active' : '' ?>"<?= $navTv ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-tv-retro" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">TV</span>
        </a>
    </div>
</nav>
