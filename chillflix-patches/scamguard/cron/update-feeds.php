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

/**
 * Download a ranked/top-sites list (plain CSV or .zip) and write a clean
 * host-per-line .txt. Streams line-by-line so 10M-row lists don't blow memory.
 * Auto-detects the domain token on each line (handles Tranco/Umbrella/Majestic/DomCop).
 */
function download_ranked_list(string $url, string $dest, int $maxLines = 2000000, int $timeout = 180): bool
{
    $isZip = str_ends_with(strtolower($url), '.zip');
    $srcPath = $dest . ($isZip ? '.zip' : '.src');
    if (!download_feed($url, $srcPath, $timeout)) {
        @unlink($srcPath);
        return false;
    }

    // Open a streaming reader over the raw CSV (unzip -p pipes the first member).
    if ($isZip) {
        $reader = popen('unzip -p ' . escapeshellarg($srcPath) . ' 2>/dev/null', 'r');
    } else {
        $reader = fopen($srcPath, 'r');
    }
    if (!$reader) {
        @unlink($srcPath);
        return false;
    }

    $tmp = $dest . '.tmp';
    $out = fopen($tmp, 'w');
    if (!$out) {
        if ($isZip) { pclose($reader); } else { fclose($reader); }
        @unlink($srcPath);
        return false;
    }

    $written = 0;
    $domainRe = '/([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+)/i';
    while (($line = fgets($reader)) !== false) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        // Strip quotes/commas, then grab the first domain-looking token.
        $line = str_replace(['"', "'"], '', $line);
        if (!preg_match($domainRe, $line, $m)) {
            continue;
        }
        $host = strtolower(ltrim($m[1], '.'));
        if (!str_contains($host, '.') || strlen($host) > 253) {
            continue;
        }
        fwrite($out, $host . "\n");
        if (++$written >= $maxLines) {
            break;
        }
    }

    if ($isZip) { pclose($reader); } else { fclose($reader); }
    fclose($out);
    @unlink($srcPath);

    if ($written < 1000) {
        @unlink($tmp);
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

// Broad discovery sources — popular + long-tail domains of every kind
// (safe, mixed, risky). These give the puller a huge, diverse pool to work
// through; discovery always skips domains already stored.
$rankedLists = [
    ['name' => 'Tranco top 1M', 'url' => 'https://tranco-list.eu/top-1m.csv.zip', 'file' => 'tranco-top-1m.txt', 'max' => 1000000],
    ['name' => 'Cisco Umbrella top 1M', 'url' => 'http://s3-us-west-1.amazonaws.com/umbrella-static/top-1m.csv.zip', 'file' => 'umbrella-top-1m.txt', 'max' => 1000000],
    ['name' => 'Majestic Million', 'url' => 'https://downloads.majestic.com/majestic_million.csv', 'file' => 'majestic-million.txt', 'max' => 1000000],
    ['name' => 'DomCop top 10M', 'url' => 'https://www.domcop.com/files/top/top10milliondomains.csv.zip', 'file' => 'domcop-top.txt', 'max' => 3000000],
];
foreach ($rankedLists as $list) {
    $dest = $dir . '/' . $list['file'];
    if (download_ranked_list($list['url'], $dest, (int) $list['max'])) {
        feed_log($list['name'] . ' OK (' . filesize($dest) . ' bytes)');
    } else {
        feed_log($list['name'] . ' FAILED');
    }
}

// Freshly-flagged phishing (fast-moving) — great for catching new scams.
$freshPhish = [
    ['name' => 'Phishing.Database NEW today', 'url' => 'https://raw.githubusercontent.com/mitchellkrogza/Phishing.Database/master/phishing-domains-NEW-today.txt', 'file' => 'phishing-new-today.txt'],
];
foreach ($freshPhish as $feed) {
    $dest = $dir . '/' . $feed['file'];
    if (download_feed($feed['url'], $dest, 60)) {
        feed_log($feed['name'] . ' OK (' . filesize($dest) . ' bytes)');
    } else {
        feed_log($feed['name'] . ' FAILED');
    }
}

feed_log('Done.');
