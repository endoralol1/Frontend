<?php
declare(strict_types=1);

/**
 * Ultra-light watch-party rooms (file-backed JSON, no DB).
 * Host posts playhead; guests poll. TTL keeps storage tiny.
 */
final class WatchParty
{
    private const TTL_SEC = 21600; // 6h hard cap
    private const HOST_IDLE_CLOSE_SEC = 1200; // 20m without host updates
    private const MAX_BODY = 4096;

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
        ];
        self::write($code, $room);
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
        $room['peers'] = min(50, (int) ($room['peers'] ?? 1) + 1);
        $room['updatedAt'] = time();
        self::write($code, $room);
        return [
            'ok' => true,
            'room' => self::publicRoom($room),
            'peerId' => self::str($payload['peerId'] ?? '', 64) ?: self::randId(),
        ];
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
            'content' => is_array($room['content'] ?? null) ? $room['content'] : [],
        ];
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
            @unlink($path);
            return null;
        }
        // Host stopped reporting (left the player) — end for everyone after idle window
        if ($updated > 0 && $age > self::HOST_IDLE_CLOSE_SEC) {
            @unlink($path);
            return null;
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
        // opportunistic cleanup of a few expired rooms
        self::gc();
    }

    private static function gc(): void
    {
        if (random_int(0, 20) !== 0) {
            return;
        }
        $dir = self::dir();
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $mtime = @filemtime($file) ?: 0;
            if ($mtime && (time() - $mtime) > self::TTL_SEC) {
                @unlink($file);
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
