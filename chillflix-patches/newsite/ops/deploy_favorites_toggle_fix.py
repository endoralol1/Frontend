#!/usr/bin/env python3
"""Fix favorites toggle: clear UI feedback + reliable id/attr handling."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui56"


FAV_FUNCS = r'''
  var FAV_KEY = 'user_bookmarks';

  function favStore() {
    try {
      return JSON.parse(localStorage.getItem(FAV_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function saveFavs(map) {
    try {
      localStorage.setItem(FAV_KEY, JSON.stringify(map));
    } catch (e) {
      showFavToast('Could not save favorite (storage blocked)');
      return false;
    }
    updateFavCounter();
    return true;
  }

  function favIdFromBtn($btn) {
    var raw = $btn.attr('data-id');
    if (raw == null || raw === '') raw = $btn.data('id');
    return String(raw == null ? '' : raw);
  }

  function syncFavButton($btn) {
    var id = favIdFromBtn($btn);
    if (!id) return;
    var on = !!favStore()[id];
    $btn.toggleClass('is-fav', on);
    $btn.attr('aria-pressed', on ? 'true' : 'false');
    $btn.attr('aria-label', on ? 'Remove from favorites' : 'Add to favorites');
    var $icon = $btn.find('i').first();
    if ($icon.length) {
      $icon.removeClass('uil-plus-circle uil-minus-circle uil-heart uil-heart-break');
      $icon.addClass(on ? 'uil-heart' : 'uil-plus-circle');
    }
    var $label = $btn.find('span.ml-1, .fav-label').first();
    if ($label.length) {
      $label.text(on ? 'Favorited' : 'Favorite');
    }
  }

  function showFavToast(msg) {
    var $note = $('#cf-fav-toast');
    if (!$note.length) {
      $note = $('<div id="cf-fav-toast" class="cf-fav-toast" role="status" aria-live="polite"></div>').appendTo('body');
    }
    $note.text(msg).addClass('is-visible');
    clearTimeout(window.__cfFavToastTimer);
    window.__cfFavToastTimer = setTimeout(function () {
      $note.removeClass('is-visible');
    }, 1600);
  }

  function updateFavCounter() {
    var n = Object.keys(favStore()).length;
    var $c = $('.favorites-counter');
    if (!n) {
      $c.attr('hidden', true).text('0');
    } else {
      $c.removeAttr('hidden').text(String(n));
    }
    $('.user-bookmark-toggle').each(function () {
      syncFavButton($(this));
    });
  }

  function toggleFav($btn) {
    var id = favIdFromBtn($btn);
    if (!id) {
      showFavToast('Could not favorite this title');
      return;
    }
    var type = $btn.attr('data-media-type') || $btn.data('media-type') || $btn.data('mediaType') || 'movie';
    type = String(type) === 'tv' ? 'tv' : 'movie';
    var map = favStore();
    var removing = !!map[id];
    if (removing) {
      delete map[id];
    } else {
      map[id] = {
        id: id,
        type: type,
        mediaType: type,
        title: $btn.attr('data-title') || $btn.data('title') || '',
        name: $btn.attr('data-title') || $btn.data('title') || '',
        poster: $btn.attr('data-poster') || $btn.data('poster') || '',
        poster_path: null,
        year: $btn.attr('data-year') || $btn.data('year') || '',
        saved_at: new Date().toISOString()
      };
    }
    if (!saveFavs(map)) return;
    syncFavButton($btn);
    showFavToast(removing ? 'Removed from favorites' : 'Added to favorites');
  }

'''


def patch_app_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()

    # Replace from FAV_KEY through end of old toggleFav function
    pattern = re.compile(
        r"  var FAV_KEY = 'user_bookmarks';\n"
        r"\n"
        r"  function favStore\(\) \{[\s\S]*?"
        r"  function toggleFav\(\$btn\) \{[\s\S]*?\n  \}\n",
        re.M,
    )
    if not pattern.search(text):
        raise SystemExit("favorites functions block not found")
    text = pattern.sub(FAV_FUNCS.lstrip("\n"), text, count=1)
    print("favorites functions replaced")

    # Ensure tip HTML uses heart-ready markup (icon class stays plus; sync updates it)
    # Make watch bookmark a real button if still a div
    watch = ROOT / "app/Views/pages/watch.php"
    w = watch.read_text()
    old_div = """                            <div class=\"bookmark movie-manager user-bookmark-toggle\"
                                 data-id=\"<?= (int) $item['id'] ?>\" data-media-type=\"<?= e($type) ?>\"
                                 data-title=\"<?= e($title) ?>\" data-poster=\"<?= e(img_url($item['poster_path'] ?? null, 'w185')) ?>\"
                                 data-year=\"<?= e($year) ?>\">
                                <i class=\"uil uil-plus-circle\"></i><span class=\"ml-1\">Favorite</span>
                            </div>"""
    new_btn = """                            <button type=\"button\" class=\"bookmark movie-manager user-bookmark-toggle\"
                                 data-id=\"<?= (int) $item['id'] ?>\" data-media-type=\"<?= e($type) ?>\"
                                 data-title=\"<?= e($title) ?>\" data-poster=\"<?= e(img_url($item['poster_path'] ?? null, 'w185')) ?>\"
                                 data-year=\"<?= e($year) ?>\" aria-pressed=\"false\" aria-label=\"Add to favorites\">
                                <i class=\"uil uil-plus-circle\" aria-hidden=\"true\"></i><span class=\"ml-1 fav-label\">Favorite</span>
                            </button>"""
    if "fav-label\">Favorite</span>" in w and "<button type=\"button\" class=\"bookmark movie-manager user-bookmark-toggle\"" in w:
        print("watch bookmark button exists")
    elif old_div in w:
        watch.write_text(w.replace(old_div, new_btn, 1))
        print("watch bookmark converted to button")
    else:
        # looser
        w2, n = re.subn(
            r'<div class="bookmark movie-manager user-bookmark-toggle"([\s\S]*?)</div>',
            r'<button type="button" class="bookmark movie-manager user-bookmark-toggle"\1</button>',
            w,
            count=1,
        )
        if n:
            w2 = w2.replace(
                '<i class="uil uil-plus-circle"></i><span class="ml-1">Favorite</span>',
                '<i class="uil uil-plus-circle" aria-hidden="true"></i><span class="ml-1 fav-label">Favorite</span>',
                1,
            )
            if "aria-pressed" not in w2.split("user-bookmark-toggle", 1)[1][:200]:
                w2 = w2.replace(
                    'class="bookmark movie-manager user-bookmark-toggle"',
                    'class="bookmark movie-manager user-bookmark-toggle" aria-pressed="false" aria-label="Add to favorites"',
                    1,
                )
            watch.write_text(w2)
            print("watch bookmark converted (regex)")
        else:
            print("WARN watch bookmark not converted")

    # snippet file if present
    snip = ROOT / "app/Views/partials/watch-managers.php"
    # also patch local snippet path used historically
    for snip in [
        ROOT / "app/Views/partials/watch-managers.php",
    ]:
        if snip.is_file():
            st = snip.read_text()
            if "bookmark movie-manager user-bookmark-toggle" in st and "<div class=\"bookmark" in st:
                st = st.replace('<div class="bookmark movie-manager user-bookmark-toggle"', '<button type="button" class="bookmark movie-manager user-bookmark-toggle"', 1)
                st = st.replace("</div>\n                            <button type=\"button\" class=\"movie-manager share-btn", "</button>\n                            <button type=\"button\" class=\"movie-manager share-btn", 1)
                snip.write_text(st)
                print("patched", snip)

    # After tip HTML inject, sync fav button state
    tip_done = """            tipCache[key] = data;
            instance.content(buildTipHtml(data));
          })"""
    tip_done_new = """            tipCache[key] = data;
            instance.content(buildTipHtml(data));
            try { updateFavCounter(); } catch (e) {}
          })"""
    if "instance.content(buildTipHtml(data));\n            try { updateFavCounter(); }" not in text:
        if tip_done in text:
            text = text.replace(tip_done, tip_done_new, 1)
            print("tip sync patched")
        else:
            print("WARN tip sync not patched")
    else:
        print("tip sync exists")

    path.write_text(text)


def patch_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    marker = "/* favorites-toggle-fix */"
    body = """#movie-managers .user-bookmark-toggle.is-fav,
.user-bookmark-toggle.is-fav {
  color: #fff !important;
  background: rgba(220, 53, 69, 0.2) !important;
  border-color: rgba(220, 53, 69, 0.5) !important;
}
#movie-managers .user-bookmark-toggle.is-fav i,
.user-bookmark-toggle.is-fav i {
  color: #ff5c6a !important;
}
.cf-fav-toast {
  position: fixed;
  left: 50%;
  bottom: calc(5.6rem + env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%) translateY(8px);
  z-index: 220;
  max-width: 90vw;
  padding: 0.55rem 0.95rem;
  border-radius: 999px;
  background: rgba(20, 22, 30, 0.94);
  border: 1px solid rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 650;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.18s ease, transform 0.18s ease;
  backdrop-filter: blur(10px);
}
.cf-fav-toast.is-visible {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}
@media (min-width: 992px) {
  .cf-fav-toast {
    bottom: 1.5rem;
  }
}
"""
    if marker in text:
        text = re.sub(
            r"/\* favorites-toggle-fix \*/[\s\S]*?(?=\n/\* [a-z]|\Z)",
            marker + "\n" + body + "\n",
            text,
            count=1,
        )
        print("css replaced")
    else:
        text = text.rstrip() + "\n\n" + marker + "\n" + body + "\n"
        print("css appended")
    css.write_text(text)


def bump() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout.read_text()))
    print("asset", ASSET_V)


def main() -> None:
    patch_app_js()
    patch_css()
    bump()
    print("deploy_favorites_toggle_fix done", ASSET_V)


if __name__ == "__main__":
    main()
