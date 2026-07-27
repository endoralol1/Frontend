<?php
/**
 * Discovery cron job.
 *
 * Run this on a schedule (e.g. every 15 minutes) via real cron:
 *   php /path/to/scamguard/cron/discover.php
 *
 * Or, if your host only allows HTTP-triggered cron, visit:
 *   https://yourdomain.com/cron/discover.php?key=YOUR_CRON_SECRET
 *
 * It pulls newly-registered domains from Certificate Transparency logs
 * (via crt.sh) and known-bad domains from public threat feeds, then
 * runs each one through the normal DomainChecker pipeline.
 * No headless browser is used anywhere in this process.
 */

require_once __DIR__ . '/../includes/DomainRepository.php';

// --- Auth guard for HTTP-triggered execution ---
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
$batchSize = max(1, (int) get_setting('discovery_batch_size', '50'));
$intervalMinutes = max(1, (int) get_setting('discovery_interval_minutes', '5'));

// Cron is expected to tick every minute; honor the admin-configured interval
// unless this run was force-triggered (Pull now).
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

set_setting('discovery_last_run_at', date('Y-m-d H:i:s'));
if ($force) {
    cron_log('Force discovery run started.');
}

$sources = $db->query('SELECT * FROM discovery_sources WHERE enabled = 1')->fetchAll();

foreach ($sources as $source) {
    $runStmt = $db->prepare('INSERT INTO discovery_runs (source_name, status) VALUES (?, ?)');
    $runStmt->execute([$source['name'], 'running']);
    $runId = (int) $db->lastInsertId();

    $found = 0;
    $queued = 0;
    $logOutput = '';

    try {
        $candidateDomains = [];

        if ($source['name'] === 'ct_logs') {
            $candidateDomains = discover_from_ct_logs();
        } elseif ($source['name'] === 'threat_feeds') {
            $candidateDomains = discover_from_threat_feeds();
        } elseif ($source['name'] === 'user_reports') {
            $candidateDomains = discover_from_pending_reports($db);
        }

        $found = count($candidateDomains);
        $candidateDomains = array_slice($candidateDomains, 0, $batchSize);

        foreach ($candidateDomains as $domain) {
            $domain = normalize_domain($domain);
            if (!$domain) continue;

            $existing = $repo->find($domain);
            if ($existing && !$repo->isStale($existing)) {
                continue; // already have a fresh result, skip
            }

            try {
                $repo->getOrCheck($domain, $source['name']);
                $queued++;
                cron_log("Checked: $domain");
            } catch (Throwable $e) {
                cron_log("Error checking $domain: " . $e->getMessage());
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

cron_log('Discovery run complete.');


/**
 * Certificate Transparency logs via crt.sh.
 * Every SSL cert issued gets logged here in near-real-time, which is
 * the standard way to catch brand-new domains as soon as they go live.
 * We look for common scam-adjacent keywords in freshly issued certs.
 */
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
                    // name_value can contain multiple newline-separated names
                    foreach (explode("\n", $name) as $n) {
                        $n = ltrim($n, '*.');
                        if ($n) $domains[] = $n;
                    }
                }
            }
        }
    }

    return array_values(array_unique($domains));
}

/**
 * Pull domains from locally cached threat feeds (refreshed by cron/update-feeds.php).
 * Samples a rotating slice each run so we do not overwhelm the checker.
 */
function discover_from_threat_feeds(): array
{
    $feedDir = __DIR__ . '/../storage/feeds';
    $files = [
        $feedDir . '/openphish.txt',
        $feedDir . '/urlhaus-online.txt',
        $feedDir . '/phishing-domains.txt',
    ];

    $domains = [];
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $fh = fopen($file, 'r');
        if (!$fh) {
            continue;
        }
        $i = 0;
        while (($line = fgets($fh)) !== false) {
            $i++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Rotate through feeds so each run samples different hosts.
            if (($i + (int) date('i')) % 17 !== 0) {
                continue;
            }
            $host = $line;
            if (str_contains($line, '://')) {
                $host = parse_url($line, PHP_URL_HOST) ?: '';
            } else {
                $host = explode('/', $line)[0];
            }
            $host = strtolower(ltrim((string) $host, '*.'));
            if ($host !== '' && str_contains($host, '.')) {
                $domains[] = $host;
            }
            if (count($domains) >= 200) {
                break;
            }
        }
        fclose($fh);
        if (count($domains) >= 200) {
            break;
        }
    }

    return array_values(array_unique($domains));
}

function discover_from_pending_reports(PDO $db): array
{
    $stmt = $db->query("SELECT DISTINCT domain_text FROM reports WHERE status = 'pending'");
    return array_column($stmt->fetchAll(), 'domain_text');
}
