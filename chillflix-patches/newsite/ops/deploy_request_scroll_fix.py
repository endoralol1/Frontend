#!/usr/bin/env python3
"""Hard-fix mobile end-scroll + redesign /request (and matching /contact)."""
from __future__ import annotations

import re
import shutil
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui84"

REQUEST = r"""<?php $headerClass = 'relative'; $site = (string) config('site_name'); ?>
<main class="cf-form-page page-pad-top">
    <div class="container">
        <section class="cf-form-shell" aria-labelledby="request-title">
            <header class="cf-form-head">
                <p class="cf-form-kicker">Missing something?</p>
                <h1 id="request-title" class="cf-form-title">Request a title</h1>
                <p class="cf-form-lead">Tell us the movie or show you want on <?= e($site) ?>. We review requests regularly.</p>
            </header>

            <?php if (!empty($sent)): ?>
                <div class="cf-form-alert cf-form-alert--ok" role="status">
                    <i class="uil uil-check-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Request received</strong>
                        <span>Thanks — we’ll look into adding it soon.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form class="cf-form" method="post" action="<?= e(url('/request')) ?>" autocomplete="on">
                <fieldset class="cf-form-type">
                    <legend>Type</legend>
                    <div class="cf-form-type-row" role="radiogroup" aria-label="Request type">
                        <label class="cf-form-type-opt">
                            <input type="radio" name="type" value="movie" checked>
                            <span><i class="uil uil-clapper-board" aria-hidden="true"></i> Movie</span>
                        </label>
                        <label class="cf-form-type-opt">
                            <input type="radio" name="type" value="tv">
                            <span><i class="uil uil-tv-retro" aria-hidden="true"></i> TV Show</span>
                        </label>
                    </div>
                </fieldset>

                <label class="cf-field">
                    <span class="cf-field-label">Title</span>
                    <input class="cf-field-input" type="text" name="title" required maxlength="160" placeholder="e.g. Dune: Part Two" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Year <em>(optional)</em></span>
                    <input class="cf-field-input" type="text" name="year" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="2024" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Notes <em>(optional)</em></span>
                    <textarea class="cf-field-input cf-field-input--area" name="notes" rows="4" maxlength="800" placeholder="Season, language, or anything that helps us find it"></textarea>
                </label>

                <button type="submit" class="cf-form-submit">
                    <i class="uil uil-message" aria-hidden="true"></i>
                    Submit request
                </button>
            </form>
        </section>
    </div>
</main>
"""

CONTACT = r"""<?php $headerClass = 'relative'; $site = (string) config('site_name'); ?>
<main class="cf-form-page page-pad-top">
    <div class="container">
        <section class="cf-form-shell" aria-labelledby="contact-title">
            <header class="cf-form-head">
                <p class="cf-form-kicker">Support</p>
                <h1 id="contact-title" class="cf-form-title">Contact us</h1>
                <p class="cf-form-lead">Questions, feedback, or issues with <?= e($site) ?> — send a short message and we’ll get back when we can.</p>
            </header>

            <?php if (!empty($sent)): ?>
                <div class="cf-form-alert cf-form-alert--ok" role="status">
                    <i class="uil uil-check-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Message received</strong>
                        <span>Thanks — we’ll review it soon.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form class="cf-form" method="post" action="<?= e(url('/contact')) ?>" autocomplete="on">
                <label class="cf-field">
                    <span class="cf-field-label">Name</span>
                    <input class="cf-field-input" type="text" name="name" required maxlength="120" placeholder="Your name" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Email</span>
                    <input class="cf-field-input" type="email" name="email" required maxlength="180" placeholder="you@email.com" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Message</span>
                    <textarea class="cf-field-input cf-field-input--area" name="message" rows="5" required maxlength="2000" placeholder="How can we help?"></textarea>
                </label>

                <button type="submit" class="cf-form-submit">
                    <i class="uil uil-envelope-alt" aria-hidden="true"></i>
                    Send message
                </button>
            </form>
        </section>
    </div>
</main>
"""

# Keep footer markup; boost clearance + fix document height so mobile can actually reach the end.
FOOTER_CSS_BOOST = r"""
/* ——— Mobile scroll unlock + deeper footer clearance (ui84) ——— */
html {
  height: auto !important;
  min-height: 100% !important;
}

body {
  height: auto !important;
  min-height: 100% !important;
  overflow-x: hidden;
  overflow-y: auto !important;
}

body > .wrapper {
  height: auto !important;
  min-height: 100% !important;
  overflow: visible !important;
}

@media (max-width: 991.98px) {
  body {
    padding-bottom: calc(9.5rem + env(safe-area-inset-bottom, 0px)) !important;
  }

  .footer.footer--v3 .footer-v3-clearance {
    height: calc(8.75rem + env(safe-area-inset-bottom, 0px)) !important;
  }

  /* Extra insurance: keep help links above the fixed nav */
  .footer.footer--v3 .footer-v3-help {
    position: relative;
    z-index: 2;
    margin-bottom: 0.35rem;
  }

  .footer.footer--v3 .footer-v3-legal {
    padding-bottom: 0.25rem;
  }
}
"""

FORM_CSS = r"""
/* ——— Request / Contact form pages (ui84) ——— */
.cf-form-page {
  position: relative;
  z-index: 1;
  padding-bottom: 2rem;
}

.cf-form-shell {
  width: min(34rem, 100%);
  margin: 0.5rem auto 0;
  padding: 1.35rem 1.2rem 1.45rem;
  border-radius: 1.4rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.045) 0%, rgba(255, 255, 255, 0.02) 100%),
    rgba(12, 14, 20, 0.55);
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.28);
}

.cf-form-head {
  margin-bottom: 1.15rem;
  text-align: left;
}

.cf-form-kicker {
  margin: 0 0 0.35rem;
  color: #ff8f6b;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.cf-form-title {
  margin: 0 0 0.45rem;
  color: #fff;
  font-size: clamp(1.55rem, 4.5vw, 1.9rem);
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.15;
}

.cf-form-lead {
  margin: 0;
  color: rgba(255, 255, 255, 0.58);
  font-size: 0.92rem;
  line-height: 1.45;
}

.cf-form-alert {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  margin: 0 0 1rem;
  padding: 0.85rem 0.95rem;
  border-radius: 1rem;
  border: 1px solid rgba(60, 180, 110, 0.35);
  background: rgba(40, 140, 85, 0.16);
  color: #d9ffe8;
}

.cf-form-alert i {
  font-size: 1.35rem;
  line-height: 1;
  color: #7dffb0;
}

.cf-form-alert strong {
  display: block;
  margin-bottom: 0.15rem;
  color: #fff;
  font-size: 0.92rem;
}

.cf-form-alert span {
  display: block;
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.82rem;
  line-height: 1.35;
}

.cf-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.cf-form-type {
  margin: 0;
  padding: 0;
  border: 0;
}

.cf-form-type legend {
  margin-bottom: 0.45rem;
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.78rem;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.cf-form-type-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem;
}

.cf-form-type-opt {
  cursor: pointer;
}

.cf-form-type-opt input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.cf-form-type-opt span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  width: 100%;
  min-height: 2.7rem;
  padding: 0.45rem 0.7rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.22);
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.9rem;
  font-weight: 650;
  transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.cf-form-type-opt span i {
  font-size: 1.05rem;
}

.cf-form-type-opt input:checked + span {
  color: #fff;
  border-color: rgba(219, 105, 55, 0.55);
  background: linear-gradient(135deg, rgba(219, 105, 55, 0.28), rgba(220, 53, 69, 0.2));
  box-shadow: inset 0 0 0 1px rgba(255, 180, 140, 0.12);
}

.cf-form-type-opt input:focus-visible + span {
  outline: 2px solid rgba(255, 143, 107, 0.55);
  outline-offset: 2px;
}

.cf-field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
}

.cf-field-label {
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.78rem;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.cf-field-label em {
  color: rgba(255, 255, 255, 0.38);
  font-style: normal;
  font-weight: 500;
  text-transform: none;
  letter-spacing: 0;
}

.cf-field-input {
  width: 100%;
  min-height: 2.85rem;
  padding: 0.7rem 0.9rem;
  border-radius: 0.95rem;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.28) !important;
  color: #fff !important;
  font-size: 0.98rem;
  font-weight: 500;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.cf-field-input::placeholder {
  color: rgba(255, 255, 255, 0.34);
}

.cf-field-input:focus {
  border-color: rgba(219, 105, 55, 0.55);
  box-shadow: 0 0 0 3px rgba(219, 105, 55, 0.16);
  background: rgba(0, 0, 0, 0.38) !important;
}

.cf-field-input--area {
  min-height: 7rem;
  resize: vertical;
  line-height: 1.45;
}

.cf-form-submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  width: 100%;
  min-height: 3rem;
  margin-top: 0.25rem;
  padding: 0.75rem 1rem;
  border: 0;
  border-radius: 0.95rem;
  background: linear-gradient(135deg, #db6937 0%, #dc3545 100%);
  color: #fff;
  font-size: 0.98rem;
  font-weight: 750;
  letter-spacing: 0.01em;
  cursor: pointer;
  box-shadow: 0 10px 24px rgba(220, 53, 69, 0.28);
  transition: transform 0.15s ease, filter 0.15s ease;
}

.cf-form-submit:hover {
  filter: brightness(1.05);
  transform: translateY(-1px);
}

.cf-form-submit:active {
  transform: translateY(0);
}

@media (max-width: 991.98px) {
  .cf-form-page {
    padding-bottom: calc(8.5rem + env(safe-area-inset-bottom, 0px));
  }

  .cf-form-shell {
    margin-top: 0.25rem;
    padding: 1.2rem 1rem 1.3rem;
  }
}

@media (min-width: 992px) {
  .cf-form-shell {
    padding: 1.6rem 1.55rem 1.7rem;
  }
}
"""


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def strip_marker_blocks(css: str, markers: list[str]) -> str:
    for marker in markers:
        if marker in css:
            css = re.sub(
                re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)",
                "",
                css,
                count=1,
            )
    return css


def main() -> None:
    req = ROOT / "app/Views/pages/request.php"
    con = ROOT / "app/Views/pages/contact.php"
    stamp = datetime.now().strftime("%Y%m%d%H%M%S")
    shutil.copy2(req, req.with_suffix(req.suffix + f".bak-ui84-{stamp}"))
    shutil.copy2(con, con.with_suffix(con.suffix + f".bak-ui84-{stamp}"))
    req.write_text(REQUEST)
    con.write_text(CONTACT)
    print("request + contact pages written")

    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    css = strip_marker_blocks(
        css,
        [
            "/* ——— Mobile scroll unlock + deeper footer clearance (ui84) ——— */",
            "/* ——— Request / Contact form pages (ui84) ——— */",
        ],
    )
    # Also boost the existing v3 clearance numbers if present
    css = css.replace(
        "padding-bottom: calc(8rem + env(safe-area-inset-bottom, 0px)) !important;",
        "padding-bottom: calc(9.5rem + env(safe-area-inset-bottom, 0px)) !important;",
    )
    css = css.replace(
        "height: calc(7.25rem + env(safe-area-inset-bottom, 0px));",
        "height: calc(8.75rem + env(safe-area-inset-bottom, 0px));",
    )
    css_path.write_text(
        css.rstrip() + "\n\n" + FOOTER_CSS_BOOST.strip() + "\n\n" + FORM_CSS.strip() + "\n"
    )
    print("scroll unlock + form css applied")

    # Move Contact/Request into the panel so they aren't the last thing under the nav
    footer = ROOT / "app/Views/partials/footer.php"
    ft = footer.read_text()
    if "footer-v3-help" in ft and "footer-v3-panel" in ft:
        # place help links inside panel after cats, before desc
        if 'class="footer-v3-help"' in ft and ft.find("footer-v3-help") > ft.find("footer-v3-desc"):
            help_block = re.search(
                r'\s*<div class="footer-v3-help">[\s\S]*?</div>\s*',
                ft,
            )
            if help_block:
                block = help_block.group(0).strip()
                ft2 = ft[: help_block.start()] + "\n" + ft[help_block.end() :]
                # insert after cats nav
                ft2 = ft2.replace(
                    "</nav>\n            <p class=\"footer-v3-desc\">",
                    "</nav>\n            "
                    + block
                    + "\n            <p class=\"footer-v3-desc\">",
                    1,
                )
                footer.write_text(ft2)
                print("footer: Contact/Request moved above blurb")
        else:
            print("footer help already positioned early")
    else:
        print("footer markup unexpected; left as-is")

    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
