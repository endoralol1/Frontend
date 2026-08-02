#!/usr/bin/env python3
"""Add Browse Settings above Log out (signed-in) and below Create account (guest)."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui65"

SETTINGS_BTN = """                    <button type="button" class="browse-list-item" data-browse-open="settings">
                        <i class="uil uil-setting" aria-hidden="true"></i>
                        <span>Settings</span>
                        <i class="uil uil-angle-right" aria-hidden="true"></i>
                    </button>"""

SETTINGS_VIEW = """
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
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Auto Next</strong>
                                <em>Play the next episode when one ends</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autoplay" role="switch" aria-checked="false" aria-label="Auto Next"></button>
                        </div>
                    </section>
                </div>
            </div>
"""

CSS_EXTRA = """
/* ——— Browse settings ——— */
.browse-home-view[hidden],
.browse-auth-view[hidden],
.browse-settings-view[hidden] {
  display: none !important;
}

.browse-settings-view {
  padding: 0.15rem 0 0.35rem;
  animation: browseAuthIn 0.22s ease;
}

.browse-settings-heading {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.browse-settings-block {
  margin-top: 0.85rem;
  padding: 0.85rem 0.9rem;
  border-radius: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
}

.browse-settings-label {
  margin: 0 0 0.65rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.45);
}

.browse-settings-langs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.browse-settings-lang {
  appearance: none;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.82);
  border-radius: 999px;
  padding: 0.4rem 0.7rem;
  font: inherit;
  font-size: 0.78rem;
  font-weight: 600;
  cursor: pointer;
}

.browse-settings-lang.is-active {
  color: #fff;
  border-color: rgba(220, 53, 69, 0.55);
  background: linear-gradient(135deg, rgba(220, 53, 69, 0.35), rgba(219, 105, 55, 0.22));
}

.browse-settings-toggle-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.browse-settings-toggle-copy {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
  flex: 1;
}

.browse-settings-toggle-copy strong {
  font-size: 0.9rem;
  font-weight: 700;
}

.browse-settings-toggle-copy em {
  font-style: normal;
  font-size: 0.68rem;
  color: rgba(255, 255, 255, 0.5);
}

.browse-settings-switch {
  position: relative;
  width: 2.7rem;
  height: 1.55rem;
  border: 0;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  flex-shrink: 0;
  cursor: pointer;
  padding: 0;
}

.browse-settings-switch::after {
  content: "";
  position: absolute;
  top: 0.18rem;
  left: 0.18rem;
  width: 1.18rem;
  height: 1.18rem;
  border-radius: 999px;
  background: #fff;
  transition: transform 0.18s ease, background 0.18s ease;
}

.browse-settings-switch[aria-checked="true"] {
  background: linear-gradient(135deg, #dc3545, #db6937);
}

.browse-settings-switch[aria-checked="true"]::after {
  transform: translateX(1.15rem);
}

.browse-sheet.settings-open .browse-sheet-panel,
.browse-sheet.auth-open .browse-sheet-panel {
  max-height: min(86vh, 46rem);
}

#browse-auth-guest .browse-list-item,
#browse-auth-user .browse-list-item {
  width: 100%;
}
"""

JS_SNIPPET = r"""
  // ——— Browse settings ———
  function closeBrowseSettings() {
    var $view = $('#browse-settings-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('settings-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
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
    $('#browse-settings-autoplay').attr('aria-checked', autoOn ? 'true' : 'false');
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
    try { localStorage.setItem('cf_np_autonext', on ? '1' : '0'); } catch (e) {}
    if (typeof syncWatchAutoplayUi === 'function') syncWatchAutoplayUi();
    if (typeof window.__cfSyncWatchAutoplay === 'function') window.__cfSyncWatchAutoplay();
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
    var on = $(this).attr('aria-checked') !== 'true';
    setBrowseAutoplay(on);
  });
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_bottom_nav() -> None:
    path = ROOT / "app/Views/partials/bottom-nav.php"
    text = path.read_text()

    if 'data-browse-open="settings"' not in text:
        # Guest: Settings below Create account block (end of browse-auth-guest)
        guest_end = '                </div>\n                <div class="browse-auth-user" id="browse-auth-user" hidden>'
        guest_insert = (
            SETTINGS_BTN
            + "\n                </div>\n                <div class=\"browse-auth-user\" id=\"browse-auth-user\" hidden>"
        )
        if guest_end not in text:
            raise SystemExit("guest account block end not found")
        text = text.replace(guest_end, guest_insert, 1)

        # Signed-in: Settings above Log out
        logout = (
            '                    <button type="button" class="browse-list-item" id="browse-auth-logout">'
        )
        if logout not in text:
            raise SystemExit("logout button not found")
        text = text.replace(logout, SETTINGS_BTN + "\n" + logout, 1)
        print("settings buttons inserted")
    else:
        print("settings buttons already present")

    if 'id="browse-settings-view"' not in text:
        marker = '            <div class="browse-auth-view" id="browse-auth-view" hidden>'
        if marker not in text:
            raise SystemExit("browse-auth-view not found")
        # Insert settings view after auth view closes — find closing of auth view
        # Structure: auth-view ... </div></div> then </div> of sheet-body
        auth_start = text.find(marker)
        if auth_start < 0:
            raise SystemExit("auth view missing")
        # Insert immediately before browse-auth-view so settings is a sibling of home/auth
        text = text[:auth_start] + SETTINGS_VIEW.strip() + "\n\n" + text[auth_start:]
        print("settings view inserted")
    else:
        print("settings view already present")

    path.write_text(text)


def patch_css() -> None:
    path = ROOT / "public/assets/css/app.css"
    text = path.read_text()
    # Expand hidden rule if old one exists
    text = text.replace(
        ".browse-home-view[hidden],\n.browse-auth-view[hidden] {\n  display: none !important;\n}",
        ".browse-home-view[hidden],\n.browse-auth-view[hidden],\n.browse-settings-view[hidden] {\n  display: none !important;\n}",
    )
    if "Browse settings" not in text and "browse-settings-view {" not in text:
        text = text.rstrip() + "\n" + CSS_EXTRA + "\n"
        print("settings css added")
    else:
        print("settings css already present")
    path.write_text(text)


def patch_js() -> None:
    path = ROOT / "public/assets/js/app.js"
    text = path.read_text()

    if "openBrowseSettings" not in text:
        # Insert before browse mock handler or after logout handler
        anchor = "  $(document).on('click', '[data-browse-mock]', function (e) {"
        if anchor not in text:
            raise SystemExit("browse-mock handler not found")
        text = text.replace(anchor, JS_SNIPPET.rstrip() + "\n\n" + anchor, 1)
        print("settings js added")
    else:
        print("settings js already present")

    # Ensure open/close browse sheet also closes settings
    if "closeBrowseSettings()" not in text.split("openBrowseSheet = function")[1][:400]:
        # Patch the wrapped openBrowseSheet
        old_open = (
            "  openBrowseSheet = function () {\n"
            "    _openBrowseSheet();\n"
            "    closeBrowseAuth();\n"
            "    refreshBrowseAuthUser();\n"
            "  };"
        )
        new_open = (
            "  openBrowseSheet = function () {\n"
            "    _openBrowseSheet();\n"
            "    closeBrowseAuth();\n"
            "    closeBrowseSettings();\n"
            "    refreshBrowseAuthUser();\n"
            "  };"
        )
        if old_open in text:
            text = text.replace(old_open, new_open, 1)
            print("openBrowseSheet closes settings")
        else:
            print("WARN: openBrowseSheet wrap not matched")

        old_close = (
            "  closeBrowseSheet = function () {\n"
            "    closeBrowseAuth();\n"
            "    _closeBrowseSheet();\n"
            "  };"
        )
        new_close = (
            "  closeBrowseSheet = function () {\n"
            "    closeBrowseAuth();\n"
            "    closeBrowseSettings();\n"
            "    _closeBrowseSheet();\n"
            "  };"
        )
        if old_close in text:
            text = text.replace(old_close, new_close, 1)
            print("closeBrowseSheet closes settings")
        else:
            print("WARN: closeBrowseSheet wrap not matched")

    # Also close settings when opening auth
    if "closeBrowseSettings()" not in text.split("function openBrowseAuth")[1][:350]:
        old = (
            "  function openBrowseAuth(mode) {\n"
            "    $('#browse-home-view').attr('hidden', true);\n"
            "    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');\n"
            "    $('#browse-sheet').addClass('auth-open');\n"
            "    setBrowseAuthMode(mode || 'login');\n"
            "  }"
        )
        new = (
            "  function openBrowseAuth(mode) {\n"
            "    closeBrowseSettings();\n"
            "    $('#browse-home-view').attr('hidden', true);\n"
            "    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');\n"
            "    $('#browse-sheet').addClass('auth-open');\n"
            "    setBrowseAuthMode(mode || 'login');\n"
            "  }"
        )
        if old in text:
            text = text.replace(old, new, 1)
            print("openBrowseAuth closes settings")
        else:
            print("WARN: openBrowseAuth not matched")

    path.write_text(text)


def main() -> None:
    patch_bottom_nav()
    patch_css()
    patch_js()
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
