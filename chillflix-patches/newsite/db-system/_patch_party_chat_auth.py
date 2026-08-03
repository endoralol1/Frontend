#!/usr/bin/env python3
"""Require login for Watch Party chat; use account name."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
wp = root / "app/Services/WatchParty.php"
watch = root / "app/Views/pages/watch.php"
party_js = root / "public/assets/js/continue-party.js"
party_css = root / "public/assets/css/continue-party.css"
main = root / "app/Views/layouts/main.php"
app_js = root / "public/assets/js/app.js"

# --- WatchParty: require Auth user on chat post; bind hostUserId ---
t = wp.read_text()

if "'hostUserId'" not in t:
    t = t.replace(
        """            'peers' => 1,
            'chatWritable' => true,
            'banned' => [],
            'names' => [],
        ];""",
        """            'peers' => 1,
            'chatWritable' => true,
            'banned' => [],
            'names' => [],
            'hostUserId' => (Auth::user()['id'] ?? null),
        ];""",
        1,
    )
    print("create hostUserId")

old_post = '''    public static function chatPost(string $code, array $payload): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired', 'closed' => true];
        }
        $peerId = self::str($payload['peerId'] ?? '', 64);
        if ($peerId === '') {
            return ['ok' => false, 'error' => 'Missing peer'];
        }
        if (self::isBanned($room, $peerId)) {
            return ['ok' => false, 'error' => 'You are banned from chat', 'banned' => true];
        }
        $isHost = $peerId === ($room['hostId'] ?? '');
        if (!$isHost && empty($room['chatWritable'])) {
            return ['ok' => false, 'error' => 'Host muted the chat'];
        }
        $text = self::str($payload['text'] ?? '', self::MAX_MSG);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Empty message'];
        }
        $name = self::str($payload['name'] ?? '', 20) ?: ('Guest ' . substr($peerId, -4));
        $names = is_array($room['names'] ?? null) ? $room['names'] : [];
        $names[$peerId] = $name;
        $room['names'] = $names;
        // Don't bump updatedAt on chat alone — host idle timer should track playback presence
        self::write($code, $room);

        $chat = self::readChat($code);
        $seq = (int) ($chat['seq'] ?? 0) + 1;
        $msg = [
            'id' => $seq,
            'peerId' => $peerId,
            'name' => $name,
            'text' => $text,
            'ts' => time(),
            'role' => $isHost ? 'host' : 'guest',
        ];
'''

new_post = '''    public static function chatPost(string $code, array $payload): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired', 'closed' => true];
        }
        $user = Auth::user();
        if (!$user) {
            return ['ok' => false, 'error' => 'Log in to chat', 'authRequired' => true];
        }
        $userId = self::str($user['id'] ?? '', 64);
        $peerId = $userId !== '' ? $userId : self::str($payload['peerId'] ?? '', 64);
        if ($peerId === '') {
            return ['ok' => false, 'error' => 'Missing peer'];
        }
        if (self::isBanned($room, $peerId)) {
            return ['ok' => false, 'error' => 'You are banned from chat', 'banned' => true];
        }
        $sessionHost = self::str($payload['hostId'] ?? '', 64);
        $isSessionHost = $sessionHost !== '' && $sessionHost === ($room['hostId'] ?? '');
        if ($isSessionHost && empty($room['hostUserId'])) {
            $room['hostUserId'] = $userId;
        }
        $isHost = ($userId !== '' && $userId === (string) ($room['hostUserId'] ?? ''))
            || $isSessionHost;
        if (!$isHost && empty($room['chatWritable'])) {
            return ['ok' => false, 'error' => 'Host muted the chat'];
        }
        $text = self::str($payload['text'] ?? '', self::MAX_MSG);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Empty message'];
        }
        $name = self::str($user['name'] ?? '', 20) ?: 'Member';
        $names = is_array($room['names'] ?? null) ? $room['names'] : [];
        $names[$peerId] = $name;
        $room['names'] = $names;
        // Don't bump updatedAt on chat alone — host idle timer should track playback presence
        self::write($code, $room);

        $chat = self::readChat($code);
        $seq = (int) ($chat['seq'] ?? 0) + 1;
        $msg = [
            'id' => $seq,
            'peerId' => $peerId,
            'userId' => $userId,
            'name' => $name,
            'text' => $text,
            'ts' => time(),
            'role' => $isHost ? 'host' : 'guest',
        ];
'''

if old_post not in t:
    raise SystemExit("chatPost block not found")
t = t.replace(old_post, new_post, 1)

# chatState: include auth + banned by user id
old_state = '''    public static function chatState(string $code, array $payload = []): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired', 'closed' => true];
        }
        $peerId = self::str($payload['peerId'] ?? ($_GET['peerId'] ?? ''), 64);
        $after = (int) ($payload['after'] ?? ($_GET['after'] ?? 0));
        $chat = self::readChat($code);
        $messages = [];
        foreach ($chat['messages'] as $m) {
            if ((int) ($m['id'] ?? 0) > $after) {
                $messages[] = $m;
            }
        }
        return [
            'ok' => true,
            'closed' => false,
            'chatWritable' => (bool) ($room['chatWritable'] ?? true),
            'banned' => $peerId !== '' && self::isBanned($room, $peerId),
            'isHost' => $peerId !== '' && $peerId === ($room['hostId'] ?? ''),
            'messages' => $messages,
            'seq' => (int) ($chat['seq'] ?? 0),
        ];
    }'''

new_state = '''    public static function chatState(string $code, array $payload = []): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired', 'closed' => true];
        }
        $user = Auth::user();
        $userId = $user ? self::str($user['id'] ?? '', 64) : '';
        $peerId = $userId !== '' ? $userId : self::str($payload['peerId'] ?? ($_GET['peerId'] ?? ''), 64);
        $after = (int) ($payload['after'] ?? ($_GET['after'] ?? 0));
        $chat = self::readChat($code);
        $messages = [];
        foreach ($chat['messages'] as $m) {
            if ((int) ($m['id'] ?? 0) > $after) {
                $messages[] = $m;
            }
        }
        $sessionHost = self::str($payload['hostId'] ?? ($_GET['hostId'] ?? ''), 64);
        $isHost = ($userId !== '' && $userId === (string) ($room['hostUserId'] ?? ''))
            || ($sessionHost !== '' && $sessionHost === ($room['hostId'] ?? ''));
        return [
            'ok' => true,
            'closed' => false,
            'authRequired' => $user === null,
            'user' => $user ? ['id' => $userId, 'name' => (string) ($user['name'] ?? '')] : null,
            'chatWritable' => (bool) ($room['chatWritable'] ?? true),
            'banned' => $peerId !== '' && self::isBanned($room, $peerId),
            'isHost' => $isHost,
            'messages' => $messages,
            'seq' => (int) ($chat['seq'] ?? 0),
        ];
    }'''

if old_state not in t:
    raise SystemExit("chatState block not found")
wp.write_text(t.replace(old_state, new_state, 1))
print("WatchParty auth chat")

# --- main.php: expose APP.user ---
mt = main.read_text()
old_app = """        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode($site, JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('main_origin', 'https://www.chillflix.lol'), JSON_UNESCAPED_SLASHES) ?>,
            turnstileSiteKey: <?= json_encode((string) config('turnstile_site_key', ''), JSON_UNESCAPED_SLASHES) ?>,
            imgBase: <?= json_encode(rtrim((string) config('tmdb_img'), '/'), JSON_UNESCAPED_SLASHES) ?>
        };"""
new_app = """        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode($site, JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('main_origin', 'https://www.chillflix.lol'), JSON_UNESCAPED_SLASHES) ?>,
            turnstileSiteKey: <?= json_encode((string) config('turnstile_site_key', ''), JSON_UNESCAPED_SLASHES) ?>,
            imgBase: <?= json_encode(rtrim((string) config('tmdb_img'), '/'), JSON_UNESCAPED_SLASHES) ?>,
            partyApi: <?= json_encode(url('/api/party'), JSON_UNESCAPED_SLASHES) ?>,
            user: <?= json_encode(Auth::user(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
        };"""
if old_app not in mt:
    raise SystemExit("APP block not found")
mt = mt.replace(old_app, new_app, 1)
mt = re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui152", mt)
main.write_text(mt)
print("APP.user + ui152")

# --- app.js: expose openBrowseAuth / auth user helpers ---
aj = app_js.read_text()
if "window.dispatchEvent(new Event('cf:auth'))" not in aj and 'new Event("cf:auth")' not in aj:
    old_apply = """  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
      try { nsSyncLibrary(); } catch (eSync) {}
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
  }"""
    new_apply = """  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    try { if (window.APP) APP.user = user || null; } catch (eAppU) {}
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
      try { nsSyncLibrary(); } catch (eSync) {}
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
    try { window.dispatchEvent(new Event('cf:auth')); } catch (eAuth) {}
  }

  window.openBrowseAuth = openBrowseAuth;
  window.getBrowseAuthUser = function () { return browseAuthUser; };
  window.refreshBrowseAuthUser = refreshBrowseAuthUser;"""
    if old_apply not in aj:
        raise SystemExit("applyBrowseAuthUser not found")
    # refreshBrowseAuthUser is defined AFTER applyBrowseAuthUser — expose after refreshBrowseAuthUser
    aj = aj.replace(old_apply, new_apply.replace(
        "\n\n  window.openBrowseAuth = openBrowseAuth;\n"
        "  window.getBrowseAuthUser = function () { return browseAuthUser; };\n"
        "  window.refreshBrowseAuthUser = refreshBrowseAuthUser;",
        "",
    ), 1)
    # Place exposes after refreshBrowseAuthUser function
    aj = aj.replace(
        """  function refreshBrowseAuthUser() {
    return authApi('/api/auth/me', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { applyBrowseAuthUser(data && data.user ? data.user : null); })
      .catch(function () { applyBrowseAuthUser(null); });
  }""",
        """  function refreshBrowseAuthUser() {
    return authApi('/api/auth/me', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { applyBrowseAuthUser(data && data.user ? data.user : null); })
      .catch(function () { applyBrowseAuthUser(null); });
  }
  window.openBrowseAuth = openBrowseAuth;
  window.getBrowseAuthUser = function () { return browseAuthUser; };
  window.refreshBrowseAuthUser = refreshBrowseAuthUser;""",
        1,
    )
    app_js.write_text(aj)
    print("app.js auth hooks")
else:
    print("app.js auth hooks exist")

# --- watch.php form: login gate, no free name field ---
w = watch.read_text()
old_form = """                        <p class="cf-party-chat-note" id="cf-party-chat-note" hidden></p>
                        <form class="cf-party-chat-form" id="cf-party-chat-form" autocomplete="off">
                            <input type="text" id="cf-party-chat-name" class="cf-party-chat-name" maxlength="20" placeholder="Name" aria-label="Chat name">
                            <input type="text" id="cf-party-chat-input" class="cf-party-chat-input" maxlength="200" placeholder="Say something…" aria-label="Chat message">
                            <button type="submit" class="cf-party-chat-send" id="cf-party-chat-send">Send</button>
                        </form>"""
new_form = """                        <p class="cf-party-chat-note" id="cf-party-chat-note" hidden></p>
                        <div class="cf-party-chat-login" id="cf-party-chat-login" hidden>
                            <p>Log in to join the party chat.</p>
                            <button type="button" class="cf-party-chat-login-btn" data-browse-auth="login">Log in</button>
                        </div>
                        <form class="cf-party-chat-form" id="cf-party-chat-form" autocomplete="off" hidden>
                            <div class="cf-party-chat-user" id="cf-party-chat-user"></div>
                            <input type="text" id="cf-party-chat-input" class="cf-party-chat-input" maxlength="200" placeholder="Say something…" aria-label="Chat message">
                            <button type="submit" class="cf-party-chat-send" id="cf-party-chat-send">Send</button>
                        </form>"""
if old_form not in w:
    raise SystemExit("chat form not found")
watch.write_text(w.replace(old_form, new_form, 1))
print("watch chat login gate")

# --- CSS ---
css = party_css.read_text()
if ".cf-party-chat-login" not in css:
    css = css.replace(
        """.cf-party-chat-form {
  display: grid;
  grid-template-columns: 5.5rem 1fr auto;
  gap: .4rem;
  padding: .55rem .65rem .65rem;
  border-top: 1px solid rgba(255,255,255,.06);
}
.cf-party-chat-name,
.cf-party-chat-input {
  width: 100%;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(0,0,0,.28);
  color: #fff;
  border-radius: .55rem;
  padding: .55rem .6rem;
  font: inherit;
  font-size: .86rem;
  outline: none;
}
.cf-party-chat-input:focus,
.cf-party-chat-name:focus {
  border-color: rgba(239, 91, 69, .55);
}""",
        """.cf-party-chat-form {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: .4rem;
  align-items: center;
  padding: .55rem .65rem .65rem;
  border-top: 1px solid rgba(255,255,255,.06);
}
.cf-party-chat-form[hidden] { display: none !important; }
.cf-party-chat-user {
  max-width: 6.5rem;
  font-size: .72rem;
  font-weight: 750;
  color: #ffb3bb;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cf-party-chat-input {
  width: 100%;
  border: 1px solid rgba(255,255,255,.1);
  background: rgba(0,0,0,.28);
  color: #fff;
  border-radius: .55rem;
  padding: .55rem .6rem;
  font: inherit;
  font-size: .86rem;
  outline: none;
}
.cf-party-chat-input:focus {
  border-color: rgba(239, 91, 69, .55);
}
.cf-party-chat-login {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .65rem;
  padding: .7rem .75rem;
  border-top: 1px solid rgba(255,255,255,.06);
}
.cf-party-chat-login[hidden] { display: none !important; }
.cf-party-chat-login p {
  margin: 0;
  color: #9aa3b2;
  font-size: .84rem;
}
.cf-party-chat-login-btn {
  appearance: none;
  border: 0;
  border-radius: .55rem;
  padding: .5rem .8rem;
  background: linear-gradient(180deg, #ef5b45, #c43c2e);
  color: #fff;
  font-weight: 750;
  font-size: .8rem;
  cursor: pointer;
  flex: 0 0 auto;
}""",
        1,
    )
    css = css.replace(
        """@media (max-width: 520px) {
  .cf-party-chat-form {
    grid-template-columns: 1fr auto;
  }
  .cf-party-chat-name { grid-column: 1 / -1; }
}""",
        """@media (max-width: 520px) {
  .cf-party-chat-form {
    grid-template-columns: 1fr auto;
  }
  .cf-party-chat-user { grid-column: 1 / -1; max-width: none; }
  .cf-party-chat-login { flex-direction: column; align-items: stretch; }
}""",
        1,
    )
    party_css.write_text(css)
    print("chat auth css")
else:
    print("chat auth css exists")

# --- continue-party.js chat auth UI ---
js = party_js.read_text()

# Update chatState default
js = js.replace(
    '  var chatState = { code: "", role: "guest", writable: true, banned: false };',
    '  var chatState = { code: "", role: "guest", writable: true, banned: false, authRequired: true, user: null };',
    1,
)

# Replace syncChatForm
old_sync = '''  function syncChatForm() {
    var $form = $("#cf-party-chat-form");
    var $note = $("#cf-party-chat-note");
    var $lock = $("#cf-party-chat-lock");
    if (!$form.length) return;
    if (chatState.banned) {
      $form.addClass("is-disabled");
      $note.text("You were banned from this chat.").prop("hidden", false);
      return;
    }
    $note.prop("hidden", true).text("");
    var muted = chatState.role !== "host" && !chatState.writable;
    $form.toggleClass("is-disabled", muted);
    if (muted) {
      $note.text("Host muted guest chat.").prop("hidden", false);
    }
    if (chatState.role === "host") {
      $(".cf-party-chat-host").prop("hidden", false);
      $lock.attr("aria-pressed", chatState.writable ? "false" : "true");
      $lock.text(chatState.writable ? "Mute guests" : "Allow chat");
    } else {
      $(".cf-party-chat-host").prop("hidden", true);
    }
  }'''

new_sync = '''  function currentAuthUser() {
    try {
      if (typeof window.getBrowseAuthUser === "function") {
        var u = window.getBrowseAuthUser();
        if (u) return u;
      }
    } catch (e0) {}
    try {
      return (window.APP && APP.user) || null;
    } catch (e1) {
      return null;
    }
  }

  function syncChatForm() {
    var $form = $("#cf-party-chat-form");
    var $note = $("#cf-party-chat-note");
    var $lock = $("#cf-party-chat-lock");
    var $login = $("#cf-party-chat-login");
    if (!$form.length) return;
    var user = chatState.user || currentAuthUser();
    chatState.user = user;
    chatState.authRequired = !user;

    if (!user) {
      $form.attr("hidden", true).addClass("is-disabled");
      $login.removeAttr("hidden");
      $note.prop("hidden", true).text("");
      $(".cf-party-chat-host").prop("hidden", chatState.role !== "host");
      if (chatState.role === "host") {
        $lock.attr("aria-pressed", chatState.writable ? "false" : "true");
        $lock.text(chatState.writable ? "Mute guests" : "Allow chat");
      }
      return;
    }

    $login.attr("hidden", true);
    $form.removeAttr("hidden");
    $("#cf-party-chat-user").text(user.name || user.email || "Member");

    if (chatState.banned) {
      $form.addClass("is-disabled");
      $note.text("You were banned from this chat.").prop("hidden", false);
      return;
    }
    $note.prop("hidden", true).text("");
    var muted = chatState.role !== "host" && !chatState.writable;
    $form.toggleClass("is-disabled", muted);
    if (muted) {
      $note.text("Host muted guest chat.").prop("hidden", false);
    }
    if (chatState.role === "host") {
      $(".cf-party-chat-host").prop("hidden", false);
      $lock.attr("aria-pressed", chatState.writable ? "false" : "true");
      $lock.text(chatState.writable ? "Mute guests" : "Allow chat");
    } else {
      $(".cf-party-chat-host").prop("hidden", true);
    }
  }'''

if old_sync not in js:
    raise SystemExit("syncChatForm not found")
js = js.replace(old_sync, new_sync, 1)

# pollPartyChat: read auth fields
js = js.replace(
    """        chatState.writable = !!data.chatWritable;
        chatState.banned = !!data.banned;
        if (data.messages && data.messages.length) {""",
    """        chatState.writable = !!data.chatWritable;
        chatState.banned = !!data.banned;
        chatState.authRequired = !!data.authRequired;
        if (data.user) chatState.user = data.user;
        else if (data.authRequired) chatState.user = null;
        if (typeof data.isHost === "boolean" && data.isHost) chatState.role = "host";
        if (data.messages && data.messages.length) {""",
    1,
)

# submit: no custom name; handle authRequired
js = js.replace(
    """  $(document).on("submit", "#cf-party-chat-form", function (e) {
    e.preventDefault();
    if (!chatState.code || chatState.banned) return;
    if (chatState.role !== "host" && !chatState.writable) return;
    var name = $.trim($("#cf-party-chat-name").val() || "");
    var text = $.trim($("#cf-party-chat-input").val() || "");
    if (!text) return;
    if (name) chatNameSet(name);
    $("#cf-party-chat-input").val("");
    $.ajax({
      url: partyChatApi() + "/" + encodeURIComponent(chatState.code) + "/chat",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        peerId: chatActorId(),
        hostId: chatState.role === "host" ? chatActorId() : undefined,
        name: name || undefined,
        text: text,
      }),
    })
      .done(function (data) {
        if (data && data.message) {
          if (data.message.id > chatAfter) chatAfter = data.message.id;
          appendChatMessages([data.message]);
        }
        if (data && typeof data.chatWritable === "boolean") {
          chatState.writable = data.chatWritable;
          syncChatForm();
        }
        if (data && data.banned) {
          chatState.banned = true;
          syncChatForm();
        }
      })
      .fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.error) || "Could not send";
        $("#cf-party-chat-note").text(msg).prop("hidden", false);
        if (xhr.responseJSON && xhr.responseJSON.banned) {
          chatState.banned = true;
          syncChatForm();
        }
      });
  });""",
    """  $(document).on("submit", "#cf-party-chat-form", function (e) {
    e.preventDefault();
    if (!chatState.code || chatState.banned) return;
    if (!currentAuthUser()) {
      syncChatForm();
      return;
    }
    if (chatState.role !== "host" && !chatState.writable) return;
    var text = $.trim($("#cf-party-chat-input").val() || "");
    if (!text) return;
    $("#cf-party-chat-input").val("");
    $.ajax({
      url: partyChatApi() + "/" + encodeURIComponent(chatState.code) + "/chat",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        peerId: chatActorId(),
        hostId: chatState.role === "host" ? chatHostId(chatState.code) || chatPeerId() : undefined,
        text: text,
      }),
    })
      .done(function (data) {
        if (data && data.authRequired) {
          chatState.user = null;
          syncChatForm();
          return;
        }
        if (data && data.message) {
          if (data.message.id > chatAfter) chatAfter = data.message.id;
          appendChatMessages([data.message]);
        }
        if (data && typeof data.chatWritable === "boolean") {
          chatState.writable = data.chatWritable;
          syncChatForm();
        }
        if (data && data.banned) {
          chatState.banned = true;
          syncChatForm();
        }
      })
      .fail(function (xhr) {
        var body = xhr.responseJSON || {};
        var msg = body.error || "Could not send";
        if (body.authRequired) {
          chatState.user = null;
          syncChatForm();
          return;
        }
        $("#cf-party-chat-note").text(msg).prop("hidden", false);
        if (body.banned) {
          chatState.banned = true;
          syncChatForm();
        }
      });
  });""",
    1,
)

# bootPartyChat: refresh auth user, pass hostId in poll
js = js.replace(
    """    chatState.code = info.code;
    chatState.role = info.role;
    $box.removeAttr("hidden");
    $box.find(".cf-party-chat-code").text(info.code);
    var saved = chatNameGet();
    if (saved) $("#cf-party-chat-name").val(saved);
    else if (!$("#cf-party-chat-name").val()) {
      $("#cf-party-chat-name").val("Guest " + chatPeerId().slice(-4));
    }
    syncChatForm();""",
    """    chatState.code = info.code;
    chatState.role = info.role;
    chatState.user = currentAuthUser();
    $box.removeAttr("hidden");
    $box.find(".cf-party-chat-code").text(info.code);
    syncChatForm();
    try {
      if (typeof window.refreshBrowseAuthUser === "function") {
        window.refreshBrowseAuthUser().then(function () {
          chatState.user = currentAuthUser();
          syncChatForm();
        });
      }
    } catch (eAuthBoot) {}""",
    1,
)

js = js.replace(
    """      "&peerId=" +
      encodeURIComponent(chatActorId());
    $.getJSON(url)""",
    """      "&peerId=" +
      encodeURIComponent(chatActorId()) +
      "&hostId=" +
      encodeURIComponent(chatHostId(chatState.code) || "");
    $.getJSON(url)""",
    1,
)

# auth event + login button already uses data-browse-auth
if 'window.addEventListener("cf:auth"' not in js:
    js = js.replace(
        '  window.addEventListener("cf:softnav", function () {',
        '  window.addEventListener("cf:auth", function () {\n'
        '    chatState.user = currentAuthUser();\n'
        '    syncChatForm();\n'
        '    if (chatState.code) pollPartyChat();\n'
        '  });\n'
        '  window.addEventListener("cf:softnav", function () {',
        1,
    )

party_js.write_text(js)
print("continue-party chat auth UI")

# bump routes watch assets too
routes = root / "app/routes.php"
rt = routes.read_text()
routes.write_text(re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui152", rt))
player_layout = root / "app/Views/layouts/player.php"
if player_layout.exists():
    player_layout.write_text(re.sub(r"\?v=2026080\d-ui\d+", "?v=20260803-ui152", player_layout.read_text()))
print("ui152")
print("DONE")
