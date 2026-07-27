<?php
/**
 * Local threat-feed cache + fast domain/host lookups.
 * Feeds are refreshed by cron/update-feeds.php (not on every page view).
 */
class ThreatFeeds
{
    private const FEED_DIR = __DIR__ . '/../storage/feeds';

    /** @return array{malware:bool,phishing:bool,sources:string[]} */
    public static function checkDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $sources = [];
        $malware = false;
        $phishing = false;

        if (self::hostInFile(self::FEED_DIR . '/urlhaus-online.txt', $domain)) {
            $malware = true;
            $sources[] = 'URLhaus (malware/botnet URLs)';
        }

        if (self::hostInFile(self::FEED_DIR . '/openphish.txt', $domain)) {
            $phishing = true;
            $sources[] = 'OpenPhish';
        }

        if (self::lineEquals(self::FEED_DIR . '/phishing-domains.txt', $domain)) {
            $phishing = true;
            $sources[] = 'Phishing.Database';
        }

        if (self::lineEquals(self::FEED_DIR . '/phishing-links.txt', $domain)) {
            $phishing = true;
            $sources[] = 'Phishing links feed';
        }

        return [
            'malware' => $malware,
            'phishing' => $phishing,
            'sources' => array_values(array_unique($sources)),
        ];
    }

    public static function status(): array
    {
        $files = [
            'urlhaus-online.txt' => 'URLhaus malware URLs',
            'openphish.txt' => 'OpenPhish',
            'phishing-domains.txt' => 'Phishing domains',
        ];
        $out = [];
        foreach ($files as $file => $label) {
            $path = self::FEED_DIR . '/' . $file;
            $out[] = [
                'label' => $label,
                'file' => $file,
                'exists' => is_file($path),
                'bytes' => is_file($path) ? filesize($path) : 0,
                'age_hours' => is_file($path) ? round((time() - filemtime($path)) / 3600, 1) : null,
            ];
        }
        return $out;
    }

    private static function hostInFile(string $path, string $domain): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $fh = fopen($path, 'r');
        if (!$fh) {
            return false;
        }
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $host = self::extractHost($line);
            // Exact host only. Do NOT treat abused subdomains (drive.google.com, etc.)
            // as a hit against the parent brand apex.
            if ($host !== '' && $host === $domain) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
        return false;
    }

    private static function lineEquals(string $path, string $domain): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $fh = fopen($path, 'r');
        if (!$fh) {
            return false;
        }
        while (($line = fgets($fh)) !== false) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $host = self::extractHost($line);
            if ($host !== '' && $host === $domain) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
        return false;
    }

    private static function extractHost(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            return strtolower((string) $host);
        }
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        return $value;
    }
}
