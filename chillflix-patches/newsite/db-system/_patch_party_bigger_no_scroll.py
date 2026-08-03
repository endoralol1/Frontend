#!/usr/bin/env python3
"""Revert form-hiding; make Watch Party sheet bigger so it fits without scroll."""
from pathlib import Path
import re

css_path = Path("/var/www/chillflix-newsite/public/assets/css/continue-party.css")
js_path = Path("/var/www/chillflix-newsite/public/assets/js/continue-party.js")
layout = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")

css = css_path.read_text()

# Bigger sheet — almost full phone height, no sheet scrollbar
old_sheet = """.cf-party-sheet {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: min(28rem, calc(100% - 1.25rem)) !important;
  max-height: min(92dvh, 36rem);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, #1c202a 0%, #141820 100%);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 1rem;
  box-shadow: 0 20px 50px rgba(0,0,0,.5);
  /* No sheet padding — header bar stays full-width inside the modal */
  padding: 0 !important;
  color: #eef1f6;
  box-sizing: border-box;
}"""

new_sheet = """.cf-party-sheet {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: min(30rem, calc(100% - 1rem)) !important;
  max-height: min(96dvh, 48rem);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, #1c202a 0%, #141820 100%);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 1rem;
  box-shadow: 0 20px 50px rgba(0,0,0,.5);
  /* No sheet padding — header bar stays full-width inside the modal */
  padding: 0 !important;
  color: #eef1f6;
  box-sizing: border-box;
}"""

if old_sheet not in css:
    raise SystemExit("sheet block not found")
css = css.replace(old_sheet, new_sheet, 1)

# Restore comfortable (non-shrunk) chrome
css = css.replace(
    """.cf-party-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0;
  width: 100%;
  flex: 0 0 auto;
  box-sizing: border-box;
  background: #161a23;
  border-bottom: 1px solid rgba(255,255,255,.06);
  border-radius: 1rem 1rem 0 0;
  padding: 12px 14px;
}
.cf-party-copy {
  min-width: 0;
  flex: 1 1 auto;
  padding: 0;
  margin: 0;
}
.cf-party-body {
  padding: 12px 14px 14px;
  box-sizing: border-box;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
}""",
    """.cf-party-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin: 0;
  width: 100%;
  flex: 0 0 auto;
  box-sizing: border-box;
  background: #161a23;
  border-bottom: 1px solid rgba(255,255,255,.06);
  border-radius: 1rem 1rem 0 0;
  padding: 16px 18px;
}
.cf-party-copy {
  min-width: 0;
  flex: 1 1 auto;
  padding: 0;
  margin: 0;
}
.cf-party-body {
  padding: 16px 18px 18px;
  box-sizing: border-box;
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}""",
    1,
)

css = css.replace(
    """.cf-party-head h3 {
  margin: .18rem 0 0;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -.02em;
  line-height: 1.15;
}""",
    """.cf-party-head h3 {
  margin: .28rem 0 0;
  font-size: 1.35rem;
  font-weight: 800;
  letter-spacing: -.02em;
  line-height: 1.15;
}""",
    1,
)

css = css.replace(
    """.cf-party-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .3rem;
  margin-bottom: .7rem;
  padding: .2rem;
  border-radius: .7rem;
  background: rgba(0,0,0,.28);
  border: 1px solid rgba(255,255,255,.06);
}
.cf-party-tabs button {
  appearance: none;
  border: 0;
  background: transparent;
  color: #9aa3b2;
  border-radius: .5rem;
  padding: .45rem .45rem;
  font-weight: 750;
  font-size: .88rem;
  cursor: pointer;
  transition: background .15s ease, color .15s ease;
}""",
    """.cf-party-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .3rem;
  margin-bottom: .85rem;
  padding: .25rem;
  border-radius: .75rem;
  background: rgba(0,0,0,.28);
  border: 1px solid rgba(255,255,255,.06);
  flex: 0 0 auto;
}
.cf-party-tabs button {
  appearance: none;
  border: 0;
  background: transparent;
  color: #9aa3b2;
  border-radius: .55rem;
  padding: .55rem .5rem;
  font-weight: 750;
  font-size: .92rem;
  cursor: pointer;
  transition: background .15s ease, color .15s ease;
}""",
    1,
)

# Pane fills body; results area is the only scrollable region if many titles
css = css.replace(
    """.cf-party-pane { display: none; }
.cf-party-pane.is-active { display: block; }
.cf-party-hint {
  margin: 0 0 .55rem;
  color: #9aa3b2;
  font-size: .82rem;
  line-height: 1.35;
}""",
    """.cf-party-pane { display: none; }
.cf-party-pane.is-active {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
}
.cf-party-hint {
  margin: 0 0 .75rem;
  color: #9aa3b2;
  font-size: .88rem;
  line-height: 1.45;
  flex: 0 0 auto;
}""",
    1,
)

css = css.replace(
    """.cf-party-input {
  width: 100%;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(0,0,0,.28);
  color: #fff;
  border-radius: .65rem;
  padding: .62rem .8rem;
  margin-bottom: .55rem;
  font: inherit;
  outline: none;
  transition: border-color .15s ease, box-shadow .15s ease;
}""",
    """.cf-party-input {
  width: 100%;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(0,0,0,.28);
  color: #fff;
  border-radius: .7rem;
  padding: .72rem .85rem;
  margin-bottom: .7rem;
  font: inherit;
  outline: none;
  transition: border-color .15s ease, box-shadow .15s ease;
  flex: 0 0 auto;
}""",
    1,
)

css = css.replace(
    """.cf-party-results {
  display: grid;
  gap: .35rem;
  max-height: min(34vh, 11.5rem);
  overflow: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}""",
    """.cf-party-results {
  display: grid;
  gap: .4rem;
  flex: 1 1 auto;
  min-height: 0;
  max-height: none;
  overflow: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}""",
    1,
)

css = css.replace(
    """.cf-party-empty {
  padding: .55rem .4rem;
  text-align: center;
  color: #8b93a3;
  font-size: .82rem;
}""",
    """.cf-party-empty {
  padding: .9rem;
  text-align: center;
  color: #8b93a3;
  font-size: .88rem;
}""",
    1,
)

css = css.replace(
    """.cf-party-btn {
  appearance: none;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(255,255,255,.06);
  color: #fff;
  border-radius: .65rem;
  padding: .62rem .8rem;
  font-weight: 750;
  font-size: .9rem;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  transition: filter .15s ease, transform .15s ease;
}""",
    """.cf-party-btn {
  appearance: none;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(255,255,255,.06);
  color: #fff;
  border-radius: .7rem;
  padding: .72rem .95rem;
  font-weight: 750;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  transition: filter .15s ease, transform .15s ease;
}""",
    1,
)

css = css.replace(
    """.cf-party-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .45rem;
  margin-top: .55rem;
}
.cf-party-code-wrap {
  display: flex;
  align-items: baseline;
  gap: .55rem;
  padding: .7rem .85rem;
  border-radius: .7rem;
  background: rgba(220,53,69,.12);
  border: 1px solid rgba(220,53,69,.35);
  margin-bottom: .4rem;
}
.cf-party-code-wrap span {
  font-size: .68rem;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #ffb3bb;
  font-weight: 750;
}
.cf-party-code-wrap strong {
  font-size: 1.35rem;
  letter-spacing: .16em;
}""",
    """.cf-party-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem;
  margin-top: .75rem;
}
.cf-party-code-wrap {
  display: flex;
  align-items: baseline;
  gap: .65rem;
  padding: .9rem 1rem;
  border-radius: .8rem;
  background: rgba(220,53,69,.12);
  border: 1px solid rgba(220,53,69,.35);
  margin-bottom: .55rem;
}
.cf-party-code-wrap span {
  font-size: .7rem;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: #ffb3bb;
  font-weight: 750;
}
.cf-party-code-wrap strong {
  font-size: 1.55rem;
  letter-spacing: .16em;
}
.cf-party-created {
  flex: 0 0 auto;
  margin-top: .35rem;
}""",
    1,
)

css = css.replace(
    """.cf-party-turnstile {
  display: flex;
  justify-content: center;
  margin: 0.35rem 0 0.2rem;
  min-height: 0;
  transform: scale(0.92);
  transform-origin: top center;
}
.cf-party-turnstile iframe {
  max-width: 100%;
}

/* After create: only show code + actions (no stacked form scroll) */
.cf-party-pane.is-created > :not(#cf-party-created) {
  display: none !important;
}
.cf-party-pane.is-created #cf-party-created {
  display: block;
}
.cf-party-pane.is-created .cf-party-hint {
  margin-bottom: 0.35rem;
}""",
    """.cf-party-turnstile {
  display: flex;
  justify-content: center;
  margin: 0.55rem 0 0.25rem;
  min-height: 0;
  flex: 0 0 auto;
}
.cf-party-turnstile iframe {
  max-width: 100%;
}
.cf-party-label {
  flex: 0 0 auto;
}""",
    1,
)

css_path.write_text(css)
print("css restored/bigger")

js = js_path.read_text()

# Revert open/switch/create UX changes — keep only clearing results after create
js = js.replace(
    """  function openPartyPanel(tab) {
    var $p = ensurePartyPanel();
    resetCreatePane();
    $p.removeAttr("hidden");
    $("body").addClass("cf-party-open");
    if (tab) switchPartyTab(tab);
    else switchPartyTab("create");
    ensurePartyTurnstiles();
  }""",
    """  function openPartyPanel(tab) {
    var $p = ensurePartyPanel();
    $p.removeAttr("hidden");
    $("body").addClass("cf-party-open");
    if (tab) switchPartyTab(tab);
    else switchPartyTab("create");
    ensurePartyTurnstiles();
  }""",
    1,
)

js = js.replace(
    """  function resetCreatePane() {
    var $create = $('[data-party-pane="create"]');
    $create.removeClass("is-created");
    $("#cf-party-created").prop("hidden", true).empty();
    $("#cf-party-results").empty();
    $("#cf-party-create-err").prop("hidden", true).text("");
  }

  function switchPartyTab(tab) {
    var $p = $("#cf-party-panel");
    $p.find("[data-party-tab]").removeClass("is-active").attr("aria-selected", "false");
    $p.find('[data-party-tab="' + tab + '"]').addClass("is-active").attr("aria-selected", "true");
    $p.find("[data-party-pane]").removeClass("is-active");
    $p.find('[data-party-pane="' + tab + '"]').addClass("is-active");
    if (tab === "create") {
      /* keep created state if already created on this open; only clear when reopening */
    }
    if (partyNeedsTurnstile()) {
      setTimeout(function () {
        if ($('[data-party-pane="create"]').hasClass("is-created") && tab === "create") return;
        renderPartyTurnstile(tab === "join" ? "join" : "create");
      }, 30);
    }
  }""",
    """  function switchPartyTab(tab) {
    var $p = $("#cf-party-panel");
    $p.find("[data-party-tab]").removeClass("is-active").attr("aria-selected", "false");
    $p.find('[data-party-tab="' + tab + '"]').addClass("is-active").attr("aria-selected", "true");
    $p.find("[data-party-pane]").removeClass("is-active");
    $p.find('[data-party-pane="' + tab + '"]').addClass("is-active");
    if (partyNeedsTurnstile()) {
      setTimeout(function () {
        renderPartyTurnstile(tab === "join" ? "join" : "create");
      }, 30);
    }
  }""",
    1,
)

js = js.replace(
    """      resetPartyTurnstile("create");
      $("#cf-party-results").empty();
      $("#cf-party-q").val("");
      $('[data-party-pane="create"]').addClass("is-created");
      $("#cf-party-created")
        .prop("hidden", false)
        .html(
          '<div class="cf-party-code-wrap"><span>Code</span><strong id="cf-party-code-val">' +
            esc(data.room.code) +
            "</strong></div>" +
            '<p class="cf-party-hint">Share this code or link. You are the host.</p>' +
            '<div class="cf-party-actions">' +
            '<button type="button" class="cf-party-btn" id="cf-party-copy" data-link="' +
            esc(share) +
            '">Copy link</button>' +
            '<a class="cf-party-btn is-primary" href="' +
            esc(watch) +
            '">Start watching</a>' +
            "</div>"
        );
      return data;""",
    """      resetPartyTurnstile("create");
      /* Drop status row so the sheet does not grow past the viewport */
      $("#cf-party-results").empty();
      $("#cf-party-created")
        .prop("hidden", false)
        .html(
          '<div class="cf-party-code-wrap"><span>Code</span><strong id="cf-party-code-val">' +
            esc(data.room.code) +
            "</strong></div>" +
            '<p class="cf-party-hint">Share this code or link. You are the host.</p>' +
            '<div class="cf-party-actions">' +
            '<button type="button" class="cf-party-btn" id="cf-party-copy" data-link="' +
            esc(share) +
            '">Copy link</button>' +
            '<a class="cf-party-btn is-primary" href="' +
            esc(watch) +
            '">Start watching</a>' +
            "</div>"
        );
      return data;""",
    1,
)

if "resetCreatePane" in js or "is-created" in js:
    raise SystemExit("is-created / resetCreatePane still present")

js_path.write_text(js)
print("js reverted")

lt = layout.read_text()
layout.write_text(re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui146", lt))
print("layout ui146")
print("DONE")
