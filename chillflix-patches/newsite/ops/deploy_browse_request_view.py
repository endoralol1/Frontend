#!/usr/bin/env python3
"""Open Request inside Browse sheet (like Settings), not a full page nav."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui85"

REQUEST_VIEW = r"""
            <div class="browse-request-view" id="browse-request-view" hidden>
                <div class="browse-request-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-request-back" aria-label="Back to browse">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-settings-heading">
                            <p class="browse-auth-kicker">Missing something?</p>
                            <h3 class="browse-auth-title" style="margin:0;">Request a title</h3>
                        </div>
                    </div>

                    <p class="browse-request-lead">Tell us the movie or show you want. We review requests regularly.</p>

                    <div class="browse-request-alert" id="browse-request-alert" hidden role="status">
                        <i class="uil uil-check-circle" aria-hidden="true"></i>
                        <div>
                            <strong>Request received</strong>
                            <span>Thanks — we’ll look into adding it soon.</span>
                        </div>
                    </div>

                    <form class="browse-request-form" id="browse-request-form" autocomplete="on">
                        <fieldset class="browse-request-type">
                            <legend>Type</legend>
                            <div class="browse-request-type-row" role="radiogroup" aria-label="Request type">
                                <label class="browse-request-type-opt">
                                    <input type="radio" name="type" value="movie" checked>
                                    <span><i class="uil uil-clapper-board" aria-hidden="true"></i> Movie</span>
                                </label>
                                <label class="browse-request-type-opt">
                                    <input type="radio" name="type" value="tv">
                                    <span><i class="uil uil-tv-retro" aria-hidden="true"></i> TV Show</span>
                                </label>
                            </div>
                        </fieldset>

                        <label class="browse-request-field">
                            <span>Title</span>
                            <input type="text" name="title" required maxlength="160" placeholder="e.g. Dune: Part Two" enterkeyhint="next">
                        </label>

                        <label class="browse-request-field">
                            <span>Year <em>(optional)</em></span>
                            <input type="text" name="year" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="2024" enterkeyhint="next">
                        </label>

                        <label class="browse-request-field">
                            <span>Notes <em>(optional)</em></span>
                            <textarea name="notes" rows="3" maxlength="800" placeholder="Season, language, or anything that helps"></textarea>
                        </label>

                        <button type="submit" class="browse-request-submit" id="browse-request-submit">
                            <span>Submit request</span>
                            <i class="uil uil-message" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
"""

CSS = r"""
/* ——— Browse request view (ui85) ——— */
.browse-home-view[hidden],
.browse-auth-view[hidden],
.browse-settings-view[hidden],
.browse-request-view[hidden] {
  display: none !important;
}

.browse-request-view {
  padding: 0.15rem 0 0.35rem;
  animation: browseAuthIn 0.22s ease;
}

.browse-request-lead {
  margin: 0.85rem 0 0.9rem;
  color: rgba(255, 255, 255, 0.58);
  font-size: 0.86rem;
  line-height: 1.4;
}

.browse-request-alert {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  margin: 0 0 0.9rem;
  padding: 0.8rem 0.85rem;
  border-radius: 1rem;
  border: 1px solid rgba(60, 180, 110, 0.35);
  background: rgba(40, 140, 85, 0.16);
  color: #d9ffe8;
}

.browse-request-alert[hidden] {
  display: none !important;
}

.browse-request-alert i {
  font-size: 1.25rem;
  line-height: 1;
  color: #7dffb0;
}

.browse-request-alert strong {
  display: block;
  margin-bottom: 0.12rem;
  color: #fff;
  font-size: 0.88rem;
}

.browse-request-alert span {
  display: block;
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.78rem;
  line-height: 1.35;
}

.browse-request-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.browse-request-type {
  margin: 0;
  padding: 0;
  border: 0;
}

.browse-request-type legend {
  margin-bottom: 0.4rem;
  color: rgba(255, 255, 255, 0.45);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.browse-request-type-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
}

.browse-request-type-opt {
  cursor: pointer;
}

.browse-request-type-opt input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.browse-request-type-opt span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  width: 100%;
  min-height: 2.55rem;
  padding: 0.4rem 0.55rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(255, 255, 255, 0.04);
  color: rgba(255, 255, 255, 0.8);
  font-size: 0.86rem;
  font-weight: 650;
}

.browse-request-type-opt input:checked + span {
  color: #fff;
  border-color: rgba(219, 105, 55, 0.55);
  background: linear-gradient(135deg, rgba(219, 105, 55, 0.28), rgba(220, 53, 69, 0.2));
}

.browse-request-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
}

.browse-request-field > span {
  color: rgba(255, 255, 255, 0.45);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.browse-request-field > span em {
  color: rgba(255, 255, 255, 0.34);
  font-style: normal;
  font-weight: 500;
  text-transform: none;
  letter-spacing: 0;
}

.browse-request-field input,
.browse-request-field textarea {
  width: 100%;
  min-height: 2.65rem;
  padding: 0.65rem 0.8rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.28) !important;
  color: #fff !important;
  font: inherit;
  font-size: 0.92rem;
  outline: none;
}

.browse-request-field textarea {
  min-height: 5.5rem;
  resize: vertical;
  line-height: 1.4;
}

.browse-request-field input:focus,
.browse-request-field textarea:focus {
  border-color: rgba(219, 105, 55, 0.55);
  box-shadow: 0 0 0 3px rgba(219, 105, 55, 0.14);
}

.browse-request-submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  width: 100%;
  min-height: 2.85rem;
  margin-top: 0.15rem;
  padding: 0.7rem 1rem;
  border: 0;
  border-radius: 0.95rem;
  background: linear-gradient(135deg, #db6937 0%, #dc3545 100%);
  color: #fff;
  font: inherit;
  font-size: 0.94rem;
  font-weight: 750;
  cursor: pointer;
}

.browse-request-submit:disabled {
  opacity: 0.7;
  cursor: wait;
}

.browse-sheet.request-open .browse-sheet-panel,
.browse-sheet.settings-open .browse-sheet-panel,
.browse-sheet.auth-open .browse-sheet-panel {
  max-height: min(86vh, 46rem);
}

@media (max-width: 991.98px) {
  .browse-sheet.request-open .browse-sheet-panel {
    max-height: calc(100dvh - 4.75rem - env(safe-area-inset-bottom, 0px) - 0.45rem) !important;
  }
}
"""

JS_HELPERS = r"""
  // ——— Browse request (in-sheet) ———
  function closeBrowseRequest() {
    var $view = $('#browse-request-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('request-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }

  function openBrowseRequest() {
    closeBrowseAuth();
    closeBrowseSettings();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-request-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('request-open');
    $('#browse-request-alert').attr('hidden', true);
    var $form = $('#browse-request-form');
    if ($form.length) {
      try { $form[0].reset(); } catch (e) {}
      $form.find('input[name=type][value=movie]').prop('checked', true);
    }
    setTimeout(function () {
      $('#browse-request-form input[name=title]').trigger('focus');
    }, 80);
  }

  $(document).on('click', '[data-browse-open="request"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (!$('#browse-sheet').hasClass('is-open')) {
      try { openBrowseSheet(); } catch (err) {}
    }
    openBrowseRequest();
  });

  $(document).on('click', '#browse-request-back', function (e) {
    e.preventDefault();
    closeBrowseRequest();
  });

  $(document).on('submit', '#browse-request-form', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $form = $(this);
    var $btn = $('#browse-request-submit');
    var title = String($form.find('[name=title]').val() || '').trim();
    if (!title) return;
    $btn.prop('disabled', true);
    var payload = {
      type: String($form.find('[name=type]:checked').val() || 'movie'),
      title: title,
      year: String($form.find('[name=year]').val() || '').trim(),
      notes: String($form.find('[name=notes]').val() || '').trim()
    };
    var base = (window.CF_BASE || '');
    fetch(base + '/request', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'text/html'
      },
      body: Object.keys(payload).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
      }).join('&'),
      credentials: 'same-origin'
    }).catch(function () { /* UI-only fallback */ }).finally(function () {
      $btn.prop('disabled', false);
      $('#browse-request-alert').removeAttr('hidden');
      try { $form[0].reset(); } catch (err) {}
      $form.find('input[name=type][value=movie]').prop('checked', true);
    });
  });

  window.openBrowseRequest = openBrowseRequest;
  window.closeBrowseRequest = closeBrowseRequest;
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_bottom_nav(text: str) -> str:
    # Replace page link with in-sheet button
    old_link = re.compile(
        r'<div class="browse-list" style="margin-top:0\.55rem;">\s*'
        r'<a class="browse-list-item" href="[^"]*?/request[^"]*">\s*'
        r'<i class="uil uil-plus-circle"></i>\s*'
        r'<span>Request</span>\s*'
        r'<i class="uil uil-angle-right"></i>\s*'
        r'</a>\s*</div>',
        re.S,
    )
    new_btn = """<div class="browse-list" style="margin-top:0.55rem;">
                    <button type="button" class="browse-list-item" data-browse-open="request">
                        <i class="uil uil-plus-circle"></i>
                        <span>Request</span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>"""
    text2, n = old_link.subn(new_btn, text, count=1)
    if n != 1:
        raise SystemExit("could not replace Request browse link")

    # Insert request view before auth view (or after settings)
    if 'id="browse-request-view"' in text2:
        # replace existing block
        text2 = re.sub(
            r'\s*<div class="browse-request-view"[\s\S]*?</div>\s*(?=\s*<div class="browse-auth-view")',
            "\n" + REQUEST_VIEW.strip() + "\n\n            ",
            text2,
            count=1,
        )
    else:
        if 'id="browse-auth-view"' not in text2:
            raise SystemExit("browse-auth-view not found for insert")
        text2 = text2.replace(
            '<div class="browse-auth-view" id="browse-auth-view" hidden>',
            REQUEST_VIEW.strip() + "\n\n            <div class=\"browse-auth-view\" id=\"browse-auth-view\" hidden>",
            1,
        )
    return text2


def patch_js(js: str) -> str:
    # Remove previous injection if re-run
    js = re.sub(
        r"\n\s*// ——— Browse request \(in-sheet\) ———[\s\S]*?window\.closeBrowseRequest = closeBrowseRequest;\n",
        "\n",
        js,
        count=1,
    )

    # Hook close into openBrowseAuth / openBrowseSettings / wrappers
    if "closeBrowseRequest();" not in js:
        js = js.replace(
            "function openBrowseAuth(mode) {\n    closeBrowseSettings();",
            "function openBrowseAuth(mode) {\n    closeBrowseSettings();\n    closeBrowseRequest();",
            1,
        )
        js = js.replace(
            "function openBrowseSettings() {\n    closeBrowseAuth();",
            "function openBrowseSettings() {\n    closeBrowseAuth();\n    closeBrowseRequest();",
            1,
        )
        js = js.replace(
            "openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();",
            "openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();",
            1,
        )
        js = js.replace(
            "closeBrowseSheet = function () {\n    closeBrowseAuth();\n    closeBrowseSettings();\n    _closeBrowseSheet();",
            "closeBrowseSheet = function () {\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();\n    _closeBrowseSheet();",
            1,
        )

    # Insert helpers before browse settings section OR after settings continue handler
    anchor = "  // ——— Browse settings ———"
    if anchor in js:
        js = js.replace(anchor, JS_HELPERS.strip() + "\n\n" + anchor, 1)
    else:
        js = js.rstrip() + "\n\n" + JS_HELPERS.strip() + "\n"

    # Footer Contact/Request: open browse request when Request is clicked on mobile/desktop browse
    # Also stop the generic "panel a closes sheet" from fighting — button already avoids that.
    return js


def patch_footer(text: str) -> str:
    # Make footer Request open browse sheet view instead of navigating away
    text = text.replace(
        '<a href="<?= e(url(\'/request\')) ?>">Request</a>',
        '<a href="<?= e(url(\'/request\')) ?>" data-browse-open="request">Request</a>',
        1,
    )
    return text


def main() -> None:
    nav = ROOT / "app/Views/partials/bottom-nav.php"
    bak = nav.with_suffix(nav.suffix + f".bak-reqview-{datetime.now().strftime('%Y%m%d%H%M%S')}")
    shutil.copy2(nav, bak)
    print("backup", bak.name)
    nav.write_text(patch_bottom_nav(nav.read_text()))
    print("bottom-nav request view wired")

    footer = ROOT / "app/Views/partials/footer.php"
    ft = footer.read_text()
    ft2 = patch_footer(ft)
    if ft2 != ft:
        footer.write_text(ft2)
        print("footer Request opens browse view")
    else:
        print("footer unchanged")

    js_path = ROOT / "public/assets/js/app.js"
    js_path.write_text(patch_js(js_path.read_text()))
    print("app.js request view handlers")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Browse request view (ui85) ——— */"
    if marker in css:
        css = re.sub(re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)", "", css, count=1)
    # broaden hidden rule for request view in older block too
    css = css.replace(
        ".browse-home-view[hidden],\n.browse-auth-view[hidden],\n.browse-settings-view[hidden] {",
        ".browse-home-view[hidden],\n.browse-auth-view[hidden],\n.browse-settings-view[hidden],\n.browse-request-view[hidden] {",
    )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("css applied")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
