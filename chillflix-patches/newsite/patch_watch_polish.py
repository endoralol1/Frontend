#!/usr/bin/env python3
"""Polish newsite watch page: similar sidebar, cast row, genre links, drop empty comments."""
from __future__ import annotations

from pathlib import Path

WATCH = Path("/var/www/chillflix-newsite/app/Views/pages/watch.php")
LAYOUT = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
APP_CSS = Path("/var/www/chillflix-newsite/public/assets/css/app.css")


def patch_watch() -> None:
    text = WATCH.read_text()

    old_cast = """$castList = array_slice($item['credits']['cast'] ?? [], 0, 5);
$castNames = array_map(static fn ($c) => $c['name'] ?? '', $castList);
$castNames = array_values(array_filter($castNames));"""

    new_cast = """$castList = array_values(array_filter(
    array_slice($item['credits']['cast'] ?? [], 0, 12),
    static fn ($c) => !empty($c['name'])
));
$castNames = array_values(array_filter(array_map(static fn ($c) => $c['name'] ?? '', array_slice($castList, 0, 5))));
$discoverPath = $type === 'tv' ? '/tv-series' : '/movies';
$watchItem = $item;
$watchType = $type;
$similar = array_values(array_filter(
    array_slice($item['similar']['results'] ?? $item['recommendations']['results'] ?? [], 0, 12),
    static fn ($s) => !empty($s['id'])
));"""

    if old_cast not in text:
        raise SystemExit("cast block not found")
    text = text.replace(old_cast, new_cast, 1)

    old_similar = "$similar = array_slice($item['similar']['results'] ?? $item['recommendations']['results'] ?? [], 0, 8);\n\n"
    if old_similar not in text:
        raise SystemExit("similar assignment not found")
    text = text.replace(old_similar, "", 1)

    text = text.replace(
        'aria-label="Play trailer"><i></i></div>',
        'aria-label="Play trailer" title="Play trailer"><i></i></div>\n'
        '                            <?php if ($trailer): ?><div class="player-trailer-label">Trailer</div><?php endif; ?>',
        1,
    )

    tv_sidebar = """            <?php if ($type === 'tv'): ?>
            <aside class="sidebar">
                <div id="movie-episode" class="tv">"""

    movie_sidebar = """            <?php if ($type === 'movie' && $similar): ?>
            <aside class="sidebar">
                <div class="section">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">You may also like</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movie-sidebar items">
                            <?php
                            $sideRank = 0;
                            foreach (array_slice($similar, 0, 8) as $sideItem) {
                                $item = $sideItem;
                                $type = 'movie';
                                if (isset($sideItem['media_type']) && in_array($sideItem['media_type'], ['movie', 'tv'], true)) {
                                    $type = $sideItem['media_type'];
                                } elseif (isset($sideItem['name']) && !isset($sideItem['title'])) {
                                    $type = 'tv';
                                }
                                $sideRank++;
                                require __DIR__ . '/../partials/sidebar-item.php';
                            }
                            $item = $watchItem;
                            $type = $watchType;
                            unset($sideRank, $sideItem);
                            ?>
                        </div>
                    </div>
                </div>
            </aside>
            <?php endif; ?>

""" + tv_sidebar

    if tv_sidebar not in text:
        raise SystemExit("tv sidebar marker not found")
    text = text.replace(tv_sidebar, movie_sidebar, 1)

    old_genres = """                            <?php if ($genres): ?>
                            <div>
                                <div>Genre:</div>
                                <span><?= e(implode(', ', $genres)) ?></span>
                            </div>
                            <?php endif; ?>"""

    new_genres = """                            <?php if (!empty($item['genres'])): ?>
                            <div>
                                <div>Genre:</div>
                                <span class="genre-links">
                                    <?php
                                    $genreParts = [];
                                    foreach ($item['genres'] as $g) {
                                        $gid = (int) ($g['id'] ?? 0);
                                        $gname = (string) ($g['name'] ?? '');
                                        if ($gname === '') {
                                            continue;
                                        }
                                        if ($gid > 0) {
                                            $genreParts[] = '<a href="' . e(url($discoverPath) . '?with_genres[]=' . $gid) . '">' . e($gname) . '</a>';
                                        } else {
                                            $genreParts[] = e($gname);
                                        }
                                    }
                                    echo implode(', ', $genreParts);
                                    ?>
                                </span>
                            </div>
                            <?php endif; ?>"""

    if old_genres not in text:
        raise SystemExit("genres block not found")
    text = text.replace(old_genres, new_genres, 1)

    # Replace cast text-only row with short list still in detail, then add cast strip + similar grid + drop comments
    old_cast_detail = """                            <?php if ($castNames): ?>
                            <div>
                                <div>Cast:</div>
                                <span><?= e(implode(', ', $castNames)) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <div class="clearfix"></div>

                <div class="section" id="comment" style="margin-top:20px;">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Comments</h2>
                        </div>
                    </div>
                    <div class="body">
                        <p class="text-muted mb-0">Comments coming soon.</p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>"""

    new_tail = """                            <?php if ($castNames): ?>
                            <div>
                                <div>Cast:</div>
                                <span><?= e(implode(', ', $castNames)) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
                <div class="clearfix"></div>

                <?php if ($castList): ?>
                <div class="section watch-cast" style="margin-top:20px;">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">Cast</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="cast-row">
                            <?php foreach ($castList as $cast):
                                $cname = (string) ($cast['name'] ?? '');
                                if ($cname === '') {
                                    continue;
                                }
                                $crole = (string) ($cast['character'] ?? '');
                                $cimg = img_url($cast['profile_path'] ?? null, 'w185');
                            ?>
                            <div class="cast-item">
                                <img class="lazyload" data-src="<?= e($cimg) ?>" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='100' height='140'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E" alt="<?= e($cname) ?>" width="100" height="140" loading="lazy" decoding="async">
                                <div class="name"><?= e($cname) ?></div>
                                <?php if ($crole !== ''): ?><div class="character text-muted"><?= e($crole) ?></div><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($similar): ?>
                <div class="section watch-similar" style="margin-top:20px;">
                    <div class="head">
                        <div class="start">
                            <h2 class="title gardiently">More like this</h2>
                        </div>
                    </div>
                    <div class="body">
                        <div class="scaff movies items">
                            <?php
                            foreach (array_slice($similar, 0, 8) as $simItem) {
                                $item = $simItem;
                                $type = $watchType;
                                if (isset($simItem['media_type']) && in_array($simItem['media_type'], ['movie', 'tv'], true)) {
                                    $type = $simItem['media_type'];
                                } elseif (isset($simItem['name']) && !isset($simItem['title'])) {
                                    $type = 'tv';
                                }
                                require __DIR__ . '/../partials/movie-card.php';
                            }
                            $item = $watchItem;
                            $type = $watchType;
                            unset($simItem);
                            ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="section watch-report" style="margin-top:20px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-toggle="modal" data-target="#report">
                        <i class="uil uil-exclamation-triangle"></i> Report an issue
                    </button>
                </div>
            </aside>
        </div>
    </div>
</main>"""

    if old_cast_detail not in text:
        raise SystemExit("tail/comments block not found")
    text = text.replace(old_cast_detail, new_tail, 1)

    WATCH.write_text(text)
    print("patched watch.php")


def patch_layout_schema() -> None:
    text = LAYOUT.read_text()
    old = """    <?php if (($seo['schema'] ?? '') === 'watch' && isset($item, $type, $title)): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => $type === 'tv' ? 'TVSeries' : 'Movie',
        'name' => $title,
        'description' => $overview ?? $desc,
        'image' => $image,
        'datePublished' => date_of($item) ?: null,
        'aggregateRating' => isset($item['vote_average']) && $item['vote_average'] > 0 ? [
            '@type' => 'AggregateRating',
            'ratingValue' => round((float) $item['vote_average'], 1),
            'ratingCount' => (int) ($item['vote_count'] ?? 1),
            'bestRating' => 10,
            'worstRating' => 1,
        ] : null,
        'url' => $canonical,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>"""

    new = """    <?php if (($seo['schema'] ?? '') === 'watch' && isset($item, $type, $title)): ?>
    <?php
        $schemaActors = [];
        foreach (array_slice($item['credits']['cast'] ?? [], 0, 8) as $schemaCast) {
            if (empty($schemaCast['name'])) {
                continue;
            }
            $schemaActors[] = [
                '@type' => 'Person',
                'name' => $schemaCast['name'],
            ];
        }
        $schemaDirectors = [];
        foreach ($item['credits']['crew'] ?? [] as $schemaCrew) {
            if (($schemaCrew['job'] ?? '') === 'Director' && !empty($schemaCrew['name'])) {
                $schemaDirectors[] = [
                    '@type' => 'Person',
                    'name' => $schemaCrew['name'],
                ];
            }
        }
        $schemaGenres = array_values(array_filter(array_map(
            static fn ($g) => $g['name'] ?? null,
            $item['genres'] ?? []
        )));
        $schemaPayload = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $type === 'tv' ? 'TVSeries' : 'Movie',
            'name' => $title,
            'description' => $overview ?? $desc,
            'image' => $image,
            'datePublished' => date_of($item) ?: null,
            'genre' => $schemaGenres ?: null,
            'director' => $schemaDirectors ?: null,
            'actor' => $schemaActors ?: null,
            'duration' => ($type === 'movie' && !empty($item['runtime']))
                ? 'PT' . (int) $item['runtime'] . 'M'
                : null,
            'aggregateRating' => isset($item['vote_average']) && $item['vote_average'] > 0 ? [
                '@type' => 'AggregateRating',
                'ratingValue' => round((float) $item['vote_average'], 1),
                'ratingCount' => (int) ($item['vote_count'] ?? 1),
                'bestRating' => 10,
                'worstRating' => 1,
            ] : null,
            'url' => $canonical,
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');
    ?>
    <script type="application/ld+json">
    <?= json_encode($schemaPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>"""

    if old not in text:
        raise SystemExit("schema block not found")
    LAYOUT.write_text(text.replace(old, new, 1))
    print("patched main.php schema")


def patch_css() -> None:
    css = APP_CSS.read_text()
    marker = "/* watch-page polish */"
    if marker in css:
        print("css already polished")
        return
    css += """

/* watch-page polish */
.player-trailer-label {
  position: absolute;
  left: 1rem;
  bottom: 1rem;
  z-index: 2;
  padding: 0.35rem 0.7rem;
  border-radius: 0.5rem;
  background: rgba(20, 23, 31, 0.78);
  color: #e9ecef;
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  pointer-events: none;
}
#movie-player.playing .player-trailer-label {
  display: none;
}
.genre-links a {
  color: #e9ecef;
  text-decoration: underline;
  text-decoration-color: rgba(233, 236, 239, 0.35);
  text-underline-offset: 0.15em;
}
.genre-links a:hover {
  color: #fff;
  text-decoration-color: #dc3545;
}
.cast-item .character {
  font-size: 0.72rem;
  line-height: 1.25;
  margin-top: 0.15rem;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.watch-report .btn {
  border-color: rgba(255, 255, 255, 0.18);
  color: #cfd3da;
}
.watch-report .btn:hover {
  border-color: #dc3545;
  color: #fff;
}
@media (max-width: 767.98px) {
  .cast-item {
    width: 88px;
  }
  .cast-item img {
    width: 88px;
    height: 124px;
  }
}
"""
    APP_CSS.write_text(css)
    print("patched app.css")


def bump_asset_versions() -> None:
    layout = LAYOUT.read_text()
    layout2 = layout.replace("?v=20260731-sidedupe1", "?v=20260731-watchpolish1")
    if layout2 == layout:
        # also try if already bumped partially
        if "watchpolish1" not in layout:
            raise SystemExit("asset version string not found")
    else:
        LAYOUT.write_text(layout2)
        print("bumped asset versions in layout")


if __name__ == "__main__":
    patch_watch()
    patch_layout_schema()
    patch_css()
    bump_asset_versions()
    print("done")
