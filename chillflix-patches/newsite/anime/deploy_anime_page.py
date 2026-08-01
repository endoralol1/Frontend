#!/usr/bin/env python3
"""Add /anime page: Animation + Japanese-only catalog from Browse."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui51"


def patch_routes() -> None:
    path = ROOT / "app/routes.php"
    text = path.read_text()

    if "$router->get('/anime'" not in text:
        needle = "$router->get('/filters', function () use ($tmdb) {\n    discover_page($tmdb, ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie', true);\n});"
        insert = needle + """

$router->get('/anime', function () use ($tmdb) {
    $type = ($_GET['type'] ?? 'tv') === 'movie' ? 'movie' : 'tv';
    discover_page($tmdb, $type, false, [
        'animeMode' => true,
        'label' => 'Anime',
        'path' => '/anime',
        'forceGenres' => [16],
        'forceOriginalLanguage' => 'ja',
        'voteCountGte' => 100,
    ]);
});"""
        if needle not in text:
            raise SystemExit("filters route not found")
        text = text.replace(needle, insert, 1)
        print("anime route added")
    else:
        print("anime route exists")

    # sitemap
    if "url('/anime')" not in text:
        text = text.replace(
            "        url('/tv-series'),\n",
            "        url('/tv-series'),\n        url('/anime'),\n",
            1,
        )
        print("sitemap anime url added")

    # discover_page signature
    text = text.replace(
        "function discover_page(Tmdb $tmdb, string $type, bool $filtersPage = false): void\n{",
        "function discover_page(Tmdb $tmdb, string $type, bool $filtersPage = false, array $options = []): void\n{",
        1,
    )

    if "animeMode" not in text or "$animeMode = !empty($options['animeMode']);" not in text:
        # inject anime defaults after genre parse / before params build
        marker = "    $genreMode = (string) ($_GET['genre_mode'] ?? '');\n"
        inject = marker + """
    $animeMode = !empty($options['animeMode']);
    $pageLabel = isset($options['label']) ? (string) $options['label'] : '';
    $pagePath = isset($options['path']) ? (string) $options['path'] : '';
    $forceGenres = array_values(array_filter(array_map('intval', (array) ($options['forceGenres'] ?? []))));
    $forceOriginalLanguage = trim((string) ($options['forceOriginalLanguage'] ?? ''));
    $voteCountGte = isset($options['voteCountGte']) ? (int) $options['voteCountGte'] : 0;
    if ($animeMode && $forceGenres) {
        foreach ($forceGenres as $gid) {
            if (!in_array($gid, $genresIn, true)) {
                $genresIn[] = $gid;
            }
            $genresOut = array_values(array_diff($genresOut, [$gid]));
        }
        $genre = $genresIn[0] ?? $genre;
    }

"""
        if marker not in text:
            raise SystemExit("genreMode marker missing")
        if "$animeMode = !empty($options['animeMode']);" not in text:
            text = text.replace(marker, inject, 1)
            print("anime options injected")
        else:
            print("anime options already injected")

    # ensure TMDB params for language / vote count
    if "'with_original_language'" not in text:
        old = """    if ($networksOut) {
        $params['without_networks'] = implode('|', $networksOut);
    }

    $data = $tmdb->discover($type, $params);
    $sidebarItems = array_slice(
        $tmdb->discover($type, ['sort_by' => 'popularity.desc', 'page' => 1])['results'] ?? [],
        0,
        8
    );
    $isMovie = $type === 'movie';
    $label = $isMovie ? 'Movies' : 'TV Shows';
    view('pages/discover', [
        'type' => $type,
"""
        new = """    if ($networksOut) {
        $params['without_networks'] = implode('|', $networksOut);
    }
    if ($forceOriginalLanguage !== '') {
        $params['with_original_language'] = $forceOriginalLanguage;
    }
    if ($voteCountGte > 0) {
        $params['vote_count.gte'] = $voteCountGte;
    }

    $data = $tmdb->discover($type, $params);
    $sidebarParams = ['sort_by' => 'popularity.desc', 'page' => 1, 'include_adult' => 'false'];
    if ($forceGenres) {
        $sidebarParams['with_genres'] = implode('|', $forceGenres);
    }
    if ($forceOriginalLanguage !== '') {
        $sidebarParams['with_original_language'] = $forceOriginalLanguage;
    }
    if ($voteCountGte > 0) {
        $sidebarParams['vote_count.gte'] = $voteCountGte;
    }
    $sidebarItems = array_slice(
        $tmdb->discover($type, $sidebarParams)['results'] ?? [],
        0,
        8
    );
    $isMovie = $type === 'movie';
    $label = $pageLabel !== '' ? $pageLabel : ($isMovie ? 'Movies' : 'TV Shows');
    $canonicalPath = $pagePath !== '' ? $pagePath : ($isMovie ? '/movies' : '/tv-series');
    view('pages/discover', [
        'type' => $type,
        'animeMode' => $animeMode,
        'pageLabel' => $label,
        'pagePath' => $canonicalPath,
"""
        if old not in text:
            raise SystemExit("discover params block not found for language patch")
        text = text.replace(old, new, 1)
        print("language/vote + view vars patched")

    # SEO title/canonical for anime
    text = re.sub(
        r"'title' => \$label \. ' \| Watch ' \. \(\$isMovie \? 'Movies' : 'TV Series'\) \. ' Online Free - ' \. config\('site_name'\),\n"
        r"\s*'description' => \$isMovie\n"
        r"\s*\? 'Explore and watch the latest movies online for free\. Browse our extensive collection of films in high definition\.'\n"
        r"\s*: 'Stream your favorite TV shows and series online for free\. Catch up on the latest episodes in HD quality\.',\n"
        r"\s*'canonical' => url\(\$isMovie \? '/movies' : '/tv-series'\),",
        "'title' => ($animeMode\n"
        "                ? ($label . ' | Watch Japanese Anime Online Free - ' . config('site_name'))\n"
        "                : ($label . ' | Watch ' . ($isMovie ? 'Movies' : 'TV Series') . ' Online Free - ' . config('site_name'))),\n"
        "            'description' => $animeMode\n"
        "                ? ('Browse Japanese anime only — series and movies. Stream anime online free in HD on ' . config('site_name') . '.')\n"
        "                : ($isMovie\n"
        "                ? 'Explore and watch the latest movies online for free. Browse our extensive collection of films in high definition.'\n"
        "                : 'Stream your favorite TV shows and series online for free. Catch up on the latest episodes in HD quality.'),\n"
        "            'canonical' => url($canonicalPath . ($animeMode && $isMovie ? '?type=movie' : '')),",
        text,
        count=1,
    )
    print("seo patched")

    path.write_text(text)


def patch_discover_view() -> None:
    path = ROOT / "app/Views/pages/discover.php"
    text = path.read_text()

    old_top = """<?php
$isMovie = $type === 'movie';
$label = $isMovie ? 'Movies' : 'TV Shows';
$results = $data['results'] ?? [];
$totalPages = max(1, (int) ($data['total_pages'] ?? 1));
$path = $isMovie ? '/movies' : '/tv-series';
"""
    new_top = """<?php
$isMovie = $type === 'movie';
$animeMode = !empty($animeMode);
$label = (isset($pageLabel) && $pageLabel !== '') ? (string) $pageLabel : ($isMovie ? 'Movies' : 'TV Shows');
$results = $data['results'] ?? [];
$totalPages = max(1, (int) ($data['total_pages'] ?? 1));
$path = (isset($pagePath) && $pagePath !== '') ? (string) $pagePath : ($isMovie ? '/movies' : '/tv-series');
"""
    if old_top not in text:
        if "$animeMode = !empty($animeMode);" in text:
            print("discover view top already patched")
        else:
            raise SystemExit("discover.php top block not found")
    else:
        text = text.replace(old_top, new_top, 1)
        print("discover view top patched")

    # Reset should keep anime type=movie when needed
    old_reset = """<a class=\"filters-reset-link\" href=\"<?= e(url($path) . ($viewMode !== 'grid' ? ('?view=' . rawurlencode($viewMode)) : '')) }\">Reset</a>"""
    new_reset = """<?php
                            $resetQs = [];
                            if ($animeMode && $isMovie) { $resetQs['type'] = 'movie'; }
                            if ($viewMode !== 'grid') { $resetQs['view'] = $viewMode; }
                            $resetHref = url($path) . ($resetQs ? ('?' . http_build_query($resetQs)) : '');
                            ?>
                            <a class="filters-reset-link" href="<?= e($resetHref) ?>">Reset</a>"""
    if old_reset in text:
        text = text.replace(old_reset, new_reset, 1)
        print("reset link patched")

    # Title + anime type tabs + hidden type
    old_head = """                        <div class=\"start\">
                            <h2 class=\"title gardiently\"><?= e($label) ?></h2>
                        </div>
                        <div class=\"end\">
                            <div class=\"btn-group view-switcher\" role=\"group\">
"""
    new_head = """                        <div class=\"start\">
                            <h2 class=\"title gardiently\"><?= e($label) ?></h2>
                            <?php if ($animeMode): ?>
                            <p class=\"discover-sub\">Japanese anime only — no regular movies or TV</p>
                            <div class=\"anime-type-tabs\" role=\"tablist\" aria-label=\"Anime type\">
                                <?php
                                $tvQs = array_filter(['view' => $viewMode !== 'grid' ? $viewMode : null]);
                                $movieQs = array_filter(['type' => 'movie', 'view' => $viewMode !== 'grid' ? $viewMode : null]);
                                ?>
                                <a class=\"anime-type-tab<?= !$isMovie ? ' is-active' : '' ?>\" href=\"<?= e(url($path) . ($tvQs ? ('?' . http_build_query($tvQs)) : '')) ?>\" role=\"tab\" aria-selected=\"<?= !$isMovie ? 'true' : 'false' ?>\">Series</a>
                                <a class=\"anime-type-tab<?= $isMovie ? ' is-active' : '' ?>\" href=\"<?= e(url($path) . '?' . http_build_query($movieQs)) ?>\" role=\"tab\" aria-selected=\"<?= $isMovie ? 'true' : 'false' ?>\">Movies</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class=\"end\">
                            <div class=\"btn-group view-switcher\" role=\"group\">
"""
    if "anime-type-tabs" not in text:
        if old_head not in text:
            raise SystemExit("discover head block not found")
        text = text.replace(old_head, new_head, 1)
        print("anime tabs added")
    else:
        print("anime tabs exist")

    old_form = """                    <form id=\"site-filters\" class=\"filters-compact\" method=\"get\" action=\"<?= e(url($path)) ?>\" autocomplete=\"off\">
                        <input type=\"hidden\" name=\"view\" id=\"view-mode-input\" value=\"<?= e($viewMode) ?>\">
"""
    new_form = """                    <form id=\"site-filters\" class=\"filters-compact\" method=\"get\" action=\"<?= e(url($path)) ?>\" autocomplete=\"off\">
                        <input type=\"hidden\" name=\"view\" id=\"view-mode-input\" value=\"<?= e($viewMode) ?>\">
                        <?php if ($animeMode && $isMovie): ?>
                        <input type=\"hidden\" name=\"type\" value=\"movie\">
                        <?php endif; ?>
"""
    if 'name="type" value="movie"' not in text:
        if old_form not in text:
            raise SystemExit("discover form block not found")
        text = text.replace(old_form, new_form, 1)
        print("hidden type input added")
    else:
        print("hidden type exists")

    # chipUrl should preserve type=movie on anime
    if "animeMode && $isMovie" not in text or "chipUrl" in text:
        old_chip = """$chipUrl = static function (array $clearKeys) use ($path): string {
    $qs = $_GET;
    unset($qs['page'], $qs['genre_mode']);
"""
        new_chip = """$chipUrl = static function (array $clearKeys) use ($path, $animeMode, $isMovie): string {
    $qs = $_GET;
    unset($qs['page'], $qs['genre_mode']);
    if ($animeMode && $isMovie) {
        $qs['type'] = 'movie';
    } elseif ($animeMode) {
        unset($qs['type']);
    }
"""
        if old_chip in text:
            text = text.replace(old_chip, new_chip, 1)
            print("chipUrl patched")

    path.write_text(text)


def patch_bottom_nav() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()
    old = """                    <a class=\"browse-tile\" href=\"<?= e(url('/filters')) ?>\">
                        <span class=\"browse-tile-icon tone-orange\"><i class=\"uil uil-star\"></i></span>
                        <span class=\"browse-tile-label\">Anime</span>
                        <span class=\"browse-tile-badge\">Mock</span>
                    </a>"""
    new = """                    <a class=\"browse-tile\" href=\"<?= e(url('/anime')) ?>\">
                        <span class=\"browse-tile-icon tone-orange\"><i class=\"uil uil-star\"></i></span>
                        <span class=\"browse-tile-label\">Anime</span>
                    </a>"""
    if old in text:
        path.write_text(text.replace(old, new, 1))
        print("browse anime tile patched")
    elif "url('/anime')" in text and "browse-tile-label\">Anime" in text:
        print("browse anime tile already points to /anime")
    else:
        raise SystemExit("anime browse tile not found")


def patch_app_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()
    old = "return /\\/(home|movies|tv-series|search|favorites|top-imdb|filters|contact|request)(\\/|$|\\?)/.test(path)"
    new = "return /\\/(home|movies|tv-series|anime|search|favorites|top-imdb|filters|contact|request)(\\/|$|\\?)/.test(path)"
    if "tv-series|anime|" in text:
        print("softnav anime exists")
    elif old in text:
        text = text.replace(old, new, 1)
        print("softnav anime added")
    else:
        alt = re.search(
            r"return /\\?/?\\?/\(home\|movies\|tv-series[^)]*\)\([^)]*\)/\.test\(path\)|"
            r"return /\\/\(home\|movies\|tv-series[^)]+\)\(\\/\|\$\|\\\?\)/\.test\(path\)",
            text,
        )
        if alt and "anime" not in alt.group(0):
            text = text.replace(alt.group(0), alt.group(0).replace("tv-series|", "tv-series|anime|", 1), 1)
            print("softnav anime added (alt)")
        else:
            print("WARN softnav pattern not updated")

    # Search chip "anime" → /anime page
    chip_handler = "  $(document).on('click', '[data-search-chip]', function (e) {"
    if "/* anime-chip-route */" not in text and chip_handler in text:
        text = text.replace(
            chip_handler,
            """  $(document).on('click', '[data-search-chip]', function (e) {
    /* anime-chip-route */
    var chipQ = String($(this).data('search-chip') || '').toLowerCase();
    if (chipQ === 'anime') {
      e.preventDefault();
      try { closeSearchSheet(); } catch (err) {}
      var animeUrl = (window.CF_BASE || '') + '/anime';
      if (typeof softNavigate === 'function') softNavigate(animeUrl);
      else window.location.href = animeUrl;
      return;
    }
""",
            1,
        )
        print("search chip anime route added")
    else:
        print("search chip anime route skipped/exists")

    path.write_text(text)


def patch_css() -> None:
    css_path = ROOT / "public/assets/css/app.css"
    text = css_path.read_text()
    marker = "/* anime-page */"
    body = """.discover-page .discover-sub {
  margin: 0.2rem 0 0.55rem;
  color: rgba(255, 255, 255, 0.5);
  font-size: 0.78rem;
  font-weight: 500;
}
.discover-page .anime-type-tabs {
  display: inline-flex;
  gap: 0.35rem;
  margin: 0.35rem 0 0.15rem;
  padding: 0.2rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid rgba(255, 255, 255, 0.08);
}
.discover-page .anime-type-tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 1.85rem;
  padding: 0.25rem 0.85rem;
  border-radius: 999px;
  color: rgba(255, 255, 255, 0.7);
  text-decoration: none;
  font-size: 0.78rem;
  font-weight: 650;
}
.discover-page .anime-type-tab.is-active {
  background: linear-gradient(135deg, #db6937 0%, #dc3545 100%);
  color: #fff;
}
"""
    if marker in text:
        text = re.sub(
            r"/\* anime-page \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("anime css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("anime css appended")
    css_path.write_text(text)


def bump_assets() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset version", ASSET_V)


def main() -> None:
    patch_routes()
    patch_discover_view()
    patch_bottom_nav()
    patch_app_js()
    patch_css()
    bump_assets()
    print("deploy_anime_page done", ASSET_V)


if __name__ == "__main__":
    main()
