#!/usr/bin/env python3
"""Watch Party chat below video: host lock/ban; wiped when party ends."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
wp = root / "app/Services/WatchParty.php"
routes = root / "app/routes.php"
watch = root / "app/Views/pages/watch.php"
party_js = root / "public/assets/js/continue-party.js"
party_css = root / "public/assets/css/continue-party.css"
main_layout = root / "app/Views/layouts/main.php"
player_layout = root / "app/Views/layouts/player.php"
routes_watch_assets = True

# ---------- WatchParty.php: full rewrite with chat ----------
wp.write_text(r'''<?php
declare(strict_types=1);

/**
 * Ultra-light watch-party rooms (file-backed JSON, no DB).
 * Host posts playhead; guests poll. Chat is wiped when the room closes.
 */
final class WatchParty
{
    private const TTL_SEC = 21600; // 6h hard cap
    private const HOST_IDLE_CLOSE_SEC = 1200; // 20m without host updates
    private const MAX_BODY = 8192;
    private const MAX_CHAT = 80;
    private const MAX_MSG = 200;

    public static function create(array $payload): array
    {
        $code = self::freshCode();
        $now = time();
        $room = [
            'code' => $code,
            'hostId' => self::str($payload['hostId'] ?? '', 64) ?: self::randId(),
            'createdAt' => $now,
            'updatedAt' => $now,
            'paused' => true,
            't' => 0.0,
            'duration' => 0.0,
            'content' => self::normalizeContent($payload['content'] ?? []),
            'peers' => 1,
            'chatWritable' => true,
            'banned' => [],
            'names' => [],
        ];
        self::write($code, $room);
        self::writeChat($code, ['seq' => 0, 'messages' => []]);
        return ['ok' => true, 'room' => self::publicRoom($room)];
    }

    public static function get(string $code): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        return ['ok' => true, 'room' => self::publicRoom($room)];
    }

    public static function update(string $code, array $payload): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        $hostId = self::str($payload['hostId'] ?? '', 64);
        if ($hostId === '' || $hostId !== ($room['hostId'] ?? '')) {
            return ['ok' => false, 'error' => 'Only the host can update this room'];
        }
        if (array_key_exists('paused', $payload)) {
            $room['paused'] = (bool) $payload['paused'];
        }
        if (isset($payload['t'])) {
            $room['t'] = max(0, (float) $payload['t']);
        }
        if (isset($payload['duration'])) {
            $room['duration'] = max(0, (float) $payload['duration']);
        }
        if (isset($payload['content']) && is_array($payload['content'])) {
            $room['content'] = self::normalizeContent($payload['content']);
        }
        if (array_key_exists('chatWritable', $payload)) {
            $room['chatWritable'] = (bool) $payload['chatWritable'];
        }
        $room['updatedAt'] = time();
        self::write($code, $room);
        return ['ok' => true, 'room' => self::publicRoom($room)];
    }

    public static function join(string $code, array $payload = []): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        $peerId = self::str($payload['peerId'] ?? '', 64) ?: self::randId();
        if (self::isBanned($room, $peerId)) {
            return ['ok' => false, 'error' => 'You are banned from this party', 'banned' => true];
        }
        $name = self::str($payload['name'] ?? '', 20);
        if ($name !== '') {
            $names = is_array($room['names'] ?? null) ? $room['names'] : [];
            $names[$peerId] = $name;
            $room['names'] = $names;
        }
        $room['peers'] = min(50, (int) ($room['peers'] ?? 1) + 1);
        $room['updatedAt'] = time();
        self::write($code, $room);
        return [
            'ok' => true,
            'room' => self::publicRoom($room),
            'peerId' => $peerId,
        ];
    }

    public static function chatState(string $code, array $payload = []): array
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
    }

    public static function chatPost(string $code, array $payload): array
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
        $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
        $messages[] = $msg;
        if (count($messages) > self::MAX_CHAT) {
            $messages = array_values(array_slice($messages, -self::MAX_CHAT));
        }
        self::writeChat($code, ['seq' => $seq, 'messages' => $messages]);
        return ['ok' => true, 'message' => $msg, 'chatWritable' => (bool) ($room['chatWritable'] ?? true)];
    }

    public static function chatLock(string $code, array $payload): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        $hostId = self::str($payload['hostId'] ?? '', 64);
        if ($hostId === '' || $hostId !== ($room['hostId'] ?? '')) {
            return ['ok' => false, 'error' => 'Only the host can change chat lock'];
        }
        $room['chatWritable'] = !empty($payload['chatWritable']);
        self::write($code, $room);
        // system line
        $chat = self::readChat($code);
        $seq = (int) ($chat['seq'] ?? 0) + 1;
        $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
        $messages[] = [
            'id' => $seq,
            'peerId' => 'system',
            'name' => 'System',
            'text' => $room['chatWritable'] ? 'Host opened chat for everyone.' : 'Host muted guest chat.',
            'ts' => time(),
            'role' => 'system',
        ];
        if (count($messages) > self::MAX_CHAT) {
            $messages = array_values(array_slice($messages, -self::MAX_CHAT));
        }
        self::writeChat($code, ['seq' => $seq, 'messages' => $messages]);
        return ['ok' => true, 'chatWritable' => (bool) $room['chatWritable']];
    }

    public static function chatBan(string $code, array $payload): array
    {
        $room = self::read($code);
        if (!$room) {
            return ['ok' => false, 'error' => 'Room not found or expired'];
        }
        $hostId = self::str($payload['hostId'] ?? '', 64);
        if ($hostId === '' || $hostId !== ($room['hostId'] ?? '')) {
            return ['ok' => false, 'error' => 'Only the host can ban'];
        }
        $peerId = self::str($payload['peerId'] ?? '', 64);
        if ($peerId === '' || $peerId === $hostId) {
            return ['ok' => false, 'error' => 'Invalid peer'];
        }
        $banned = is_array($room['banned'] ?? null) ? $room['banned'] : [];
        if (!in_array($peerId, $banned, true)) {
            $banned[] = $peerId;
        }
        $room['banned'] = array_values($banned);
        $name = self::str(
            $payload['name'] ?? (($room['names'][$peerId] ?? null) ?: ('Guest ' . substr($peerId, -4))),
            20
        );
        self::write($code, $room);

        $chat = self::readChat($code);
        $seq = (int) ($chat['seq'] ?? 0) + 1;
        $messages = is_array($chat['messages'] ?? null) ? $chat['messages'] : [];
        $messages[] = [
            'id' => $seq,
            'peerId' => 'system',
            'name' => 'System',
            'text' => $name . ' was banned from chat.',
            'ts' => time(),
            'role' => 'system',
        ];
        if (count($messages) > self::MAX_CHAT) {
            $messages = array_values(array_slice($messages, -self::MAX_CHAT));
        }
        self::writeChat($code, ['seq' => $seq, 'messages' => $messages]);
        return ['ok' => true, 'banned' => true];
    }

    /** @param array<string,mixed> $content */
    private static function normalizeContent(array $content): array
    {
        $type = (($content['type'] ?? '') === 'tv') ? 'tv' : 'movie';
        $id = (int) ($content['id'] ?? $content['tmdbId'] ?? 0);
        return [
            'type' => $type,
            'id' => $id,
            'title' => self::str($content['title'] ?? '', 160),
            'poster' => self::str($content['poster'] ?? '', 400),
            'year' => self::str($content['year'] ?? '', 12),
            'season' => max(1, (int) ($content['season'] ?? 1)),
            'episode' => max(1, (int) ($content['episode'] ?? 1)),
            'url' => self::str($content['url'] ?? '', 500),
        ];
    }

    /** @param array<string,mixed> $room */
    private static function publicRoom(array $room): array
    {
        return [
            'code' => (string) ($room['code'] ?? ''),
            'hostId' => (string) ($room['hostId'] ?? ''),
            'paused' => (bool) ($room['paused'] ?? true),
            't' => (float) ($room['t'] ?? 0),
            'duration' => (float) ($room['duration'] ?? 0),
            'updatedAt' => (int) ($room['updatedAt'] ?? 0),
            'peers' => (int) ($room['peers'] ?? 1),
            'chatWritable' => (bool) ($room['chatWritable'] ?? true),
            'content' => is_array($room['content'] ?? null) ? $room['content'] : [],
        ];
    }

    /** @param array<string,mixed> $room */
    private static function isBanned(array $room, string $peerId): bool
    {
        $banned = is_array($room['banned'] ?? null) ? $room['banned'] : [];
        return $peerId !== '' && in_array($peerId, $banned, true);
    }

    private static function dir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/party';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function path(string $code): string
    {
        return self::dir() . '/' . preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) . '.json';
    }

    private static function chatPath(string $code): string
    {
        return self::dir() . '/' . preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) . '.chat.json';
    }

    private static function read(string $code): ?array
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
        if (strlen($code) < 4) {
            return null;
        }
        $path = self::path($code);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $updated = (int) ($data['updatedAt'] ?? 0);
        $age = $updated > 0 ? (time() - $updated) : 0;
        if ($updated > 0 && $age > self::TTL_SEC) {
            self::destroy($code);
            return null;
        }
        if ($updated > 0 && $age > self::HOST_IDLE_CLOSE_SEC) {
            self::destroy($code);
            return null;
        }
        if (!isset($data['chatWritable'])) {
            $data['chatWritable'] = true;
        }
        if (!isset($data['banned']) || !is_array($data['banned'])) {
            $data['banned'] = [];
        }
        return $data;
    }

    /** @param array<string,mixed> $room */
    private static function write(string $code, array $room): void
    {
        $path = self::path($code);
        $json = json_encode($room, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }
        @chmod($path, 0664);
        self::gc();
    }

    /** @return array{seq:int,messages:list<array<string,mixed>>} */
    private static function readChat(string $code): array
    {
        $path = self::chatPath($code);
        if (!is_file($path)) {
            return ['seq' => 0, 'messages' => []];
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['seq' => 0, 'messages' => []];
        }
        return [
            'seq' => (int) ($data['seq'] ?? 0),
            'messages' => is_array($data['messages'] ?? null) ? $data['messages'] : [],
        ];
    }

    /** @param array{seq:int,messages:list<array<string,mixed>>} $chat */
    private static function writeChat(string $code, array $chat): void
    {
        $path = self::chatPath($code);
        $json = json_encode($chat, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return;
        }
        @chmod($path, 0664);
    }

    private static function gc(): void
    {
        if (random_int(0, 20) !== 0) {
            return;
        }
        $dir = self::dir();
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (str_ends_with($file, '.chat.json')) {
                continue;
            }
            $mtime = @filemtime($file) ?: 0;
            if ($mtime && (time() - $mtime) > self::TTL_SEC) {
                $base = basename($file, '.json');
                @unlink($file);
                @unlink($dir . '/' . $base . '.chat.json');
            }
        }
    }

    private static function freshCode(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = (string) random_int(1000, 9999);
            if (!is_file(self::path($code))) {
                return $code;
            }
        }
        return strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private static function randId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function str(mixed $v, int $max): string
    {
        $s = trim((string) $v);
        if (strlen($s) > $max) {
            $s = substr($s, 0, $max);
        }
        return $s;
    }

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
        $chat = self::chatPath($code);
        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($chat)) {
            @unlink($chat);
        }
    }

    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY);
        if ($raw === false || $raw === '') {
            return $_POST ?: [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
''')
print("WatchParty.php chat-ready")

# ---------- routes ----------
rt = routes.read_text()
if "/api/party/{code}/chat" not in rt:
    needle = """$router->map(['POST'], '/api/party/{code}/close', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::close((string) $p['code'], $body));
});
"""
    add = needle + """
$router->get('/api/party/{code}/chat', function (array $p) {
    json_response(WatchParty::chatState((string) $p['code'], $_GET));
});
$router->map(['POST'], '/api/party/{code}/chat', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatPost((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/chat/lock', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatLock((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/chat/ban', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatBan((string) $p['code'], $body));
});
"""
    if needle not in rt:
        raise SystemExit("close route not found")
    routes.write_text(rt.replace(needle, add, 1))
    print("chat routes added")
else:
    print("chat routes exist")

# bump watch player assets
rt = routes.read_text()
routes.write_text(re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui150", rt))
print("routes asset pins ui150")

# ---------- watch.php: chat shell below video ----------
w = watch.read_text()
marker = """                    </div>
                    <div id="movie-managers">
"""
chat_html = """                    </div>
                    <div id="cf-party-chat" class="cf-party-chat" hidden>
                        <div class="cf-party-chat-head">
                            <div class="cf-party-chat-title">
                                <span class="cf-party-chat-dot" aria-hidden="true"></span>
                                <strong>Party chat</strong>
                                <em class="cf-party-chat-code"></em>
                            </div>
                            <div class="cf-party-chat-host" hidden>
                                <button type="button" class="cf-party-chat-lock" id="cf-party-chat-lock" aria-pressed="false">Mute guests</button>
                            </div>
                        </div>
                        <div class="cf-party-chat-log" id="cf-party-chat-log" aria-live="polite"></div>
                        <p class="cf-party-chat-note" id="cf-party-chat-note" hidden></p>
                        <form class="cf-party-chat-form" id="cf-party-chat-form" autocomplete="off">
                            <input type="text" id="cf-party-chat-name" class="cf-party-chat-name" maxlength="20" placeholder="Name" aria-label="Chat name">
                            <input type="text" id="cf-party-chat-input" class="cf-party-chat-input" maxlength="200" placeholder="Say something…" aria-label="Chat message">
                            <button type="submit" class="cf-party-chat-send" id="cf-party-chat-send">Send</button>
                        </form>
                    </div>
                    <div id="movie-managers">
"""
if 'id="cf-party-chat"' not in w:
    if marker not in w:
        raise SystemExit("movie-managers marker not found")
    watch.write_text(w.replace(marker, chat_html, 1))
    print("watch.php chat markup")
else:
    print("watch.php chat exists")

# ---------- CSS ----------
css = party_css.read_text()
if ".cf-party-chat {" not in css:
    css += r'''

/* ——— Watch Party chat (below video) ——— */
.cf-party-chat[hidden] { display: none !important; }
.cf-party-chat {
  margin: 0.65rem 0 0.15rem;
  border-radius: 0.9rem;
  border: 1px solid rgba(255,255,255,.1);
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), transparent 40%),
    rgba(12, 14, 20, .88);
  overflow: hidden;
}
.cf-party-chat-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .6rem;
  padding: .55rem .7rem;
  border-bottom: 1px solid rgba(255,255,255,.06);
  background: rgba(0,0,0,.22);
}
.cf-party-chat-title {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  min-width: 0;
  color: #eef1f6;
  font-size: .86rem;
}
.cf-party-chat-title strong { font-weight: 750; }
.cf-party-chat-code {
  font-style: normal;
  color: #ff8b76;
  font-weight: 800;
  letter-spacing: .08em;
  font-size: .72rem;
}
.cf-party-chat-dot {
  width: .42rem;
  height: .42rem;
  border-radius: 50%;
  background: #ff3b30;
  box-shadow: 0 0 0 0 rgba(255,59,48,.4);
  animation: cfPartyPulse 1.7s ease-out infinite;
}
.cf-party-chat-host { flex: 0 0 auto; }
.cf-party-chat-lock {
  appearance: none;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
  color: #e8edf5;
  border-radius: .5rem;
  padding: .35rem .55rem;
  font-size: .7rem;
  font-weight: 750;
  cursor: pointer;
}
.cf-party-chat-lock[aria-pressed="true"] {
  background: rgba(220,53,69,.22);
  border-color: rgba(220,53,69,.45);
  color: #ffc3c9;
}
.cf-party-chat-log {
  height: min(38vh, 14.5rem);
  overflow: auto;
  padding: .55rem .65rem;
  display: flex;
  flex-direction: column;
  gap: .4rem;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}
.cf-party-chat-empty {
  margin: auto;
  color: #8b93a3;
  font-size: .82rem;
  text-align: center;
  padding: 1rem .5rem;
}
.cf-party-chat-msg {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: .2rem .45rem;
  align-items: start;
  padding: .35rem .45rem;
  border-radius: .55rem;
  background: rgba(255,255,255,.03);
}
.cf-party-chat-msg.is-host { background: rgba(220,53,69,.10); }
.cf-party-chat-msg.is-system {
  background: transparent;
  color: #8b93a3;
  font-size: .78rem;
  text-align: center;
  display: block;
}
.cf-party-chat-msg-meta {
  grid-column: 1 / -1;
  display: flex;
  align-items: baseline;
  gap: .35rem;
  min-width: 0;
}
.cf-party-chat-msg-name {
  font-size: .72rem;
  font-weight: 750;
  color: #ffb3bb;
}
.cf-party-chat-msg.is-host .cf-party-chat-msg-name { color: #ff8b76; }
.cf-party-chat-msg-text {
  grid-column: 1 / 2;
  color: #eef1f6;
  font-size: .86rem;
  line-height: 1.35;
  word-break: break-word;
}
.cf-party-chat-ban {
  appearance: none;
  border: 0;
  background: rgba(255,255,255,.06);
  color: #ffb3bb;
  border-radius: .4rem;
  padding: .2rem .4rem;
  font-size: .65rem;
  font-weight: 750;
  cursor: pointer;
  grid-column: 2;
  grid-row: 2;
}
.cf-party-chat-note {
  margin: 0;
  padding: .35rem .7rem 0;
  color: #ff8b96;
  font-size: .78rem;
}
.cf-party-chat-form {
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
}
.cf-party-chat-send {
  appearance: none;
  border: 0;
  border-radius: .55rem;
  padding: .55rem .8rem;
  background: linear-gradient(180deg, #ef5b45, #c43c2e);
  color: #fff;
  font-weight: 750;
  font-size: .82rem;
  cursor: pointer;
}
.cf-party-chat-form.is-disabled .cf-party-chat-input,
.cf-party-chat-form.is-disabled .cf-party-chat-send {
  opacity: .45;
  pointer-events: none;
}
@media (max-width: 520px) {
  .cf-party-chat-form {
    grid-template-columns: 1fr auto;
  }
  .cf-party-chat-name { grid-column: 1 / -1; }
}
'''
    party_css.write_text(css)
    print("chat css added")
else:
    print("chat css exists")

# ---------- JS chat client in continue-party.js ----------
js = party_js.read_text()
if "bootPartyChat" not in js:
    # Insert before ChillflixParty export block end - after paintPartyResume export
    anchor = """  window.ChillflixParty = {
    open: openPartyPanel,
    close: closePartyPanel,
    paintResume: paintPartyResume,
    clearHosting: clearHostingResume,
  };"""
    if anchor not in js:
        raise SystemExit("ChillflixParty export anchor missing")
    chat_js = r'''
  /* ——— Party chat (below video) ——— */
  var chatTimer = null;
  var chatAfter = 0;
  var chatState = { code: "", role: "guest", writable: true, banned: false };

  function partyChatApi() {
    return ((window.APP && APP.partyApi) || ((window.APP && APP.baseUrl) || "") + "/api/party").replace(/\/$/, "");
  }

  function partyFromLocation() {
    try {
      var p = new URLSearchParams(location.search);
      var code = String(p.get("party") || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
      if (!code) return null;
      return { code: code, role: p.get("host") === "1" ? "host" : "guest" };
    } catch (e) {
      return null;
    }
  }

  function chatPeerId() {
    try {
      var id = sessionStorage.getItem("cf_party_peer");
      if (!id) {
        id = Math.random().toString(36).slice(2) + Date.now().toString(36);
        sessionStorage.setItem("cf_party_peer", id);
      }
      return id;
    } catch (e) {
      return "anon";
    }
  }

  function chatHostId(code) {
    try {
      return sessionStorage.getItem("cf_party_host_" + code) || "";
    } catch (e) {
      return "";
    }
  }

  function chatNameGet() {
    try {
      return sessionStorage.getItem("cf_party_chat_name") || "";
    } catch (e) {
      return "";
    }
  }

  function chatNameSet(name) {
    try {
      sessionStorage.setItem("cf_party_chat_name", name);
    } catch (e) {}
  }

  function escChat(s) {
    return $("<div/>").text(s == null ? "" : String(s)).html();
  }

  function stopPartyChat(hide) {
    if (chatTimer) {
      clearInterval(chatTimer);
      chatTimer = null;
    }
    chatAfter = 0;
    if (hide) {
      $("#cf-party-chat").attr("hidden", true);
      $("#cf-party-chat-log").empty();
    }
  }

  function renderChatMessage(m, isHostView) {
    if (!m) return "";
    if (m.role === "system") {
      return '<div class="cf-party-chat-msg is-system">' + escChat(m.text) + "</div>";
    }
    var ban =
      isHostView && m.role !== "host" && m.peerId
        ? '<button type="button" class="cf-party-chat-ban" data-ban-peer="' +
          escChat(m.peerId) +
          '" data-ban-name="' +
          escChat(m.name || "") +
          '">Ban</button>'
        : "";
    return (
      '<div class="cf-party-chat-msg' +
      (m.role === "host" ? " is-host" : "") +
      '" data-peer="' +
      escChat(m.peerId || "") +
      '">' +
      '<div class="cf-party-chat-msg-meta"><span class="cf-party-chat-msg-name">' +
      escChat(m.name || "Guest") +
      (m.role === "host" ? " · Host" : "") +
      "</span></div>" +
      '<div class="cf-party-chat-msg-text">' +
      escChat(m.text || "") +
      "</div>" +
      ban +
      "</div>"
    );
  }

  function syncChatForm() {
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
  }

  function appendChatMessages(rows) {
    var $log = $("#cf-party-chat-log");
    if (!$log.length) return;
    $log.find(".cf-party-chat-empty").remove();
    var isHostView = chatState.role === "host";
    var html = rows.map(function (m) { return renderChatMessage(m, isHostView); }).join("");
    if (html) {
      $log.append(html);
      $log.scrollTop($log[0].scrollHeight);
    }
    if (!$log.children().length) {
      $log.html('<div class="cf-party-chat-empty">No messages yet — say hi.</div>');
    }
  }

  function chatActorId() {
    if (chatState.role === "host") {
      return chatHostId(chatState.code) || chatPeerId();
    }
    return chatPeerId();
  }

  function pollPartyChat() {
    if (!chatState.code) return;
    var url =
      partyChatApi() +
      "/" +
      encodeURIComponent(chatState.code) +
      "/chat?after=" +
      encodeURIComponent(String(chatAfter)) +
      "&peerId=" +
      encodeURIComponent(chatActorId());
    $.getJSON(url)
      .done(function (data) {
        if (!data || data.ok === false || data.closed) {
          stopPartyChat(true);
          return;
        }
        chatState.writable = !!data.chatWritable;
        chatState.banned = !!data.banned;
        if (data.messages && data.messages.length) {
          data.messages.forEach(function (m) {
            if (m && m.id > chatAfter) chatAfter = m.id;
          });
          appendChatMessages(data.messages);
        } else if (!$("#cf-party-chat-log").children().length) {
          appendChatMessages([]);
        }
        syncChatForm();
      })
      .fail(function () {});
  }

  function bootPartyChat() {
    var info = partyFromLocation();
    var $box = $("#cf-party-chat");
    if (!$box.length) {
      stopPartyChat(true);
      return;
    }
    if (!info) {
      stopPartyChat(true);
      return;
    }
    var same = chatState.code === info.code && chatTimer;
    chatState.code = info.code;
    chatState.role = info.role;
    $box.removeAttr("hidden");
    $box.find(".cf-party-chat-code").text(info.code);
    var saved = chatNameGet();
    if (saved) $("#cf-party-chat-name").val(saved);
    else if (!$("#cf-party-chat-name").val()) {
      $("#cf-party-chat-name").val("Guest " + chatPeerId().slice(-4));
    }
    syncChatForm();
    if (!same) {
      chatAfter = 0;
      $("#cf-party-chat-log").empty();
      if (chatTimer) clearInterval(chatTimer);
      pollPartyChat();
      chatTimer = setInterval(pollPartyChat, 1800);
    }
  }

  $(document).on("submit", "#cf-party-chat-form", function (e) {
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
  });

  $(document).on("click", "#cf-party-chat-lock", function () {
    if (chatState.role !== "host" || !chatState.code) return;
    var next = $(this).attr("aria-pressed") === "true"; // currently muted -> allow
    $.ajax({
      url: partyChatApi() + "/" + encodeURIComponent(chatState.code) + "/chat/lock",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        hostId: chatActorId(),
        chatWritable: next,
      }),
    }).done(function (data) {
      if (data && data.ok) {
        chatState.writable = !!data.chatWritable;
        syncChatForm();
        pollPartyChat();
      }
    });
  });

  $(document).on("click", "[data-ban-peer]", function () {
    if (chatState.role !== "host" || !chatState.code) return;
    var peer = $(this).data("ban-peer");
    var name = $(this).data("ban-name") || "";
    if (!peer) return;
    if (!window.confirm("Ban " + (name || "this user") + " from chat?")) return;
    $.ajax({
      url: partyChatApi() + "/" + encodeURIComponent(chatState.code) + "/chat/ban",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        hostId: chatActorId(),
        peerId: peer,
        name: name,
      }),
    }).done(function () {
      pollPartyChat();
    });
  });

  window.ChillflixParty = {
    open: openPartyPanel,
    close: closePartyPanel,
    paintResume: paintPartyResume,
    clearHosting: clearHostingResume,
    bootChat: bootPartyChat,
    stopChat: stopPartyChat,
  };
'''
    js = js.replace(anchor, chat_js, 1)
    # boot chat on ready / softnav
    js = js.replace(
        """  $(function () {
    bootContinue();
    paintPartyResume();
    // Re-check shortly after load in case watch tab wrote storage just before nav
    setTimeout(bootContinue, 250);
    setTimeout(bootContinue, 1200);
    setTimeout(paintPartyResume, 300);
  });""",
        """  $(function () {
    bootContinue();
    paintPartyResume();
    bootPartyChat();
    // Re-check shortly after load in case watch tab wrote storage just before nav
    setTimeout(bootContinue, 250);
    setTimeout(bootContinue, 1200);
    setTimeout(paintPartyResume, 300);
    setTimeout(bootPartyChat, 400);
  });""",
        1,
    )
    js = js.replace(
        """  window.addEventListener("cf:softnav", function () {
    bootContinue();
    paintPartyResume();
    setTimeout(paintPartyResume, 50);
    setTimeout(paintPartyResume, 300);
    setTimeout(paintPartyResume, 1000);
  });""",
        """  window.addEventListener("cf:softnav", function () {
    bootContinue();
    paintPartyResume();
    bootPartyChat();
    setTimeout(paintPartyResume, 50);
    setTimeout(paintPartyResume, 300);
    setTimeout(paintPartyResume, 1000);
    setTimeout(bootPartyChat, 200);
  });""",
        1,
    )
    party_js.write_text(js)
    print("continue-party.js chat client")
else:
    print("chat js exists")

# bump layouts
for layout in (main_layout, player_layout):
    lt = layout.read_text()
    layout.write_text(re.sub(r"\?v=2026080\d-ui\d+", "?v=20260803-ui150", lt))
print("layouts ui150")
print("DONE")
