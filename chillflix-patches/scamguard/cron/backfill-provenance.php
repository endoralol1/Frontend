<?php
/**
 * One-shot / periodic backfill: reclassify existing caution/risky/unknown domains
 * from local feed membership so homepage Likely safe / Flagged scams counters
 * match what discovery would assign with turbo provenance.
 *
 * Usage (CLI):
 *   php cron/backfill-provenance.php
 *   php cron/backfill-provenance.php --dry-run
 *   php cron/backfill-provenance.php --limit=5000
 *
 * Or via cron secret:
 *   /cron/backfill-provenance.php?key=CRON_SECRET
 */

require_once __DIR__ . '/../includes/functions.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
}

$dryRun = false;
$limit = 0;
if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif (str_starts_with($arg, '--limit=')) {
            $limit = max(0, (int) substr($arg, 8));
        }
    }
} else {
    $dryRun = isset($_GET['dry_run']);
    $limit = max(0, (int) ($_GET['limit'] ?? 0));
}

function bf_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
    @ob_flush();
    @flush();
}

/** Same file → provenance class map as discover.php */
function bf_classify_feed_file(string $file): ?string
{
    $b = strtolower(basename($file));
    if (str_contains($b, 'urlhaus')) {
        return 'malware';
    }
    if (str_contains($b, 'phishing') || str_contains($b, 'openphish')) {
        return 'phishing';
    }
    if (str_contains($b, 'tranco')) {
        return 'popular_strong';
    }
    if (str_contains($b, 'umbrella') || str_contains($b, 'majestic') || str_contains($b, 'domcop')) {
        return 'popular';
    }
    return null;
}

function bf_class_priority(?string $class): int
{
    return match ($class) {
        'malware' => 40,
        'phishing' => 30,
        'popular_strong' => 20,
        'popular' => 10,
        default => 0,
    };
}

function bf_extract_host(string $line): string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        return '';
    }
    // CSV-ish ranked lists: rank,domain
    if (str_contains($line, ',') && !str_contains($line, '://')) {
        $parts = explode(',', $line);
        $line = trim(end($parts));
    }
    $host = str_contains($line, '://') ? (parse_url($line, PHP_URL_HOST) ?: '') : explode('/', $line)[0];
    $host = strtolower(ltrim((string) $host, '*.'));
    return normalize_domain($host) ?? '';
}

function bf_patch_signals(?string $signalsJson, string $class, bool $hasIp): string
{
    $signals = json_decode((string) $signalsJson, true);
    if (!is_array($signals)) {
        $signals = [];
    }
    // Drop deferred / old threat-feed placeholders so the report isn't contradictory.
    $signals = array_values(array_filter($signals, static function ($s) {
        $label = strtolower((string) ($s['label'] ?? ''));
        $value = strtolower((string) ($s['value'] ?? ''));
        if ($label === 'threat feeds' || $label === 'traffic ranking') {
            return false;
        }
        if (str_contains($value, 'deferred')) {
            return false;
        }
        return true;
    }));

    if ($class === 'malware') {
        $signals[] = [
            'group' => 'threat',
            'label' => 'Threat feeds',
            'value' => 'Listed (malware)',
            'note' => 'Backfilled from local malware/botnet URL feed membership.',
            'tone' => 'bad',
        ];
    } elseif ($class === 'phishing') {
        $signals[] = [
            'group' => 'threat',
            'label' => 'Threat feeds',
            'value' => 'Listed (phishing)',
            'note' => 'Backfilled from local phishing feed membership.',
            'tone' => 'bad',
        ];
    } elseif ($class === 'popular_strong') {
        $signals[] = [
            'group' => 'reputation',
            'label' => 'Traffic ranking',
            'value' => 'Top-ranked site',
            'note' => 'Backfilled: listed on the Tranco top sites ranking.',
            'tone' => $hasIp ? 'good' : 'neutral',
        ];
    } elseif ($class === 'popular') {
        $signals[] = [
            'group' => 'reputation',
            'label' => 'Traffic ranking',
            'value' => 'On a top-domains list',
            'note' => 'Backfilled: appears on a major top-domains ranking.',
            'tone' => $hasIp ? 'good' : 'neutral',
        ];
    }

    return json_encode($signals, JSON_UNESCAPED_SLASHES);
}

$db = Database::getConnection();
$feedDir = __DIR__ . '/../storage/feeds';
$files = glob($feedDir . '/*.txt') ?: [];
sort($files);

if (!$files) {
    bf_log('No feed files in ' . $feedDir);
    exit(1);
}

bf_log('Loading candidate domains (caution/risky/unknown, no manual override)...');
$sql = "SELECT id, domain, status, trust_score, ip_address, signals_json, verdict
        FROM domains
        WHERE manual_override = 0
          AND status IN ('caution','risky','unknown')";
if ($limit > 0) {
    $sql .= ' LIMIT ' . (int) $limit;
}
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/** @var array<string, array> $candidates domain => row */
$candidates = [];
foreach ($rows as $row) {
    $d = strtolower((string) $row['domain']);
    $candidates[$d] = $row;
}
bf_log('Candidates loaded: ' . count($candidates));

/** @var array<string, string> $matched domain => best class */
$matched = [];

foreach ($files as $file) {
    $class = bf_classify_feed_file($file);
    if ($class === null) {
        bf_log('Skip unclassified feed: ' . basename($file));
        continue;
    }
    $prio = bf_class_priority($class);
    $fh = fopen($file, 'r');
    if (!$fh) {
        bf_log('Cannot open ' . basename($file));
        continue;
    }
    $hits = 0;
    $lines = 0;
    while (($line = fgets($fh)) !== false) {
        $lines++;
        $host = bf_extract_host($line);
        if ($host === '' || !isset($candidates[$host])) {
            continue;
        }
        $prev = $matched[$host] ?? null;
        if ($prio > bf_class_priority($prev)) {
            $matched[$host] = $class;
            $hits++;
        }
    }
    fclose($fh);
    bf_log(sprintf(
        'Scanned %s (%s): lines=%d candidate_hits=%d',
        basename($file),
        $class,
        $lines,
        $hits
    ));
}

bf_log('Matched candidates in feeds: ' . count($matched));

$counts = ['malware' => 0, 'phishing' => 0, 'popular_strong' => 0, 'popular' => 0, 'skipped_no_ip' => 0, 'updated' => 0];
foreach ($matched as $class) {
    if (isset($counts[$class])) {
        $counts[$class]++;
    }
}
bf_log('Class breakdown: ' . json_encode($counts));

if ($dryRun) {
    bf_log('Dry-run only — no DB writes.');
    bf_log('Would update up to ' . count($matched) . ' rows (popular without IP may stay caution).');
    exit(0);
}

$updThreat = $db->prepare(
    'UPDATE domains SET
        trust_score = ?, status = ?, verdict = ?, verdict_reasons = ?,
        malware_hit = ?, phishing_hit = ?, threat_feed_hit = ?, threat_feed_sources = ?,
        signals_json = ?, updated_at = NOW()
     WHERE id = ? AND manual_override = 0'
);
$updSafe = $db->prepare(
    'UPDATE domains SET
        trust_score = ?, status = ?, verdict = ?, verdict_reasons = ?,
        malware_hit = 0, phishing_hit = 0, threat_feed_hit = 0, threat_feed_sources = NULL,
        ip_address = COALESCE(NULLIF(ip_address, ''), ?),
        signals_json = ?, updated_at = NOW()
     WHERE id = ? AND manual_override = 0'
);
$hist = $db->prepare('INSERT INTO domain_history (domain_id, trust_score, status) VALUES (?, ?, ?)');

$db->beginTransaction();
try {
    foreach ($matched as $domain => $class) {
        $row = $candidates[$domain];
        $id = (int) $row['id'];
        $hasIp = !empty($row['ip_address']);
        $ip = $row['ip_address'] ?: null;

        if ($class === 'malware' || $class === 'phishing') {
            $malware = $class === 'malware' ? 1 : 0;
            $phishing = $class === 'phishing' ? 1 : 0;
            $sources = $class === 'malware'
                ? 'URLhaus (malware/botnet URLs)'
                : 'Phishing feed (OpenPhish / Phishing.Database)';
            $score = $class === 'malware' ? 8 : 12;
            $verdict = $class === 'malware' ? 'malware' : 'phishing';
            $reasons = json_encode([
                $class === 'malware'
                    ? 'Domain/host appears on malware or botnet URL intelligence.'
                    : 'Domain appears on phishing intelligence feeds.',
                'Feeds: ' . $sources,
                'Reclassified from feed membership backfill.',
            ]);
            $signals = bf_patch_signals($row['signals_json'] ?? null, $class, true);
            $updThreat->execute([
                $score, 'scam', $verdict, $reasons,
                $malware, $phishing, 1, $sources,
                $signals, $id,
            ]);
            if ($updThreat->rowCount() > 0) {
                $hist->execute([$id, $score, 'scam']);
                $counts['updated']++;
            }
            continue;
        }

        // popular / popular_strong → likely safe when DNS resolves
        if (!$hasIp) {
            $records = @dns_get_record($domain, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $rec) {
                if (!empty($rec['ip'])) {
                    $ip = $rec['ip'];
                    $hasIp = true;
                    break;
                }
                if (!empty($rec['ipv6'])) {
                    $ip = $rec['ipv6'];
                    $hasIp = true;
                    break;
                }
            }
        }

        if (!$hasIp) {
            $counts['skipped_no_ip']++;
            continue;
        }

        $score = max(82, (int) $row['trust_score']);
        $verdict = 'likely_safe';
        $reasons = json_encode([
            'No malware/phishing/feed hits and strong positive signals.',
            $class === 'popular_strong'
                ? 'Listed on the Tranco top sites ranking (high global traffic).'
                : 'Appears on a major top-domains ranking (presumed legitimate; re-verified on full scan).',
            'Reclassified from feed membership backfill.',
        ]);
        $signals = bf_patch_signals($row['signals_json'] ?? null, $class, true);
        $updSafe->execute([
            $score, 'safe', $verdict, $reasons,
            $ip,
            $signals, $id,
        ]);
        if ($updSafe->rowCount() > 0) {
            $hist->execute([$id, $score, 'safe']);
            $counts['updated']++;
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    bf_log('ERROR: ' . $e->getMessage());
    exit(1);
}

$stats = $db->query(
    "SELECT status, COUNT(*) c FROM domains GROUP BY status ORDER BY c DESC"
)->fetchAll(PDO::FETCH_ASSOC);

bf_log('Backfill complete. updated=' . $counts['updated'] . ' skipped_no_ip=' . $counts['skipped_no_ip']);
bf_log('Status counts now: ' . json_encode($stats));
