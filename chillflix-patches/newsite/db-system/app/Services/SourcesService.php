<?php
declare(strict_types=1);

final class SourcesService
{
    /** Greek-ish public labels for guests/users */
    public const PUBLIC_LABELS = [
        'Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta',
        'Iota', 'Kappa', 'Lambda', 'Mu', 'Nu', 'Xi', 'Omicron', 'Pi',
    ];

    /** Known providers the main cinepro API can resolve */
    public const CATALOG = [
        'vaplayer' => 'VAPlayer',
        'huhu' => 'Huhu',
        'flixhqz' => 'FlixHQz',
        'vidapiru' => 'VidAPiru',
        'fsharetv' => 'FshareTV',
        'notorrent' => 'NoTorrent',
        'vidnest' => 'VidNest',
        'peachify' => 'Peachify',
        'vidrock' => 'VidRock',
        'videasy' => 'Videasy',
        'stremify' => 'Stremify',
        'vidmoly' => 'Vidmoly',
    ];

    /** Ensure catalog rows exist (safe to call often). */
    public static function ensureCatalogRows(): void
    {
        self::seedDefaults();
    }

    public static function seedDefaults(): void
    {
        $now = Auth::now();
        $order = 10;
        $i = 0;
        foreach (self::CATALOG as $id => $name) {
            $label = self::PUBLIC_LABELS[$i] ?? ('Source ' . ($i + 1));
            $enabled = in_array($id, ['vaplayer', 'huhu'], true) ? 1 : 0;
            Database::pdo()->prepare(
                'INSERT INTO sources (id, name, public_label, enabled, sort_order, notes, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)'
            )->execute([$id, $name, $label, $enabled, $order, $now]);
            $order += 10;
            $i++;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, name, public_label, enabled, sort_order, notes, updated_at
             FROM sources ORDER BY sort_order ASC, name ASC'
        );
        return array_map([self::class, 'mapRow'], $stmt->fetchAll() ?: []);
    }

    /** @return list<array<string,mixed>> */
    public static function enabledOrdered(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, name, public_label, enabled, sort_order, notes, updated_at
             FROM sources WHERE enabled = 1 ORDER BY sort_order ASC, name ASC'
        );
        return array_map([self::class, 'mapRow'], $stmt->fetchAll() ?: []);
    }

    /** @return list<string> provider ids in test/playback order */
    public static function enabledIds(): array
    {
        return array_values(array_map(
            static fn(array $s): string => (string) $s['id'],
            self::enabledOrdered()
        ));
    }

    /** @param array<string,mixed> $row */
    private static function mapRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'publicLabel' => (string) $row['public_label'],
            'enabled' => (int) $row['enabled'] === 1,
            'sortOrder' => (int) $row['sort_order'],
            'notes' => (string) ($row['notes'] ?? ''),
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    public static function setEnabled(string $id, bool $enabled): void
    {
        Database::pdo()->prepare(
            'UPDATE sources SET enabled = ?, updated_at = ? WHERE id = ?'
        )->execute([$enabled ? 1 : 0, Auth::now(), $id]);
    }

    /** @param list<string> $orderedIds */
    public static function reorder(array $orderedIds): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $order = 10;
            $stmt = $pdo->prepare('UPDATE sources SET sort_order = ?, updated_at = ? WHERE id = ?');
            $now = Auth::now();
            foreach ($orderedIds as $id) {
                $id = strtolower(trim((string) $id));
                if ($id === '') {
                    continue;
                }
                $stmt->execute([$order, $now, $id]);
                $order += 10;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateMeta(string $id, ?string $publicLabel = null, ?string $notes = null, ?string $name = null): void
    {
        $fields = ['updated_at = ?'];
        $vals = [Auth::now()];
        if ($publicLabel !== null) {
            $fields[] = 'public_label = ?';
            $vals[] = substr(trim($publicLabel), 0, 64);
        }
        if ($notes !== null) {
            $fields[] = 'notes = ?';
            $vals[] = substr(trim($notes), 0, 512);
        }
        if ($name !== null) {
            $fields[] = 'name = ?';
            $vals[] = substr(trim($name), 0, 128);
        }
        $vals[] = $id;
        Database::pdo()->prepare('UPDATE sources SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($vals);
    }

    /**
     * Map provider display for a viewer.
     * Staff see real names; users/guests see Alpha/Beta…
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    public static function applyPublicLabels(array $sources, bool $revealRealNames): array
    {
        $map = [];
        foreach (self::enabledOrdered() as $s) {
            $map[strtolower((string) $s['id'])] = $s;
        }
        $out = [];
        foreach ($sources as $src) {
            if (!is_array($src)) {
                continue;
            }
            $pid = strtolower((string) ($src['provider'] ?? ''));
            $meta = $map[$pid] ?? null;
            $public = $meta['publicLabel'] ?? ('Source');
            $real = $meta['name'] ?? ((string) ($src['providerName'] ?? $pid));
            $labelName = $revealRealNames ? $real : $public;
            $src['providerName'] = $labelName;
            $src['publicLabel'] = $public;
            $src['realName'] = $real;
            $src['revealReal'] = $revealRealNames;
            // Rewrite human label: keep quality / language bits when possible
            $quality = trim((string) ($src['quality'] ?? ''));
            $lang = trim((string) ($src['language'] ?? ''));
            $bits = [$labelName];
            if ($lang !== '') {
                $bits[] = strtoupper($lang);
            } elseif ($quality !== '' && strcasecmp($quality, 'Auto') !== 0) {
                $bits[] = $quality;
            }
            $src['label'] = implode(' · ', $bits);
            if (!$revealRealNames) {
                unset($src['realName']);
            }
            $out[] = $src;
        }
        return $out;
    }

    /** Test one provider against a title via PlayerSources single-provider path */
    public static function test(string $sourceId, string $type, int $tmdbId, int $season = 1, int $episode = 1, ?string $testedBy = null): array
    {
        $sourceId = strtolower(trim($sourceId));
        $type = $type === 'tv' ? 'tv' : 'movie';
        // Force single provider through config override for this request.
        $GLOBALS['__cf_config_override'] = ['player_providers' => [$sourceId]];
        try {
            $result = PlayerSources::fetch($type, $tmdbId, $season, $episode);
        } finally {
            unset($GLOBALS['__cf_config_override']);
        }

        // Count only streams that actually belong to the tested provider.
        $all = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $matched = [];
        foreach ($all as $src) {
            if (!is_array($src)) {
                continue;
            }
            $pid = strtolower((string) ($src['provider'] ?? ''));
            if ($pid === $sourceId || str_starts_with($pid, $sourceId)) {
                $matched[] = $src;
            }
        }
        $ok = !empty($matched);
        $count = count($matched);
        $message = $ok
            ? ("OK — {$count} stream(s) from {$sourceId}")
            : (string) ($result['error'] ?? ("No streams from {$sourceId}"));
        $result['sources'] = $matched;

        Database::pdo()->prepare(
            'INSERT INTO source_test_logs
              (source_id, media_type, tmdb_id, season, episode, ok, source_count, message, tested_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $sourceId,
            $type,
            $tmdbId,
            $type === 'tv' ? $season : null,
            $type === 'tv' ? $episode : null,
            $ok ? 1 : 0,
            $count,
            substr($message, 0, 512),
            $testedBy,
            Auth::now(),
        ]);

        return [
            'ok' => $ok,
            'sourceId' => $sourceId,
            'sourceCount' => $count,
            'message' => $message,
            'sources' => $result['sources'] ?? [],
            'diagnostics' => $result['diagnostics'] ?? [],
        ];
    }
}
