<?php
declare(strict_types=1);

final class UserData
{
    /** @return list<array<string,mixed>> */
    public static function listFavorites(string $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT media_type, tmdb_id, title, poster, backdrop, year, updated_at
             FROM favorites WHERE user_id = ? ORDER BY updated_at DESC'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'type' => (string) $row['media_type'],
                'id' => (int) $row['tmdb_id'],
                'title' => (string) $row['title'],
                'poster' => (string) ($row['poster'] ?? ''),
                'backdrop' => (string) ($row['backdrop'] ?? ''),
                'year' => (string) ($row['year'] ?? ''),
                'updated' => (int) $row['updated_at'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    public static function upsertFavorite(string $userId, array $item): void
    {
        $type = (($item['type'] ?? '') === 'tv') ? 'tv' : 'movie';
        $id = (int) ($item['id'] ?? $item['tmdbId'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid media id');
        }
        $now = Auth::now();
        Database::pdo()->prepare(
            'INSERT INTO favorites (user_id, media_type, tmdb_id, title, poster, backdrop, year, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               title = VALUES(title),
               poster = VALUES(poster),
               backdrop = VALUES(backdrop),
               year = VALUES(year),
               updated_at = VALUES(updated_at)'
        )->execute([
            $userId,
            $type,
            $id,
            substr((string) ($item['title'] ?? ''), 0, 255),
            substr((string) ($item['poster'] ?? ''), 0, 512) ?: null,
            substr((string) ($item['backdrop'] ?? ''), 0, 512) ?: null,
            substr((string) ($item['year'] ?? ''), 0, 8) ?: null,
            $now,
        ]);
    }

    public static function removeFavorite(string $userId, string $type, int $tmdbId): void
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        Database::pdo()->prepare(
            'DELETE FROM favorites WHERE user_id = ? AND media_type = ? AND tmdb_id = ?'
        )->execute([$userId, $type, $tmdbId]);
    }

    public static function clearFavorites(string $userId): void
    {
        Database::pdo()->prepare('DELETE FROM favorites WHERE user_id = ?')->execute([$userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function listContinue(string $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT media_key, media_type, tmdb_id, season, episode, title, poster, backdrop, year,
                    position_sec, duration_sec, updated_at
             FROM continue_watching WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'key' => (string) $row['media_key'],
                'type' => (string) $row['media_type'],
                'id' => (int) $row['tmdb_id'],
                'season' => $row['season'] !== null ? (int) $row['season'] : null,
                'episode' => $row['episode'] !== null ? (int) $row['episode'] : null,
                'title' => (string) $row['title'],
                'poster' => (string) ($row['poster'] ?? ''),
                'backdrop' => (string) ($row['backdrop'] ?? ''),
                'year' => (string) ($row['year'] ?? ''),
                't' => (float) $row['position_sec'],
                'd' => (float) $row['duration_sec'],
                'updated' => (int) $row['updated_at'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    public static function upsertContinue(string $userId, array $item): void
    {
        $type = (($item['type'] ?? '') === 'tv') ? 'tv' : 'movie';
        $id = (int) ($item['id'] ?? $item['tmdbId'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid media id');
        }
        $season = isset($item['season']) ? max(1, (int) $item['season']) : null;
        $episode = isset($item['episode']) ? max(1, (int) $item['episode']) : null;
        // One row per title (movie OR tv show). Episode keys collapse to tv:{id}.
        $key = (string) ($item['key'] ?? '');
        if ($key === '' || ($type === 'tv' && preg_match('/^tv:\d+:s\d+e\d+$/', $key))) {
            $key = $type === 'tv'
                ? sprintf('tv:%d', $id)
                : sprintf('movie:%d', $id);
        } elseif ($type === 'movie') {
            $key = sprintf('movie:%d', $id);
        } elseif ($type === 'tv' && !preg_match('/^tv:\d+$/', $key)) {
            $key = sprintf('tv:%d', $id);
        }
        $now = Auth::now();
        Database::pdo()->prepare(
            'INSERT INTO continue_watching
               (user_id, media_key, media_type, tmdb_id, season, episode, title, poster, backdrop, year, position_sec, duration_sec, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               season = VALUES(season),
               episode = VALUES(episode),
               title = VALUES(title),
               poster = VALUES(poster),
               backdrop = VALUES(backdrop),
               year = VALUES(year),
               position_sec = VALUES(position_sec),
               duration_sec = VALUES(duration_sec),
               updated_at = VALUES(updated_at)'
        )->execute([
            $userId,
            substr($key, 0, 64),
            $type,
            $id,
            $season,
            $episode,
            substr((string) ($item['title'] ?? ''), 0, 255),
            substr((string) ($item['poster'] ?? ''), 0, 512) ?: null,
            substr((string) ($item['backdrop'] ?? ''), 0, 512) ?: null,
            substr((string) ($item['year'] ?? ''), 0, 8) ?: null,
            (float) ($item['t'] ?? $item['currentTime'] ?? $item['position'] ?? 0),
            (float) ($item['d'] ?? $item['duration'] ?? 0),
            $now,
        ]);
        // Drop legacy per-episode rows for this title, then keep newest 5.
        if ($type === 'tv') {
            Database::pdo()->prepare(
                'DELETE FROM continue_watching
                 WHERE user_id = ? AND media_type = ? AND tmdb_id = ?
                   AND media_key <> ?'
            )->execute([$userId, $type, $id, substr($key, 0, 64)]);
        }
        self::pruneContinueToLimit($userId, 5);
    }

    /** Keep only the newest N continue-watching titles per user. */
    public static function pruneContinueToLimit(string $userId, int $limit = 5): void
    {
        $limit = max(1, min(50, $limit));
        $pdo = Database::pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM continue_watching WHERE user_id = ?');
        $countStmt->execute([$userId]);
        if ((int) $countStmt->fetchColumn() <= $limit) {
            return;
        }
        $keep = $pdo->prepare(
            'SELECT media_key FROM continue_watching
             WHERE user_id = ? ORDER BY updated_at DESC LIMIT ' . (int) $limit
        );
        $keep->execute([$userId]);
        $keys = $keep->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($keys === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $del = $pdo->prepare(
            "DELETE FROM continue_watching WHERE user_id = ? AND media_key NOT IN ($placeholders)"
        );
        $del->execute(array_merge([$userId], $keys));
    }

    public static function removeContinue(string $userId, string $key): void
    {
        Database::pdo()->prepare(
            'DELETE FROM continue_watching WHERE user_id = ? AND media_key = ?'
        )->execute([$userId, $key]);
    }

    /** @param array<string,mixed> $prefs */
    public static function updatePrefs(string $userId, array $prefs): array
    {
        $fields = [];
        $vals = [];
        $map = [
            'language' => 'language',
            'autoplayEnabled' => 'autoplay_enabled',
            'autoNextEnabled' => 'auto_next_enabled',
            'watchlistEnabled' => 'watchlist_enabled',
            'continueEnabled' => 'continue_enabled',
        ];
        foreach ($map as $in => $col) {
            if (!array_key_exists($in, $prefs)) {
                continue;
            }
            if ($col === 'language') {
                $fields[] = 'language = ?';
                $vals[] = substr(strtolower((string) $prefs[$in]), 0, 10);
            } else {
                $fields[] = "{$col} = ?";
                $vals[] = !empty($prefs[$in]) ? 1 : 0;
            }
        }
        if (!$fields) {
            $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch() ?: [];
            return Auth::publicUser($row);
        }
        $fields[] = 'updated_at = ?';
        $vals[] = Auth::now();
        $vals[] = $userId;
        Database::pdo()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($vals);
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return Auth::publicUser($stmt->fetch() ?: ['id' => $userId, 'email' => '', 'name' => '', 'role' => 'user', 'status' => 'active']);
    }

    /** Full library pull for device sync */
    public static function library(string $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'user' => Auth::publicUser($row),
            'favorites' => self::listFavorites($userId),
            'continueWatching' => self::listContinue($userId),
        ];
    }
}
