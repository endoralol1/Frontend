<?php
$headerClass = $headerClass ?? 'absolute';
$site = (string) config('site_name');
$headerStaff = class_exists('Auth') ? Auth::isStaff() : false;
$adminUser = $headerStaff && class_exists('Auth') ? Auth::user() : null;
$adminPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$adminRole = (string) (($adminUser['role'] ?? '') ?: 'staff');
$adminName = (string) (($adminUser['name'] ?? '') ?: 'Admin');
$uiLang = class_exists('Locale') ? Locale::current() : 'en';
$uiLangLabel = class_exists('Locale') ? Locale::label($uiLang) : 'English';
?>
<header class="<?= e($headerClass) ?>">
    <div class="container">
        <div class="wrapper justify-content-between align-items-center">
            <div class="start d-flex align-items-center">
                <div id="menu-toggler" aria-label="<?= e(t('nav.menu')) ?>"><i class="uil uil-list-ui-alt"></i></div>
                <div class="logo">
                    <a href="<?= e(url('/home')) ?>"><img src="<?= e(logo_url()) ?>?v=20260803-vuflix1" alt="<?= e($site) ?>" width="388" height="208" decoding="async"></a>
                </div>
                <div id="language-toggler" title="<?= e(t('nav.language')) ?>: <?= e($uiLangLabel) ?>"><span class="lang-code"><?= e(strtoupper($uiLang)) ?></span></div>
                <div class="header-admin-menu<?= $headerStaff ? ' is-staff' : '' ?>" id="header-admin-menu"<?= $headerStaff ? '' : ' hidden' ?>>
                    <button type="button" id="header-admin-link" class="header-admin-link" title="<?= e(t('admin.menu')) ?>" aria-label="<?= e(t('admin.menu')) ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="header-admin-dropdown">
                        <i class="uil uil-shield-check" aria-hidden="true"></i>
                    </button>
                    <div id="header-admin-dropdown" class="header-admin-dropdown" role="menu" hidden>
                        <div class="header-admin-dropdown-top">
                            <span class="header-admin-dropdown-kicker"><?= e(t('admin.control')) ?></span>
                            <strong><?= e($adminName) ?></strong>
                            <em><?= e(ucfirst($adminRole)) ?></em>
                        </div>
                        <div class="header-admin-dropdown-list">
                            <a role="menuitem" href="<?= e(url('/admin')) ?>" class="<?= preg_match('#/admin/?$#', $adminPath) ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-chart" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong><?= e(t('admin.dashboard')) ?></strong><small><?= e(t('admin.overview')) ?></small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/users')) ?>" class="<?= str_contains($adminPath, '/admin/users') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-users-alt" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong><?= e(t('admin.users')) ?></strong><small><?= e(t('admin.roles')) ?></small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/sources')) ?>" class="<?= str_contains($adminPath, '/admin/sources') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong><?= e(t('admin.sources')) ?></strong><small><?= e(t('admin.sources_help')) ?></small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
                        </div>
                        <a role="menuitem" class="header-admin-back" href="<?= e(url('/home')) ?>">
                            <i class="uil uil-arrow-left" aria-hidden="true"></i><span><?= e(t('admin.back_to_site')) ?></span>
                        </a>
                    </div>
                </div>
                <div id="language-menu" style="display:none">
                    <ul>
                        <?php foreach ((class_exists('Locale') ? Locale::LABELS : ['en' => 'English']) as $code => $label): ?>
                        <li class="<?= $uiLang === $code ? 'active' : '' ?>"><a href="#" data-lang="<?= e($code) ?>" class="<?= $uiLang === $code ? 'active' : '' ?>"><?= e($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <nav id="menu" class="header-nav" aria-label="<?= e(t('aria.main')) ?>">
                    <ul>
                        <li><a href="<?= e(url('/home')) ?>" class="<?= is_active('/home') ? 'active' : '' ?>" data-i18n="nav.home"><?= e(t('nav.home')) ?></a></li>
                        <li><a href="<?= e(url('/movies')) ?>" class="<?= is_active('/movies') ? 'active' : '' ?>" data-i18n="nav.movies"><?= e(t('nav.movies')) ?></a></li>
                        <li><a href="<?= e(url('/tv-series')) ?>" class="<?= is_active('/tv-series') ? 'active' : '' ?>" data-i18n="nav.tv_shows"><?= e(t('nav.tv_shows')) ?></a></li>
                        <li><a href="<?= e(url('/top-imdb')) ?>" class="<?= is_active('/top-imdb') ? 'active' : '' ?>" data-i18n="nav.top_imdb"><?= e(t('nav.top_imdb')) ?></a></li>
                        <li><a href="<?= e(url('/favorites')) ?>" class="<?= is_active('/favorites') ? 'active' : '' ?>" data-i18n="nav.watchlist"><?= e(t('nav.watchlist')) ?> <span class="favorites-counter" hidden>0</span></a></li>
                    </ul>
                </nav>
            </div>

            <div class="step">
                <div id="search">
                    <button id="show-search" class="btn-header" type="button" aria-label="<?= e(t('aria.toggle_search')) ?>">
                        <i class="uil uil-search"></i>
                    </button>
                    <div id="search-wrapper">
                        <form class="align-items-center" action="<?= e(url('/search')) ?>" method="get" autocomplete="off" role="search">
                            <a class="filter-btn btn btn-sm" href="<?= e(url('/filters')) ?>">
                                <i class="uil uil-filter"></i> <span><?= e(t('nav.filter')) ?></span>
                            </a>
                            <input type="text" name="keyword" placeholder="<?= e(t('search.placeholder')) ?>" aria-label="<?= e(t('search.placeholder')) ?>" value="<?= e($_GET['keyword'] ?? '') ?>">
                            <button type="submit" aria-label="<?= e(t('aria.submit_search')) ?>">
                                <i class="uil uil-search"></i>
                            </button>
                        </form>
                        <div class="search-suggest" role="listbox" aria-label="<?= e(t('search.suggestions')) ?>"></div>
                    </div>
                </div>
            </div>
            <div class="end">
                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>
            </div>
        </div>
    </div>
</header>
