#!/usr/bin/env python3
from pathlib import Path

JS = Path("/var/www/chillflix-newsite/public/assets/js/continue-party.js")
CSS = Path("/var/www/chillflix-newsite/public/assets/css/continue-party.css")
ROUTES = Path("/var/www/chillflix-newsite/app/routes.php")
LAYOUT = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")

t = JS.read_text()

old_create = (
    '\'<section class="cf-party-pane is-active" data-party-pane="create">\' +\n'
    '        \'<p class="cf-party-hint">Pick any movie or show, share the code, and watch in sync.</p>\' +\n'
    '        \'<label class="cf-party-label" for="cf-party-q">Search title</label>\' +\n'
    '        \'<input type="search" id="cf-party-q" class="cf-party-input" placeholder="Search movies & TV…" autocomplete="off">\' +\n'
    '        \'<div id="cf-party-results" class="cf-party-results"></div>\' +\n'
    '        \'<div id="cf-party-created" class="cf-party-created" hidden></div>\' +\n'
    '        "</section>" +'
)

new_create = (
    '\'<section class="cf-party-pane is-active" data-party-pane="create">\' +\n'
    '        \'<p class="cf-party-hint">Pick any movie or show, share the code, and watch in sync.</p>\' +\n'
    '        \'<label class="cf-party-label" for="cf-party-q">Search title</label>\' +\n'
    '        \'<input type="search" id="cf-party-q" class="cf-party-input" placeholder="Search movies & TV…" autocomplete="off">\' +\n'
    '        \'<div class="cf-party-turnstile" id="cf-party-turnstile-create"></div>\' +\n'
    '        \'<p id="cf-party-create-err" class="cf-party-err" hidden></p>\' +\n'
    '        \'<div id="cf-party-results" class="cf-party-results"></div>\' +\n'
    '        \'<div id="cf-party-created" class="cf-party-created" hidden></div>\' +\n'
    '        "</section>" +'
)

old_join = (
    '\'<section class="cf-party-pane" data-party-pane="join">\' +\n'
    '        \'<p class="cf-party-hint">Enter a 4-digit party code from your friend.</p>\' +\n'
    '        \'<label class="cf-party-label" for="cf-party-code">Party code</label>\' +\n'
    '        \'<input type="text" id="cf-party-code" class="cf-party-input" maxlength="6" inputmode="numeric" placeholder="1234">\' +\n'
    '        \'<button type="button" class="cf-party-btn is-primary" id="cf-party-join">Join party</button>\' +\n'
    '        \'<p id="cf-party-join-err" class="cf-party-err" hidden></p>\' +\n'
    '        "</section>" +'
)

new_join = (
    '\'<section class="cf-party-pane" data-party-pane="join">\' +\n'
    '        \'<p class="cf-party-hint">Enter a 4-digit party code from your friend.</p>\' +\n'
    '        \'<label class="cf-party-label" for="cf-party-code">Party code</label>\' +\n'
    '        \'<input type="text" id="cf-party-code" class="cf-party-input" maxlength="6" inputmode="numeric" placeholder="1234">\' +\n'
    '        \'<div class="cf-party-turnstile" id="cf-party-turnstile-join"></div>\' +\n'
    '        \'<button type="button" class="cf-party-btn is-primary" id="cf-party-join">Join party</button>\' +\n'
    '        \'<p id="cf-party-join-err" class="cf-party-err" hidden></p>\' +\n'
    '        "</section>" +'
)

if "cf-party-turnstile-create" not in t:
    if old_create not in t:
        raise SystemExit("create pane markup not found")
    if old_join not in t:
        raise SystemExit("join pane markup not found")
    t = t.replace(old_create, new_create, 1).replace(old_join, new_join, 1)
    print("HTML slots added")
else:
    print("HTML slots already present")

helper = r'''
  var partyTurnstile = { create: null, join: null, createToken: "", joinToken: "" };

  function partyNeedsTurnstile() {
    return !!(window.APP && APP.turnstileSiteKey);
  }

  function renderPartyTurnstile(which) {
    var key = (window.APP && APP.turnstileSiteKey) || "";
    if (!key || !window.turnstile) return;
    var id = which === "join" ? "cf-party-turnstile-join" : "cf-party-turnstile-create";
    var el = document.getElementById(id);
    if (!el) return;
    try {
      if (which === "join" && partyTurnstile.join != null) {
        window.turnstile.remove(partyTurnstile.join);
        partyTurnstile.join = null;
      }
      if (which !== "join" && partyTurnstile.create != null) {
        window.turnstile.remove(partyTurnstile.create);
        partyTurnstile.create = null;
      }
    } catch (e) {}
    el.innerHTML = "";
    try {
      var wid = window.turnstile.render(el, {
        sitekey: key,
        theme: "dark",
        callback: function (token) {
          if (which === "join") partyTurnstile.joinToken = token || "";
          else partyTurnstile.createToken = token || "";
        },
        "expired-callback": function () {
          if (which === "join") partyTurnstile.joinToken = "";
          else partyTurnstile.createToken = "";
        },
        "error-callback": function () {
          if (which === "join") partyTurnstile.joinToken = "";
          else partyTurnstile.createToken = "";
        },
      });
      if (which === "join") partyTurnstile.join = wid;
      else partyTurnstile.create = wid;
    } catch (err) {}
  }

  function resetPartyTurnstile(which) {
    if (which === "join") partyTurnstile.joinToken = "";
    else partyTurnstile.createToken = "";
    if (!window.turnstile) return;
    var wid = which === "join" ? partyTurnstile.join : partyTurnstile.create;
    if (wid != null) {
      try {
        window.turnstile.reset(wid);
      } catch (e) {
        renderPartyTurnstile(which);
      }
    } else {
      renderPartyTurnstile(which);
    }
  }

  function ensurePartyTurnstiles() {
    if (!partyNeedsTurnstile()) return;
    var tries = 0;
    (function boot() {
      tries += 1;
      if (window.turnstile) {
        renderPartyTurnstile("create");
        renderPartyTurnstile("join");
        return;
      }
      if (tries < 40) setTimeout(boot, 100);
    })();
  }

'''

if "var partyTurnstile" not in t:
    marker = "  function ensurePartyPanel() {"
    if marker not in t:
        raise SystemExit("ensurePartyPanel not found")
    t = t.replace(marker, helper + marker, 1)
    print("helpers inserted")
else:
    print("helpers already present")

old_open = """  function openPartyPanel(tab) {
    var $p = ensurePartyPanel();
    $p.removeAttr("hidden");
    $("body").addClass("cf-party-open");
    if (tab) switchPartyTab(tab);
  }"""
new_open = """  function openPartyPanel(tab) {
    var $p = ensurePartyPanel();
    $p.removeAttr("hidden");
    $("body").addClass("cf-party-open");
    if (tab) switchPartyTab(tab);
    else switchPartyTab("create");
    ensurePartyTurnstiles();
  }"""
if "ensurePartyTurnstiles()" not in t.split("function openPartyPanel", 1)[-1][:300]:
    if old_open not in t:
        raise SystemExit("openPartyPanel block missing")
    t = t.replace(old_open, new_open, 1)
    print("openPartyPanel patched")
else:
    print("openPartyPanel already patched")

old_switch = """  function switchPartyTab(tab) {
    var $p = $("#cf-party-panel");
    $p.find("[data-party-tab]").removeClass("is-active").attr("aria-selected", "false");
    $p.find('[data-party-tab="' + tab + '"]').addClass("is-active").attr("aria-selected", "true");
    $p.find("[data-party-pane]").removeClass("is-active");
    $p.find('[data-party-pane="' + tab + '"]').addClass("is-active");
  }"""
new_switch = """  function switchPartyTab(tab) {
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
  }"""
if 'renderPartyTurnstile(tab === "join"' not in t:
    if old_switch not in t:
        raise SystemExit("switchPartyTab block missing")
    t = t.replace(old_switch, new_switch, 1)
    print("switchPartyTab patched")
else:
    print("switchPartyTab already patched")

old_ajax_create = """    return $.ajax({
      url: base() + "/api/party",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        hostId: hostId,
        content: {
          type: item.type,
          id: item.id,
          title: item.title,
          poster: item.poster,
          year: item.year,
          url: url,
        },
      }),
    }).then(function (data) {"""

new_ajax_create = """    var $cerr = $("#cf-party-create-err").prop("hidden", true).text("");
    if (partyNeedsTurnstile() && !partyTurnstile.createToken) {
      $cerr.text("Complete the captcha verification.").prop("hidden", false);
      return $.Deferred().reject({ responseJSON: { error: "Complete the captcha verification." } }).promise();
    }
    return $.ajax({
      url: base() + "/api/party",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        hostId: hostId,
        turnstileToken: partyTurnstile.createToken || undefined,
        content: {
          type: item.type,
          id: item.id,
          title: item.title,
          poster: item.poster,
          year: item.year,
          url: url,
        },
      }),
    }).then(function (data) {"""

if "turnstileToken: partyTurnstile.createToken" not in t:
    if old_ajax_create not in t:
        raise SystemExit("create ajax block missing")
    t = t.replace(old_ajax_create, new_ajax_create, 1)
    print("create ajax patched")
else:
    print("create ajax already patched")

success_anchor = """      var share = watch.replace("&host=1", "");
      $("#cf-party-created")"""
success_repl = """      var share = watch.replace("&host=1", "");
      resetPartyTurnstile("create");
      $("#cf-party-created")"""
if 'resetPartyTurnstile("create")' not in t:
    if success_anchor not in t:
        raise SystemExit("create success anchor missing")
    t = t.replace(success_anchor, success_repl, 1)
    print("create success reset added")
else:
    print("create success reset already")

old_join_ajax = """    $.ajax({
      url: base() + "/api/party/" + encodeURIComponent(code) + "/join",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({ peerId: peerId() }),
    })"""
new_join_ajax = """    if (partyNeedsTurnstile() && !partyTurnstile.joinToken) {
      $err.text("Complete the captcha verification.").prop("hidden", false);
      return;
    }
    $.ajax({
      url: base() + "/api/party/" + encodeURIComponent(code) + "/join",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({ peerId: peerId(), turnstileToken: partyTurnstile.joinToken || undefined }),
    })"""
if "turnstileToken: partyTurnstile.joinToken" not in t:
    if old_join_ajax not in t:
        raise SystemExit("join ajax missing")
    t = t.replace(old_join_ajax, new_join_ajax, 1)
    print("join ajax patched")
else:
    print("join ajax already patched")

old_join_fail_msg = """          $err.text((data && data.error) || "Could not join").prop("hidden", false);
          return;"""
new_join_fail_msg = """          $err.text((data && data.error) || "Could not join").prop("hidden", false);
          resetPartyTurnstile("join");
          return;"""
if old_join_fail_msg in t:
    t = t.replace(old_join_fail_msg, new_join_fail_msg, 1)
    print("join fail reset added")

old_join_fail2 = """      .fail(function () {
        $err.text("Could not join party").prop("hidden", false);
      });"""
new_join_fail2 = """      .fail(function () {
        $err.text("Could not join party").prop("hidden", false);
        resetPartyTurnstile("join");
      });"""
if old_join_fail2 in t:
    t = t.replace(old_join_fail2, new_join_fail2, 1)
    print("join network fail reset added")

old_pick_fail = """    }).fail(function (xhr) {
      $("#cf-party-results").html(
        '<div class="cf-party-empty">' +
          esc((xhr.responseJSON && xhr.responseJSON.error) || "Could not create party") +
          "</div>"
      );
    });"""
new_pick_fail = """    }).fail(function (xhr) {
      resetPartyTurnstile("create");
      var msg = (xhr.responseJSON && xhr.responseJSON.error) || "Could not create party";
      $("#cf-party-create-err").text(msg).prop("hidden", false);
      $("#cf-party-results").html(
        '<div class="cf-party-empty">' + esc(msg) + "</div>"
      );
    });"""
if "cf-party-create-err" in t and "resetPartyTurnstile(\"create\")" in t and "Could not create party" in t and old_pick_fail not in t:
    print("pick fail already patched or different")
elif old_pick_fail in t:
    t = t.replace(old_pick_fail, new_pick_fail, 1)
    print("pick fail patched")
else:
    print("WARN pick fail block missing")

JS.write_text(t)
print("JS ok bytes", len(t))

# CSS
css = CSS.read_text()
snippet = """
.cf-party-turnstile {
  display: flex;
  justify-content: center;
  margin: 0.75rem 0 0.35rem;
  min-height: 0.25rem;
}
.cf-party-turnstile iframe {
  max-width: 100%;
}
"""
if ".cf-party-turnstile" not in css:
    CSS.write_text(css.rstrip() + "\n" + snippet)
    print("CSS added")
else:
    print("CSS already present")

# Routes: verify turnstile on create + join
routes = ROUTES.read_text()
old_create_route = """$router->map(['POST'], '/api/party', function () {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::create($body), 200);
});"""
new_create_route = """$router->map(['POST'], '/api/party', function () {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::create($body), 200);
});"""
old_join_route = """$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::join((string) $p['code'], $body));
});"""
new_join_route = """$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::join((string) $p['code'], $body));
});"""

if "Captcha verification failed." in routes and "/api/party" in routes:
    # check both
    if "api/party/{code}/join" in routes and routes.count("Captcha verification failed.") >= 2:
        print("routes already have captcha checks")
    else:
        if old_create_route in routes:
            routes = routes.replace(old_create_route, new_create_route, 1)
            print("create route patched")
        if old_join_route in routes:
            routes = routes.replace(old_join_route, new_join_route, 1)
            print("join route patched")
        ROUTES.write_text(routes)
else:
    if old_create_route not in routes:
        raise SystemExit("create route missing")
    if old_join_route not in routes:
        raise SystemExit("join route missing")
    routes = routes.replace(old_create_route, new_create_route, 1).replace(old_join_route, new_join_route, 1)
    ROUTES.write_text(routes)
    print("routes patched")

# bump asset versions
layout = LAYOUT.read_text()
layout2 = layout.replace("20260803-ui137", "20260803-ui138")
if layout2 == layout:
    layout2 = layout.replace("20260803-ui136", "20260803-ui138")
LAYOUT.write_text(layout2)
print("layout bumped")

print("DONE")
