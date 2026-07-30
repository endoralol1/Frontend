<?php
$headerClass = $headerClass ?? 'absolute';
$site = (string) config('site_name');
?>
<header class="<?= e($headerClass) ?>">
    <div class="container">
        <div class="wrapper justify-content-between align-items-center">
            <div class="start d-flex align-items-center">
                <div id="menu-toggler" aria-label="Open menu"><i class="uil uil-list-ui-alt"></i></div>
                <div class="logo">
                    <a href="<?= e(url('/home')) ?>"><img src="<?= e(asset('img/logo.webp')) ?>?v=20260730-home1" alt="<?= e($site) ?>" width="388" height="208" decoding="async"></a>
                </div>
                <div id="language-toggler" title="Language"><span class="lang-code">EN</span></div>
                <div id="language-menu" style="display:none">
                    <ul>
                        <li class="active"><a href="#" data-lang="en">English</a></li>
                        <li><a href="#" data-lang="es">Español</a></li>
                        <li><a href="#" data-lang="it">Italiano</a></li>
                        <li><a href="#" data-lang="fr">Français</a></li>
                        <li><a href="#" data-lang="de">Deutsch</a></li>
                        <li><a href="#" data-lang="pt">Português</a></li>
                        <li><a href="#" data-lang="ru">Русский</a></li>
                    </ul>
                </div>
                <?php /* Same top links as before — desktop only; phone keeps bottom nav */ ?>
                <nav id="menu" class="header-nav" aria-label="Main">
                    <ul>
                        <li><a href="<?= e(url('/home')) ?>" class="<?= is_active('/home') ? 'active' : '' ?>">Home</a></li>
                        <li><a href="<?= e(url('/movies')) ?>" class="<?= is_active('/movies') ? 'active' : '' ?>">Movies</a></li>
                        <li><a href="<?= e(url('/tv-series')) ?>" class="<?= is_active('/tv-series') ? 'active' : '' ?>">TV Shows</a></li>
                        <li><a href="<?= e(url('/top-imdb')) ?>" class="<?= is_active('/top-imdb') ? 'active' : '' ?>">Top IMDB</a></li>
                        <li><a href="<?= e(url('/favorites')) ?>" class="<?= is_active('/favorites') ? 'active' : '' ?>">Favorites <span class="favorites-counter" hidden>0</span></a></li>
                    </ul>
                </nav>
            </div>

            <div class="step">
                <div id="search">
                    <button id="show-search" class="btn-header" type="button" aria-label="Toggle search">
                        <i class="uil uil-search"></i>
                    </button>
                    <div id="search-wrapper">
                        <form class="align-items-center" action="<?= e(url('/search')) ?>" method="get" autocomplete="off" role="search">
                            <a class="filter-btn btn btn-sm" href="<?= e(url('/filters')) ?>">
                                <i class="uil uil-filter"></i> <span>Filter</span>
                            </a>
                            <input type="text" name="keyword" placeholder="Search movies & TV shows..." aria-label="Search movies and TV shows" value="<?= e($_GET['keyword'] ?? '') ?>">
                            <button type="submit" aria-label="Submit search">
                                <i class="uil uil-search"></i>
                            </button>
                        </form>
                        <div class="search-suggest" role="listbox" aria-label="Search suggestions"></div>
                    </div>
                </div>
            </div>
            <div class="end"></div>
        </div>
    </div>
</header>
