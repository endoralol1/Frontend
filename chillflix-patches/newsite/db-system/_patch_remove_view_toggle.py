#!/usr/bin/env python3
"""Remove list/grid view toggle from Movies/TV discover pages."""
from pathlib import Path
import re

discover = Path("/var/www/chillflix-newsite/app/Views/pages/discover.php")
layout = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
js = Path("/var/www/chillflix-newsite/public/assets/js/app.js")

t = discover.read_text()

t = t.replace(
    "$viewMode = (($_GET['view'] ?? 'grid') === 'list') ? 'list' : 'grid';",
    "$viewMode = 'grid';",
)

old_head_end = """                        <div class="end">
                            <div class="btn-group view-switcher" role="group">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-switcher <?= $viewMode === 'list' ? 'active' : '' ?>" data-view="list" aria-label="List view">
                                    <i class="uil uil-list-ul"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-switcher <?= $viewMode === 'grid' ? 'active' : '' ?>" data-view="grid" aria-label="Grid view">
                                    <i class="uil uil-apps"></i>
                                </button>
                            </div>
                        </div>
"""
if old_head_end not in t:
    raise SystemExit("view-switcher block not found")
t = t.replace(old_head_end, "")

old_anime = """                                <?php
                                $tvQs = array_filter(['view' => $viewMode !== 'grid' ? $viewMode : null]);
                                $movieQs = array_filter(['type' => 'movie', 'view' => $viewMode !== 'grid' ? $viewMode : null]);
                                ?>
                                <a class="anime-type-tab<?= !$isMovie ? ' is-active' : '' ?>" href="<?= e(url($path) . ($tvQs ? ('?' . http_build_query($tvQs)) : '')) ?>" role="tab" aria-selected="<?= !$isMovie ? 'true' : 'false' ?>">Series</a>
                                <a class="anime-type-tab<?= $isMovie ? ' is-active' : '' ?>" href="<?= e(url($path) . '?' . http_build_query($movieQs)) ?>" role="tab" aria-selected="<?= $isMovie ? 'true' : 'false' ?>">Movies</a>
"""
new_anime = """                                <a class="anime-type-tab<?= !$isMovie ? ' is-active' : '' ?>" href="<?= e(url($path)) ?>" role="tab" aria-selected="<?= !$isMovie ? 'true' : 'false' ?>">Series</a>
                                <a class="anime-type-tab<?= $isMovie ? ' is-active' : '' ?>" href="<?= e(url($path) . '?type=movie') ?>" role="tab" aria-selected="<?= $isMovie ? 'true' : 'false' ?>">Movies</a>
"""
if old_anime not in t:
    raise SystemExit("anime tabs block not found")
t = t.replace(old_anime, new_anime)

hidden = '                        <input type="hidden" name="view" id="view-mode-input" value="<?= e($viewMode) ?>">\n'
if hidden not in t:
    raise SystemExit("view hidden input not found")
t = t.replace(hidden, "")

discover.write_text(t)
print("discover.php: toggle removed, list locked")

js_t = js.read_text()
js_old = """  // Discover view switcher — update class + persist via query
  $(document).on('click', '.btn-view-switcher', function () {
    var view = $(this).data('view');
    $('.btn-view-switcher').removeClass('active');
    $(this).addClass('active');
    $('#view-mode-input').val(view);
    $('#discover-results').removeClass('view-grid view-list').addClass('view-' + view);
    var url = new URL(location.href);
    url.searchParams.set('view', view);
    history.replaceState(null, '', url.toString());
  });

"""
if js_old not in js_t:
    raise SystemExit("js switcher block not found")
js.write_text(js_t.replace(js_old, "", 1))
print("app.js: switcher handler removed")

lt = layout.read_text()
layout.write_text(re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui144", lt))
print("layout ui144")
print("DONE")
