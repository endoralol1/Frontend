<?php
/**
 * Rescan cron job — refreshes domains whose last check is older than
 * the configured recheck interval. Run hourly, e.g.:
 *   php /path/to/scamguard/cron/rescan.php
 * or via HTTP:
 *   https://yourdomain.com/cron/rescan.php?key=YOUR_CRON_SECRET
 */

require_once __DIR__ . '/../includes/DomainRepository.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
}

function rescan_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$db = Database::getConnection();
$repo = new DomainRepository();

$intervalHours = (int) get_score_config('recheck_interval_hours', 72);
$batchSize = (int) get_setting('discovery_batch_size', '50');

$stmt = $db->prepare('
    SELECT * FROM domains
    WHERE manual_override = 0
    AND (last_checked IS NULL OR last_checked < DATE_SUB(NOW(), INTERVAL ? HOUR))
    ORDER BY last_checked ASC
    LIMIT ?
');
$stmt->bindValue(1, $intervalHours, PDO::PARAM_INT);
$stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
$stmt->execute();
$stale = $stmt->fetchAll();

rescan_log('Found ' . count($stale) . ' stale domain(s) to re-check.');

require_once __DIR__ . '/../includes/DomainChecker.php';

$checked = 0;
foreach ($stale as $record) {
    try {
        $checker = new DomainChecker($record['domain']);
        $result = $checker->run();
        $repo->upsert($result, $record['id'], $record['discovered_via']);
        $checked++;
        rescan_log('Re-checked: ' . $record['domain'] . ' -> score ' . $result['trust_score']);
    } catch (Throwable $e) {
        rescan_log('Error re-checking ' . $record['domain'] . ': ' . $e->getMessage());
    }
}

rescan_log("Rescan complete. $checked domain(s) refreshed.");
