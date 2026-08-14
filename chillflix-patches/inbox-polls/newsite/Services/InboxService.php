<?php
declare(strict_types=1);

/**
 * Site inbox: polls + notifications (header dropdown).
 * Guests use a session cookie; signed-in users use their user id.
 */
final class InboxService
{
    public const GUEST_COOKIE = 'ns_inbox';

    public static function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $pdo = Database::pdo();
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inbox_items (
                id CHAR(36) NOT NULL PRIMARY KEY,
                type ENUM('poll','notification') NOT NULL DEFAULT 'notification',
                title VARCHAR(200) NOT NULL,
                body TEXT NULL,
                status ENUM('draft','active','closed','archived') NOT NULL DEFAULT 'draft',
                settings_json JSON NULL,
                created_by CHAR(36) NULL,
                published_at BIGINT NULL,
                ends_at BIGINT NULL,
                like_count INT NOT NULL DEFAULT 0,
                dislike_count INT NOT NULL DEFAULT 0,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL,
                KEY idx_inbox_status_pub (status, published_at),
                KEY idx_inbox_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inbox_options (
                id CHAR(36) NOT NULL PRIMARY KEY,
                item_id CHAR(36) NOT NULL,
                label VARCHAR(200) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                vote_count INT NOT NULL DEFAULT 0,
                KEY idx_inbox_opt_item (item_id, sort_order),
                CONSTRAINT fk_inbox_opt_item FOREIGN KEY (item_id) REFERENCES inbox_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inbox_votes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                item_id CHAR(36) NOT NULL,
                option_id CHAR(36) NOT NULL,
                voter_key VARCHAR(80) NOT NULL,
                created_at BIGINT NOT NULL,
                UNIQUE KEY uq_inbox_vote (item_id, voter_key, option_id),
                KEY idx_inbox_vote_item (item_id),
                CONSTRAINT fk_inbox_vote_item FOREIGN KEY (item_id) REFERENCES inbox_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_inbox_vote_opt FOREIGN KEY (option_id) REFERENCES inbox_options(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inbox_reactions (
                item_id CHAR(36) NOT NULL,
                voter_key VARCHAR(80) NOT NULL,
                reaction ENUM('like','dislike') NOT NULL,
                created_at BIGINT NOT NULL,
                PRIMARY KEY (item_id, voter_key),
                CONSTRAINT fk_inbox_react_item FOREIGN KEY (item_id) REFERENCES inbox_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inbox_reads (
                item_id CHAR(36) NOT NULL,
                viewer_key VARCHAR(80) NOT NULL,
                read_at BIGINT NOT NULL,
                PRIMARY KEY (item_id, viewer_key),
                CONSTRAINT fk_inbox_read_item FOREIGN KEY (item_id) REFERENCES inbox_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Session cookie guest id (no long-lived tracking). */
    public static function viewerKey(?array $user = null): string
    {
        $user = $user ?? Auth::user();
        if ($user && !empty($user['id'])) {
            return 'u:' . (string) $user['id'];
        }
        $raw = (string) ($_COOKIE[self::GUEST_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9-]{16,64}$/i', $raw)) {
            return 'g:' . strtolower($raw);
        }
        $id = Auth::uuid();
        // Session cookie (expires when browser closes).
        setcookie(self::GUEST_COOKIE, $id, [
            'expires' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::GUEST_COOKIE] = $id;
        return 'g:' . strtolower($id);
    }

    /** @return array<string,mixed> */
    public static function defaultSettings(string $type = 'notification'): array
    {
        return [
            'allowMultiple' => false,
            'showResults' => 'after_vote', // after_vote | always | after_close | never
            'allowGuests' => true,
            'allowReactions' => true,
            'requireAuthToVote' => false,
            'requireAuthToReact' => false,
            'pin' => false,
            'icon' => $type === 'poll' ? 'chart' : 'bell',
        ];
    }

    /** @param mixed $raw */
    public static function normalizeSettings($raw, string $type = 'notification'): array
    {
        $base = self::defaultSettings($type);
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        } elseif (is_array($raw)) {
            $data = $raw;
        }
        $out = array_merge($base, array_intersect_key($data, $base));
        $out['allowMultiple'] = !empty($out['allowMultiple']);
        $out['allowGuests'] = !empty($out['allowGuests']);
        $out['allowReactions'] = !empty($out['allowReactions']);
        $out['requireAuthToVote'] = !empty($out['requireAuthToVote']);
        $out['requireAuthToReact'] = !empty($out['requireAuthToReact']);
        $out['pin'] = !empty($out['pin']);
        $sr = (string) ($out['showResults'] ?? 'after_vote');
        if (!in_array($sr, ['after_vote', 'always', 'after_close', 'never'], true)) {
            $sr = 'after_vote';
        }
        $out['showResults'] = $sr;
        $icon = preg_replace('/[^a-z0-9_-]/i', '', (string) ($out['icon'] ?? '')) ?: ($type === 'poll' ? 'chart' : 'bell');
        $out['icon'] = $icon;
        return $out;
    }

    /** Auto-close expired active polls. */
    public static function closeExpired(): void
    {
        $now = Auth::now();
        Database::pdo()->prepare(
            "UPDATE inbox_items SET status = 'closed', updated_at = ?
             WHERE status = 'active' AND ends_at IS NOT NULL AND ends_at > 0 AND ends_at < ?"
        )->execute([$now, $now]);
    }

    /** @return array{items:list<array<string,mixed>>,unread:int,totalActive:int} */
    public static function feedForViewer(?array $user = null): array
    {
        self::ensureTables();
        self::closeExpired();
        $viewer = self::viewerKey($user);
        $isAuthed = $user !== null || (Auth::user() !== null);
        $pdo = Database::pdo();

        $stmt = $pdo->query(
            "SELECT * FROM inbox_items
             WHERE status IN ('active','closed')
               AND (published_at IS NULL OR published_at <= UNIX_TIMESTAMP())
             ORDER BY
               CAST(JSON_EXTRACT(COALESCE(settings_json,'{}'), '$.pin') AS UNSIGNED) DESC,
               COALESCE(published_at, created_at) DESC
             LIMIT 40"
        );
        $rows = $stmt->fetchAll() ?: [];
        $items = [];
        $activeCount = 0;
        foreach ($rows as $row) {
            $mapped = self::mapItem($row, $viewer, $isAuthed, false);
            if (($mapped['status'] ?? '') === 'active') {
                $activeCount++;
            }
            // Hide guest-disallowed items from guests entirely.
            $settings = $mapped['settings'] ?? [];
            if (!$isAuthed && empty($settings['allowGuests'])) {
                continue;
            }
            $items[] = $mapped;
        }

        $unread = 0;
        if ($items !== []) {
            $ids = array_column($items, 'id');
            $place = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare(
                "SELECT item_id FROM inbox_reads WHERE viewer_key = ? AND item_id IN ($place)"
            );
            $q->execute(array_merge([$viewer], $ids));
            $read = [];
            foreach ($q->fetchAll() ?: [] as $r) {
                $read[(string) $r['item_id']] = true;
            }
            foreach ($items as &$it) {
                $isUnread = empty($read[(string) $it['id']]) && ($it['status'] ?? '') === 'active';
                $it['unread'] = $isUnread;
                if ($isUnread) {
                    $unread++;
                }
            }
            unset($it);
        }

        return [
            'items' => $items,
            'unread' => $unread,
            'totalActive' => $activeCount,
            'viewerKey' => $viewer,
            'isGuest' => str_starts_with($viewer, 'g:'),
        ];
    }

    /** @return array<string,mixed> */
    private static function mapItem(array $row, string $viewer, bool $isAuthed, bool $adminView): array
    {
        $type = (string) ($row['type'] ?? 'notification');
        $settings = self::normalizeSettings($row['settings_json'] ?? null, $type);
        $status = (string) ($row['status'] ?? 'draft');
        $id = (string) $row['id'];

        $options = [];
        $myVotes = [];
        $totalVotes = 0;
        if ($type === 'poll') {
            $optStmt = Database::pdo()->prepare(
                'SELECT * FROM inbox_options WHERE item_id = ? ORDER BY sort_order ASC, label ASC'
            );
            $optStmt->execute([$id]);
            $opts = $optStmt->fetchAll() ?: [];
            foreach ($opts as $o) {
                $vc = (int) ($o['vote_count'] ?? 0);
                $totalVotes += $vc;
                $options[] = [
                    'id' => (string) $o['id'],
                    'label' => (string) $o['label'],
                    'sortOrder' => (int) ($o['sort_order'] ?? 0),
                    'voteCount' => $vc,
                ];
            }
            $vStmt = Database::pdo()->prepare(
                'SELECT option_id FROM inbox_votes WHERE item_id = ? AND voter_key = ?'
            );
            $vStmt->execute([$id, $viewer]);
            foreach ($vStmt->fetchAll() ?: [] as $v) {
                $myVotes[] = (string) $v['option_id'];
            }
            $hasVoted = $myVotes !== [];
            $show = $adminView || self::shouldShowResults($settings, $status, $hasVoted);
            if ($show) {
                foreach ($options as &$o) {
                    $o['percent'] = $totalVotes > 0
                        ? (int) round(($o['voteCount'] / $totalVotes) * 100)
                        : 0;
                }
                unset($o);
            } else {
                foreach ($options as &$o) {
                    unset($o['voteCount']);
                    $o['percent'] = null;
                }
                unset($o);
                $totalVotes = null;
            }
        }

        $myReaction = null;
        if (!empty($settings['allowReactions'])) {
            $rStmt = Database::pdo()->prepare(
                'SELECT reaction FROM inbox_reactions WHERE item_id = ? AND voter_key = ? LIMIT 1'
            );
            $rStmt->execute([$id, $viewer]);
            $rr = $rStmt->fetch();
            if ($rr) {
                $myReaction = (string) $rr['reaction'];
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'status' => $status,
            'settings' => $settings,
            'publishedAt' => isset($row['published_at']) ? (int) $row['published_at'] : null,
            'endsAt' => isset($row['ends_at']) ? (int) $row['ends_at'] : null,
            'likeCount' => (int) ($row['like_count'] ?? 0),
            'dislikeCount' => (int) ($row['dislike_count'] ?? 0),
            'createdAt' => (int) ($row['created_at'] ?? 0),
            'updatedAt' => (int) ($row['updated_at'] ?? 0),
            'options' => $options,
            'myVotes' => $myVotes,
            'hasVoted' => $myVotes !== [],
            'totalVotes' => $type === 'poll' ? $totalVotes : null,
            'myReaction' => $myReaction,
            'canVote' => $type === 'poll'
                && $status === 'active'
                && (empty($settings['requireAuthToVote']) || $isAuthed)
                && (!empty($settings['allowGuests']) || $isAuthed),
            'canReact' => !empty($settings['allowReactions'])
                && in_array($status, ['active', 'closed'], true)
                && (empty($settings['requireAuthToReact']) || $isAuthed)
                && (!empty($settings['allowGuests']) || $isAuthed),
        ];
    }

    /** @param array<string,mixed> $settings */
    private static function shouldShowResults(array $settings, string $status, bool $hasVoted): bool
    {
        $mode = (string) ($settings['showResults'] ?? 'after_vote');
        return match ($mode) {
            'always' => true,
            'never' => false,
            'after_close' => $status === 'closed',
            default => $hasVoted || $status === 'closed',
        };
    }

    public static function markRead(string $itemId, ?array $user = null): void
    {
        self::ensureTables();
        $viewer = self::viewerKey($user);
        Database::pdo()->prepare(
            'INSERT INTO inbox_reads (item_id, viewer_key, read_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        )->execute([$itemId, $viewer, Auth::now()]);
    }

    public static function markAllRead(?array $user = null): int
    {
        self::ensureTables();
        self::closeExpired();
        $viewer = self::viewerKey($user);
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT id FROM inbox_items WHERE status = 'active'"
        );
        $n = 0;
        $now = Auth::now();
        $ins = $pdo->prepare(
            'INSERT INTO inbox_reads (item_id, viewer_key, read_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)'
        );
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $ins->execute([(string) $r['id'], $viewer, $now]);
            $n++;
        }
        return $n;
    }

    /** @param list<string> $optionIds */
    public static function vote(string $itemId, array $optionIds, ?array $user = null): array
    {
        self::ensureTables();
        self::closeExpired();
        $user = $user ?? Auth::user();
        $viewer = self::viewerKey($user);
        $isAuthed = $user !== null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM inbox_items WHERE id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        if (!$row || (string) $row['type'] !== 'poll') {
            throw new RuntimeException('Poll not found');
        }
        if ((string) $row['status'] !== 'active') {
            throw new RuntimeException('Poll is closed');
        }
        $settings = self::normalizeSettings($row['settings_json'] ?? null, 'poll');
        if (!$isAuthed && (empty($settings['allowGuests']) || !empty($settings['requireAuthToVote']))) {
            throw new RuntimeException('Sign in to vote');
        }

        $optionIds = array_values(array_unique(array_filter(array_map('strval', $optionIds))));
        if ($optionIds === []) {
            throw new RuntimeException('Pick at least one option');
        }
        if (empty($settings['allowMultiple']) && count($optionIds) > 1) {
            $optionIds = [array_values($optionIds)[0]];
        }

        $valid = [];
        $oStmt = $pdo->prepare('SELECT id FROM inbox_options WHERE item_id = ?');
        $oStmt->execute([$itemId]);
        foreach ($oStmt->fetchAll() ?: [] as $o) {
            $valid[(string) $o['id']] = true;
        }
        foreach ($optionIds as $oid) {
            if (!isset($valid[$oid])) {
                throw new RuntimeException('Invalid option');
            }
        }

        $pdo->beginTransaction();
        try {
            // Remove previous votes and decrement counts.
            $prev = $pdo->prepare('SELECT option_id FROM inbox_votes WHERE item_id = ? AND voter_key = ?');
            $prev->execute([$itemId, $viewer]);
            $prevIds = array_map(static fn($r) => (string) $r['option_id'], $prev->fetchAll() ?: []);
            if ($prevIds !== []) {
                $pdo->prepare('DELETE FROM inbox_votes WHERE item_id = ? AND voter_key = ?')
                    ->execute([$itemId, $viewer]);
                foreach ($prevIds as $oid) {
                    $pdo->prepare(
                        'UPDATE inbox_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?'
                    )->execute([$oid]);
                }
            }
            $ins = $pdo->prepare(
                'INSERT INTO inbox_votes (item_id, option_id, voter_key, created_at) VALUES (?, ?, ?, ?)'
            );
            $now = Auth::now();
            foreach ($optionIds as $oid) {
                $ins->execute([$itemId, $oid, $viewer, $now]);
                $pdo->prepare('UPDATE inbox_options SET vote_count = vote_count + 1 WHERE id = ?')
                    ->execute([$oid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::markRead($itemId, $user);
        return self::mapItem(
            self::fetchRow($itemId) ?? $row,
            $viewer,
            $isAuthed,
            false
        );
    }

    public static function react(string $itemId, ?string $reaction, ?array $user = null): array
    {
        self::ensureTables();
        $user = $user ?? Auth::user();
        $viewer = self::viewerKey($user);
        $isAuthed = $user !== null;
        if ($reaction !== null && !in_array($reaction, ['like', 'dislike'], true)) {
            throw new RuntimeException('Invalid reaction');
        }

        $row = self::fetchRow($itemId);
        if (!$row) {
            throw new RuntimeException('Item not found');
        }
        $settings = self::normalizeSettings($row['settings_json'] ?? null, (string) $row['type']);
        if (empty($settings['allowReactions'])) {
            throw new RuntimeException('Reactions disabled');
        }
        if (!$isAuthed && (empty($settings['allowGuests']) || !empty($settings['requireAuthToReact']))) {
            throw new RuntimeException('Sign in to react');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT reaction FROM inbox_reactions WHERE item_id = ? AND voter_key = ?');
            $cur->execute([$itemId, $viewer]);
            $existing = $cur->fetch();
            $old = $existing ? (string) $existing['reaction'] : null;

            if ($old === 'like') {
                $pdo->prepare('UPDATE inbox_items SET like_count = GREATEST(0, like_count - 1) WHERE id = ?')
                    ->execute([$itemId]);
            } elseif ($old === 'dislike') {
                $pdo->prepare('UPDATE inbox_items SET dislike_count = GREATEST(0, dislike_count - 1) WHERE id = ?')
                    ->execute([$itemId]);
            }

            if ($reaction === null || $reaction === $old) {
                $pdo->prepare('DELETE FROM inbox_reactions WHERE item_id = ? AND voter_key = ?')
                    ->execute([$itemId, $viewer]);
                $reaction = null;
            } else {
                $pdo->prepare(
                    'INSERT INTO inbox_reactions (item_id, voter_key, reaction, created_at) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = VALUES(created_at)'
                )->execute([$itemId, $viewer, $reaction, Auth::now()]);
                if ($reaction === 'like') {
                    $pdo->prepare('UPDATE inbox_items SET like_count = like_count + 1 WHERE id = ?')
                        ->execute([$itemId]);
                } else {
                    $pdo->prepare('UPDATE inbox_items SET dislike_count = dislike_count + 1 WHERE id = ?')
                        ->execute([$itemId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::markRead($itemId, $user);
        return self::mapItem(self::fetchRow($itemId) ?? $row, $viewer, $isAuthed, false);
    }

    /** @return array<string,mixed>|null */
    private static function fetchRow(string $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM inbox_items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // -------- Admin --------

    /** @return list<array<string,mixed>> */
    public static function adminList(): array
    {
        self::ensureTables();
        self::closeExpired();
        $stmt = Database::pdo()->query(
            'SELECT * FROM inbox_items ORDER BY created_at DESC LIMIT 200'
        );
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[] = self::mapItem($row, 'admin', true, true);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function adminCreate(array $payload, string $adminId): array
    {
        self::ensureTables();
        $type = (($payload['type'] ?? '') === 'poll') ? 'poll' : 'notification';
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Title required');
        }
        $body = trim((string) ($payload['body'] ?? ''));
        $status = (string) ($payload['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'closed', 'archived'], true)) {
            $status = 'draft';
        }
        $settings = self::normalizeSettings($payload['settings'] ?? [], $type);
        $endsAt = null;
        if (!empty($payload['endsAt'])) {
            $endsAt = is_numeric($payload['endsAt'])
                ? (int) $payload['endsAt']
                : (int) strtotime((string) $payload['endsAt']);
            if ($endsAt <= 0) {
                $endsAt = null;
            }
        }
        $options = [];
        if ($type === 'poll') {
            $rawOpts = $payload['options'] ?? [];
            if (!is_array($rawOpts)) {
                $rawOpts = [];
            }
            foreach ($rawOpts as $i => $opt) {
                $label = '';
                $seedVotes = 0;
                if (is_array($opt)) {
                    $label = trim((string) ($opt['label'] ?? ''));
                    $seedVotes = max(0, (int) ($opt['voteCount'] ?? $opt['votes'] ?? 0));
                } else {
                    $raw = trim((string) $opt);
                    // Support "Label|12" seed votes in plain textarea lines.
                    if (preg_match('/^(.*)\|(\d+)\s*$/', $raw, $m)) {
                        $label = trim($m[1]);
                        $seedVotes = max(0, (int) $m[2]);
                    } else {
                        $label = $raw;
                    }
                }
                if ($label === '') {
                    continue;
                }
                $options[] = ['label' => $label, 'voteCount' => $seedVotes];
            }
            if (count($options) < 2) {
                throw new RuntimeException('Polls need at least 2 options');
            }
        }

        $id = Auth::uuid();
        $now = Auth::now();
        $published = $status === 'active' ? $now : null;
        Database::pdo()->prepare(
            'INSERT INTO inbox_items
              (id, type, title, body, status, settings_json, created_by, published_at, ends_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $type,
            mb_substr($title, 0, 200),
            $body !== '' ? $body : null,
            $status,
            json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $adminId,
            $published,
            $endsAt,
            $now,
            $now,
        ]);

        if ($type === 'poll') {
            $ins = Database::pdo()->prepare(
                'INSERT INTO inbox_options (id, item_id, label, sort_order, vote_count) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($options as $i => $opt) {
                $ins->execute([
                    Auth::uuid(),
                    $id,
                    mb_substr((string) $opt['label'], 0, 200),
                    $i,
                    max(0, (int) ($opt['voteCount'] ?? 0)),
                ]);
            }
        }

        // Optional seed reaction counts on create.
        $likeSeed = max(0, (int) ($payload['likeCount'] ?? $payload['likes'] ?? 0));
        $dislikeSeed = max(0, (int) ($payload['dislikeCount'] ?? $payload['dislikes'] ?? 0));
        if ($likeSeed > 0 || $dislikeSeed > 0) {
            Database::pdo()->prepare(
                'UPDATE inbox_items SET like_count = ?, dislike_count = ? WHERE id = ?'
            )->execute([$likeSeed, $dislikeSeed, $id]);
        }

        return self::mapItem(self::fetchRow($id) ?? [], 'admin', true, true);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function adminUpdate(string $id, array $payload): array
    {
        self::ensureTables();
        $row = self::fetchRow($id);
        if (!$row) {
            throw new RuntimeException('Not found');
        }
        $type = (string) $row['type'];
        $fields = [];
        $vals = [];
        if (isset($payload['title'])) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                throw new RuntimeException('Title required');
            }
            $fields[] = 'title = ?';
            $vals[] = mb_substr($title, 0, 200);
        }
        if (array_key_exists('body', $payload)) {
            $fields[] = 'body = ?';
            $vals[] = trim((string) $payload['body']) ?: null;
        }
        if (isset($payload['status'])) {
            $status = (string) $payload['status'];
            if (!in_array($status, ['draft', 'active', 'closed', 'archived'], true)) {
                throw new RuntimeException('Bad status');
            }
            $fields[] = 'status = ?';
            $vals[] = $status;
            if ($status === 'active' && empty($row['published_at'])) {
                $fields[] = 'published_at = ?';
                $vals[] = Auth::now();
            }
        }
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $settings = self::normalizeSettings(
                array_merge(self::normalizeSettings($row['settings_json'] ?? null, $type), $payload['settings']),
                $type
            );
            $fields[] = 'settings_json = ?';
            $vals[] = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('endsAt', $payload)) {
            $endsAt = $payload['endsAt'];
            if ($endsAt === null || $endsAt === '' || $endsAt === 0) {
                $fields[] = 'ends_at = NULL';
            } else {
                $ts = is_numeric($endsAt) ? (int) $endsAt : (int) strtotime((string) $endsAt);
                $fields[] = 'ends_at = ?';
                $vals[] = $ts > 0 ? $ts : null;
            }
        }
        if ($fields !== []) {
            $fields[] = 'updated_at = ?';
            $vals[] = Auth::now();
            $vals[] = $id;
            Database::pdo()->prepare('UPDATE inbox_items SET ' . implode(', ', $fields) . ' WHERE id = ?')
                ->execute($vals);
        }

        // Replace options only when explicitly provided and poll has no votes yet (or force).
        if ($type === 'poll' && isset($payload['options']) && is_array($payload['options'])) {
            $force = !empty($payload['replaceOptions']);
            $vc = (int) Database::pdo()->query(
                "SELECT COALESCE(SUM(vote_count),0) FROM inbox_options WHERE item_id = " . Database::pdo()->quote($id)
            )->fetchColumn();
            if ($vc > 0 && !$force) {
                // skip option replace when votes exist unless forced
            } else {
                $labels = [];
                foreach ($payload['options'] as $label) {
                    $label = trim(is_array($label) ? (string) ($label['label'] ?? '') : (string) $label);
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                if (count($labels) >= 2) {
                    Database::pdo()->prepare('DELETE FROM inbox_votes WHERE item_id = ?')->execute([$id]);
                    Database::pdo()->prepare('DELETE FROM inbox_options WHERE item_id = ?')->execute([$id]);
                    $ins = Database::pdo()->prepare(
                        'INSERT INTO inbox_options (id, item_id, label, sort_order, vote_count) VALUES (?, ?, ?, ?, 0)'
                    );
                    foreach ($labels as $i => $label) {
                        $ins->execute([Auth::uuid(), $id, mb_substr($label, 0, 200), $i]);
                    }
                }
            }
        }

        // Admin can set like / dislike totals (display counts; does not rewrite reaction rows).
        $countFields = [];
        $countVals = [];
        if (array_key_exists('likeCount', $payload) || array_key_exists('likes', $payload)) {
            $n = (int) ($payload['likeCount'] ?? $payload['likes'] ?? 0);
            $countFields[] = 'like_count = ?';
            $countVals[] = max(0, $n);
        }
        if (array_key_exists('dislikeCount', $payload) || array_key_exists('dislikes', $payload)) {
            $n = (int) ($payload['dislikeCount'] ?? $payload['dislikes'] ?? 0);
            $countFields[] = 'dislike_count = ?';
            $countVals[] = max(0, $n);
        }
        if ($countFields !== []) {
            $countFields[] = 'updated_at = ?';
            $countVals[] = Auth::now();
            $countVals[] = $id;
            Database::pdo()->prepare('UPDATE inbox_items SET ' . implode(', ', $countFields) . ' WHERE id = ?')
                ->execute($countVals);
        }

        // Admin can set per-option vote totals.
        if ($type === 'poll' && isset($payload['optionVotes']) && is_array($payload['optionVotes'])) {
            self::adminSetOptionVotes($id, $payload['optionVotes']);
        }

        return self::mapItem(self::fetchRow($id) ?? [], 'admin', true, true);
    }

    /**
     * Set poll option vote counts from admin.
     * Accepts list of {id, voteCount} or map optionId => count.
     *
     * @param array<mixed> $votes
     */
    public static function adminSetOptionVotes(string $itemId, array $votes): void
    {
        $pdo = Database::pdo();
        $upd = $pdo->prepare(
            'UPDATE inbox_options SET vote_count = ? WHERE id = ? AND item_id = ?'
        );
        // Normalize map or list.
        $pairs = [];
        $isList = array_keys($votes) === range(0, count($votes) - 1);
        if ($isList) {
            foreach ($votes as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oid = (string) ($row['id'] ?? $row['optionId'] ?? '');
                if ($oid === '') {
                    continue;
                }
                $pairs[$oid] = max(0, (int) ($row['voteCount'] ?? $row['votes'] ?? $row['count'] ?? 0));
            }
        } else {
            foreach ($votes as $oid => $count) {
                $pairs[(string) $oid] = max(0, (int) $count);
            }
        }
        foreach ($pairs as $oid => $count) {
            $upd->execute([$count, $oid, $itemId]);
        }
        $pdo->prepare('UPDATE inbox_items SET updated_at = ? WHERE id = ?')
            ->execute([Auth::now(), $itemId]);
    }

    public static function adminDelete(string $id): void
    {
        self::ensureTables();
        Database::pdo()->prepare('DELETE FROM inbox_items WHERE id = ?')->execute([$id]);
    }
}
