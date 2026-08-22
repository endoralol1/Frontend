<?php
declare(strict_types=1);

final class SourcesService
{
    /** Greek-ish public labels for guests/users */
    public const PUBLIC_LABELS = [
        'Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta',
        'Iota', 'Kappa', 'Lambda', 'Mu', 'Nu', 'Xi', 'Omicron', 'Pi',
    ];

    /**
     * Known providers (CinePro + local scrapers + Vuflix-only).
     * Order mirrors Chillflix DEFAULT_PROVIDER_ORDER; stremify is Vuflix-only.
     */
    public const CATALOG = [
        'cineplay' => 'Cineplay',
        'cinesu' => 'CineSu',
        'onlyflix' => 'OnlyFlix',
        'soapy' => 'SoaPy',
        'telennovelas' => 'Telennovelas',
        'cinemacity' => 'Cinemacity',
        'movies123' => '123Movies',
        'vidlink' => 'VidLink',
        'flixhq' => 'FlixHQ',
        'huhu' => 'Huhu',
        'flixhqz' => 'Flixhqz',
        'notorrent' => 'NoTorrent',
        'vidrock' => 'VidRock',
        'icefy' => 'Icefy',
        'vidsrc' => 'VidSrc',
        'fsharetv' => 'FshareTV',
        'vaplayer' => 'VAPlayer',
        'upcloud' => 'UpCloud',
        'vidmoly' => 'Vidmoly',
        'hollymoviehd' => 'HollyMovieHD',
        'moviebox' => 'MovieBox',
        'vidapi' => 'VidAPI',
        'vidapiru' => 'vidapi.ru',
        'vidify' => 'Vidify',
        'mafiaembed' => 'MafiaEmbed',
        'streammafia' => 'StreamMafia',
        'videasy' => 'Videasy',
        '4khdhub' => '4K',
        'cinejoy' => '4K',
        'vixsrc' => 'VixSrc',
        'vidzee' => 'VidZee',
        'popr' => 'Popr',
        'vidnest' => 'VidNest',
        '02moviedownloader' => '02MovieDownloader',
        'peachify' => 'Peachify',
        'moviesonlinehd' => 'MoviesOnlineHD',
        'novahd' => 'NovaHD',
        'netmirror' => 'NetMirror',
        'ridomovies' => 'RidoMovies',
        'filesun' => 'FileSuN',
        'bingr' => 'Bingr',
        'vsembed' => 'VSEmbed',
        // Vuflix-only experimental
        'stremify' => 'Stremify',
        // Vuflix-only Railway aggregators (Main/Premium + HDGHAR + MovieBox-like)
        'nxsha' => 'Nxsha',
        'castle' => 'Castle (Multi-Audio)',
        'awsind' => 'AwsInd (by language)',
        'nitro' => 'Nitro',
        'riveprime' => 'IP Cloud / Rive',
        'hdghar' => 'HDGHAR (Multi-Audio)',
        'hollybox' => 'HollyBox (by language)',
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
            'SELECT id, name, public_label, enabled, auto_load, autoload_non_english, scrape_timeout_sec, auto_wait_sec, sort_order, notes, updated_at
             FROM sources ORDER BY sort_order ASC, name ASC'
        );
        $rows = array_map([self::class, 'mapRow'], $stmt->fetchAll() ?: []);
        $last = self::latestTestStatsBySource();
        foreach ($rows as &$row) {
            $id = strtolower((string) $row['id']);
            if (isset($last[$id])) {
                $row['lastTest'] = $last[$id];
            }
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private static function latestTestStatsBySource(): array
    {
        try {
            $stmt = Database::pdo()->query(
                'SELECT s.source_id, s.ok, s.playable, s.source_count, s.elapsed_ms, s.message, s.created_at
                 FROM source_test_logs s
                 INNER JOIN (
                   SELECT source_id, MAX(id) AS max_id FROM source_test_logs GROUP BY source_id
                 ) x ON x.max_id = s.id'
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $r) {
            $ms = isset($r['elapsed_ms']) ? (int) $r['elapsed_ms'] : null;
            $out[strtolower((string) $r['source_id'])] = [
                'ok' => (int) ($r['ok'] ?? 0) === 1,
                'playable' => (int) ($r['playable'] ?? 0) === 1,
                'sourceCount' => (int) ($r['source_count'] ?? 0),
                'elapsedMs' => $ms,
                'elapsedLabel' => self::formatElapsedMs($ms),
                'message' => (string) ($r['message'] ?? ''),
                'testedAt' => (int) ($r['created_at'] ?? 0),
            ];
        }
        return $out;
    }

    private static function formatElapsedMs(?int $ms): string
    {
        if ($ms === null || $ms < 0) {
            return '';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return number_format($ms / 1000, 2) . 's';
    }

    /** @return list<array<string,mixed>> */
    public static function enabledOrdered(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, name, public_label, enabled, auto_load, autoload_non_english, scrape_timeout_sec, auto_wait_sec, sort_order, notes, updated_at
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
            'autoLoad' => (int) ($row['auto_load'] ?? 1) === 1,
            'autoloadNonEnglish' => (int) ($row['autoload_non_english'] ?? 0) === 1,
            'scrapeTimeoutSec' => max(15, min(180, (int) ($row['scrape_timeout_sec'] ?? 45))),
            'autoWaitSec' => max(3, min(120, (int) ($row['auto_wait_sec'] ?? 15))),
            'sortOrder' => (int) $row['sort_order'],
            'notes' => (string) ($row['notes'] ?? ''),
            'updatedAt' => (int) $row['updated_at'],
        ];
    }

    public static function setAutoLoad(string $id, bool $autoLoad): void
    {
        Database::pdo()->prepare(
            'UPDATE sources SET auto_load = ?, updated_at = ? WHERE id = ?'
        )->execute([$autoLoad ? 1 : 0, Auth::now(), $id]);
    }

    public static function setScrapeTimeout(string $id, int $seconds): void
    {
        $seconds = max(15, min(180, $seconds));
        Database::pdo()->prepare(
            'UPDATE sources SET scrape_timeout_sec = ?, updated_at = ? WHERE id = ?'
        )->execute([$seconds, Auth::now(), $id]);
    }

    /** How long auto-mode waits for playback to start before trying the next source. */
    public static function setAutoWait(string $id, int $seconds): void
    {
        $seconds = max(3, min(120, $seconds));
        Database::pdo()->prepare(
            'UPDATE sources SET auto_wait_sec = ?, updated_at = ? WHERE id = ?'
        )->execute([$seconds, Auth::now(), $id]);
    }

    public static function setAutoloadNonEnglish(string $id, bool $on): void
    {
        Database::pdo()->prepare(
            'UPDATE sources SET autoload_non_english = ?, updated_at = ? WHERE id = ?'
        )->execute([$on ? 1 : 0, Auth::now(), $id]);
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
            // Rewrite human label: prefer friendly language names (English not HI/EN)
            $quality = trim((string) ($src['quality'] ?? ''));
            $lang = trim((string) ($src['language'] ?? ''));
            $bits = [$labelName];
            $langNice = self::friendlyLanguage($lang);
            if ($langNice !== '') {
                $bits[] = $langNice;
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

    private static function friendlyLanguage(string $lang): string
    {
        $l = strtolower(trim($lang));
        if ($l === '') {
            return '';
        }
        return match (true) {
            $l === 'en' || $l === 'eng' || $l === 'english' => 'English',
            $l === 'hi' || $l === 'hin' || $l === 'hindi' => 'Hindi',
            $l === 'ta' || $l === 'tam' || $l === 'tamil' => 'Tamil',
            $l === 'te' || $l === 'tel' || $l === 'telugu' => 'Telugu',
            $l === 'es' || $l === 'spa' || $l === 'spanish' => 'Spanish',
            $l === 'pt' || $l === 'por' || $l === 'portuguese' => 'Portuguese',
            $l === 'de' || $l === 'ger' || $l === 'german' => 'German',
            $l === 'fr' || $l === 'fra' || $l === 'french' => 'French',
            $l === 'original' => 'Original',
            $l === 'ipcloud' || $l === 'ip cloud' => 'IP Cloud',
            default => (strlen($lang) <= 3 ? strtoupper($lang) : ucfirst($lang)),
        };
    }

    /** Test one provider against a title via PlayerSources single-provider path */
    public static function test(string $sourceId, string $type, int $tmdbId, int $season = 1, int $episode = 1, ?string $testedBy = null): array
    {
        $sourceId = strtolower(trim($sourceId));
        $type = $type === 'tv' ? 'tv' : 'movie';
        $started = microtime(true);
        // Force single provider through config override for this request.
        $GLOBALS['__cf_config_override'] = ['player_providers' => [$sourceId]];
        try {
            $result = PlayerSources::fetch($type, $tmdbId, $season, $episode);
        } finally {
            unset($GLOBALS['__cf_config_override']);
        }
        $scrapeMs = (int) round((microtime(true) - $started) * 1000);

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
        $playable = false;
        $probeMs = null;
        if ($ok) {
            $probeStarted = microtime(true);
            $playable = self::probePlayableUrl((string) ($matched[0]['url'] ?? ''), (string) ($matched[0]['type'] ?? 'hls'));
            $probeMs = (int) round((microtime(true) - $probeStarted) * 1000);
        }
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $timeLabel = self::formatElapsedMs($elapsedMs);
        if ($ok && $playable) {
            $message = "OK — {$count} playable stream(s) from {$sourceId} · {$timeLabel}";
        } elseif ($ok) {
            $message = "Links OK ({$count}) but stream probe failed · {$timeLabel}";
            // Still count as ok for admin listing of returned links; playable flag false.
        } else {
            $message = (string) ($result['error'] ?? ("No streams from {$sourceId}"));
            $message .= ' · ' . $timeLabel;
        }
        $result['sources'] = $matched;

        try {
            Database::pdo()->prepare(
                'INSERT INTO source_test_logs
                  (source_id, media_type, tmdb_id, season, episode, ok, playable, source_count, elapsed_ms, message, tested_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sourceId,
                $type,
                $tmdbId,
                $type === 'tv' ? $season : null,
                $type === 'tv' ? $episode : null,
                $ok ? 1 : 0,
                ($ok && $playable) ? 1 : 0,
                $count,
                $elapsedMs,
                substr($message, 0, 512),
                $testedBy,
                Auth::now(),
            ]);
        } catch (Throwable $e) {
            // Fallback if migration not applied yet.
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
        }

        return [
            'ok' => $ok,
            'playable' => $ok && $playable,
            'sourceId' => $sourceId,
            'sourceCount' => $count,
            'elapsedMs' => $elapsedMs,
            'scrapeMs' => $scrapeMs,
            'probeMs' => $probeMs,
            'elapsedLabel' => $timeLabel,
            'message' => $message,
            'sources' => $result['sources'] ?? [],
            'diagnostics' => $result['diagnostics'] ?? [],
        ];
    }

    /** Confirm the first returned URL looks like a real HLS/media response. */
    private static function probePlayableUrl(string $url, string $type = 'hls'): bool
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        if (!function_exists('curl_init')) {
            return true;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_USERAGENT => 'VuflixAdminSourceProbe/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Referer: https://vuflix.co/',
            ],
            CURLOPT_RANGE => '0-2047',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 400) {
            return false;
        }
        $trim = ltrim((string) $body);
        if (str_starts_with($trim, '#EXTM3U')) {
            return true;
        }
        if (str_contains(strtolower($type), 'hls') || preg_match('/\.m3u8(\\?|$)/i', $url) || str_contains($url, 'lang-proxy') || str_contains($url, 'media-proxy') || str_contains($url, 'a-relay') || str_contains($url, 'v-relay')) {
            // Proxied playlists sometimes omit the first bytes under RANGE — accept 206/200 with body.
            return strlen((string) $body) > 32;
        }
        // Progressive / MP4: ftyp box or non-empty binary
        if (strlen((string) $body) >= 8) {
            $box = substr((string) $body, 4, 4);
            if (in_array($box, ['ftyp', 'moof', 'mdat', 'styp'], true)) {
                return true;
            }
        }
        return strlen((string) $body) > 64;
    }

}
