#!/usr/bin/env python3
"""Host leave closes party; guest leave exits; Leave button + unload close."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
wp = root / "app/Services/WatchParty.php"
routes = root / "app/routes.php"
player_js = root / "public/assets/js/player.js"
player_css = root / "public/assets/css/player.css"
player_layout = root / "app/Views/layouts/player.php"

# --- WatchParty.php: add close() + leave() before readJsonBody ---
wp_t = wp.read_text()
if "public static function close(" not in wp_t:
    insert = '''
    /** Host ends the party for everyone. */
    public static function close(string $code, array $payload = []): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        $hostId = self::str($payload['hostId'] ?? '', 64);
        if ($hostId === '' || $hostId !== ($room['hostId'] ?? '')) {
            return ['ok' => false, 'error' => 'Only the host can close this party'];
        }
        self::destroy($code);
        return ['ok' => true, 'closed' => true];
    }

    /**
     * Guest leaves, or host leave closes the room.
     * @return array{ok:bool,closed?:bool,left?:bool,error?:string}
     */
    public static function leave(string $code, array $payload = []): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => true, 'closed' => true, 'alreadyGone' => true];
        }
        $peerId = self::str($payload['peerId'] ?? '', 64);
        $hostId = self::str($payload['hostId'] ?? '', 64);
        $isHost = ($hostId !== '' && $hostId === ($room['hostId'] ?? ''))
            || ($peerId !== '' && $peerId === ($room['hostId'] ?? ''));
        if ($isHost) {
            self::destroy($code);
            return ['ok' => true, 'closed' => true];
        }
        $room['peers'] = max(1, (int) ($room['peers'] ?? 1) - 1);
        $room['updatedAt'] = time();
        self::write($code, $room);
        return ['ok' => true, 'left' => true, 'room' => self::publicRoom($room)];
    }

    private static function destroy(string $code): void
    {
        $path = self::path($code);
        if (is_file($path)) {
            @unlink($path);
        }
    }

'''
    marker = "    public static function readJsonBody(): array\n"
    if marker not in wp_t:
        raise SystemExit("readJsonBody marker missing")
    wp.write_text(wp_t.replace(marker, insert + marker, 1))
    print("WatchParty close/leave added")
else:
    print("WatchParty already has close")

# --- routes ---
rt = routes.read_text()
if "/api/party/{code}/leave" not in rt:
    old = """$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::join((string) $p['code'], $body));
});
"""
    new = """$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::join((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/leave', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::leave((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/close', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::close((string) $p['code'], $body));
});
"""
    if old not in rt:
        raise SystemExit("join route block not found")
    routes.write_text(rt.replace(old, new, 1))
    print("routes leave/close added")
else:
    print("routes already have leave")

# --- player.js ---
js = player_js.read_text()

# Replace paintPartyChip + add leave helpers after partyPoll block, and enhance ensureParty/partyPoll/destroy
old_paint = """    function paintPartyChip() {
      if (!state.party || !els.shell) return;
      let chip = els.shell.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        els.shell.querySelector(".np-top-actions")?.appendChild(chip);
      }
      chip.textContent =
        state.party.role === "host"
          ? `Party ${state.party.code} · Host`
          : `Party ${state.party.code}`;
    }"""

new_paint = """    function paintPartyChip() {
      if (!state.party || !els.shell) return;
      let chip = els.shell.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        chip.innerHTML =
          '<span class="np-party-chip-label"></span>' +
          '<button type="button" class="np-party-leave" id="np-party-leave" aria-label="Leave party">Leave</button>';
        els.shell.querySelector(".np-top-actions")?.appendChild(chip);
        chip.querySelector("#np-party-leave")?.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          leaveParty(true);
        });
      }
      const label = chip.querySelector(".np-party-chip-label");
      if (label) {
        label.textContent =
          state.party.role === "host"
            ? `Party ${state.party.code} · Host`
            : `Party ${state.party.code}`;
      }
    }

    function clearPartyFromUrl() {
      try {
        const u = new URL(location.href);
        u.searchParams.delete("party");
        u.searchParams.delete("host");
        history.replaceState(null, "", u.toString());
      } catch (_) {}
    }

    function stopPartyLocal(message) {
      if (state.partyTimer) {
        clearInterval(state.partyTimer);
        state.partyTimer = null;
      }
      if (state.partyUnloadBound) {
        window.removeEventListener("pagehide", state.partyUnloadBound);
        window.removeEventListener("beforeunload", state.partyUnloadBound);
        state.partyUnloadBound = null;
      }
      const code = state.party && state.party.code;
      if (code) {
        try {
          sessionStorage.removeItem("cf_party_host_" + code);
        } catch (_) {}
      }
      state.party = null;
      state.partyHostId = null;
      els.shell?.querySelector("#np-party-chip")?.remove();
      clearPartyFromUrl();
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

    function partyCloseBeacon() {
      if (!state.party || state.party.role !== "host") return;
      const base = partyApiBase();
      const body = JSON.stringify(partyLeavePayload());
      const url = `${base}/${encodeURIComponent(state.party.code)}/close`;
      try {
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: "application/json" });
          navigator.sendBeacon(url, blob);
          return;
        }
      } catch (_) {}
      try {
        fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body,
          keepalive: true,
        });
      } catch (_) {}
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

    function bindPartyUnload() {
      if (!state.party || state.party.role !== "host" || state.partyUnloadBound) return;
      state.partyUnloadBound = () => partyCloseBeacon();
      window.addEventListener("pagehide", state.partyUnloadBound);
      window.addEventListener("beforeunload", state.partyUnloadBound);
    }"""

if old_paint not in js:
    raise SystemExit("paintPartyChip block not found")
js = js.replace(old_paint, new_paint, 1)

# ensureParty: bind unload for host
js = js.replace(
    """        if (state.party.role === "host") {
          // hostId from create flow, or claim via first update using session peer
          state.partyHostId = sessionStorage.getItem("cf_party_host_" + state.party.code) || peer;
          sessionStorage.setItem("cf_party_host_" + state.party.code, state.partyHostId);
          setStatus("Watch Party host · " + state.party.code);
          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
        } else {""",
    """        if (state.party.role === "host") {
          // hostId from create flow, or claim via first update using session peer
          state.partyHostId = sessionStorage.getItem("cf_party_host_" + state.party.code) || peer;
          sessionStorage.setItem("cf_party_host_" + state.party.code, state.partyHostId);
          setStatus("Watch Party host · " + state.party.code);
          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
          bindPartyUnload();
        } else {""",
    1,
)

# Guest join: if room missing, stop
js = js.replace(
    """        } else {
          await fetch(`${base}/${encodeURIComponent(state.party.code)}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ peerId: peer }),
          });
          setStatus("Watch Party · synced to host · " + state.party.code);
          state.partyTimer = setInterval(() => partyPoll(), 2000);
          partyPoll();
        }
        paintPartyChip();
      } catch (_) {}
    }""",
    """        } else {
          const joinRes = await fetch(`${base}/${encodeURIComponent(state.party.code)}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ peerId: peer }),
          });
          const joinData = await joinRes.json().catch(() => null);
          if (!joinData?.ok) {
            stopPartyLocal((joinData && joinData.error) || "Watch Party ended");
            return;
          }
          setStatus("Watch Party · synced to host · " + state.party.code);
          state.partyTimer = setInterval(() => partyPoll(), 2000);
          partyPoll();
        }
        paintPartyChip();
      } catch (_) {}
    }""",
    1,
)

# partyPoll: detect closed room
js = js.replace(
    """        const data = await res.json();
        if (!data?.ok || !data.room) return;
        const room = data.room;""",
    """        const data = await res.json();
        if (!data?.ok || !data.room) {
          stopPartyLocal("Host left — party ended");
          return;
        }
        const room = data.room;""",
    1,
)

# destroy(): close if host
js = js.replace(
    """      destroy() {
        if (state.partyTimer) clearInterval(state.partyTimer);
        try {
          const v = els.video;
          if (v) cwSave(cfg, v.currentTime, v.duration, { forcePush: true });
        } catch (_) {}
        destroyHls();
        els.shell?.remove();
      },""",
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
    1,
)

# Add partyUnloadBound to state init
js = js.replace(
    """      party: partyFromUrl(),
      partyTimer: null,
      partyApplying: false,
      partyHostId: null,""",
    """      party: partyFromUrl(),
      partyTimer: null,
      partyApplying: false,
      partyHostId: null,
      partyUnloadBound: null,""",
    1,
)

# Host report: if update fails because room gone, stop
js = js.replace(
    """        await fetch(`${base}/${encodeURIComponent(state.party.code)}`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            hostId: state.partyHostId || partyPeerId(),
            paused: !!v.paused,
            t: v.currentTime || 0,
            duration: v.duration || 0,
            content: {
              type: cfg.type,
              id: cfg.id,
              title: cfg.title,
              poster: cfg.poster,
              year: cfg.year,
              season: cfg.season,
              episode: cfg.episode,
              url: cfg.watchUrl || location.href,
            },
          }),
          keepalive: !!force,
        });
      } catch (_) {}
    }""",
    """        const res = await fetch(`${base}/${encodeURIComponent(state.party.code)}`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            hostId: state.partyHostId || partyPeerId(),
            paused: !!v.paused,
            t: v.currentTime || 0,
            duration: v.duration || 0,
            content: {
              type: cfg.type,
              id: cfg.id,
              title: cfg.title,
              poster: cfg.poster,
              year: cfg.year,
              season: cfg.season,
              episode: cfg.episode,
              url: cfg.watchUrl || location.href,
            },
          }),
          keepalive: !!force,
        });
        const data = await res.json().catch(() => null);
        if (data && data.ok === false) {
          stopPartyLocal(data.error || "Watch Party ended");
        }
      } catch (_) {}
    }""",
    1,
)

player_js.write_text(js)
print("player.js leave/close wired")

# --- CSS ---
css = player_css.read_text()
old_chip = """.np-party-chip {
  display: inline-flex;
  align-items: center;
  padding: .28rem .55rem;
  border-radius: .45rem;
  background: rgba(220, 53, 69, .18);
  border: 1px solid rgba(220, 53, 69, .4);
  color: #ffc3c9;
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}"""
new_chip = """.np-party-chip {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  padding: .22rem .3rem .22rem .55rem;
  border-radius: .45rem;
  background: rgba(220, 53, 69, .18);
  border: 1px solid rgba(220, 53, 69, .4);
  color: #ffc3c9;
  font-size: .68rem;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.np-party-chip-label {
  white-space: nowrap;
}
.np-party-leave {
  appearance: none;
  border: 0;
  margin: 0;
  padding: .22rem .45rem;
  border-radius: .35rem;
  background: rgba(0, 0, 0, .35);
  color: #fff;
  font: inherit;
  font-size: .62rem;
  font-weight: 750;
  letter-spacing: .03em;
  text-transform: uppercase;
  cursor: pointer;
}
.np-party-leave:hover {
  background: rgba(255, 255, 255, .14);
}"""
if old_chip not in css:
    raise SystemExit("party chip css not found")
player_css.write_text(css.replace(old_chip, new_chip, 1))
print("player.css leave button")

# bump player layout assets
lt = player_layout.read_text()
player_layout.write_text(re.sub(r"\?v=2026080\d-ui\d+", "?v=20260803-ui147", lt))
print("player layout ui147")
print("DONE")
