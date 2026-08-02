<?php
$headerClass = $headerClass ?? 'absolute';
$site = (string) config('site_name');
$headerStaff = class_exists('Auth') ? Auth::isStaff() : false;
$adminUser = $headerStaff && class_exists('Auth') ? Auth::user() : null;
$adminPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$adminRole = (string) (($adminUser['role'] ?? '') ?: 'staff');
$adminName = (string) (($adminUser['name'] ?? '') ?: 'Admin');
?>
<header class="<?= e($headerClass) ?>">
    <div class="container">
        <div class="wrapper justify-content-between align-items-center">
            <div class="start d-flex align-items-center">
                <div id="menu-toggler" aria-label="Open menu"><i class="uil uil-list-ui-alt"></i></div>
                <div class="logo">
                    <a href="<?= e(url('/home')) ?>"><img src="<?= e(asset('img/logo.webp')) ?>?v=20260730-browse1" alt="<?= e($site) ?>" width="388" height="208" decoding="async"></a>
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
                <nav id="menu" class="header-nav" aria-label="Main">
                    <ul>
                        <li><a href="<?= e(url('/home')) ?>" class="<?= is_active('/home') ? 'active' : '' ?>">Home</a></li>
                        <li><a href="<?= e(url('/movies')) ?>" class="<?= is_active('/movies') ? 'active' : '' ?>">Movies</a></li>
                        <li><a href="<?= e(url('/tv-series')) ?>" class="<?= is_active('/tv-series') ? 'active' : '' ?>">TV Shows</a></li>
                        <li><a href="<?= e(url('/anime')) ?>" class="<?= is_active('/anime') ? 'active' : '' ?>">Anime</a></li>
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
            <div class="end">
                <div class="header-admin-menu<?= $headerStaff ? ' is-staff' : '' ?>" id="header-admin-menu"<?= $headerStaff ? '' : ' hidden' ?>>
                    <button type="button" id="header-admin-link" class="header-admin-link" title="Admin" aria-label="Admin menu" aria-haspopup="menu" aria-expanded="false" aria-controls="header-admin-dropdown">
                        <i class="uil uil-shield-check" aria-hidden="true"></i>
                    </button>
                    <div id="header-admin-dropdown" class="header-admin-dropdown" role="menu" hidden>
                        <div class="header-admin-dropdown-top">
                            <span class="header-admin-dropdown-kicker">Control</span>
                            <strong><?= e($adminName) ?></strong>
                            <em><?= e(ucfirst($adminRole)) ?></em>
                        </div>
                        <div class="header-admin-dropdown-list">
                            <a role="menuitem" href="<?= e(url('/admin')) ?>" class="<?= preg_match('#/admin/?$#', $adminPath) ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-chart" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong>Dashboard</strong><small>Overview & health</small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/users')) ?>" class="<?= str_contains($adminPath, '/admin/users') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-users-alt" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong>Users</strong><small>Roles & accounts</small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/sources')) ?>" class="<?= str_contains($adminPath, '/admin/sources') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong>Sources</strong><small>Order, labels, tests</small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <a role="menuitem" class="header-admin-back" href="<?= e(url('/home')) ?>">
                            <i class="uil uil-arrow-left" aria-hidden="true"></i><span>Back to site</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
