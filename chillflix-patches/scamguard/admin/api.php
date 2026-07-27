<?php
/**
 * JSON API for the React / shadcn admin SPA.
 * Session auth + CSRF for mutating requests.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/DomainRepository.php';
require_once __DIR__ . '/../includes/DomainChecker.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::start();

function api_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function api_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function api_require_csrf(?string $token): void
{
    if (!Auth::verifyCsrf($token)) {
        api_json(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? api_read_json() : [];
if ($action === '' && isset($body['action'])) {
    $action = (string) $body['action'];
}

$db = Database::getConnection();

// -------- Public (login / session probe) --------
if ($action === 'session') {
    api_json([
        'ok' => true,
        'authenticated' => Auth::check(),
        'username' => Auth::username(),
        'csrf' => Auth::check() ? Auth::csrfToken() : null,
    ]);
}

if ($action === 'login' && $method === 'POST') {
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($username === '' || $password === '') {
        api_json(['ok' => false, 'error' => 'Username and password required.'], 422);
    }
    if (!Auth::attempt($username, $password)) {
        usleep(400000);
        api_json(['ok' => false, 'error' => 'Invalid username or password.'], 401);
    }
    log_admin_activity(Auth::id(), 'login');
    api_json([
        'ok' => true,
        'username' => Auth::username(),
        'csrf' => Auth::csrfToken(),
    ]);
}

if ($action === 'logout' && $method === 'POST') {
    if (Auth::check()) {
        log_admin_activity(Auth::id(), 'logout');
    }
    Auth::logout();
    api_json(['ok' => true]);
}

// -------- Authenticated routes --------
if (!Auth::check()) {
    api_json(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

$csrf = Auth::csrfToken();

if ($action === 'dashboard') {
    $repo = new DomainRepository();
    $stats = $repo->stats();
    $pendingReports = (int) $db->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
    $recentDomains = $db->query('SELECT domain, trust_score, status, last_checked, discovered_via FROM domains ORDER BY last_checked DESC LIMIT 10')->fetchAll();
    $lastRuns = $db->query('SELECT source_name, started_at, finished_at, domains_found, domains_queued, status FROM discovery_runs ORDER BY started_at DESC LIMIT 8')->fetchAll();
    api_json([
        'ok' => true,
        'csrf' => $csrf,
        'stats' => $stats,
        'pending_reports' => $pendingReports,
        'recent_domains' => $recentDomains,
        'recent_runs' => $lastRuns,
        'discovery' => [
            'batch_size' => (int) get_setting('discovery_batch_size', '50'),
            'interval_minutes' => (int) get_setting('discovery_interval_minutes', '5'),
            'last_run_at' => get_setting('discovery_last_run_at', ''),
            'pull_running' => is_file(__DIR__ . '/../storage/discovery.lock'),
        ],
    ]);
}

if ($action === 'discovery_get') {
    $sources = $db->query('SELECT * FROM discovery_sources ORDER BY name')->fetchAll();
    $runs = $db->query('SELECT * FROM discovery_runs ORDER BY started_at DESC LIMIT 30')->fetchAll();
    $lockFile = __DIR__ . '/../storage/discovery.lock';
    api_json([
        'ok' => true,
        'csrf' => $csrf,
        'sources' => $sources,
        'runs' => $runs,
        'settings' => [
            'discovery_batch_size' => (int) get_setting('discovery_batch_size', '50'),
            'discovery_interval_minutes' => (int) get_setting('discovery_interval_minutes', '5'),
            'discovery_rate_limit_per_hour' => (int) get_setting('discovery_rate_limit_per_hour', '500'),
            'discovery_last_run_at' => get_setting('discovery_last_run_at', ''),
        ],
        'pull_running' => is_file($lockFile),
    ]);
}

if ($action === 'discovery_save' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $batch = max(1, min(500, (int) ($body['discovery_batch_size'] ?? 50)));
    $interval = max(1, min(1440, (int) ($body['discovery_interval_minutes'] ?? 5)));
    $rate = max(1, min(100000, (int) ($body['discovery_rate_limit_per_hour'] ?? 500)));
    set_setting('discovery_batch_size', (string) $batch);
    set_setting('discovery_interval_minutes', (string) $interval);
    set_setting('discovery_rate_limit_per_hour', (string) $rate);
    log_admin_activity(Auth::id(), 'update_discovery_settings', null, "batch=$batch interval={$interval}m rate=$rate");
    api_json(['ok' => true, 'message' => 'Discovery settings saved.']);
}

if ($action === 'discovery_toggle' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $source = trim((string) ($body['source'] ?? ''));
    if ($source === '') {
        api_json(['ok' => false, 'error' => 'Source required.'], 422);
    }
    $stmt = $db->prepare('UPDATE discovery_sources SET enabled = 1 - enabled WHERE name = ?');
    $stmt->execute([$source]);
    log_admin_activity(Auth::id(), 'toggle_discovery_source', $source);
    api_json(['ok' => true]);
}

if ($action === 'discovery_pull_now' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $lockFile = __DIR__ . '/../storage/discovery.lock';
    if (is_file($lockFile)) {
        $age = time() - (int) filemtime($lockFile);
        if ($age < 900) {
            api_json(['ok' => false, 'error' => 'A discovery pull is already running.', 'pull_running' => true], 409);
        }
        @unlink($lockFile);
    }

    file_put_contents($lockFile, (string) getmypid());
    @chmod($lockFile, 0660);

    // PHP_BINARY under php-fpm is often php-fpm itself — prefer a real CLI binary.
    $candidates = ['/usr/bin/php', '/usr/bin/php8.1', '/usr/bin/php8.2', '/usr/bin/php8.3', 'php'];
    $php = 'php';
    foreach ($candidates as $candidate) {
        if ($candidate === 'php' || (is_file($candidate) && is_executable($candidate))) {
            $php = $candidate;
            break;
        }
    }

    $script = realpath(__DIR__ . '/../cron/discover.php');
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $logFile = $logDir . '/discover.log';
    $cmd = sprintf(
        '(%s %s --force >> %s 2>&1; rm -f %s) > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg((string) $script),
        escapeshellarg($logFile),
        escapeshellarg($lockFile)
    );
    exec($cmd);
    log_admin_activity(Auth::id(), 'discovery_pull_now');
    api_json(['ok' => true, 'message' => 'Discovery pull started.', 'pull_running' => true]);
}

if ($action === 'domains') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = 'domain LIKE ?';
        $params[] = "%$search%";
    }
    if ($statusFilter !== '') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $countStmt = $db->prepare("SELECT COUNT(*) FROM domains $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $stmt = $db->prepare("SELECT id, domain, trust_score, status, domain_age_days, last_checked, discovered_via, manual_override, threat_feed_hit, verdict FROM domains $whereSql ORDER BY last_checked DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    api_json([
        'ok' => true,
        'csrf' => $csrf,
        'domains' => $stmt->fetchAll(),
        'page' => $page,
        'total' => $total,
        'per_page' => $perPage,
    ]);
}

if ($action === 'domain_action' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $repo = new DomainRepository();
    $op = (string) ($body['op'] ?? '');
    $id = (int) ($body['id'] ?? 0);

    if ($op === 'bulk_add') {
        $lines = preg_split('/[\r\n,]+/', (string) ($body['bulk_domains'] ?? '')) ?: [];
        $added = 0;
        foreach ($lines as $line) {
            $d = normalize_domain(trim($line));
            if ($d && !$repo->find($d)) {
                $repo->getOrCheck($d, 'manual');
                $added++;
            }
        }
        log_admin_activity(Auth::id(), 'bulk_add', null, "$added domains added");
        api_json(['ok' => true, 'message' => "$added domain(s) added and checked."]);
    }

    if (!$id) {
        api_json(['ok' => false, 'error' => 'Domain id required.'], 422);
    }
    $record = $repo->findById($id);
    if (!$record && $op !== 'delete') {
        api_json(['ok' => false, 'error' => 'Domain not found.'], 404);
    }

    if ($op === 'rescan' && $record) {
        $checker = new DomainChecker($record['domain']);
        $result = $checker->run();
        $repo->upsert($result, $id, $record['discovered_via']);
        log_admin_activity(Auth::id(), 'rescan_domain', $record['domain']);
        api_json(['ok' => true, 'message' => 'Domain re-checked.']);
    }
    if ($op === 'override' && $record) {
        $status = (string) ($body['status'] ?? 'unknown');
        $score = max(1, min(100, (int) ($body['score'] ?? 50)));
        $notes = trim((string) ($body['notes'] ?? ''));
        $db->prepare('UPDATE domains SET status = ?, trust_score = ?, manual_override = 1, admin_notes = ? WHERE id = ?')
            ->execute([$status, $score, $notes, $id]);
        log_admin_activity(Auth::id(), 'manual_override', (string) $id, "status=$status score=$score");
        api_json(['ok' => true, 'message' => 'Manual override applied.']);
    }
    if ($op === 'clear_override') {
        $db->prepare('UPDATE domains SET manual_override = 0 WHERE id = ?')->execute([$id]);
        log_admin_activity(Auth::id(), 'clear_override', (string) $id);
        api_json(['ok' => true, 'message' => 'Override cleared.']);
    }
    if ($op === 'delete') {
        $db->prepare('DELETE FROM domains WHERE id = ?')->execute([$id]);
        log_admin_activity(Auth::id(), 'delete_domain', (string) $id);
        api_json(['ok' => true, 'message' => 'Domain deleted.']);
    }
    api_json(['ok' => false, 'error' => 'Unknown domain op.'], 400);
}

if ($action === 'reports') {
    $statusFilter = (string) ($_GET['status'] ?? 'pending');
    $stmt = $db->prepare('SELECT * FROM reports WHERE status = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->execute([$statusFilter]);
    api_json(['ok' => true, 'csrf' => $csrf, 'reports' => $stmt->fetchAll(), 'status' => $statusFilter]);
}

if ($action === 'report_review' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $repo = new DomainRepository();
    $id = (int) ($body['id'] ?? 0);
    $op = (string) ($body['op'] ?? '');
    $stmt = $db->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $report = $stmt->fetch();
    if (!$report) {
        api_json(['ok' => false, 'error' => 'Report not found.'], 404);
    }
    if ($op === 'approve') {
        $existing = $repo->find($report['domain_text']);
        if ($existing) {
            $newScore = min((int) $existing['trust_score'], 25);
            $db->prepare('UPDATE domains SET trust_score = ?, status = ?, last_checked = NULL WHERE id = ?')
                ->execute([$newScore, score_to_status($newScore), $existing['id']]);
        } else {
            $repo->getOrCheck($report['domain_text'], 'user_report');
        }
        $db->prepare("UPDATE reports SET status = 'approved', admin_id = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([Auth::id(), $id]);
        $msg = 'Report approved.';
    } elseif ($op === 'reject') {
        $db->prepare("UPDATE reports SET status = 'rejected', admin_id = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([Auth::id(), $id]);
        $msg = 'Report rejected.';
    } else {
        api_json(['ok' => false, 'error' => 'Unknown op.'], 400);
    }
    log_admin_activity(Auth::id(), 'review_report', (string) $id, $op);
    api_json(['ok' => true, 'message' => $msg]);
}

if ($action === 'settings_get') {
    api_json([
        'ok' => true,
        'csrf' => $csrf,
        'settings' => [
            'site_name' => get_setting('site_name'),
            'site_tagline' => get_setting('site_tagline'),
            'announcement_banner' => get_setting('announcement_banner'),
            'announcement_enabled' => get_setting('announcement_enabled') === '1',
        ],
    ]);
}

if ($action === 'settings_save' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    set_setting('site_name', trim((string) ($body['site_name'] ?? '')));
    set_setting('site_tagline', trim((string) ($body['site_tagline'] ?? '')));
    set_setting('announcement_banner', trim((string) ($body['announcement_banner'] ?? '')));
    set_setting('announcement_enabled', !empty($body['announcement_enabled']) ? '1' : '0');
    log_admin_activity(Auth::id(), 'update_site_settings');
    api_json(['ok' => true, 'message' => 'Settings saved.']);
}

if ($action === 'scoring_get') {
    $rows = $db->query('SELECT config_key, config_value, description FROM scoring_config')->fetchAll();
    api_json(['ok' => true, 'csrf' => $csrf, 'config' => $rows]);
}

if ($action === 'scoring_save' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $values = $body['values'] ?? [];
    if (!is_array($values)) {
        api_json(['ok' => false, 'error' => 'Invalid values.'], 422);
    }
    $stmt = $db->prepare('UPDATE scoring_config SET config_value = ? WHERE config_key = ?');
    foreach ($values as $key => $val) {
        $stmt->execute([(float) $val, (string) $key]);
    }
    log_admin_activity(Auth::id(), 'update_scoring_config');
    api_json(['ok' => true, 'message' => 'Scoring configuration updated.']);
}

if ($action === 'api_keys_get') {
    $keys = $db->query('SELECT id, api_key, label, owner_email, rate_limit_per_day, active, created_at, last_used_at FROM api_keys ORDER BY created_at DESC')->fetchAll();
    api_json(['ok' => true, 'csrf' => $csrf, 'keys' => $keys]);
}

if ($action === 'api_keys_action' && $method === 'POST') {
    api_require_csrf($body['csrf'] ?? null);
    $op = (string) ($body['op'] ?? '');
    if ($op === 'create') {
        $label = trim((string) ($body['label'] ?? ''));
        $email = trim((string) ($body['owner_email'] ?? ''));
        $rate = max(1, (int) ($body['rate_limit_per_day'] ?? 1000));
        $newKey = bin2hex(random_bytes(24));
        $db->prepare('INSERT INTO api_keys (api_key, label, owner_email, rate_limit_per_day) VALUES (?, ?, ?, ?)')
            ->execute([$newKey, $label ?: null, $email ?: null, $rate]);
        log_admin_activity(Auth::id(), 'create_api_key', $label);
        api_json(['ok' => true, 'message' => 'API key created.', 'api_key' => $newKey]);
    }
    if ($op === 'revoke') {
        $id = (int) ($body['id'] ?? 0);
        $db->prepare('UPDATE api_keys SET active = 0 WHERE id = ?')->execute([$id]);
        log_admin_activity(Auth::id(), 'revoke_api_key', (string) $id);
        api_json(['ok' => true, 'message' => 'API key revoked.']);
    }
    api_json(['ok' => false, 'error' => 'Unknown op.'], 400);
}

if ($action === 'activity') {
    $rows = $db->query(
        'SELECT a.*, u.username FROM admin_activity_log a
         LEFT JOIN admin_users u ON u.id = a.admin_id
         ORDER BY a.created_at DESC LIMIT 100'
    )->fetchAll();
    api_json(['ok' => true, 'csrf' => $csrf, 'activity' => $rows]);
}

api_json(['ok' => false, 'error' => 'Unknown action.'], 404);
