<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/functions.php';
Auth::requireLogin();

$pageTitle = 'Discovery Puller';
$db = Database::getConnection();
$flash = null;

function discovery_start_background(): bool
{
    if (discovery_is_running()) {
        return false;
    }

    $php = 'php';
    foreach (['/usr/bin/php', '/usr/bin/php8.1', '/usr/bin/php8.2', '/usr/bin/php8.3', 'php'] as $candidate) {
        if ($candidate === 'php' || (is_file($candidate) && is_executable($candidate))) {
            $php = $candidate;
            break;
        }
    }

    discovery_clear_stop_flag();
    @unlink(discovery_lock_path());

    $script = realpath(__DIR__ . '/../cron/discover.php');
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    $cmd = sprintf(
        '(%s %s --force >> %s 2>&1) > /dev/null 2>&1 &',
        escapeshellarg($php),
        escapeshellarg((string) $script),
        escapeshellarg($logDir . '/discover.log')
    );
    exec($cmd);
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'save') {
        $batch = max(1, min(10000, (int) ($_POST['discovery_batch_size'] ?? 1000)));
        $interval = max(1, min(1440, (int) ($_POST['discovery_interval_minutes'] ?? 1)));
        $rate = max(1, min(1000000, (int) ($_POST['discovery_rate_limit_per_hour'] ?? 50000)));
        set_setting('discovery_batch_size', (string) $batch);
        set_setting('discovery_interval_minutes', (string) $interval);
        set_setting('discovery_rate_limit_per_hour', (string) $rate);
        $autoOn = isset($_POST['discovery_auto_enabled']);
        set_setting('discovery_turbo_fast', isset($_POST['discovery_turbo_fast']) ? '1' : '0');

        $sourceRows = $db->query('SELECT name FROM discovery_sources')->fetchAll(PDO::FETCH_COLUMN);
        $enabled = array_map('strval', $_POST['sources'] ?? []);
        $stmt = $db->prepare('UPDATE discovery_sources SET enabled = ? WHERE name = ?');
        foreach ($sourceRows as $name) {
            $stmt->execute([in_array((string) $name, $enabled, true) ? 1 : 0, $name]);
        }

        if ($autoOn) {
            set_setting('discovery_auto_enabled', '1');
            discovery_clear_stop_flag();
            $flash = 'Discovery settings saved.';
        } else {
            $result = discovery_disable_and_stop();
            $flash = $result['killed'] > 0
                ? 'Discovery settings saved. Running pull stopped and automatic puller disabled.'
                : 'Discovery settings saved. Automatic puller disabled.';
        }
        log_admin_activity(Auth::id(), 'update_discovery_settings');
    } elseif ($action === 'start') {
        set_setting('discovery_auto_enabled', '1');
        discovery_clear_stop_flag();
        $flash = discovery_start_background()
            ? 'Discovery pull started in the background.'
            : 'A discovery pull is already running.';
        log_admin_activity(Auth::id(), 'discovery_pull_now');
    } elseif ($action === 'stop') {
        $result = discovery_disable_and_stop();
        $flash = $result['killed'] > 0
            ? 'Running pull stopped and automatic discovery disabled.'
            : 'Automatic discovery disabled.';
        log_admin_activity(Auth::id(), 'discovery_stop');
    }
}

require __DIR__ . '/includes/layout_top.php';

$sources = $db->query('SELECT * FROM discovery_sources ORDER BY name')->fetchAll();
$runs = $db->query('SELECT * FROM discovery_runs ORDER BY started_at DESC LIMIT 12')->fetchAll();
$running = discovery_is_running();
$lastHour = (int) $db->query(
    "SELECT COUNT(*) FROM domains
     WHERE discovered_via IN ('threat_feeds','ct_logs','user_reports','popular_seed')
       AND first_seen >= NOW() - INTERVAL 1 HOUR"
)->fetchColumn();
$totalDiscovered = (int) $db->query(
    "SELECT COUNT(*) FROM domains
     WHERE discovered_via IN ('threat_feeds','ct_logs','user_reports','popular_seed')"
)->fetchColumn();
$feedRows = [];
$feedDir = __DIR__ . '/../storage/feeds';
foreach (glob($feedDir . '/*.txt') ?: [] as $file) {
    $feedRows[] = [
        'name' => basename($file),
        'lines' => (int) exec('wc -l < ' . escapeshellarg($file)),
        'size' => filesize($file),
        'age' => round((time() - filemtime($file)) / 3600, 1),
    ];
}
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="summary-grid" style="margin-bottom:18px;">
    <div class="card summary-card"><div class="summary-label">Auto puller</div><div class="summary-value"><?= get_setting('discovery_auto_enabled', '1') === '1' ? 'Enabled' : 'Stopped' ?></div></div>
    <div class="card summary-card"><div class="summary-label">Running now</div><div class="summary-value"><?= $running ? 'Yes' : 'No' ?></div></div>
    <div class="card summary-card"><div class="summary-label">Pulled last hour</div><div class="summary-value"><?= number_format($lastHour) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Discovery total</div><div class="summary-value"><?= number_format($totalDiscovered) ?></div></div>
</div>

<div class="card" style="max-width:860px; margin-bottom:18px;">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="grid grid-3">
            <div class="field">
                <label>Batch per run</label>
                <input type="number" name="discovery_batch_size" min="1" max="10000" value="<?= h(get_setting('discovery_batch_size', '1000')) ?>">
                <small style="color:var(--faint);">Max domains attempted per cron run.</small>
            </div>
            <div class="field">
                <label>Interval minutes</label>
                <input type="number" name="discovery_interval_minutes" min="1" max="1440" value="<?= h(get_setting('discovery_interval_minutes', '1')) ?>">
                <small style="color:var(--faint);">Cron ticks can be frequent; this controls skip interval.</small>
            </div>
            <div class="field">
                <label>Hourly cap</label>
                <input type="number" name="discovery_rate_limit_per_hour" min="1" max="1000000" value="<?= h(get_setting('discovery_rate_limit_per_hour', '50000')) ?>">
                <small style="color:var(--faint);">Hard cap across automatic pulls.</small>
            </div>
        </div>

        <div style="display:flex; gap:16px; flex-wrap:wrap; margin:14px 0;">
            <label><input type="checkbox" name="discovery_auto_enabled" value="1" style="width:auto; display:inline;" <?= get_setting('discovery_auto_enabled', '1') === '1' ? 'checked' : '' ?>> Automatic puller enabled</label>
            <label><input type="checkbox" name="discovery_turbo_fast" value="1" style="width:auto; display:inline;" <?= get_setting('discovery_turbo_fast', '1') === '1' ? 'checked' : '' ?>> Turbo mode (max volume, less complete)</label>
        </div>

        <h3 style="margin:18px 0 8px;">Sources</h3>
        <div class="signal-list" style="margin-bottom:14px;">
            <?php foreach ($sources as $source): ?>
                <label class="signal-row tone-neutral" style="cursor:pointer;">
                    <span class="signal-main">
                        <span class="label"><?= h($source['label']) ?></span>
                        <span class="signal-note">Last: <?= h($source['last_run_status'] ?? 'never') ?> · found <?= number_format((int) ($source['last_run_found'] ?? 0)) ?></span>
                    </span>
                    <span class="value"><input type="checkbox" name="sources[]" value="<?= h($source['name']) ?>" <?= !empty($source['enabled']) ? 'checked' : '' ?> style="width:auto;"></span>
                </label>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-primary" type="submit">Save Discovery Settings</button>
    </form>
</div>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; align-items:center;">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="stop">
        <button class="btn btn-danger" type="submit">Stop Puller Now</button>
    </form>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="start">
        <button class="btn btn-primary" type="submit">Start Pull Now</button>
    </form>
    <a class="btn" href="<?= BASE_PATH ?>/admin/discovery.php">Refresh Status</a>
</div>
<p style="color:var(--faint); font-size:13px; margin:-8px 0 18px;">
    Uncheck <strong>Automatic puller enabled</strong> and Save, or press <strong>Stop Puller Now</strong> — both kill any in-flight batch.
    <strong>Start Pull Now</strong> turns the automatic puller back on.
</p>

<div class="card" style="margin-bottom:18px;">
    <h3 style="margin-top:0;">Feed files</h3>
    <div class="check-list">
        <?php foreach ($feedRows as $feed): ?>
            <div class="check-item">
                <div class="check-main">
                    <span class="check-domain"><?= h($feed['name']) ?></span>
                    <span class="check-sub"><?= number_format($feed['lines']) ?> lines · <?= number_format((int) ($feed['size'] / 1024)) ?> KB · age <?= h((string) $feed['age']) ?>h</span>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$feedRows): ?><p class="check-empty">No feed files found yet. Run <code>cron/update-feeds.php</code>.</p><?php endif; ?>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">Recent runs</h3>
    <div class="check-list">
        <?php foreach ($runs as $run): ?>
            <?php $seconds = !empty($run['finished_at']) ? max(1, strtotime($run['finished_at']) - strtotime($run['started_at'])) : max(1, time() - strtotime($run['started_at'])); ?>
            <div class="check-item">
                <div class="check-main">
                    <span class="check-domain"><?= h($run['source_name']) ?> — <?= h($run['status']) ?></span>
                    <span class="check-sub"><?= h($run['started_at']) ?> · found <?= number_format((int) $run['domains_found']) ?> · queued <?= number_format((int) $run['domains_queued']) ?> · <?= $seconds ?>s</span>
                </div>
                <span class="check-meta"><span class="check-score"><?= $run['domains_queued'] ? number_format(((int) $run['domains_queued'] / $seconds) * 3600) . '/h' : '—' ?></span></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="alert alert-info" style="margin-top:18px;">
    Discovery skips domains already in the database. Turbo mode maximizes volume: it stores lightweight rows quickly; opening a report later performs the full scan.
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
