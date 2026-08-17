<?php
declare(strict_types=1);

/**
 * Admin disk / temp / log housekeeping for Vuflix (newsite).
 * Safe actions only — never touches Chillflix live .next build.
 */
final class SiteStorage
{
    /** @return list<array{id:string,label:string,path:string,bytes:int,count:int,safe:bool,note:string}> */
    public static function inventory(): array
    {
        $items = [];

        $cfhls = self::scanGlob('/tmp/cfhls_*');
        $items[] = [
            'id' => 'cfhls',
            'label' => 'Stream temp (cfhls)',
            'path' => '/tmp/cfhls_*',
            'bytes' => $cfhls['bytes'],
            'count' => $cfhls['count'],
            'safe' => true,
            'note' => 'Leftover Vuflix stream proxy temps. Safe to delete anytime.',
        ];

        $cacheDir = self::cacheDir();
        $cache = self::scanDir($cacheDir);
        $items[] = [
            'id' => 'tmdb_cache',
            'label' => 'Vuflix API cache',
            'path' => $cacheDir,
            'bytes' => $cache['bytes'],
            'count' => $cache['count'],
            'safe' => true,
            'note' => 'TMDB/API JSON cache. Safe, but pages re-fetch for a while (slower briefly). Leave it if you have space.',
        ];

        foreach ([
            'vuflix.access.log' => 'Vuflix nginx access (live)',
            'vuflix.access.log.1' => 'Vuflix nginx access (yesterday)',
            'chillflix.cf.log' => 'Chillflix nginx access (live)',
            'chillflix.cf.log.1' => 'Chillflix nginx access (yesterday)',
            'error.log' => 'nginx error (live)',
            'access.log' => 'nginx access default (live)',
        ] as $name => $label) {
            $path = '/var/log/nginx/' . $name;
            $stat = self::statFile($path);
            $items[] = [
                'id' => 'log:' . $name,
                'label' => $label,
                'path' => $path,
                'bytes' => $stat['bytes'],
                'count' => $stat['exists'] ? 1 : 0,
                'safe' => true,
                'note' => str_ends_with($name, '.1')
                    ? 'Rotated log. Safe to truncate/delete.'
                    : 'Live log. Truncate clears contents (file stays).',
            ];
        }

        // Rotated .gz nginx logs (old)
        $gz = self::scanGlob('/var/log/nginx/{vuflix.access,chillflix.cf,access,error}.log.*.gz');
        // brace may not work in PHP glob — do explicit
        $gz = self::scanNginxGz();
        $items[] = [
            'id' => 'nginx_gz',
            'label' => 'Old nginx logs (.gz)',
            'path' => '/var/log/nginx/*.log.*.gz',
            'bytes' => $gz['bytes'],
            'count' => $gz['count'],
            'safe' => true,
            'note' => 'Compressed old rotations. Safe to delete.',
        ];

        $pm2 = self::privilegedSize('pm2_logs');
        $items[] = [
            'id' => 'pm2_logs',
            'label' => 'PM2 process logs',
            'path' => '/root/.pm2/logs',
            'bytes' => $pm2['bytes'],
            'count' => $pm2['count'],
            'safe' => true,
            'note' => 'App stdout/stderr. Safe to truncate (does not stop apps).',
        ];

        $npm = self::privilegedSize('npm_cache');
        $items[] = [
            'id' => 'npm_cache',
            'label' => 'npm cache',
            'path' => '/root/.npm',
            'bytes' => $npm['bytes'],
            'count' => $npm['count'],
            'safe' => true,
            'note' => 'Package download cache. Safe; next install may re-download.',
        ];

        $rcache = self::privilegedSize('root_cache');
        $items[] = [
            'id' => 'root_cache',
            'label' => 'Root user cache',
            'path' => '/root/.cache',
            'bytes' => $rcache['bytes'],
            'count' => $rcache['count'],
            'safe' => true,
            'note' => 'Generic caches (playwright, etc.). Safe to clear.',
        ];

        return $items;
    }

    private static function cacheDir(): string
    {
        if (function_exists('config')) {
            return (string) config('cache_dir', dirname(__DIR__, 2) . '/storage/cache');
        }
        return dirname(__DIR__, 2) . '/storage/cache';
    }

    /** @return array{bytes:int,count:int} */
    private static function privilegedSize(string $id): array
    {
        $tool = '/usr/local/sbin/site-storage-tool';
        if (!is_file($tool)) {
            return ['bytes' => 0, 'count' => 0];
        }
        $cmd = 'sudo -n ' . escapeshellarg($tool) . ' size ' . escapeshellarg($id) . ' 2>/dev/null';
        $out = [];
        @exec($cmd, $out);
        $decoded = json_decode(trim(implode("\n", $out)), true);
        if (!is_array($decoded)) {
            return ['bytes' => 0, 'count' => 0];
        }
        return [
            'bytes' => (int) ($decoded['bytes'] ?? 0),
            'count' => (int) ($decoded['count'] ?? 0),
        ];
    }

    /** @return array{totalBytes:int,availableBytes:int,usedPercent:float,mount:string} */
    public static function disk(): array
    {
        $total = @disk_total_space('/') ?: 0;
        $free = @disk_free_space('/') ?: 0;
        $used = max(0, $total - $free);
        $pct = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;
        return [
            'totalBytes' => (int) $total,
            'availableBytes' => (int) $free,
            'usedBytes' => (int) $used,
            'usedPercent' => $pct,
            'mount' => '/',
        ];
    }

    /**
     * @return array{ok:bool,message:string,freedBytes?:int,error?:string}
     */
    public static function clean(string $id): array
    {
        return match ($id) {
            'cfhls' => self::cleanGlob('/tmp/cfhls_*', 'Stream temp files'),
            'tmdb_cache' => self::cleanDirFiles(self::cacheDir(), 'Vuflix API cache'),
            'nginx_gz' => self::cleanNginxGz(),
            'pm2_logs', 'npm_cache', 'root_cache' => self::cleanPrivileged($id),
            default => str_starts_with($id, 'log:')
                ? self::truncateFile('/var/log/nginx/' . substr($id, 4), substr($id, 4))
                : ['ok' => false, 'message' => 'Unknown target', 'error' => 'unknown'],
        };
    }

    /**
     * Root-owned caches/logs — PHP-FPM (www-data) calls a sudo helper.
     * @return array{ok:bool,message:string,freedBytes?:int,error?:string}
     */
    private static function cleanPrivileged(string $id): array
    {
        $allowed = ['pm2_logs', 'npm_cache', 'root_cache'];
        if (!in_array($id, $allowed, true)) {
            return ['ok' => false, 'message' => 'Unknown target', 'error' => 'unknown'];
        }
        $tool = '/usr/local/sbin/site-storage-tool';
        if (!is_file($tool)) {
            return ['ok' => false, 'message' => 'Cleanup helper missing on server', 'error' => 'helper'];
        }
        $cmd = 'sudo -n ' . escapeshellarg($tool) . ' clean ' . escapeshellarg($id) . ' 2>/dev/null';
        $out = [];
        $code = 1;
        @exec($cmd, $out, $code);
        $raw = trim(implode("\n", $out));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'message' => 'Privileged clean failed (sudo helper).',
                'error' => 'sudo',
                'freedBytes' => 0,
            ];
        }
        return [
            'ok' => !empty($decoded['ok']),
            'message' => (string) ($decoded['message'] ?? 'Done'),
            'freedBytes' => (int) ($decoded['freedBytes'] ?? 0),
            'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
        ];
    }

    /** @return array{ok:bool,content?:string,bytes?:int,path?:string,error?:string} */
    public static function readLog(string $name, int $maxBytes = 400_000): array
    {
        $name = basename($name);
        $allowed = [
            'vuflix.access.log',
            'vuflix.access.log.1',
            'chillflix.cf.log',
            'chillflix.cf.log.1',
            'error.log',
            'access.log',
        ];
        if (!in_array($name, $allowed, true)) {
            return ['ok' => false, 'error' => 'Log not allowed'];
        }
        $path = '/var/log/nginx/' . $name;
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'error' => 'Log missing or unreadable'];
        }
        $size = (int) filesize($path);
        if ($size <= $maxBytes) {
            $content = (string) file_get_contents($path);
        } else {
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return ['ok' => false, 'error' => 'Cannot open log'];
            }
            fseek($fh, -$maxBytes, SEEK_END);
            $content = (string) stream_get_contents($fh);
            fclose($fh);
            $content = "… [truncated, last " . self::fmt($maxBytes) . " of " . self::fmt($size) . "]\n" . $content;
        }
        return ['ok' => true, 'content' => $content, 'bytes' => $size, 'path' => $path];
    }

    public static function fmt(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /** @return array{bytes:int,count:int} */
    private static function scanGlob(string $pattern): array
    {
        $bytes = 0;
        $count = 0;
        foreach (glob($pattern) ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $count++;
            $bytes += (int) (@filesize($f) ?: 0);
        }
        return ['bytes' => $bytes, 'count' => $count];
    }

    /** @return array{bytes:int,count:int} */
    private static function scanNginxGz(): array
    {
        $bytes = 0;
        $count = 0;
        foreach (glob('/var/log/nginx/*.gz') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $base = basename($f);
            if (!preg_match('/^(vuflix\.access|chillflix\.cf|access|error)\.log\.\d+\.gz$/', $base)) {
                continue;
            }
            $count++;
            $bytes += (int) (@filesize($f) ?: 0);
        }
        return ['bytes' => $bytes, 'count' => $count];
    }

    /** @return array{bytes:int,count:int} */
    private static function scanDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['bytes' => 0, 'count' => 0];
        }
        $bytes = 0;
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $count++;
                $bytes += (int) $file->getSize();
            }
        }
        return ['bytes' => $bytes, 'count' => $count];
    }

    /** @return array{bytes:int,count:int,exists:bool} */
    private static function statFile(string $path): array
    {
        if (!is_file($path)) {
            return ['bytes' => 0, 'count' => 0, 'exists' => false];
        }
        return ['bytes' => (int) (@filesize($path) ?: 0), 'count' => 1, 'exists' => true];
    }

    /** @return array{bytes:int,count:int} */
    private static function statDir(string $dir): array
    {
        return self::scanDir($dir);
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function cleanGlob(string $pattern, string $label): array
    {
        $freed = 0;
        $n = 0;
        foreach (glob($pattern) ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $sz = (int) (@filesize($f) ?: 0);
            if (@unlink($f)) {
                $freed += $sz;
                $n++;
            }
        }
        return [
            'ok' => true,
            'message' => "Removed {$n} {$label} file(s).",
            'freedBytes' => $freed,
        ];
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function cleanDirFiles(string $dir, string $label): array
    {
        if (!is_dir($dir)) {
            return ['ok' => true, 'message' => "{$label} already empty.", 'freedBytes' => 0];
        }
        $freed = 0;
        $n = 0;
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $sz = (int) (@filesize($f) ?: 0);
            if (@unlink($f)) {
                $freed += $sz;
                $n++;
            }
        }
        return [
            'ok' => true,
            'message' => "Cleared {$n} {$label} file(s).",
            'freedBytes' => $freed,
        ];
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function cleanNginxGz(): array
    {
        $freed = 0;
        $n = 0;
        foreach (glob('/var/log/nginx/*.gz') ?: [] as $f) {
            $base = basename($f);
            if (!preg_match('/^(vuflix\.access|chillflix\.cf|access|error)\.log\.\d+\.gz$/', $base)) {
                continue;
            }
            $sz = (int) (@filesize($f) ?: 0);
            if (@unlink($f)) {
                $freed += $sz;
                $n++;
            }
        }
        return [
            'ok' => true,
            'message' => "Deleted {$n} old nginx .gz log(s).",
            'freedBytes' => $freed,
        ];
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function truncateDirFiles(string $dir, string $label): array
    {
        if (!is_dir($dir)) {
            return ['ok' => true, 'message' => "{$label} missing.", 'freedBytes' => 0];
        }
        $freed = 0;
        $n = 0;
        foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) {
            if (!is_file($f) || !is_writable($f)) {
                continue;
            }
            $sz = (int) (@filesize($f) ?: 0);
            if (@file_put_contents($f, '') !== false) {
                $freed += $sz;
                $n++;
            }
        }
        return [
            'ok' => true,
            'message' => "Truncated {$n} {$label} file(s).",
            'freedBytes' => $freed,
        ];
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function cleanDirContents(string $dir, string $label): array
    {
        if (!is_dir($dir)) {
            return ['ok' => true, 'message' => "{$label} already empty.", 'freedBytes' => 0];
        }
        $before = self::scanDir($dir)['bytes'];
        // Prefer npm cache clean when applicable
        if ($label === 'npm cache' && is_file('/usr/bin/npm')) {
            @exec('npm cache clean --force 2>/dev/null', $out, $code);
        }
        self::rmTreeContents($dir);
        $after = self::scanDir($dir)['bytes'];
        return [
            'ok' => true,
            'message' => "Cleared {$label}.",
            'freedBytes' => max(0, $before - $after),
        ];
    }

    private static function rmTreeContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /** @return array{ok:bool,message:string,freedBytes:int} */
    private static function truncateFile(string $path, string $label): array
    {
        if (!is_file($path)) {
            return ['ok' => true, 'message' => "{$label} already missing.", 'freedBytes' => 0];
        }
        if (!is_writable($path)) {
            return ['ok' => false, 'message' => "{$label} not writable", 'freedBytes' => 0, 'error' => 'permission'];
        }
        $sz = (int) (@filesize($path) ?: 0);
        if (@file_put_contents($path, '') === false) {
            return ['ok' => false, 'message' => "Failed to truncate {$label}", 'freedBytes' => 0, 'error' => 'write'];
        }
        return [
            'ok' => true,
            'message' => "Truncated {$label}.",
            'freedBytes' => $sz,
        ];
    }
}
