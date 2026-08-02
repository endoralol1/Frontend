#!/usr/bin/env python3
"""Move Browse contact handlers inside the main app.js IIFE."""
from __future__ import annotations

import re
from pathlib import Path

JS_PATH = Path("/var/www/chillflix-newsite/public/assets/js/app.js")
LAYOUT = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
ASSET_V = "20260801-ui87"

CONTACT_BLOCK = r"""
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


def main() -> None:
    js = JS_PATH.read_text()

    # Drop any existing contact blocks (inside or trailing)
    js = re.sub(
        r"\n\s*// ——— Browse contact \(in-sheet\) ———[\s\S]*?window\.closeBrowseContact = closeBrowseContact;\s*",
        "\n",
        js,
    )

    anchor = "// ——— Browse request (in-sheet) ———"
    if anchor not in js:
        raise SystemExit("request anchor missing")

    js = js.replace(anchor, CONTACT_BLOCK.strip() + "\n\n" + anchor, 1)
    JS_PATH.write_text(js)

    pos_c = js.find("Browse contact (in-sheet)")
    pos_r = js.find("Browse request (in-sheet)")
    pos_end = js.find("/* browse-watch-stats */")
    if not (0 < pos_c < pos_r < pos_end):
        raise SystemExit(f"bad positions contact={pos_c} request={pos_r} stats={pos_end}")
    if js.find("Browse contact (in-sheet)", pos_c + 10) >= 0:
        raise SystemExit("duplicate contact block")

    layout = LAYOUT.read_text()
    LAYOUT.write_text(re.sub(r"20260801-ui\d+", ASSET_V, layout))
    print("fixed contact js scope +", ASSET_V)


if __name__ == "__main__":
    main()
