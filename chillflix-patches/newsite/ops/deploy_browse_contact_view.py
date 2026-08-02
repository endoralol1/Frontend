#!/usr/bin/env python3
"""Open Contact inside Browse sheet (same pattern as Request/Settings)."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui86"

CONTACT_VIEW = r"""
            <div class="browse-contact-view" id="browse-contact-view" hidden>
                <div class="browse-contact-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-contact-back" aria-label="Back to browse">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-settings-heading">
                            <p class="browse-auth-kicker">Support</p>
                            <h3 class="browse-auth-title" style="margin:0;">Contact us</h3>
                        </div>
                    </div>

                    <p class="browse-request-lead">Questions, feedback, or issues — send a short message and we’ll get back when we can.</p>

                    <div class="browse-request-alert" id="browse-contact-alert" hidden role="status">
                        <i class="uil uil-check-circle" aria-hidden="true"></i>
                        <div>
                            <strong>Message received</strong>
                            <span>Thanks — we’ll review it soon.</span>
                        </div>
                    </div>

                    <form class="browse-request-form" id="browse-contact-form" autocomplete="on">
                        <label class="browse-request-field">
                            <span>Name</span>
                            <input type="text" name="name" required maxlength="120" placeholder="Your name" enterkeyhint="next">
                        </label>

                        <label class="browse-request-field">
                            <span>Email</span>
                            <input type="email" name="email" required maxlength="180" placeholder="you@email.com" enterkeyhint="next">
                        </label>

                        <label class="browse-request-field">
                            <span>Message</span>
                            <textarea name="message" rows="4" required maxlength="2000" placeholder="How can we help?"></textarea>
                        </label>

                        <button type="submit" class="browse-request-submit" id="browse-contact-submit">
                            <span>Send message</span>
                            <i class="uil uil-envelope-alt" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            </div>
"""

CSS = r"""
/* ——— Browse contact view (ui86) ——— */
.browse-home-view[hidden],
.browse-auth-view[hidden],
.browse-settings-view[hidden],
.browse-request-view[hidden],
.browse-contact-view[hidden] {
  display: none !important;
}

.browse-contact-view {
  padding: 0.15rem 0 0.35rem;
  animation: browseAuthIn 0.22s ease;
}

.browse-sheet.contact-open .browse-sheet-panel,
.browse-sheet.request-open .browse-sheet-panel,
.browse-sheet.settings-open .browse-sheet-panel,
.browse-sheet.auth-open .browse-sheet-panel {
  max-height: min(86vh, 46rem);
}

@media (max-width: 991.98px) {
  .browse-sheet.contact-open .browse-sheet-panel {
    max-height: calc(100dvh - 4.75rem - env(safe-area-inset-bottom, 0px) - 0.45rem) !important;
  }
}
"""

JS = r"""
  // ——— Browse contact (in-sheet) ———
  function closeBrowseContact() {
    var $view = $('#browse-contact-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('contact-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }

  function openBrowseContact() {
    closeBrowseAuth();
    closeBrowseSettings();
    closeBrowseRequest();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-contact-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('contact-open');
    $('#browse-contact-alert').attr('hidden', true);
    var $form = $('#browse-contact-form');
    if ($form.length) {
      try { $form[0].reset(); } catch (e) {}
    }
    setTimeout(function () {
      $('#browse-contact-form input[name=name]').trigger('focus');
    }, 80);
  }

  $(document).on('click', '[data-browse-open="contact"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (!$('#browse-sheet').hasClass('is-open')) {
      try { openBrowseSheet(); } catch (err) {}
    }
    openBrowseContact();
  });

  $(document).on('click', '#browse-contact-back', function (e) {
    e.preventDefault();
    closeBrowseContact();
  });

  $(document).on('submit', '#browse-contact-form', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $form = $(this);
    var $btn = $('#browse-contact-submit');
    var name = String($form.find('[name=name]').val() || '').trim();
    var email = String($form.find('[name=email]').val() || '').trim();
    var message = String($form.find('[name=message]').val() || '').trim();
    if (!name || !email || !message) return;
    $btn.prop('disabled', true);
    var payload = { name: name, email: email, message: message };
    var base = (window.CF_BASE || '');
    fetch(base + '/contact', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'text/html'
      },
      body: Object.keys(payload).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
      }).join('&'),
      credentials: 'same-origin'
    }).catch(function () {}).finally(function () {
      $btn.prop('disabled', false);
      $('#browse-contact-alert').removeAttr('hidden');
      try { $form[0].reset(); } catch (err) {}
    });
  });

  window.openBrowseContact = openBrowseContact;
  window.closeBrowseContact = closeBrowseContact;
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_nav(text: str) -> str:
    # Ensure Contact + Request buttons in browse list
    old_list = re.compile(
        r'<div class="browse-list" style="margin-top:0\.55rem;">\s*'
        r'(?:<button type="button" class="browse-list-item" data-browse-open="contact">[\s\S]*?</button>\s*)?'
        r'<button type="button" class="browse-list-item" data-browse-open="request">[\s\S]*?</button>\s*'
        r'</div>',
        re.S,
    )
    new_list = """<div class="browse-list" style="margin-top:0.55rem;">
                    <button type="button" class="browse-list-item" data-browse-open="contact">
                        <i class="uil uil-envelope"></i>
                        <span>Contact</span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-list-item" data-browse-open="request">
                        <i class="uil uil-plus-circle"></i>
                        <span>Request</span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>"""
    text2, n = old_list.subn(new_list, text, count=1)
    if n != 1:
        raise SystemExit("could not patch browse Contact/Request list")

    if 'id="browse-contact-view"' in text2:
        text2 = re.sub(
            r'\s*<div class="browse-contact-view"[\s\S]*?</div>\s*(?=\s*<div class="browse-(?:request|auth)-view")',
            "\n" + CONTACT_VIEW.strip() + "\n\n            ",
            text2,
            count=1,
        )
    else:
        # insert before request view if present, else before auth
        if 'id="browse-request-view"' in text2:
            text2 = text2.replace(
                '<div class="browse-request-view" id="browse-request-view" hidden>',
                CONTACT_VIEW.strip()
                + '\n\n            <div class="browse-request-view" id="browse-request-view" hidden>',
                1,
            )
        elif 'id="browse-auth-view"' in text2:
            text2 = text2.replace(
                '<div class="browse-auth-view" id="browse-auth-view" hidden>',
                CONTACT_VIEW.strip()
                + '\n\n            <div class="browse-auth-view" id="browse-auth-view" hidden>',
                1,
            )
        else:
            raise SystemExit("no insert point for contact view")
    return text2


def patch_footer(text: str) -> str:
    text = text.replace(
        '<a href="<?= e(url(\'/contact\')) ?>">Contact</a>',
        '<a href="<?= e(url(\'/contact\')) ?>" data-browse-open="contact">Contact</a>',
        1,
    )
    return text


def patch_js(js: str) -> str:
    js = re.sub(
        r"\n\s*// ——— Browse contact \(in-sheet\) ———[\s\S]*?window\.closeBrowseContact = closeBrowseContact;\n",
        "\n",
        js,
        count=1,
    )

    # Hook closeBrowseContact into existing open/close paths
    replacements = [
        (
            "function openBrowseAuth(mode) {\n    closeBrowseSettings();\n    closeBrowseRequest();",
            "function openBrowseAuth(mode) {\n    closeBrowseSettings();\n    closeBrowseRequest();\n    closeBrowseContact();",
        ),
        (
            "function openBrowseSettings() {\n    closeBrowseAuth();\n    closeBrowseRequest();",
            "function openBrowseSettings() {\n    closeBrowseAuth();\n    closeBrowseRequest();\n    closeBrowseContact();",
        ),
        (
            "function openBrowseRequest() {\n    closeBrowseAuth();\n    closeBrowseSettings();",
            "function openBrowseRequest() {\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseContact();",
        ),
        (
            "openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();",
            "openBrowseSheet = function () {\n    _openBrowseSheet();\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();\n    closeBrowseContact();",
        ),
        (
            "closeBrowseSheet = function () {\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();\n    _closeBrowseSheet();",
            "closeBrowseSheet = function () {\n    closeBrowseAuth();\n    closeBrowseSettings();\n    closeBrowseRequest();\n    closeBrowseContact();\n    _closeBrowseSheet();",
        ),
    ]
    for old, new in replacements:
        if old in js and "closeBrowseContact();" not in old:
            js = js.replace(old, new, 1)

    anchor = "  // ——— Browse request (in-sheet) ———"
    if anchor in js:
        js = js.replace(anchor, JS.strip() + "\n\n" + anchor, 1)
    else:
        js = js.rstrip() + "\n\n" + JS.strip() + "\n"
    return js


def main() -> None:
    nav = ROOT / "app/Views/partials/bottom-nav.php"
    bak = nav.with_suffix(
        nav.suffix + f".bak-contactview-{datetime.now().strftime('%Y%m%d%H%M%S')}"
    )
    shutil.copy2(nav, bak)
    print("backup", bak.name)
    nav.write_text(patch_nav(nav.read_text()))
    print("browse contact view + list item")

    footer = ROOT / "app/Views/partials/footer.php"
    ft = footer.read_text()
    ft2 = patch_footer(ft)
    if ft2 != ft:
        footer.write_text(ft2)
        print("footer Contact opens browse view")
    else:
        print("footer already patched or pattern miss")

    js_path = ROOT / "public/assets/js/app.js"
    js_path.write_text(patch_js(js_path.read_text()))
    print("js contact handlers")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Browse contact view (ui86) ——— */"
    if marker in css:
        css = re.sub(re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)", "", css, count=1)
    # ensure hidden selectors include contact
    css = css.replace(
        ".browse-request-view[hidden] {\n  display: none !important;\n}",
        ".browse-request-view[hidden],\n.browse-contact-view[hidden] {\n  display: none !important;\n}",
    )
    css_path.write_text(css.rstrip() + "\n\n" + CSS.strip() + "\n")
    print("css applied")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
