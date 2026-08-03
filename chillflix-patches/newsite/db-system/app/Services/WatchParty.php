<?php
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
            'hostUserId' => (Auth::user()['id'] ?? null),
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
    }

    public static function chatPost(string $code, array $payload): array
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
