<?php
/**
 * Discovery cron job.
 *
 * Cron ticks every minute; honors discovery_interval_minutes unless --force.
 * Uses TURBO checks for newly discovered domains by default (DNS + local threat
 * feeds + heuristics only) so we can process very large batches/hour. Full
 * reputation runs when a user opens a page.
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
$startedAt = microtime(true);
$batchSize = max(1, min(10000, (int) get_setting('discovery_batch_size', '1000')));
$intervalMinutes = max(1, (int) get_setting('discovery_interval_minutes', '1'));
$rateLimit = max(10, (int) get_setting('discovery_rate_limit_per_hour', '50000'));

if (discovery_is_stop_requested()) {
    cron_log('Stop flag present — skipping.');
    exit(0);
}

// Auto-off blocks cron AND leftover --force workers. Admin "Pull now"/"Start"
// always sets discovery_auto_enabled=1 before spawning.
if (get_setting_fresh('discovery_auto_enabled', '1') !== '1') {
    cron_log('Automatic discovery is disabled in Admin — skipping.');
    exit(0);
}

// Fresh start clears any previous stop request (Pull now / cron when enabled).
discovery_clear_stop_flag();

$lockFile = discovery_lock_path();
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

/** Stop mid-batch when admin presses Stop or turns automatic puller off. */
function discovery_should_abort(bool $force): bool
{
    unset($force); // force does not ignore admin disable/stop
    if (discovery_is_stop_requested()) {
        return true;
    }
    return get_setting_fresh('discovery_auto_enabled', '1') !== '1';
}

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

$sources = $db->query(
    "SELECT * FROM discovery_sources
     WHERE enabled = 1
     ORDER BY FIELD(name, 'threat_feeds', 'user_reports', 'ct_logs'), name"
)->fetchAll();
$totalQueued = 0;

$aborted = false;
foreach ($sources as $source) {
    if ($totalQueued >= $runBudget) {
        break;
    }
    if (discovery_should_abort($force)) {
        $aborted = true;
        cron_log('Stop/disable requested — aborting discovery run.');
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

        // Candidate lists are maps of host => provenance class (null if unknown).
        foreach ($candidateDomains as $domain => $sourceClass) {
            if ($queued >= $slot) {
                break;
            }
            // Check every domain so admin Stop takes effect immediately.
            if (discovery_should_abort($force)) {
                $aborted = true;
                cron_log('Stop/disable requested — leaving batch early.');
                break;
            }
            $domain = normalize_domain((string) $domain);
            if (!$domain) {
                continue;
            }

            // Prefer brand-new domains only (already-checked ones are skipped).
            $existing = $repo->find($domain);
            if ($existing) {
                continue;
            }

            try {
                $repo->getOrCheck($domain, $source['name'], false, true, $sourceClass); // fast discovery
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

        $status = $aborted ? 'stopped' : 'completed';
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
    if ($aborted) {
        break;
    }
}

$elapsed = max(0.001, microtime(true) - $startedAt);
$perMinute = $totalQueued > 0 ? round(($totalQueued / $elapsed) * 60, 1) : 0;
$perHour = $totalQueued > 0 ? (int) round(($totalQueued / $elapsed) * 3600) : 0;
cron_log("Discovery run complete. queued_total={$totalQueued} elapsed=" . round($elapsed, 2) . "s throughput={$perMinute}/min (~{$perHour}/hour)");


function discover_from_ct_logs(): array
{
    $keywords = [
        // credential / support bait
        'secure-verify', 'account-update', 'wallet-support', 'login-confirm', 'billing-alert',
        'verify-login', 'account-verify', 'support-login', 'password-reset', 'security-check',
        // money / crypto bait
        'wallet-connect', 'airdrop-claim', 'bonus-claim', 'reward-claim', 'crypto-support',
        // commerce / shipping bait
        'delivery-track', 'parcel-update', 'invoice-pay', 'payment-confirm',
        // gray/high-risk content niches we still want to catalog quickly
        'streaming', 'movie', 'iptv', 'casino', 'betting',
    ];
    $domains = [];

    foreach ($keywords as $kw) {
        $url = 'https://crt.sh/?q=' . urlencode('%' . $kw . '%') . '&output=json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
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

    // CT-log hosts have unknown safety — no provenance class.
    return array_fill_keys(array_values(array_unique($domains)), null);
}

/**
 * Walk threat-feed files with a persistent offset, collecting domains not yet in DB.
 * Returns a map of host => provenance class.
 */
function discover_new_from_threat_feeds(PDO $db, int $want): array
{
    $feedDir = __DIR__ . '/../storage/feeds';
    $files = glob($feedDir . '/*.txt') ?: [];
    sort($files);
    if (!$files) {
        return [];
    }

    // Preload known domains for fast skip (only exact host match).
    $known = [];
    foreach ($db->query('SELECT domain FROM domains')->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $known[strtolower((string) $d)] = true;
    }

    // Sample a share from EVERY feed each run (round-robin via per-file byte
    // offsets). This keeps discovery diverse so both the "safe" (Tranco) and
    // "scam" (phishing/malware) counters move every run instead of the puller
    // getting stuck for hours in one giant popularity list.
    // Collect a slice from each file into its own list, then round-robin merge
    // so the run's budget is shared across ALL source types (safe + scam + mixed)
    // instead of the first files eating the whole batch.
    $perFile = max(20, (int) ceil($want / count($files)));
    $perFileLists = [];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $class = classify_feed_file($file);
        $size = filesize($file) ?: 0;
        if ($size <= 0) {
            continue;
        }

        $key = 'feedoff_' . preg_replace('/[^a-z0-9]/', '', strtolower(basename($file)));
        $byteOffset = (int) get_setting($key, '0');
        if ($byteOffset < 0 || $byteOffset >= $size) {
            $byteOffset = 0;
        }

        $fh = fopen($file, 'r');
        if (!$fh) {
            continue;
        }
        if ($byteOffset > 0) {
            fseek($fh, $byteOffset);
            fgets($fh); // discard partial line
        }

        $list = [];
        $scanned = 0;
        // Scan deep enough to push past big already-known clusters at the top of
        // threat feeds so fresh (undiscovered) domains surface as new hits.
        $maxScan = max(40000, $perFile * 400);
        while (($line = fgets($fh)) !== false) {
            $scanned++;
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $host = str_contains($line, '://') ? (parse_url($line, PHP_URL_HOST) ?: '') : explode('/', $line)[0];
                $host = strtolower(ltrim((string) $host, '*.'));
                $host = normalize_domain($host) ?? '';
                if ($host !== '' && !isset($known[$host])) {
                    $known[$host] = true;
                    $list[$host] = $class;
                }
            }
            if (count($list) >= $perFile || $scanned >= $maxScan) {
                break;
            }
        }

        $newOffset = feof($fh) ? 0 : ftell($fh);
        fclose($fh);
        set_setting($key, (string) $newOffset);

        if ($list) {
            $perFileLists[] = $list;
        }
    }

    // Round-robin merge across files for a balanced mix.
    $domains = [];
    $cursors = array_map(static fn($l) => array_keys($l), $perFileLists);
    $exhausted = false;
    for ($i = 0; !$exhausted; $i++) {
        $exhausted = true;
        foreach ($perFileLists as $fi => $list) {
            if (isset($cursors[$fi][$i])) {
                $host = $cursors[$fi][$i];
                $domains[$host] = $list[$host];
                $exhausted = false;
            }
        }
    }

    return $domains; // map of host => source class, interleaved by source
}

/** Classify a feed file into a provenance class used for fast turbo scoring. */
function classify_feed_file(string $file): ?string
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

function discover_from_pending_reports(PDO $db): array
{
    $stmt = $db->query("SELECT DISTINCT domain_text FROM reports WHERE status = 'pending'");
    // User-reported domains have unknown safety — no provenance class.
    return array_fill_keys(array_column($stmt->fetchAll(), 'domain_text'), null);
}
