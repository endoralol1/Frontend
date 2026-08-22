<?php
declare(strict_types=1);

/**
 * Player API protection: session cookie, IP/session-bound short tokens,
 * rate limits, scraper event log, and admin IP blocks.
 *
 * Real browsers on vuflix.co get a cookie on watch/session ping.
 * Bare curl/scrapers without that session (or blocked UAs / rate floods) are denied and logged.
 */
final class ProxyGuard
{
    public const COOKIE = 'vf_ps';
    public const TTL_SEC = 900; // 15 min stream tokens
    public const SESSION_TTL = 21600; // 6h browser session
    public const SOURCES_PER_MIN = 60;
    public const RELAY_PER_MIN = 1200;
    /** Auto-ban OFF by default — too easy to hit real viewers (parallel /sources). Manual admin block only. */
    public const AUTO_BLOCK_ENABLED = false;
    public const AUTO_BLOCK_EVENTS = 80;
    public const AUTO_BLOCK_WINDOW = 600; // 10 min
    public const AUTO_BLOCK_HOURS = 24;

    private static bool $booted = false;

    public static function bootSchema(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        try {
            $pdo = Database::pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS proxy_blocks (
                    ip VARCHAR(64) NOT NULL PRIMARY KEY,
                    reason VARCHAR(255) NOT NULL DEFAULT '',
                    created_at INT NOT NULL,
                    expires_at INT NULL,
                    created_by VARCHAR(64) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS proxy_events (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    created_at INT NOT NULL,
                    ip VARCHAR(64) NOT NULL,
                    event VARCHAR(32) NOT NULL,
                    path VARCHAR(160) NOT NULL DEFAULT '',
                    detail VARCHAR(512) NOT NULL DEFAULT '',
                    KEY idx_created (created_at),
                    KEY idx_ip_created (ip, created_at),
                    KEY idx_event_created (event, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            // Don't break playback if DB is briefly down.
        }
    }

    public static function clientIp(): string
    {
        // Prefer Cloudflare's visitor IP. Never fall back to a CF edge address —
        // that minted tokens with colo IPs and caused intermittent "Proxy binding failed".
        foreach ([
            (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            (string) ($_SERVER['HTTP_TRUE_CLIENT_IP'] ?? ''),
        ] as $cand) {
            $cand = trim($cand);
            if ($cand !== '' && filter_var($cand, FILTER_VALIDATE_IP) && !self::isCloudflareIp($cand)) {
                return $cand;
            }
        }

        $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '') {
            foreach (explode(',', $xff) as $part) {
                $first = trim($part);
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP) && !self::isCloudflareIp($first)) {
                    return $first;
                }
            }
        }

        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !self::isCloudflareIp($ip)) {
            return $ip;
        }
        // Last resort: still return something stable for rate keys (even if CF).
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    /** Rough Cloudflare IP check (common colo ranges seen in binding failures). */
    private static function isCloudflareIp(string $ip): bool
    {
        if (str_starts_with($ip, '2a06:98c0:') || str_starts_with($ip, '2a06:98c1:')) {
            return true;
        }
        // CF IPv4 published ranges (partial — enough to avoid colo-as-client).
        $cidrs = [
            '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '104.16.0.0/13',
            '104.24.0.0/14', '108.162.192.0/18', '131.0.72.0/22', '141.101.64.0/18',
            '162.158.0.0/15', '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
        ];
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            // IPv6 CF-ish
            return str_starts_with(strtolower($ip), '2400:cb00:')
                || str_starts_with(strtolower($ip), '2606:4700:')
                || str_starts_with(strtolower($ip), '2803:f800:')
                || str_starts_with(strtolower($ip), '2405:b500:')
                || str_starts_with(strtolower($ip), '2405:8100:')
                || str_starts_with(strtolower($ip), '2a06:98c0:')
                || str_starts_with(strtolower($ip), '2c0f:f248:');
        }
        foreach ($cidrs as $cidr) {
            [$subnet, $mask] = explode('/', $cidr, 2);
            $subnetLong = ip2long($subnet);
            $mask = (int) $mask;
            if ($subnetLong === false) {
                continue;
            }
            $maskLong = -1 << (32 - $mask);
            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                return true;
            }
        }
        return false;
    }

    public static function requestPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function secret(): string
    {
        $s = (string) config('auth_secret', '');
        return $s !== '' ? $s : 'vuflix-proxy-guard';
    }

    /** @return array{sid:string,iat:int,exp:int}|null */
    public static function readSession(): ?array
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '' || !str_contains($raw, '.')) {
            return null;
        }
        [$body, $sig] = explode('.', $raw, 2);
        if ($body === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $body, self::secret()), $sig)) {
            return null;
        }
        $pad = strlen($body) % 4;
        $json = base64_decode(strtr($body, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $exp = (int) ($data['exp'] ?? 0);
        $sid = preg_replace('/[^a-f0-9]/', '', (string) ($data['sid'] ?? '')) ?? '';
        if ($exp < time() || strlen($sid) < 16) {
            return null;
        }
        return [
            'sid' => $sid,
            'iat' => (int) ($data['iat'] ?? 0),
            'exp' => $exp,
        ];
    }

    /** Mint / refresh player session cookie (same-site, HttpOnly). */
    public static function ensureSession(): array
    {
        $existing = self::readSession();
        if ($existing !== null && ($existing['exp'] - time()) > 600) {
            return $existing;
        }

        // Parallel /sources race (no cookie yet) used to mint 3 different sids and
        // stomp Set-Cookie — tokens from the losing responses then 403 on a-relay.
        // Sticky short-lived sid per IP + flock so concurrent first hits share one sid.
        $ip = self::clientIp();
        $stickyPath = sys_get_temp_dir() . '/vf_ps_' . hash('sha256', $ip);
        $lockPath = $stickyPath . '.lock';
        $lockFh = null;
        if ($existing === null) {
            $lockFh = @fopen($lockPath, 'c+');
            if ($lockFh !== false) {
                @flock($lockFh, LOCK_EX);
            }
            // Re-read after lock — another worker may have written sticky.
            if (is_readable($stickyPath)) {
                $stickyRaw = (string) @file_get_contents($stickyPath);
                $sticky = json_decode($stickyRaw, true);
                if (is_array($sticky)
                    && !empty($sticky['sid'])
                    && !empty($sticky['cookie'])
                    && (int) ($sticky['exp'] ?? 0) > time() + 60
                    && (int) ($sticky['iat'] ?? 0) > time() - 45
                ) {
                    $cookie = (string) $sticky['cookie'];
                    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                    setcookie(self::COOKIE, $cookie, [
                        'expires' => (int) $sticky['exp'],
                        'path' => '/',
                        'secure' => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE[self::COOKIE] = $cookie;
                    self::writeStickySid((string) $sticky['sid'], $ip, (int) $sticky['exp']);
                    if ($lockFh !== false && $lockFh !== null) {
                        @flock($lockFh, LOCK_UN);
                        @fclose($lockFh);
                    }
                    return [
                        'sid' => (string) $sticky['sid'],
                        'iat' => (int) $sticky['iat'],
                        'exp' => (int) $sticky['exp'],
                    ];
                }
            }
        }

        $sid = $existing['sid'] ?? bin2hex(random_bytes(16));
        $iat = time();
        $exp = $iat + self::SESSION_TTL;
        $payload = ['sid' => $sid, 'iat' => $iat, 'exp' => $exp];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::secret());
        $cookie = $body . '.' . $sig;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, $cookie, [
            'expires' => $exp,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $cookie;
        if ($existing === null) {
            @file_put_contents(
                $stickyPath,
                json_encode(['sid' => $sid, 'iat' => $iat, 'exp' => $exp, 'cookie' => $cookie, 'ip' => $ip], JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            @chmod($stickyPath, 0666);
            // Sid index so native HLS (no cookie) can still prove a recent mint.
            self::writeStickySid($sid, $ip, $exp);
            if ($lockFh !== false && $lockFh !== null) {
                @flock($lockFh, LOCK_UN);
                @fclose($lockFh);
            }
        } else {
            self::writeStickySid($sid, $ip, $exp);
        }
        return ['sid' => $sid, 'iat' => $iat, 'exp' => $exp];
    }

    private static function stickySidPath(string $sid): string
    {
        return sys_get_temp_dir() . '/vf_ps_sid_' . preg_replace('/[^a-f0-9]/', '', $sid);
    }

    private static function writeStickySid(string $sid, string $ip, int $exp): void
    {
        $sid = preg_replace('/[^a-f0-9]/', '', $sid) ?? '';
        if (strlen($sid) < 16) {
            return;
        }
        $path = self::stickySidPath($sid);
        $prev = [];
        if (is_readable($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                $prev = $decoded;
            }
        }
        $ips = [];
        foreach (($prev['ips'] ?? []) as $old) {
            $old = trim((string) $old);
            if ($old !== '' && filter_var($old, FILTER_VALIDATE_IP)) {
                $ips[$old] = true;
            }
        }
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $ips[$ip] = true;
        }
        @file_put_contents(
            $path,
            json_encode([
                'sid' => $sid,
                'exp' => $exp,
                'ips' => array_keys($ips),
                'updated' => time(),
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($path, 0666);
    }

    private static function stickySidAlive(string $sid): bool
    {
        $path = self::stickySidPath($sid);
        if (!is_readable($path)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return false;
        }
        return (int) ($data['exp'] ?? 0) > time();
    }

    private static function stickySidMatchesIp(string $sid, string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $path = self::stickySidPath($sid);
        if (!is_readable($path)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return false;
        }
        // Remember this IP under the sid (dual-stack viewer).
        self::writeStickySid($sid, $ip, (int) $data['exp']);
        return true;
    }

    public static function isStaffBypass(): bool
    {
        try {
            if (!class_exists('Auth')) {
                return false;
            }
            $user = Auth::user();
            return is_array($user) && Auth::isStaff($user);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function isBlocked(?string $ip = null): bool
    {
        self::bootSchema();
        $ip = $ip ?? self::clientIp();
        try {
            $st = Database::pdo()->prepare(
                'SELECT expires_at FROM proxy_blocks WHERE ip = ? LIMIT 1'
            );
            $st->execute([$ip]);
            $row = $st->fetch();
            if (!$row) {
                return false;
            }
            $exp = $row['expires_at'];
            if ($exp !== null && (int) $exp > 0 && (int) $exp < time()) {
                Database::pdo()->prepare('DELETE FROM proxy_blocks WHERE ip = ?')->execute([$ip]);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function blockIp(string $ip, string $reason, ?int $hours = 24, ?string $by = null): bool
    {
        self::bootSchema();
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $now = time();
        $exp = $hours === null ? null : ($now + max(1, $hours) * 3600);
        try {
            $st = Database::pdo()->prepare(
                'INSERT INTO proxy_blocks (ip, reason, created_at, expires_at, created_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_at = VALUES(created_at),
                   expires_at = VALUES(expires_at), created_by = VALUES(created_by)'
            );
            $st->execute([$ip, mb_substr($reason, 0, 255), $now, $exp, $by]);
            self::logEvent('blocked_ip', 'manual/auto block: ' . $reason, $ip);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function unblockIp(string $ip): bool
    {
        self::bootSchema();
        try {
            Database::pdo()->prepare('DELETE FROM proxy_blocks WHERE ip = ?')->execute([trim($ip)]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function logEvent(string $event, string $detail = '', ?string $ip = null, ?string $path = null): void
    {
        self::bootSchema();
        $ip = $ip ?? self::clientIp();
        $path = $path ?? self::requestPath();

        // Dedupe noisy signals (one row per IP/event/minute) so admin stays readable
        // and parallel player /sources calls don't look like a botnet.
        if (in_array($event, ['no_session', 'blocked_ip', 'rate_limit'], true)) {
            try {
                $st = Database::pdo()->prepare(
                    'SELECT id FROM proxy_events WHERE ip = ? AND event = ? AND created_at >= ? LIMIT 1'
                );
                $st->execute([$ip, $event, time() - 60]);
                if ($st->fetch()) {
                    return;
                }
            } catch (Throwable $e) {
                // fall through and insert
            }
        }

        try {
            $st = Database::pdo()->prepare(
                'INSERT INTO proxy_events (created_at, ip, event, path, detail) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([
                time(),
                mb_substr($ip, 0, 64),
                mb_substr($event, 0, 32),
                mb_substr($path, 0, 160),
                mb_substr($detail, 0, 512),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
        if (self::AUTO_BLOCK_ENABLED
            && (in_array($event, ['blocked_ua', 'rate_limit'], true)
                || ($event === 'ip_mismatch' && !str_contains($detail, 'allowed via session')))) {
            self::maybeAutoBlock($ip);
        }
    }

    private static function maybeAutoBlock(string $ip): void
    {
        if (!self::AUTO_BLOCK_ENABLED) {
            return;
        }
        try {
            $since = time() - self::AUTO_BLOCK_WINDOW;
            // no_session excluded — normal players can emit it during cookie race.
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM proxy_events
                 WHERE ip = ? AND created_at >= ? AND event IN ('blocked_ua','rate_limit')"
            );
            $st->execute([$ip, $since]);
            $n = (int) $st->fetchColumn();
            if ($n >= self::AUTO_BLOCK_EVENTS && !self::isBlocked($ip)) {
                self::blockIp($ip, "auto: {$n} scraper events / 10m", self::AUTO_BLOCK_HOURS, 'auto');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    private static function rateKey(string $bucket): string
    {
        $ip = preg_replace('/[^a-fA-F0-9:.]/', '', self::clientIp()) ?: '0';
        $min = (string) (int) floor(time() / 60);
        return sys_get_temp_dir() . '/vf_rl_' . hash('sha256', $bucket . '|' . $ip . '|' . $min);
    }

    public static function rateAllow(string $bucket, int $limit): bool
    {
        $file = self::rateKey($bucket);
        $n = 0;
        if (is_file($file)) {
            $n = (int) @file_get_contents($file);
        }
        $n++;
        @file_put_contents($file, (string) $n, LOCK_EX);
        @chmod($file, 0666);
        return $n <= $limit;
    }

    public static function isScraperUa(?string $ua = null): bool
    {
        $ua = strtolower($ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '') {
            return true;
        }
        return (bool) preg_match(
            '/curl|wget|python-requests|python-urllib|scrapy|libwww-perl|go-http-client|httpie|postman|okhttp|java\/|node-fetch|axios\//i',
            $ua
        );
    }

    /**
     * Gate for /api/player/sources and relays.
     * @return array{ok:bool,session?:array,error?:string,code?:int}
     */
    public static function gate(string $kind = 'sources'): array
    {
        self::bootSchema();
        $ip = self::clientIp();

        if (self::isBlocked($ip)) {
            self::logEvent('blocked_ip', 'hit while blocked', $ip);
            return ['ok' => false, 'error' => 'Forbidden', 'code' => 403];
        }

        if (self::isScraperUa()) {
            self::logEvent('blocked_ua', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), $ip);
            return ['ok' => false, 'error' => 'Forbidden', 'code' => 403];
        }

        if (!self::isStaffBypass()) {
            $session = self::readSession();
            if ($session === null) {
                // Mint session on first hit (sets cookie). Do NOT 403 normal browsers —
                // parallel /sources during watch looked like scrapers and auto-banned people.
                // Curl/scrapers still die on isScraperUa() above.
                if ($kind === 'sources') {
                    self::logEvent('no_session', 'minted on sources', $ip);
                }
                $session = self::ensureSession();
            }
        } else {
            $session = self::ensureSession();
        }

        $limit = $kind === 'relay' ? self::RELAY_PER_MIN : self::SOURCES_PER_MIN;
        if (!self::rateAllow($kind, $limit)) {
            self::logEvent('rate_limit', $kind . ' > ' . $limit . '/min', $ip);
            return ['ok' => false, 'error' => 'Too many requests', 'code' => 429];
        }

        return ['ok' => true, 'session' => $session ?? self::ensureSession()];
    }

    /** Fields to embed in signed stream tokens. */
    public static function tokenBindFields(): array
    {
        $session = self::readSession() ?? self::ensureSession();
        return [
            'sid' => (string) ($session['sid'] ?? ''),
            'ip' => self::clientIp(),
            'e' => time() + self::TTL_SEC,
        ];
    }

    /**
     * Validate sid/ip on a decoded token payload.
     * Session match is enough (mobile IP can change); IP-only without session is rejected.
     */
    public static function assertTokenBinding(array $data): void
    {
        if (self::isStaffBypass()) {
            return;
        }
        $ip = self::clientIp();
        if (self::isBlocked($ip)) {
            self::logEvent('blocked_ip', 'relay while blocked', $ip);
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            exit;
        }

        $tokSid = preg_replace('/[^a-f0-9]/', '', (string) ($data['sid'] ?? '')) ?? '';
        $tokIp = (string) ($data['ip'] ?? '');
        $session = self::readSession();
        $sidOk = $session !== null && $tokSid !== '' && hash_equals($tokSid, $session['sid']);
        // Native HLS (PS4/Safari) often omits cookies — also accept a live sticky sid mint.
        if (!$sidOk && $tokSid !== '' && self::stickySidAlive($tokSid)) {
            $sidOk = true;
        }
        $ipOk = $tokIp !== '' && hash_equals($tokIp, $ip);
        // Dual-stack: mint on v4, play on v6 (or vice versa) — accept if sticky file for tokIp
        // was written for this sid (same browser session).
        if (!$ipOk && $tokIp !== '' && $tokSid !== '' && self::stickySidMatchesIp($tokSid, $ip)) {
            $ipOk = true;
        }

        // Accept either bind:
        // - sidOk: mobile IP can change; cookie/sticky proves the browser
        // - ipOk: same IP even if media/HLS omit the cookie (Safari native HLS, workers)
        // Reject only when BOTH fail (stolen token used from another IP without session).
        if ($sidOk || $ipOk) {
            return;
        }

        self::logEvent('ip_mismatch', 'rejected (no session bind)', $ip);
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Proxy binding failed']);
        exit;
    }

    /** @return list<array<string,mixed>> */
    public static function recentEvents(int $limit = 100, ?string $event = null): array
    {
        self::bootSchema();
        $limit = max(1, min(500, $limit));
        try {
            if ($event) {
                $st = Database::pdo()->prepare(
                    'SELECT * FROM proxy_events WHERE event = ? ORDER BY id DESC LIMIT ' . $limit
                );
                $st->execute([$event]);
            } else {
                $st = Database::pdo()->query(
                    'SELECT * FROM proxy_events ORDER BY id DESC LIMIT ' . $limit
                );
            }
            return $st ? ($st->fetchAll() ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public static function listBlocks(): array
    {
        self::bootSchema();
        try {
            $st = Database::pdo()->query('SELECT * FROM proxy_blocks ORDER BY created_at DESC LIMIT 500');
            return $st ? ($st->fetchAll() ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<string,int> */
    public static function stats24h(): array
    {
        self::bootSchema();
        $out = [
            'blocked_ua' => 0,
            'no_session' => 0,
            'rate_limit' => 0,
            'ip_mismatch' => 0,
            'blocked_ip' => 0,
            'blocks_active' => 0,
        ];
        try {
            $since = time() - 86400;
            $st = Database::pdo()->prepare(
                'SELECT event, COUNT(*) AS c FROM proxy_events WHERE created_at >= ? GROUP BY event'
            );
            $st->execute([$since]);
            foreach ($st->fetchAll() ?: [] as $row) {
                $k = (string) ($row['event'] ?? '');
                if (isset($out[$k])) {
                    $out[$k] = (int) $row['c'];
                }
            }
            $out['blocks_active'] = (int) Database::pdo()->query('SELECT COUNT(*) FROM proxy_blocks')->fetchColumn();
        } catch (Throwable $e) {
            // ignore
        }
        return $out;
    }

    /** Top suspicious IPs in last 24h (scraper-like events only). */
    public static function topSuspects(int $limit = 30): array
    {
        self::bootSchema();
        $limit = max(1, min(100, $limit));
        try {
            $since = time() - 86400;
            $st = Database::pdo()->prepare(
                "SELECT ip, COUNT(*) AS hits,
                        SUM(event='blocked_ua') AS ua,
                        SUM(event='no_session') AS nosess,
                        SUM(event='rate_limit') AS rate,
                        SUM(event='ip_mismatch') AS mism
                 FROM proxy_events
                 WHERE created_at >= ?
                   AND event IN ('blocked_ua','no_session','rate_limit','ip_mismatch')
                 GROUP BY ip
                 ORDER BY hits DESC
                 LIMIT {$limit}"
            );
            $st->execute([$since]);
            return $st->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
