<?php
declare(strict_types=1);

/**
 * Shared Cloudflare Worker relay config for Chillflix + Vuflix.
 * Source of truth: /var/www/cinepro/config/worker-relay.json
 */
final class WorkerRelayService
{
    private static int $rr = 0;

    public static function configPath(): string
    {
        $env = getenv('WORKER_RELAY_CONFIG_PATH');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return '/var/www/cinepro/config/worker-relay.json';
    }

    public static function scriptPath(): string
    {
        $env = getenv('WORKER_RELAY_SCRIPT_PATH');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        foreach ([
            '/var/www/chillflix-newsite/workers/yoru-relay.js',
            '/var/www/cinepro/workers/yoru-relay.js',
        ] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }
        return '/var/www/chillflix-newsite/workers/yoru-relay.js';
    }

    /** @return array{content:string,path:string} */
    public static function readScript(): array
    {
        $path = self::scriptPath();
        if (!is_readable($path)) {
            throw new RuntimeException('yoru-relay.js not found at ' . $path);
        }
        $content = (string) file_get_contents($path);
        if (trim($content) === '') {
            throw new RuntimeException('yoru-relay.js is empty');
        }
        return ['content' => $content, 'path' => $path];
    }

    /** @return array{enabled:bool,preferWorker:bool,cacheTtlSeconds:int,workers:list<array{id:string,label:string,url:string,secret:string,enabled:bool}>} */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'preferWorker' => true,
            'cacheTtlSeconds' => 7200,
            'workers' => [],
        ];
    }

    /** @return array{enabled:bool,preferWorker:bool,cacheTtlSeconds:int,workers:list<array{id:string,label:string,url:string,secret:string,enabled:bool}>} */
    public static function get(): array
    {
        $path = self::configPath();
        $config = self::defaults();
        if (is_readable($path)) {
            $raw = json_decode((string) file_get_contents($path), true);
            if (is_array($raw)) {
                $config = self::sanitize($raw);
            }
        }

        if ($config['workers'] === []) {
            $env = self::envPrimary();
            if ($env !== null) {
                $config['workers'] = [$env];
            }
        } else {
            $secret = self::envValue('YORU_RELAY_SECRET');
            if ($secret !== '') {
                foreach ($config['workers'] as &$w) {
                    if (($w['secret'] ?? '') === '') {
                        $w['secret'] = $secret;
                    }
                }
                unset($w);
            }
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{enabled:bool,preferWorker:bool,cacheTtlSeconds:int,workers:list<array{id:string,label:string,url:string,secret:string,enabled:bool}>}
     */
    public static function update(array $body): array
    {
        $previous = self::get();
        $incoming = self::sanitize($body);
        $prevById = [];
        foreach ($previous['workers'] as $w) {
            $prevById[$w['id']] = $w;
        }

        $workers = [];
        foreach ($incoming['workers'] as $i => $w) {
            $prev = $prevById[$w['id']] ?? null;
            if ($prev === null) {
                foreach ($previous['workers'] as $p) {
                    if ($p['url'] === $w['url']) {
                        $prev = $p;
                        break;
                    }
                }
            }
            $secret = $w['secret'];
            if ($secret === '' || str_contains($secret, '…')) {
                $secret = $prev['secret'] ?? '';
            }
            $w['secret'] = $secret;
            $cfToken = (string) ($w['cfApiToken'] ?? '');
            if ($cfToken === '' || str_contains($cfToken, '…')) {
                $cfToken = (string) ($prev['cfApiToken'] ?? '');
            }
            $w['cfApiToken'] = $cfToken;
            if (($w['cfAccountId'] ?? '') === '' && isset($prev['cfAccountId'])) {
                $w['cfAccountId'] = $prev['cfAccountId'];
            }
            if (($w['cfScriptName'] ?? '') === '' && isset($prev['cfScriptName'])) {
                $w['cfScriptName'] = $prev['cfScriptName'];
            }
            if ($w['id'] === '') {
                $w['id'] = 'worker-' . ($i + 1);
            }
            if ($w['url'] === '') {
                continue;
            }
            $workers[] = $w;
        }

        $next = self::sanitize([
            'enabled' => $incoming['enabled'],
            'preferWorker' => $incoming['preferWorker'],
            'cacheTtlSeconds' => $incoming['cacheTtlSeconds'],
            'workers' => $workers,
        ]);

        $path = self::configPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create config dir: ' . $dir);
        }
        $payload = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($path, $payload) === false) {
            throw new RuntimeException(
                'Cannot write worker-relay config (check permissions): ' . $path
            );
        }
        // Re-read to confirm persistence (catches root-owned files PHP can't update).
        $verify = @file_get_contents($path);
        $decoded = is_string($verify) ? json_decode($verify, true) : null;
        $savedCount = is_array($decoded['workers'] ?? null) ? count($decoded['workers']) : 0;
        if ($savedCount !== count($next['workers'])) {
            throw new RuntimeException(
                'Worker relay save did not persist (expected '
                . count($next['workers']) . ' workers, file has ' . $savedCount . ')'
            );
        }

        $primary = null;
        foreach ($next['workers'] as $w) {
            if (!empty($w['enabled']) && $w['url'] !== '' && $w['secret'] !== '') {
                $primary = $w;
                break;
            }
        }
        if ($primary !== null) {
            self::syncEnvFiles(
                $primary['url'],
                $primary['secret'],
                $next['preferWorker'] && $next['enabled']
            );
        }

        return $next;
    }

    /** @return array{enabled:bool,preferWorker:bool,cacheTtlSeconds:int,workers:list<array{id:string,label:string,url:string,secret:string,enabled:bool,secretSet?:bool}>,path:string} */
    public static function publicConfig(): array
    {
        $config = self::get();
        $workers = [];
        foreach ($config['workers'] as $w) {
            $secret = $w['secret'];
            $masked = $secret === ''
                ? ''
                : (substr($secret, 0, 4) . '…' . substr($secret, -4));
            $cfToken = (string) ($w['cfApiToken'] ?? '');
            $cfMasked = $cfToken === ''
                ? ''
                : (substr($cfToken, 0, 4) . '…' . substr($cfToken, -4));
            $host = (string) (parse_url($w['url'], PHP_URL_HOST) ?: '');
            $autoScript = $host !== '' ? (explode('.', $host)[0] ?? '') : '';
            $workers[] = [
                'id' => $w['id'],
                'label' => $w['label'],
                'url' => $w['url'],
                'secret' => $masked,
                'enabled' => $w['enabled'],
                'secretSet' => $secret !== '',
                'cfAccountId' => (string) ($w['cfAccountId'] ?? ''),
                'cfApiToken' => $cfMasked,
                'cfApiTokenSet' => $cfToken !== '',
                'cfScriptName' => (string) (($w['cfScriptName'] ?? '') !== '' ? $w['cfScriptName'] : $autoScript),
            ];
        }
        return [
            'enabled' => $config['enabled'],
            'preferWorker' => $config['preferWorker'],
            'cacheTtlSeconds' => $config['cacheTtlSeconds'],
            'workers' => $workers,
            'path' => self::configPath(),
        ];
    }

    /**
     * @return list<array{id:string,label:string,scriptName:string,ok:bool,error?:string,todayRequests:int,todayErrors:int,last24hRequests:int,last24hErrors:int,freeDailyLimit:int,fetchedAt:string}>
     */
    public static function analytics(?string $workerId = null, bool $force = false): array
    {
        $cachePath = dirname(self::configPath()) . '/worker-relay-stats.json';
        if (
            !$force
            && ($workerId === null || $workerId === '')
            && is_readable($cachePath)
        ) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            $at = is_array($cached) ? (int) ($cached['at'] ?? 0) : 0;
            if ($at > 0 && (time() - $at) < 55 && is_array($cached['stats'] ?? null)) {
                /** @var list<array{id:string,label:string,scriptName:string,ok:bool,error?:string,todayRequests:int,todayErrors:int,last24hRequests:int,last24hErrors:int,freeDailyLimit:int,fetchedAt:string}> $stats */
                $stats = $cached['stats'];
                return $stats;
            }
        }

        $config = self::get();
        $out = [];
        foreach ($config['workers'] as $w) {
            if ($workerId !== null && $workerId !== '' && $w['id'] !== $workerId) {
                continue;
            }
            $out[] = self::fetchWorkerAnalytics($w);
        }

        if ($workerId === null || $workerId === '') {
            @file_put_contents(
                $cachePath,
                json_encode(['at' => time(), 'stats' => $out], JSON_UNESCAPED_SLASHES)
            );
        }
        return $out;
    }

    /**
     * @param array{id:string,label:string,url:string,cfAccountId?:string,cfApiToken?:string,cfScriptName?:string} $worker
     * @return array{id:string,label:string,scriptName:string,ok:bool,error?:string,todayRequests:int,todayErrors:int,last24hRequests:int,last24hErrors:int,freeDailyLimit:int,fetchedAt:string}
     */
    public static function fetchWorkerAnalytics(array $worker): array
    {
        $host = (string) (parse_url((string) ($worker['url'] ?? ''), PHP_URL_HOST) ?: '');
        $scriptName = trim((string) ($worker['cfScriptName'] ?? ''));
        if ($scriptName === '' && $host !== '') {
            $scriptName = explode('.', $host)[0] ?? '';
        }
        $accountId = trim((string) ($worker['cfAccountId'] ?? ''));
        $token = trim((string) ($worker['cfApiToken'] ?? ''));
        $base = [
            'id' => (string) ($worker['id'] ?? ''),
            'label' => (string) ($worker['label'] ?? ''),
            'scriptName' => $scriptName,
            'ok' => false,
            'todayRequests' => 0,
            'todayErrors' => 0,
            'last24hRequests' => 0,
            'last24hErrors' => 0,
            'freeDailyLimit' => 100000,
            'fetchedAt' => gmdate('c'),
        ];
        if ($accountId === '' || $token === '' || $scriptName === '') {
            $base['error'] = $accountId === ''
                ? 'Set CF Account ID'
                : ($token === '' ? 'Set CF API token' : 'Set CF script name');
            return $base;
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $startToday = $now->setTime(0, 0, 0)->format('Y-m-d\TH:i:s.000\Z');
            $start24h = $now->modify('-24 hours')->format('Y-m-d\TH:i:s.000\Z');
            $end = $now->format('Y-m-d\TH:i:s.000\Z');

            $today = self::queryCfInvocations($accountId, $token, $scriptName, $startToday, $end);
            $last24 = self::queryCfInvocations($accountId, $token, $scriptName, $start24h, $end);

            $base['ok'] = true;
            $base['todayRequests'] = $today['requests'];
            $base['todayErrors'] = $today['errors'];
            $base['last24hRequests'] = $last24['requests'];
            $base['last24hErrors'] = $last24['errors'];
            return $base;
        } catch (Throwable $e) {
            $base['error'] = $e->getMessage();
            return $base;
        }
    }

    /** @return array{requests:int,errors:int} */
    private static function queryCfInvocations(
        string $accountId,
        string $token,
        string $scriptName,
        string $start,
        string $end
    ): array {
        $query = <<<'GQL'
query WorkerStats($accountTag: string, $scriptName: string, $start: Time, $end: Time) {
  viewer {
    accounts(filter: { accountTag: $accountTag }) {
      workersInvocationsAdaptive(
        limit: 10000
        filter: {
          scriptName: $scriptName
          datetime_geq: $start
          datetime_leq: $end
        }
      ) {
        sum { requests errors }
      }
    }
  }
}
GQL;
        $payload = json_encode([
            'query' => $query,
            'variables' => [
                'accountTag' => $accountId,
                'scriptName' => $scriptName,
                'start' => $start,
                'end' => $end,
            ],
        ], JSON_UNESCAPED_SLASHES);
        $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException($cerr !== '' ? $cerr : 'empty Cloudflare response');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Cloudflare API HTTP ' . $status);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Cloudflare bad JSON');
        }
        if (!empty($json['errors']) && is_array($json['errors'])) {
            $msgs = [];
            foreach ($json['errors'] as $err) {
                if (is_array($err) && isset($err['message'])) {
                    $msgs[] = (string) $err['message'];
                }
            }
            throw new RuntimeException($msgs !== [] ? implode('; ', $msgs) : 'GraphQL error');
        }
        $rows = $json['data']['viewer']['accounts'][0]['workersInvocationsAdaptive'] ?? [];
        $requests = 0;
        $errors = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $requests += (int) ($row['sum']['requests'] ?? 0);
                $errors += (int) ($row['sum']['errors'] ?? 0);
            }
        }
        return ['requests' => $requests, 'errors' => $errors];
    }

    /** Round-robin pick among enabled workers. */
    /** @return array{id:string,label:string,url:string,secret:string,enabled:bool}|null */
    public static function pickWorker(): ?array
    {
        $config = self::get();
        if (!$config['enabled']) {
            return null;
        }
        $enabled = [];
        foreach ($config['workers'] as $w) {
            if (!empty($w['enabled']) && $w['url'] !== '' && $w['secret'] !== '') {
                $enabled[] = $w;
            }
        }
        if ($enabled === []) {
            return null;
        }
        self::$rr = (self::$rr + 1) % count($enabled);
        return $enabled[self::$rr];
    }

    /** @return array{ok:bool,status?:int,error?:string,cache?:string} */
    public static function test(string $url, string $secret): array
    {
        $base = rtrim($url, '/');
        $secret = trim($secret);
        if ($base === '' || $secret === '') {
            return ['ok' => false, 'error' => 'url and secret required'];
        }

        $health = self::httpGet($base . '/health', [], 8);
        if (($health['status'] ?? 0) < 200 || ($health['status'] ?? 0) >= 300) {
            return [
                'ok' => false,
                'status' => (int) ($health['status'] ?? 0),
                'error' => 'health HTTP ' . (int) ($health['status'] ?? 0),
            ];
        }

        $probe = $base . '/vaplayer?' . http_build_query([
            'type' => 'movie',
            'tmdb' => '550',
            'key' => $secret,
            'cacheTtl' => '120',
        ]);
        $res = self::httpGet($probe, [
            'Accept: application/json',
            'x-yoru-key: ' . $secret,
        ], 20);
        $json = is_string($res['body'] ?? null) ? json_decode($res['body'], true) : null;
        $ok = ($res['status'] ?? 0) >= 200
            && ($res['status'] ?? 0) < 300
            && is_array($json)
            && !empty($json['ok'])
            && !empty($json['sources'])
            && is_array($json['sources']);

        return [
            'ok' => $ok,
            'status' => (int) ($res['status'] ?? 0),
            'error' => $ok ? null : (string) ($json['error'] ?? ('HTTP ' . (int) ($res['status'] ?? 0))),
            'cache' => is_string($res['cache'] ?? null) ? $res['cache'] : null,
        ];
    }

    /** @return array{restarted:bool,error?:string} */
    public static function bounceCinepro(): array
    {
        $cmd = "cd /var/www/cinepro && pm2 restart cinepro --update-env 2>&1";
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0) {
            return ['restarted' => false, 'error' => implode("\n", $out) ?: 'pm2 restart failed'];
        }
        return ['restarted' => true];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{enabled:bool,preferWorker:bool,cacheTtlSeconds:int,workers:list<array{id:string,label:string,url:string,secret:string,enabled:bool}>}
     */
    private static function sanitize(array $raw): array
    {
        $workers = [];
        $list = $raw['workers'] ?? [];
        if (is_array($list)) {
            foreach (array_values($list) as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $url = rtrim(trim((string) ($row['url'] ?? '')), '/');
                if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . $url;
                }
                if ($url === '') {
                    continue;
                }
                $scriptName = trim((string) ($row['cfScriptName'] ?? ''));
                if ($scriptName === '') {
                    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
                    $scriptName = $host !== '' ? (explode('.', $host)[0] ?? '') : '';
                }
                $workers[] = [
                    'id' => trim((string) ($row['id'] ?? ('worker-' . ($i + 1)))) ?: ('worker-' . ($i + 1)),
                    'label' => trim((string) ($row['label'] ?? ('Worker ' . ($i + 1)))) ?: ('Worker ' . ($i + 1)),
                    'url' => $url,
                    'secret' => trim((string) ($row['secret'] ?? '')),
                    'enabled' => ($row['enabled'] ?? true) !== false,
                    'cfAccountId' => trim((string) ($row['cfAccountId'] ?? '')),
                    'cfApiToken' => trim((string) ($row['cfApiToken'] ?? '')),
                    'cfScriptName' => $scriptName,
                ];
            }
        }

        $ttl = (int) ($raw['cacheTtlSeconds'] ?? 7200);
        if ($ttl < 60) {
            $ttl = 60;
        }
        if ($ttl > 86400) {
            $ttl = 86400;
        }

        return [
            'enabled' => ($raw['enabled'] ?? true) !== false,
            'preferWorker' => ($raw['preferWorker'] ?? true) !== false,
            'cacheTtlSeconds' => $ttl,
            'workers' => $workers,
        ];
    }

    /** @return array{id:string,label:string,url:string,secret:string,enabled:bool}|null */
    private static function envPrimary(): ?array
    {
        $url = rtrim(self::envValue('YORU_RELAY_URL'), '/');
        $secret = self::envValue('YORU_RELAY_SECRET');
        if ($url === '' || $secret === '') {
            return null;
        }
        return [
            'id' => 'primary',
            'label' => 'Primary',
            'url' => $url,
            'secret' => $secret,
            'enabled' => true,
        ];
    }

    private static function envValue(string $key): string
    {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return trim($v);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return trim($_ENV[$key]);
        }
        // Fall back to newsite / cinepro .env files
        foreach (['/var/www/chillflix-newsite/.env', '/var/www/cinepro/.env'] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $val] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    return trim($val, " \t\"'");
                }
            }
        }
        return '';
    }

    private static function syncEnvFiles(string $url, string $secret, bool $preferWorker): void
    {
        self::upsertEnv('/var/www/cinepro/.env', [
            'YORU_RELAY_URL' => $url,
            'YORU_RELAY_SECRET' => $secret,
            'VAPLAYER_PREFER_WORKER' => $preferWorker ? '1' : '0',
        ]);
        self::upsertEnv('/var/www/chillflix-newsite/.env', [
            'YORU_RELAY_URL' => $url,
            'YORU_RELAY_SECRET' => $secret,
        ]);
    }

    /** @param array<string,string> $updates */
    private static function upsertEnv(string $path, array $updates): void
    {
        $content = is_readable($path) ? (string) file_get_contents($path) : '';
        foreach ($updates as $key => $value) {
            $line = $key . '=' . $value;
            $re = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($re, $content)) {
                $content = preg_replace($re, $line, $content) ?? $content;
            } else {
                $content = rtrim($content) . "\n" . $line . "\n";
            }
        }
        file_put_contents($path, rtrim($content) . "\n");
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body?:string,cache?:string}
     */
    private static function httpGet(string $url, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($raw)) {
            return ['status' => $status];
        }
        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $cache = null;
        if (preg_match('/^x-yoru-cache:\s*(.+)$/mi', $headerBlob, $m)) {
            $cache = trim($m[1]);
        }
        return ['status' => $status, 'body' => $body, 'cache' => $cache];
    }
}
