<?php
/**
 * Discovery cron job.
 *
 * Cron ticks every minute; honors discovery_interval_minutes unless --force.
 * Uses FAST checks for newly discovered domains (WHOIS/DNS/SSL/threat feeds only)
 * so we can process thousands/hour. Full reputation runs when a user opens a page.
 */

require_once __DIR__ . '/../includes/DomainRepository.php';

$isCli = (php_sapi_name() === 'cli');
$force = false;
if ($isCli) {
    $force = in_array('--force', $argv ?? [], true);
} else {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
    $force = (($_GET['force'] ?? '') === '1');
}

function cron_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$db = Database::getConnection();
$repo = new DomainRepository();
$batchSize = max(1, min(500, (int) get_setting('discovery_batch_size', '100')));
$intervalMinutes = max(1, (int) get_setting('discovery_interval_minutes', '1'));
$rateLimit = max(10, (int) get_setting('discovery_rate_limit_per_hour', '2000'));

$lockFile = __DIR__ . '/../storage/discovery.lock';
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    cron_log('Another discovery run is already in progress — skipping.');
    exit(0);
}
fwrite($lockFp, (string) getmypid());
fflush($lockFp);
register_shutdown_function(static function () use ($lockFp, $lockFile): void {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
});

if (!$force) {
    $lastRunAt = get_setting('discovery_last_run_at', '');
    if ($lastRunAt !== '') {
        $elapsed = time() - strtotime($lastRunAt);
        $needed = $intervalMinutes * 60;
        if ($elapsed >= 0 && $elapsed < $needed) {
            cron_log("Skipping discovery — next run in " . max(1, (int) ceil(($needed - $elapsed) / 60)) . " min (interval={$intervalMinutes}m).");
            exit(0);
        }
    }
}

$checkedThisHour = (int) $db->query(
    "SELECT COUNT(*) FROM domains
     WHERE discovered_via IN ('threat_feeds','ct_logs','user_reports','popular_seed')
       AND first_seen >= NOW() - INTERVAL 1 HOUR"
)->fetchColumn();

$remainingBudget = max(0, $rateLimit - $checkedThisHour);
if ($remainingBudget <= 0 && !$force) {
    cron_log("Rate limit reached ({$rateLimit}/hour). Skipping.");
    exit(0);
}

$runBudget = $force ? $batchSize : min($batchSize, $remainingBudget);
set_setting('discovery_last_run_at', date('Y-m-d H:i:s'));
if ($force) {
    cron_log('Force discovery run started.');
}
cron_log("Budget this run={$runBudget} (hourly remaining={$remainingBudget}, limit={$rateLimit}).");

$sources = $db->query('SELECT * FROM discovery_sources WHERE enabled = 1')->fetchAll();
$totalQueued = 0;

foreach ($sources as $source) {
    if ($totalQueued >= $runBudget) {
        break;
    }

    $runStmt = $db->prepare('INSERT INTO discovery_runs (source_name, status) VALUES (?, ?)');
    $runStmt->execute([$source['name'], 'running']);
    $runId = (int) $db->lastInsertId();

    $found = 0;
    $queued = 0;
    $logOutput = '';

    try {
        $slot = $runBudget - $totalQueued;
        $candidateDomains = [];

        if ($source['name'] === 'ct_logs') {
            $candidateDomains = discover_from_ct_logs();
        } elseif ($source['name'] === 'threat_feeds') {
            $candidateDomains = discover_new_from_threat_feeds($db, max(200, $slot * 5));
        } elseif ($source['name'] === 'user_reports') {
            $candidateDomains = discover_from_pending_reports($db);
        }

        $found = count($candidateDomains);

        foreach ($candidateDomains as $domain) {
            if ($queued >= $slot) {
                break;
            }
            $domain = normalize_domain($domain);
            if (!$domain) {
                continue;
            }

            // Prefer brand-new domains only (already-checked ones are skipped).
            $existing = $repo->find($domain);
            if ($existing) {
                continue;
            }

            try {
                $repo->getOrCheck($domain, $source['name'], false, true); // fast discovery
                $queued++;
                $totalQueued++;
                cron_log("Checked: $domain");
            } catch (Throwable $e) {
                // Race with a parallel run / duplicate host — not fatal.
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    cron_log("Skip duplicate: $domain");
                } else {
                    cron_log("Error checking $domain: " . $e->getMessage());
                }
            }
        }

        $status = 'completed';
    } catch (Throwable $e) {
        $status = 'failed';
        $logOutput = $e->getMessage();
        cron_log('Source ' . $source['name'] . ' failed: ' . $e->getMessage());
    }

    $db->prepare('UPDATE discovery_runs SET finished_at = NOW(), domains_found = ?, domains_queued = ?, status = ?, log_output = ? WHERE id = ?')
       ->execute([$found, $queued, $status, $logOutput, $runId]);

    $db->prepare('UPDATE discovery_sources SET last_run_at = NOW(), last_run_status = ?, last_run_found = ? WHERE name = ?')
       ->execute([$status, $found, $source['name']]);

    cron_log("Source '{$source['name']}' done: found=$found queued=$queued status=$status");
}

cron_log("Discovery run complete. queued_total={$totalQueued}");


function discover_from_ct_logs(): array
{
    $keywords = ['secure-verify', 'account-update', 'wallet-support', 'login-confirm', 'billing-alert'];
    $domains = [];

    foreach ($keywords as $kw) {
        $url = 'https://crt.sh/?q=' . urlencode('%' . $kw . '%') . '&output=json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'ScamGuardBot/1.0 (+https://www.chillflix.lol/scamguard)',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $rows = json_decode($response ?: '', true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $name = $row['common_name'] ?? $row['name_value'] ?? null;
                if ($name) {
                    foreach (explode("\n", $name) as $n) {
                        $n = ltrim($n, '*.');
                        if ($n) {
                            $domains[] = $n;
                        }
                    }
                }
            }
        }
    }

    return array_values(array_unique($domains));
}

/**
 * Walk threat-feed files with a persistent offset, collecting domains not yet in DB.
 */
function discover_new_from_threat_feeds(PDO $db, int $want): array
{
    $feedDir = __DIR__ . '/../storage/feeds';
    $files = [
        $feedDir . '/phishing-domains.txt',
        $feedDir . '/urlhaus-online.txt',
        $feedDir . '/openphish.txt',
    ];

    $offset = max(0, (int) get_setting('threat_feed_scan_offset', '0'));
    $domains = [];
    $scanned = 0;
    $maxScan = max(5000, $want * 40); // lines to read while hunting for unknowns

    // Preload known domains for fast skip (only exact host match).
    // For very large DBs this stays fine at current scale.
    $known = [];
    foreach ($db->query('SELECT domain FROM domains')->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $known[strtolower((string) $d)] = true;
    }

    $fileSizes = [];
    $totalLines = 0;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $lines = max(0, (int) exec('wc -l < ' . escapeshellarg($file)));
        $fileSizes[$file] = $lines;
        $totalLines += $lines;
    }
    if ($totalLines <= 0) {
        return [];
    }
    $offset = $offset % $totalLines;

    $globalIndex = 0;
    foreach ($files as $file) {
        if (!isset($fileSizes[$file])) {
            continue;
        }
        $fh = fopen($file, 'r');
        if (!$fh) {
            $globalIndex += $fileSizes[$file];
            continue;
        }

        $local = 0;
        while (($line = fgets($fh)) !== false) {
            $absolute = $globalIndex + $local;
            $local++;

            if ($absolute < $offset) {
                continue;
            }

            $scanned++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $host = $line;
            if (str_contains($line, '://')) {
                $host = parse_url($line, PHP_URL_HOST) ?: '';
            } else {
                $host = explode('/', $line)[0];
            }
            $host = strtolower(ltrim((string) $host, '*.'));
            $host = normalize_domain($host) ?? '';
            if ($host === '' || isset($known[$host])) {
                if ($scanned >= $maxScan) {
                    break 2;
                }
                continue;
            }

            $domains[] = $host;
            $known[$host] = true;
            if (count($domains) >= $want) {
                $offset = $absolute + 1;
                break 2;
            }
            if ($scanned >= $maxScan) {
                $offset = $absolute + 1;
                break 2;
            }
        }
        fclose($fh);
        $globalIndex += $fileSizes[$file];
    }

    // Wrap when we near EOF without filling the batch.
    if (count($domains) < max(10, (int) floor($want / 5))) {
        $offset = 0;
    }
    set_setting('threat_feed_scan_offset', (string) $offset);

    return array_values(array_unique($domains));
}

function discover_from_pending_reports(PDO $db): array
{
    $stmt = $db->query("SELECT DISTINCT domain_text FROM reports WHERE status = 'pending'");
    return array_column($stmt->fetchAll(), 'domain_text');
}
