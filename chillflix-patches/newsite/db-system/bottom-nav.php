<?php
$path = current_path();
$navHome = is_active('/home') || $path === '/' || $path === '';
$navMovies = is_active('/movies') || (bool) preg_match('#^/movie(/|$)#', $path);
$navTv = is_active('/tv-series') || (bool) preg_match('#^/tv(/|$)#', $path);
$navAnime = is_active('/anime');
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
        <a href="<?= e(url('/anime')) ?>" class="bottom-nav-item<?= $navAnime ? ' active' : '' ?>"<?= $navAnime ? ' aria-current="page"' : '' ?>>
            <span class="bottom-nav-icon"><i class="uil uil-star" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Anime</span>
        </a>
        <button type="button" class="bottom-nav-item bottom-nav-search" aria-haspopup="dialog" aria-controls="search-sheet" aria-expanded="false">
            <span class="bottom-nav-icon"><i class="uil uil-search" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Search</span>
        </button>
        <button type="button" class="bottom-nav-item bottom-nav-browse" aria-haspopup="dialog" aria-controls="browse-sheet" aria-expanded="false">
            <span class="bottom-nav-icon"><i class="uil uil-apps" aria-hidden="true"></i></span>
            <span class="bottom-nav-label">Browse</span>
        </button>
    </div>
</nav>

<div class="search-sheet" id="search-sheet" hidden>
    <button type="button" class="search-sheet-backdrop" aria-label="Close search"></button>
    <div class="search-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="search-sheet-title">
        <div class="search-sheet-glow" aria-hidden="true"></div>
        <div class="search-sheet-head">
            <div>
                <p class="search-sheet-kicker">Find something to watch</p>
                <h2 id="search-sheet-title">Search</h2>
            </div>
            <button type="button" class="search-sheet-close" aria-label="Close search"><i class="uil uil-times"></i></button>
        </div>
        <form class="search-sheet-form" action="<?= e(url('/search')) ?>" method="get" autocomplete="off" role="search">
            <div class="search-sheet-field">
                <i class="uil uil-search" aria-hidden="true"></i>
                <input id="search-sheet-input" type="search" name="keyword" placeholder="Movies, TV shows, anime…" aria-label="Search movies and TV shows" enterkeyhint="search">
                <button type="submit" class="search-sheet-go">Go</button>
            </div>
        </form>
        <div class="search-sheet-chips" aria-label="Quick searches">
            <button type="button" class="search-chip" data-search-chip="action">Action</button>
            <button type="button" class="search-chip" data-search-chip="comedy">Comedy</button>
            <button type="button" class="search-chip" data-search-chip="horror">Horror</button>
            <button type="button" class="search-chip" data-search-chip="anime">Anime</button>
            <button type="button" class="search-chip" data-search-chip="marvel">Marvel</button>
        </div>
        <div class="search-sheet-suggest search-suggest" role="listbox" aria-label="Search suggestions"></div>
        <div class="search-sheet-empty-hint">
            <i class="uil uil-bolt-alt"></i>
            <span>Start typing — results show up instantly</span>
        </div>
    </div>
</div>

<div class="browse-sheet" id="browse-sheet" hidden>
    <button type="button" class="browse-sheet-backdrop" aria-label="Close browse"></button>
    <div class="browse-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="browse-sheet-title">
        <div class="browse-sheet-handle" aria-hidden="true"></div>
        <div class="browse-sheet-head">
            <h2 id="browse-sheet-title">Browse</h2>
            <button type="button" class="browse-sheet-close" aria-label="Close"><i class="uil uil-times"></i></button>
        </div>

        <div class="browse-sheet-body">
            <div class="browse-home-view" id="browse-home-view">

            <section class="browse-section browse-stats-section" id="browse-stats-section" aria-label="Watch stats">
                <h3 class="browse-section-title">Your activity</h3>
                <div class="browse-stats-card">
                    <div class="browse-stats-grid" role="list">
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-clapper-board" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-movies">0</strong>
                                <span>Movies</span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-tv-retro" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-tv">0</strong>
                                <span>TV Shows</span>
                            </div>
                        </div>
                        <div class="browse-stat" role="listitem">
                            <i class="uil uil-heart" aria-hidden="true"></i>
                            <div class="browse-stat-copy">
                                <strong id="browse-stat-favs">0</strong>
                                <span>Favorites</span>
                            </div>
                        </div>
                    </div>
                    <div class="browse-stats-foot">
                        <i class="uil uil-clock" aria-hidden="true"></i>
                        <p class="browse-stats-time" id="browse-stats-time">No watch history yet</p>
                    </div>
                </div>
            </section>

<section class="browse-section">
                <h3 class="browse-section-title">Features</h3>
                <div class="browse-grid browse-grid-3">
                    <a class="browse-tile" href="<?= e(url('/live')) ?>">
                        <span class="browse-tile-icon tone-blue"><i class="uil uil-rss-alt"></i></span>
                        <span class="browse-tile-label">Live TV</span>
                        <span class="browse-tile-badge">Live</span>
                    </a>
                    <button type="button" class="browse-tile" data-browse-mock="games">
                        <span class="browse-tile-icon tone-purple" aria-hidden="true"><svg class="browse-tile-svg" viewBox="0 0 24 24" width="1.15rem" height="1.15rem" fill="currentColor" focusable="false"><path d="M9 7h6a6 6 0 0 1 6 6v1.5a3.5 3.5 0 0 1-3.5 3.5h-.35a2 2 0 0 1-1.7-.95l-.55-.9a1 1 0 0 0-.85-.48H10a1 1 0 0 0-.85.48l-.55.9a2 2 0 0 1-1.7.95H6.5A3.5 3.5 0 0 1 3 14.5V13a6 6 0 0 1 6-6Zm-1.75 4.25a.75.75 0 0 0-.75.75v.75H5.75a.75.75 0 0 0 0 1.5H6.5v.75a.75.75 0 0 0 1.5 0v-.75h.75a.75.75 0 0 0 0-1.5H8v-.75a.75.75 0 0 0-.75-.75Zm8.5 1a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm2.25 1.75a1 1 0 1 0-2 0 1 1 0 0 0 2 0Z"/></svg></span>
                        <span class="browse-tile-label">Games</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>
                    <button type="button" class="browse-tile" data-browse-open="watch-party">
                        <span class="browse-tile-icon tone-yellow"><i class="uil uil-users-alt"></i></span>
                        <span class="browse-tile-label">Watch Party</span>
                        <span class="browse-tile-badge">Live</span>
                    </button>
                </div>
            </section>

            <section class="browse-section">
                <h3 class="browse-section-title">Personal</h3>
                <div class="browse-grid browse-grid-2">
                    <a class="browse-card" href="<?= e(url('/favorites')) ?>" data-browse-pref="watchlist">
                        <span class="browse-card-icon"><i class="uil uil-heart"></i></span>
                        <span class="browse-card-copy">
                            <strong>Watchlist</strong>
                            <em>Your saved titles</em>
                        </span>
                    </a>
                    <a class="browse-card" href="<?= e(url('/home')) ?>#continue-watching" data-browse-open="history" data-browse-pref="continue">
                        <span class="browse-card-icon"><i class="uil uil-history"></i></span>
                        <span class="browse-card-copy">
                            <strong>History</strong>
                            <em>Continue watching</em>
                        </span>
                    </a>
                </div>
            </section>

            <section class="browse-section" id="browse-account-section">
                <h3 class="browse-section-title">Account</h3>
                <div class="browse-auth-cta" id="browse-auth-guest">
                    <button type="button" class="browse-auth-cta-card" data-browse-auth="login">
                        <span class="browse-auth-cta-icon"><i class="uil uil-signin"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong>Login</strong>
                            <em>Sync favorites across devices</em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-auth-cta-card is-accent" data-browse-auth="register">
                        <span class="browse-auth-cta-icon"><i class="uil uil-user-plus"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong>Create account</strong>
                            <em>Join Chillflix in seconds</em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-list-item" data-browse-open="settings">
                        <i class="uil uil-setting" aria-hidden="true"></i>
                        <span>Settings</span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="browse-auth-user" id="browse-auth-user" hidden>
                    <div class="browse-auth-user-card">
                        <span class="browse-auth-user-avatar" id="browse-auth-avatar">?</span>
                        <span class="browse-auth-cta-copy">
                            <strong id="browse-auth-name">Signed in</strong>
                            <em id="browse-auth-email"></em>
                        </span>
                    </div>
                    <button type="button" class="browse-list-item" data-browse-open="settings">
                        <i class="uil uil-setting" aria-hidden="true"></i>
                        <span>Settings</span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="browse-list-item" id="browse-auth-logout">
                        <i class="uil uil-signout"></i>
                        <span>Log out</span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
            </section>
            </div>

<div class="browse-settings-view" id="browse-settings-view" hidden>
                <div class="browse-settings-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-settings-back" aria-label="Back to browse">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-settings-heading">
                            <p class="browse-auth-kicker">Preferences</p>
                            <h3 class="browse-auth-title" style="margin:0;">Settings</h3>
                        </div>
                    </div>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label">Language</h4>
                        <div class="browse-settings-langs" id="browse-settings-langs" role="listbox" aria-label="Language">
                            <button type="button" class="browse-settings-lang is-active" data-lang="en" role="option" aria-selected="true">English</button>
                            <button type="button" class="browse-settings-lang" data-lang="es" role="option" aria-selected="false">Español</button>
                            <button type="button" class="browse-settings-lang" data-lang="it" role="option" aria-selected="false">Italiano</button>
                            <button type="button" class="browse-settings-lang" data-lang="fr" role="option" aria-selected="false">Français</button>
                            <button type="button" class="browse-settings-lang" data-lang="de" role="option" aria-selected="false">Deutsch</button>
                            <button type="button" class="browse-settings-lang" data-lang="pt" role="option" aria-selected="false">Português</button>
                            <button type="button" class="browse-settings-lang" data-lang="ru" role="option" aria-selected="false">Русский</button>
                        </div>
                    </section>

<section class="browse-settings-block">
                        <h4 class="browse-settings-label">Playback</h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Autoplay</strong>
                                <em>Start playback when you open a title</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autoplay" role="switch" aria-checked="false" aria-label="Autoplay"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Auto Next</strong>
                                <em>Play the next episode when one ends</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autonext" role="switch" aria-checked="false" aria-label="Auto Next"></button>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label">Library</h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Watchlist</strong>
                                <em>Save and manage favorite titles</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-watchlist" role="switch" aria-checked="true" aria-label="Watchlist"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Continue Watching</strong>
                                <em>Track progress and show resume row</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-continue" role="switch" aria-checked="true" aria-label="Continue Watching"></button>
                        </div>
                    </section>

                </div>
            </div>

            <div class="browse-auth-view" id="browse-auth-view" hidden>
                <div class="browse-auth-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-auth-back" aria-label="Back to browse">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-auth-tabs" role="tablist">
                            <button type="button" class="browse-auth-tab is-active" data-auth-tab="login" role="tab" aria-selected="true">Login</button>
                            <button type="button" class="browse-auth-tab" data-auth-tab="register" role="tab" aria-selected="false">Register</button>
                        </div>
                    </div>

                    <div class="browse-auth-hero">
                        <div class="browse-auth-orb" aria-hidden="true"></div>
                        <p class="browse-auth-kicker" id="browse-auth-kicker">Welcome back</p>
                        <h3 class="browse-auth-title" id="browse-auth-title">Sign in to Chillflix</h3>
                        <p class="browse-auth-sub" id="browse-auth-sub">Pick up your watchlist, favorites, and profile anywhere.</p>
                    </div>

                    <form class="browse-auth-form" id="browse-login-form" autocomplete="on">
                        <label class="browse-auth-field">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@email.com">
                        </label>
                        <label class="browse-auth-field">
                            <span>Password</span>
                            <input type="password" name="password" required autocomplete="current-password" placeholder="Your password" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-login"></div>
                        <p class="browse-auth-error" id="browse-login-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-login-submit">
                            <span>Sign in</span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>

                    <form class="browse-auth-form" id="browse-register-form" autocomplete="on" hidden>
                        <label class="browse-auth-field">
                            <span>Name</span>
                            <input type="text" name="name" required autocomplete="name" placeholder="Display name">
                        </label>
                        <label class="browse-auth-field">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@email.com">
                        </label>
                        <label class="browse-auth-field">
                            <span>Password</span>
                            <input type="password" name="password" required autocomplete="new-password" placeholder="At least 6 characters" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-register"></div>
                        <p class="browse-auth-error" id="browse-register-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-register-submit">
                            <span>Create account</span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
