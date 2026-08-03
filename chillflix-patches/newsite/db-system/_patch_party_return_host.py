#!/usr/bin/env python3
"""
Keep party alive if host navigates away; show Return to party bar.
Only explicit Leave/End (or 20m host idle) closes the room.
"""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
wp = root / "app/Services/WatchParty.php"
player_js = root / "public/assets/js/player.js"
party_js = root / "public/assets/js/continue-party.js"
party_css = root / "public/assets/css/continue-party.css"
main_layout = root / "app/Views/layouts/main.php"
player_layout = root / "app/Views/layouts/player.php"

# --- idle close after host stops reporting ---
wp_t = wp.read_text()
if "HOST_IDLE_CLOSE_SEC" not in wp_t:
    wp_t = wp_t.replace(
        "    private const TTL_SEC = 21600; // 6h\n    private const MAX_BODY = 4096;\n",
        "    private const TTL_SEC = 21600; // 6h hard cap\n"
        "    private const HOST_IDLE_CLOSE_SEC = 1200; // 20m without host updates\n"
        "    private const MAX_BODY = 4096;\n",
        1,
    )
    wp_t = wp_t.replace(
        """        $updated = (int) ($data['updatedAt'] ?? 0);
        if ($updated > 0 && (time() - $updated) > self::TTL_SEC) {
            @unlink($path);
            return null;
        }
        return $data;""",
        """        $updated = (int) ($data['updatedAt'] ?? 0);
        $age = $updated > 0 ? (time() - $updated) : 0;
        if ($updated > 0 && $age > self::TTL_SEC) {
            @unlink($path);
            return null;
        }
        // Host stopped reporting (left the player) — end for everyone after idle window
        if ($updated > 0 && $age > self::HOST_IDLE_CLOSE_SEC) {
            @unlink($path);
            return null;
        }
        return $data;""",
        1,
    )
    wp.write_text(wp_t)
    print("WatchParty idle close 20m")
else:
    print("WatchParty idle already set")

# --- player.js: stop auto-close on unload; save hosting resume ---
js = player_js.read_text()

# Remove unload binding from ensureParty
js = js.replace(
    """          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
          bindPartyUnload();
        } else {""",
    """          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
          saveHostingResume();
        } else {""",
    1,
)

# Replace paint/leave helper block: remove unload close, add saveHostingResume
old_helpers_start = "    function clearPartyFromUrl() {"
if old_helpers_start not in js:
    raise SystemExit("clearPartyFromUrl missing")

# Find from clearPartyFromUrl through bindPartyUnload and replace that whole section
m = re.search(
    r"    function clearPartyFromUrl\(\) \{.*?\n    function bindPartyUnload\(\) \{.*?\n    \}\n\n    async function partyReport",
    js,
    flags=re.S,
)
if not m:
    raise SystemExit("helper block not found")

new_helpers = r'''    function clearPartyFromUrl() {
      try {
        const u = new URL(location.href);
        u.searchParams.delete("party");
        u.searchParams.delete("host");
        history.replaceState(null, "", u.toString());
      } catch (_) {}
    }

    function hostingStoreKey() {
      return "cf_party_hosting_v1";
    }

    function saveHostingResume() {
      if (!state.party || state.party.role !== "host") return;
      try {
        const watch =
          (cfg.watchUrl || location.href).split("#")[0];
        const u = new URL(watch, location.origin);
        u.searchParams.set("party", state.party.code);
        u.searchParams.set("host", "1");
        u.searchParams.set("play", "1");
        sessionStorage.setItem(
          hostingStoreKey(),
          JSON.stringify({
            code: state.party.code,
            hostId: state.partyHostId || partyPeerId(),
            url: u.toString(),
            title: cfg.title || "Watch Party",
            updated: Date.now(),
          })
        );
      } catch (_) {}
      try {
        window.ChillflixParty?.paintResume?.();
      } catch (_) {}
    }

    function clearHostingResume() {
      try {
        sessionStorage.removeItem(hostingStoreKey());
      } catch (_) {}
      try {
        window.ChillflixParty?.paintResume?.();
      } catch (_) {}
    }

    function stopPartyLocal(message) {
      if (state.partyTimer) {
        clearInterval(state.partyTimer);
        state.partyTimer = null;
      }
      const code = state.party && state.party.code;
      if (code) {
        try {
          sessionStorage.removeItem("cf_party_host_" + code);
        } catch (_) {}
      }
      state.party = null;
      state.partyHostId = null;
      state.partyUnloadBound = null;
      els.shell?.querySelector("#np-party-chip")?.remove();
      clearPartyFromUrl();
      clearHostingResume();
      if (message) setStatus(message);
    }

    function partyLeavePayload() {
      const peer = partyPeerId();
      const hostId = state.partyHostId || sessionStorage.getItem("cf_party_host_" + (state.party?.code || "")) || peer;
      return {
        peerId: peer,
        hostId: state.party?.role === "host" ? hostId : undefined,
      };
    }

    async function leaveParty(confirmHost) {
      if (!state.party) return;
      if (state.party.role === "host" && confirmHost) {
        const ok = window.confirm("End Watch Party for everyone?");
        if (!ok) return;
      }
      const base = partyApiBase();
      const code = state.party.code;
      const role = state.party.role;
      const path = role === "host" ? "close" : "leave";
      try {
        await fetch(`${base}/${encodeURIComponent(code)}/${path}`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(partyLeavePayload()),
          keepalive: true,
        });
      } catch (_) {}
      stopPartyLocal(role === "host" ? "Watch Party ended" : "Left Watch Party");
    }

    async function partyReport'''

js = js[: m.start()] + new_helpers + js[m.end() :]

# destroy(): do NOT auto-close party (host may soft-nav home and return)
js = js.replace(
    """      destroy() {
        if (state.party && state.party.role === "host") partyCloseBeacon();
        if (state.partyTimer) clearInterval(state.partyTimer);
        if (state.partyUnloadBound) {
          window.removeEventListener("pagehide", state.partyUnloadBound);
          window.removeEventListener("beforeunload", state.partyUnloadBound);
          state.partyUnloadBound = null;
        }
        try {
          const v = els.video;
          if (v) cwSave(cfg, v.currentTime, v.duration, { forcePush: true });
        } catch (_) {}
        destroyHls();
        els.shell?.remove();
      },""",
    """      destroy() {
        // Keep host party alive so they can return from Home / other pages
        if (state.party && state.party.role === "host") saveHostingResume();
        if (state.partyTimer) clearInterval(state.partyTimer);
        state.partyTimer = null;
        try {
          const v = els.video;
          if (v) cwSave(cfg, v.currentTime, v.duration, { forcePush: true });
        } catch (_) {}
        destroyHls();
        els.shell?.remove();
      },""",
    1,
)

# Refresh hosting resume timestamp on successful report
js = js.replace(
    """        const data = await res.json().catch(() => null);
        if (data && data.ok === false) {
          stopPartyLocal(data.error || "Watch Party ended");
        }
      } catch (_) {}
    }""",
    """        const data = await res.json().catch(() => null);
        if (data && data.ok === false) {
          stopPartyLocal(data.error || "Watch Party ended");
        } else if (data && data.ok) {
          saveHostingResume();
        }
      } catch (_) {}
    }""",
    1,
)

# Soft-nav away from watch: tear down player without ending the party
if 'window.addEventListener("cf:softnav"' not in js and "cf:softnav" not in js.split("ChillflixPlayer")[-1]:
    js = js.replace(
        """  window.ChillflixPlayer = {
    mount,
    startOnWatch(cfg) {""",
        """  window.addEventListener("cf:softnav", () => {
    if (!active) return;
    try {
      active.destroy();
    } catch (_) {}
    active = null;
  });

  window.ChillflixPlayer = {
    mount,
    startOnWatch(cfg) {""",
        1,
    )

player_js.write_text(js)
print("player.js return-to-host mode")

# --- continue-party.js: floating resume bar ---
pj = party_js.read_text()
if "paintPartyResume" not in pj:
    hook = '  window.ChillflixParty = { open: openPartyPanel, close: closePartyPanel };'
    if hook not in pj:
        raise SystemExit("ChillflixParty export missing")
    resume_js = r'''
  var PARTY_HOSTING_KEY = "cf_party_hosting_v1";

  function readHostingResume() {
    try {
      var raw = sessionStorage.getItem(PARTY_HOSTING_KEY);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || !data.code || !data.url) return null;
      // Drop stale local resume after 25 minutes
      if (data.updated && Date.now() - Number(data.updated) > 25 * 60 * 1000) {
        sessionStorage.removeItem(PARTY_HOSTING_KEY);
        return null;
      }
      return data;
    } catch (e) {
      return null;
    }
  }

  function clearHostingResume() {
    try {
      sessionStorage.removeItem(PARTY_HOSTING_KEY);
    } catch (e) {}
  }

  function ensureResumeBar() {
    var $el = $("#cf-party-resume");
    if ($el.length) return $el;
    $el = $(
      '<div id="cf-party-resume" class="cf-party-resume" hidden>' +
        '<div class="cf-party-resume-inner">' +
        '<div class="cf-party-resume-copy">' +
        '<span class="cf-party-resume-kicker">Hosting</span>' +
        '<strong class="cf-party-resume-code"></strong>' +
        '<span class="cf-party-resume-title"></span>' +
        "</div>" +
        '<div class="cf-party-resume-actions">' +
        '<a class="cf-party-resume-return" href="#">Return</a>' +
        '<button type="button" class="cf-party-resume-end" data-party-end>End</button>' +
        "</div></div></div>"
    );
    $("body").append($el);
    return $el;
  }

  function paintPartyResume() {
    var data = readHostingResume();
    var $el = ensureResumeBar();
    if (!data) {
      $el.attr("hidden", true);
      return;
    }
    // Already on the hosted watch URL — hide bar
    try {
      var here = new URL(location.href);
      var there = new URL(data.url, location.origin);
      if (
        here.pathname === there.pathname &&
        (here.searchParams.get("party") || "").toUpperCase() === String(data.code).toUpperCase()
      ) {
        $el.attr("hidden", true);
        return;
      }
    } catch (e0) {}
    $el.find(".cf-party-resume-code").text(data.code);
    $el.find(".cf-party-resume-title").text(data.title || "Watch Party");
    $el.find(".cf-party-resume-return").attr("href", data.url);
    $el.removeAttr("hidden");
  }

  async function endHostingFromBar() {
    var data = readHostingResume();
    if (!data) {
      paintPartyResume();
      return;
    }
    if (!window.confirm("End Watch Party for everyone?")) return;
    var api = ((window.APP && APP.partyApi) || ((window.APP && APP.baseUrl) || "") + "/api/party").replace(/\/$/, "");
    try {
      await fetch(api + "/" + encodeURIComponent(data.code) + "/close", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ hostId: data.hostId || undefined, peerId: data.hostId || undefined }),
        keepalive: true,
      });
    } catch (e) {}
    clearHostingResume();
    paintPartyResume();
  }

  $(document).on("click", "[data-party-end]", function (e) {
    e.preventDefault();
    endHostingFromBar();
  });

  window.ChillflixParty = {
    open: openPartyPanel,
    close: closePartyPanel,
    paintResume: paintPartyResume,
    clearHosting: clearHostingResume,
  };
'''
    pj = pj.replace(hook, resume_js, 1)
    # boot resume bar
    pj = pj.replace(
        """  $(function () {
    bootContinue();
    // Re-check shortly after load in case watch tab wrote storage just before nav
    setTimeout(bootContinue, 250);
    setTimeout(bootContinue, 1200);
  });""",
        """  $(function () {
    bootContinue();
    paintPartyResume();
    // Re-check shortly after load in case watch tab wrote storage just before nav
    setTimeout(bootContinue, 250);
    setTimeout(bootContinue, 1200);
    setTimeout(paintPartyResume, 300);
  });""",
        1,
    )
    if 'window.addEventListener("cf:softnav"' not in pj and "cf:softnav" not in pj:
        pj = pj.replace(
            '  window.addEventListener("pageshow", bootContinue);',
            '  window.addEventListener("pageshow", bootContinue);\n'
            '  window.addEventListener("cf:softnav", function () { paintPartyResume(); });\n'
            '  window.addEventListener("pageshow", paintPartyResume);',
            1,
        )
    party_js.write_text(pj)
    print("continue-party.js resume bar")
else:
    print("resume bar already present")

# --- CSS ---
css = party_css.read_text()
if ".cf-party-resume" not in css:
    css += """

/* Host stepped away — return to party */
.cf-party-resume[hidden] { display: none !important; }
.cf-party-resume {
  position: fixed;
  left: 50%;
  bottom: calc(4.6rem + env(safe-area-inset-bottom, 0px));
  transform: translateX(-50%);
  z-index: 1250;
  width: min(26rem, calc(100% - 1.25rem));
  pointer-events: none;
}
@media (min-width: 992px) {
  .cf-party-resume {
    bottom: 1.25rem;
    left: auto;
    right: max(15px, calc((100vw - min(100vw, 1800px)) / 2 + 15px));
    transform: none;
    width: min(22rem, calc(100% - 2rem));
  }
}
.cf-party-resume-inner {
  pointer-events: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .65rem;
  padding: .55rem .6rem .55rem .8rem;
  border-radius: .85rem;
  background:
    linear-gradient(180deg, rgba(255,255,255,.08), transparent 48%),
    rgba(16, 18, 26, .92);
  border: 1px solid rgba(220, 53, 69, .4);
  box-shadow: 0 12px 30px rgba(0,0,0,.45);
  backdrop-filter: blur(16px) saturate(1.2);
  -webkit-backdrop-filter: blur(16px) saturate(1.2);
  color: #eef1f6;
}
.cf-party-resume-copy {
  min-width: 0;
  display: grid;
  gap: .1rem;
}
.cf-party-resume-kicker {
  font-size: .62rem;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #ff8b76;
}
.cf-party-resume-code {
  font-size: 1rem;
  letter-spacing: .12em;
  font-weight: 800;
  line-height: 1.1;
}
.cf-party-resume-title {
  font-size: .72rem;
  color: #9aa3b2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 11rem;
}
.cf-party-resume-actions {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  flex: 0 0 auto;
}
.cf-party-resume-return,
.cf-party-resume-end {
  appearance: none;
  border: 0;
  border-radius: .55rem;
  padding: .48rem .7rem;
  font-size: .75rem;
  font-weight: 750;
  text-decoration: none;
  cursor: pointer;
  line-height: 1;
}
.cf-party-resume-return {
  background: linear-gradient(180deg, #ef5b45, #c43c2e);
  color: #fff;
}
.cf-party-resume-end {
  background: rgba(255,255,255,.08);
  color: #e8edf5;
  border: 1px solid rgba(255,255,255,.1);
}
"""
    party_css.write_text(css)
    print("continue-party.css resume bar")
else:
    print("resume css already present")

for layout, ver in ((main_layout, "ui148"), (player_layout, "ui148")):
    lt = layout.read_text()
    layout.write_text(re.sub(r"\?v=2026080\d-ui\d+", f"?v=20260803-{ver}", lt))
print("assets ui148")
print("DONE")
