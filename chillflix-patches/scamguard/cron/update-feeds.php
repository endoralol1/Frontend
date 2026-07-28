<?php
/**
 * Refresh local malware/phishing feeds used by ScamGuard checks.
 *
 * Cron example (every 30 minutes):
 *   php /var/www/chillflix.lol/scamguard/cron/update-feeds.php
 *
 * HTTP:
 *   /scamguard/cron/update-feeds.php?key=CRON_SECRET
 */

require_once __DIR__ . '/../config/config.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
}

function feed_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$dir = __DIR__ . '/../storage/feeds';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

function download_feed(string $url, string $dest, int $timeout = 45): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'ScamGuardFeedBot/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($err || $code >= 400 || !is_string($body) || strlen($body) < 20) {
        return false;
    }

    $tmp = $dest . '.tmp';
    if (file_put_contents($tmp, $body) === false) {
        return false;
    }
    rename($tmp, $dest);
    return true;
}

function download_tranco_domains(string $dest): bool
{
    $zipPath = $dest . '.zip';
    if (!download_feed('https://tranco-list.eu/top-1m.csv.zip', $zipPath, 120)) {
        return false;
    }

    $domains = [];
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name && str_ends_with($name, '.csv')) {
                    $csv = $zip->getFromIndex($i);
                    foreach (preg_split("/\r\n|\n|\r/", (string) $csv) as $line) {
                        $parts = explode(',', trim($line), 2);
                        if (count($parts) === 2 && $parts[1] !== '') {
                            $domains[] = strtolower(trim($parts[1]));
                        }
                    }
                    break;
                }
            }
            $zip->close();
        }
    } else {
        $csv = shell_exec('unzip -p ' . escapeshellarg($zipPath) . ' 2>/dev/null');
        foreach (preg_split("/\r\n|\n|\r/", (string) $csv) as $line) {
            $parts = explode(',', trim($line), 2);
            if (count($parts) === 2 && $parts[1] !== '') {
                $domains[] = strtolower(trim($parts[1]));
            }
        }
    }
    @unlink($zipPath);

    $domains = array_values(array_unique(array_filter($domains)));
    if (count($domains) < 1000) {
        return false;
    }

    $tmp = $dest . '.tmp';
    if (file_put_contents($tmp, implode("\n", $domains) . "\n") === false) {
        return false;
    }
    rename($tmp, $dest);
    return true;
}

$feeds = [
    [
        'name' => 'URLhaus online malware URLs',
        'url' => 'https://urlhaus.abuse.ch/downloads/text_online/',
        'file' => 'urlhaus-online.txt',
    ],
    [
        'name' => 'URLhaus all malware URLs',
        'url' => 'https://urlhaus.abuse.ch/downloads/text/',
        'file' => 'urlhaus-all.txt',
        'timeout' => 90,
    ],
    [
        'name' => 'OpenPhish',
        'url' => 'https://raw.githubusercontent.com/openphish/public/master/feed.txt',
        'file' => 'openphish.txt',
        'fallback' => 'https://openphish.com/feed.txt',
    ],
    [
        'name' => 'Phishing.Database domains',
        'url' => 'https://raw.githubusercontent.com/mitchellkrogza/Phishing.Database/master/phishing-domains-ACTIVE.txt',
        'file' => 'phishing-domains.txt',
    ],
    [
        'name' => 'Phishing.Database links',
        'url' => 'https://raw.githubusercontent.com/mitchellkrogza/Phishing.Database/master/phishing-links-ACTIVE.txt',
        'file' => 'phishing-links.txt',
        'timeout' => 90,
    ],
];

foreach ($feeds as $feed) {
    $dest = $dir . '/' . $feed['file'];
    $ok = download_feed($feed['url'], $dest, (int) ($feed['timeout'] ?? 45));
    if (!$ok && !empty($feed['fallback'])) {
        $ok = download_feed($feed['fallback'], $dest);
    }
    if ($ok) {
        feed_log($feed['name'] . ' OK (' . filesize($dest) . ' bytes)');
    } else {
        feed_log($feed['name'] . ' FAILED');
    }
}

$trancoDest = $dir . '/tranco-top-1m.txt';
if (download_tranco_domains($trancoDest)) {
    feed_log('Tranco top 1M OK (' . filesize($trancoDest) . ' bytes)');
} else {
    feed_log('Tranco top 1M FAILED');
}

feed_log('Done.');
