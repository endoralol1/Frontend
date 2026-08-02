#!/usr/bin/env python3
"""Bigger Browse (no scroll) + Settings: Autoplay, Watchlist, Continue Watching."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui67"

CSS_BLOCK = """
/* ——— Browse bigger + settings prefs (ui67) ——— */
@media (max-width: 991.98px) {
  .browse-sheet-panel {
    left: 0.65rem !important;
    right: 0.65rem !important;
    bottom: calc(4.55rem + env(safe-area-inset-bottom, 0px)) !important;
    max-height: calc(100dvh - 4.75rem - env(safe-area-inset-bottom, 0px) - 0.45rem) !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    scrollbar-width: none !important;
  }

  .browse-sheet-panel::-webkit-scrollbar { display: none !important; width: 0 !important; }

  .browse-sheet-body {
    padding: 0.15rem 0.85rem 0.75rem !important;
    overflow: visible !important;
    max-height: none !important;
  }

  .browse-section + .browse-section {
    margin-top: 0.7rem !important;
  }

  .browse-section-title {
    margin: 0 0 0.4rem !important;
  }

  .browse-grid {
    gap: 0.45rem !important;
  }

  .browse-tile {
    min-height: 4.35rem !important;
    padding: 0.55rem 0.3rem !important;
  }

  .browse-card {
    min-height: 3.55rem !important;
    padding: 0.6rem 0.7rem !important;
  }

  .browse-list-item {
    min-height: 2.55rem !important;
  }

  .browse-auth-cta-card,
  .browse-auth-user-card {
    min-height: 3.15rem !important;
  }
}

.browse-sheet.settings-open .browse-sheet-panel,
.browse-sheet.auth-open .browse-sheet-panel {
  max-height: calc(100dvh - 4.75rem - env(safe-area-inset-bottom, 0px) - 0.45rem) !important;
  overflow: auto !important;
}

@media (min-width: 992px) {
  .browse-sheet-panel {
    width: min(28.5rem, calc(100vw - 2.5rem)) !important;
  }

  .browse-sheet.settings-open .browse-sheet-panel,
  .browse-sheet.auth-open .browse-sheet-panel {
    max-height: min(88vh, 46rem) !important;
    overflow: auto !important;
  }
}

.browse-settings-toggle-row + .browse-settings-toggle-row {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

.browse-card.is-pref-off,
.browse-list-item.is-pref-off {
  opacity: 0.42;
  pointer-events: none;
  filter: grayscale(0.25);
}

body.cf-watchlist-off .user-bookmark-toggle,
body.cf-watchlist-off #btn-favorite,
body.cf-watchlist-off .movie-manager.favorite {
  opacity: 0.45;
  pointer-events: none;
}

body.cf-continue-off #continue-watching {
  display: none !important;
}
"""

SETTINGS_TOGGLES = """
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
"""

JS_SETTINGS_REPLACE_FROM = r"""  function syncBrowseSettingsUi() {
    var lang = 'en';
    try { lang = localStorage.getItem('cf_lang') || 'en'; } catch (e) {}
    var $active = $('#language-menu a\[data-lang\].active, #language-menu li.active a\[data-lang\]').first();
    if ($active.length) lang = String($active.data('lang') || lang);
    var code = String($('#language-toggler .lang-code').text() || '').trim().toLowerCase();
    if (code) {
      var map = { en: 'en', es: 'es', it: 'it', fr: 'fr', de: 'de', pt: 'pt', ru: 'ru' };
      if (map\[code\]) lang = map\[code\];
    }
    $('#browse-settings-langs .browse-settings-lang').each(function () {
      var on = String($(this).data('lang') || '') === lang;
      $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
    });
    var autoOn = false;
    try { autoOn = localStorage.getItem('cf_watch_autoplay') === '1'; } catch (e) {}
    if (!autoOn) autoOn = /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
    $('#browse-settings-autoplay').attr('aria-checked', autoOn ? 'true' : 'false');
  }"""

# We'll do simpler string replace without regex escaping issues in the Python file itself.


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_settings_html() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()

    # Mark personal cards for pref gating
    text = text.replace(
        '<a class="browse-card" href="<?= e(url(\'/favorites\')) ?>">',
        '<a class="browse-card" href="<?= e(url(\'/favorites\')) ?>" data-browse-pref="watchlist">',
        1,
    )
    text = text.replace(
        'data-browse-open="history">',
        'data-browse-open="history" data-browse-pref="continue">',
        1,
    )

    old_toggle = """                    <section class="browse-settings-block">
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Auto Next</strong>
                                <em>Play the next episode when one ends</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autoplay" role="switch" aria-checked="false" aria-label="Auto Next"></button>
                        </div>
                    </section>"""

    if 'id="browse-settings-watchlist"' in text:
        print("settings toggles already expanded")
    elif old_toggle in text:
        text = text.replace(old_toggle, SETTINGS_TOGGLES.strip() + "\n", 1)
        print("settings toggles expanded")
    else:
        raise SystemExit("old Auto Next settings block not found")

    path.write_text(text)


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    text = path.read_text()

    # Raise base mobile max-heights (non-!important baselines)
    text = text.replace(
        "max-height: min(78vh, 40rem);",
        "max-height: calc(100dvh - 4.75rem - env(safe-area-inset-bottom, 0px) - 0.45rem);",
    )
    # Desktop centered search can keep its own; avoid changing search if shared — only browse later via CSS_BLOCK

    if "Browse bigger + settings prefs (ui67)" in text:
        # refresh block
        text = re.sub(
            r"/\* ——— Browse bigger \+ settings prefs \(ui66\) ——— \*/[\s\S]*?(?=\n/\* ———|\n@media|\Z)",
            CSS_BLOCK.strip() + "\n\n",
            text,
            count=1,
        )
        print("css block refreshed")
    else:
        text = text.rstrip() + "\n" + CSS_BLOCK + "\n"
        print("css block added")

    # Widen desktop dropdown in existing desktop-browse-dropdown section
    text2, n = re.subn(
        r"(width:\s*min\()24rem(,\s*calc\(100vw - 2\.5rem\)\)\s*!important;)",
        r"\g<1>28.5rem\2",
        text,
        count=2,
    )
    if n:
        text = text2
        print(f"desktop browse width bumped x{n}")

    path.write_text(text)


def patch_app_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()

    # Gate favorites
    if "Watchlist is turned off" not in text:
        old = """  function toggleFav($btn) {
    var id = favIdFromBtn($btn);
    if (!id) {
      showFavToast('Could not favorite this title');
      return;
    }"""
        new = """  function toggleFav($btn) {
    if (typeof prefEnabled === 'function' && !prefEnabled('cf_pref_watchlist', true)) {
      showFavToast('Watchlist is turned off in Settings');
      return;
    }
    var id = favIdFromBtn($btn);
    if (!id) {
      showFavToast('Could not favorite this title');
      return;
    }"""
        if old not in text:
            raise SystemExit("toggleFav not found")
        text = text.replace(old, new, 1)
        print("toggleFav gated")

    # Gate seedContinueFromPlayer
    if "cf_pref_continue" not in text.split("function seedContinueFromPlayer")[1][:220]:
        old = """  function seedContinueFromPlayer() {
    try {
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;"""
        new = """  function seedContinueFromPlayer() {
    try {
      try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (e0) {}
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;"""
        if old not in text:
            raise SystemExit("seedContinueFromPlayer not found")
        text = text.replace(old, new, 1)
        print("seedContinueFromPlayer gated")

    # Replace settings sync + setters + handlers
    if "browse-settings-watchlist" not in text or "prefEnabled" not in text:
        # Remove old setBrowseAutoplay that also sets autonext, replace block from syncBrowseSettingsUi through autoplay click handler
        start = text.find("  function syncBrowseSettingsUi()")
        end = text.find("  $(document).on('click', '[data-browse-mock]'", start)
        if start < 0 or end < 0:
            raise SystemExit("settings js block bounds not found")
        new_block = r"""  function prefEnabled(key, defaultOn) {
    try {
      var v = localStorage.getItem(key);
      if (v == null || v === '') return !!defaultOn;
      return v !== '0';
    } catch (e) {
      return !!defaultOn;
    }
  }

  function setPrefEnabled(key, on) {
    on = !!on;
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    document.cookie = key + '=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
  }

  function applyBrowsePrefs() {
    var watchlistOn = prefEnabled('cf_pref_watchlist', true);
    var continueOn = prefEnabled('cf_pref_continue', true);
    document.body.classList.toggle('cf-watchlist-off', !watchlistOn);
    document.body.classList.toggle('cf-continue-off', !continueOn);
    $('[data-browse-pref="watchlist"]').toggleClass('is-pref-off', !watchlistOn);
    $('[data-browse-pref="continue"]').toggleClass('is-pref-off', !continueOn);
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
    } catch (e) {}
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e2) {}
  }

  function syncBrowseSettingsUi() {
    var lang = 'en';
    try { lang = localStorage.getItem('cf_lang') || 'en'; } catch (e) {}
    var $active = $('#language-menu a[data-lang].active, #language-menu li.active a[data-lang]').first();
    if ($active.length) lang = String($active.data('lang') || lang);
    var code = String($('#language-toggler .lang-code').text() || '').trim().toLowerCase();
    if (code) {
      var map = { en: 'en', es: 'es', it: 'it', fr: 'fr', de: 'de', pt: 'pt', ru: 'ru' };
      if (map[code]) lang = map[code];
    }
    $('#browse-settings-langs .browse-settings-lang').each(function () {
      var on = String($(this).data('lang') || '') === lang;
      $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
    });
    var autoOn = false;
    try { autoOn = localStorage.getItem('cf_watch_autoplay') === '1'; } catch (e) {}
    if (!autoOn) autoOn = /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
    var nextOn = true;
    try { nextOn = localStorage.getItem('cf_np_autonext') !== '0'; } catch (e) {}
    $('#browse-settings-autoplay').attr('aria-checked', autoOn ? 'true' : 'false');
    $('#browse-settings-autonext').attr('aria-checked', nextOn ? 'true' : 'false');
    $('#browse-settings-watchlist').attr('aria-checked', prefEnabled('cf_pref_watchlist', true) ? 'true' : 'false');
    $('#browse-settings-continue').attr('aria-checked', prefEnabled('cf_pref_continue', true) ? 'true' : 'false');
  }

  function openBrowseSettings() {
    closeBrowseAuth();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-settings-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('settings-open');
    syncBrowseSettingsUi();
  }

  function setBrowseLanguage(lang) {
    lang = String(lang || 'en').toLowerCase();
    try { localStorage.setItem('cf_lang', lang); } catch (e) {}
    document.cookie = 'cf_lang=' + encodeURIComponent(lang) + ';path=/;max-age=31536000;SameSite=Lax';
    $('#language-menu li').removeClass('active');
    $('#language-menu a[data-lang]').removeClass('active');
    var $link = $('#language-menu a[data-lang="' + lang + '"]');
    $link.addClass('active').closest('li').addClass('active');
    var label = ($link.text() || lang).trim();
    var code = lang.toUpperCase();
    $('#language-toggler .lang-code').text(code);
    $('#language-toggler').attr('title', 'Language: ' + label);
    syncBrowseSettingsUi();
  }

  function setBrowseAutoplay(on) {
    on = !!on;
    try { localStorage.setItem('cf_watch_autoplay', on ? '1' : '0'); } catch (e) {}
    document.cookie = 'cf_watch_autoplay=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
    if (typeof syncWatchAutoplayUi === 'function') syncWatchAutoplayUi();
    if (typeof window.__cfSyncWatchAutoplay === 'function') window.__cfSyncWatchAutoplay();
    syncBrowseSettingsUi();
  }

  function setBrowseAutoNext(on) {
    on = !!on;
    try { localStorage.setItem('cf_np_autonext', on ? '1' : '0'); } catch (e) {}
    syncBrowseSettingsUi();
  }

  $(document).on('click', '[data-browse-open="settings"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openBrowseSettings();
  });

  $(document).on('click', '#browse-settings-back', function (e) {
    e.preventDefault();
    closeBrowseSettings();
  });

  $(document).on('click', '#browse-settings-langs .browse-settings-lang', function (e) {
    e.preventDefault();
    setBrowseLanguage($(this).data('lang') || 'en');
  });

  $(document).on('click', '#browse-settings-autoplay', function (e) {
    e.preventDefault();
    setBrowseAutoplay($(this).attr('aria-checked') !== 'true');
  });

  $(document).on('click', '#browse-settings-autonext', function (e) {
    e.preventDefault();
    setBrowseAutoNext($(this).attr('aria-checked') !== 'true');
  });

  $(document).on('click', '#browse-settings-watchlist', function (e) {
    e.preventDefault();
    setPrefEnabled('cf_pref_watchlist', $(this).attr('aria-checked') !== 'true');
    applyBrowsePrefs();
    syncBrowseSettingsUi();
  });

  $(document).on('click', '#browse-settings-continue', function (e) {
    e.preventDefault();
    setPrefEnabled('cf_pref_continue', $(this).attr('aria-checked') !== 'true');
    applyBrowsePrefs();
    syncBrowseSettingsUi();
  });

"""
        # Keep closeBrowseSettings before sync — find closeBrowseSettings and keep it
        close_start = text.rfind("  function closeBrowseSettings()", 0, start)
        if close_start < 0:
            raise SystemExit("closeBrowseSettings missing before sync")
        # Replace from syncBrowseSettingsUi to mock handler; keep closeBrowseSettings
        text = text[:start] + new_block + text[end:]
        print("settings js replaced")

        # Ensure applyBrowsePrefs on ready
        if "applyBrowsePrefs()" not in text.split("$(function () {")[-1][:500]:
            text = text.replace(
                "  $(function () {\n    updateFavCounter();\n    initSwiper();",
                "  $(function () {\n    applyBrowsePrefs();\n    updateFavCounter();\n    initSwiper();",
                1,
            )
            print("applyBrowsePrefs on ready")
    else:
        print("settings js already expanded")

    # Also apply prefs when opening browse
    if "applyBrowsePrefs();" not in text.split("openBrowseSheet = function")[1][:500]:
        text = text.replace(
            "  openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();\n    refreshBrowseAuthUser();\n  };",
            "  openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();\n    applyBrowsePrefs();\n    refreshBrowseAuthUser();\n  };",
            1,
        )
        print("openBrowseSheet applies prefs")

    path.write_text(text)


def patch_continue_party() -> None:
    path = ROOT / "public/assets/js/continue-party.js"
    text = path.read_text()
    old = """  function renderContinueRail() {
    var $rail = $("#continue-watching");
    if (!$rail.length) return;
    var items = readContinue().slice(0, 24);"""
    new = """  function renderContinueRail() {
    var $rail = $("#continue-watching");
    if (!$rail.length) return;
    try {
      if (localStorage.getItem("cf_pref_continue") === "0") {
        $rail.attr("hidden", true).addClass("d-none").css("display", "none");
        $rail.find(".media-rail-items").empty().removeClass("cw-track");
        return;
      }
    } catch (e) {}
    var items = readContinue().slice(0, 24);"""
    if 'cf_pref_continue' in text.split("function renderContinueRail")[1][:350]:
        print("continue-party already gated")
    elif old in text:
        path.write_text(text.replace(old, new, 1))
        print("continue-party gated")
    else:
        raise SystemExit("renderContinueRail not found")


def patch_player_js() -> None:
    path = ROOT / "public/assets/js/player.js"
    text = path.read_text()
    old = """  function cwSave(cfg, watched, duration, opts) {
    const id = Number(cfg.id) || 0;
    if (!id) return false;"""
    new = """  function cwSave(cfg, watched, duration, opts) {
    try {
      if (localStorage.getItem("cf_pref_continue") === "0") return false;
    } catch (_) {}
    const id = Number(cfg.id) || 0;
    if (!id) return false;"""
    if 'cf_pref_continue' in text.split("function cwSave")[1][:220]:
        print("player cwSave already gated")
    elif old in text:
        path.write_text(text.replace(old, new, 1))
        print("player cwSave gated")
    else:
        raise SystemExit("cwSave not found")


def patch_watch_seed() -> None:
    path = ROOT / "app/Views/pages/watch.php"
    text = path.read_text()
    needle = "          try {\n            var cfg = window.PLAYER;\n            if (!cfg || !cfg.id) return;"
    insert = (
        "          try {\n"
        "            try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (ePref) {}\n"
        "            var cfg = window.PLAYER;\n"
        "            if (!cfg || !cfg.id) return;"
    )
    if "cf_pref_continue" in text.split("cw-seed-on-watch-page")[1][:400]:
        print("watch seed already gated")
        return
    if needle not in text:
        raise SystemExit("watch seed block not found")
    path.write_text(text.replace(needle, insert, 1))
    print("watch seed gated")


def bump_assets() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    routes = ROOT / "app/routes.php"
    r = routes.read_text()
    r2, n = re.subn(
        r"asset\('js/player\.js'\)\s*\.\s*'\?v=[^']+'",
        f"asset('js/player.js') . '?v={ASSET_V}'",
        r,
    )
    r2, n2 = re.subn(
        r"asset\('css/player\.css'\)\s*\.\s*'\?v=[^']+'",
        f"asset('css/player.css') . '?v={ASSET_V}'",
        r2,
    )
    routes.write_text(r2)
    print(f"routes player bump js={n} css={n2}")
    player_layout = ROOT / "app/Views/layouts/player.php"
    if player_layout.exists():
        player_layout.write_text(bump(player_layout.read_text()))
        # also handle player1 style versions
        pl = player_layout.read_text()
        pl = re.sub(
            r"(\?>\?v=)20260801-[^\"]+",
            rf"\g<1>{ASSET_V}",
            pl,
        )
        player_layout.write_text(pl)
    print("asset", ASSET_V)


def main() -> None:
    patch_settings_html()
    patch_css()
    patch_app_js()
    patch_continue_party()
    patch_player_js()
    patch_watch_seed()
    bump_assets()


if __name__ == "__main__":
    main()
