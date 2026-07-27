<?php
require_once __DIR__ . '/../config/database.php';

/** Escape output for safe HTML display */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Normalize user input into a bare domain (strip scheme, path, www) */
function normalize_domain(string $input): ?string
{
    $input = trim(strtolower($input));
    $input = preg_replace('#^https?://#', '', $input);
    $input = preg_replace('#^www\.#', '', $input);
    $input = explode('/', $input)[0];
    $input = explode('?', $input)[0];
    $input = explode(':', $input)[0]; // strip port

    if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{1,251})[a-z0-9]\.[a-z]{2,24}$/', $input)) {
        return null;
    }

    return $input;
}

/** @return array<string,string> */
function &settings_cache(): array
{
    static $cache = [];
    static $loaded = false;

    if (!$loaded) {
        $db = Database::getConnection();
        $stmt = $db->query('SELECT setting_key, setting_value FROM site_settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
        $loaded = true;
    }

    return $cache;
}

/** Fetch a site_settings value, with fallback default */
function get_setting(string $key, string $default = ''): string
{
    $cache = &settings_cache();
    return $cache[$key] ?? $default;
}

/** Upsert a site_settings value and refresh the in-request cache */
function set_setting(string $key, string $value): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);

    $cache = &settings_cache();
    $cache[$key] = $value;
}

/** Fetch a scoring_config value as a float, with fallback default */
function get_score_config(string $key, float $default = 0): float
{
    static $cache = null;
    $db = Database::getConnection();

    if ($cache === null) {
        $cache = [];
        $stmt = $db->query('SELECT config_key, config_value FROM scoring_config');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['config_key']] = $row['config_value'];
        }
    }

    return isset($cache[$key]) ? (float) $cache[$key] : $default;
}

/** Map a numeric trust score to a status label using admin-configured thresholds */
function score_to_status(int $score): string
{
    $safe    = (int) get_score_config('threshold_safe', 80);
    $caution = (int) get_score_config('threshold_caution', 50);
    $risky   = (int) get_score_config('threshold_risky', 25);

    if ($score >= $safe) return 'safe';
    if ($score >= $caution) return 'caution';
    if ($score >= $risky) return 'risky';
    return 'scam';
}

/** Human label + color class for a status badge */
function status_badge(string $status): array
{
    return match ($status) {
        'safe'         => ['label' => 'Likely Safe',    'class' => 'badge-safe'],
        'caution'      => ['label' => 'Use Caution',    'class' => 'badge-caution'],
        'risky'        => ['label' => 'Risky',          'class' => 'badge-risky'],
        'scam'         => ['label' => 'Likely Scam',    'class' => 'badge-scam'],
        'whitelisted'  => ['label' => 'Verified Safe',  'class' => 'badge-safe'],
        'blacklisted'  => ['label' => 'Confirmed Scam', 'class' => 'badge-scam'],
        default        => ['label' => 'Unknown',        'class' => 'badge-unknown'],
    };
}

/**
 * Big top-of-page verdict banner (ScamAdviser-style check / X).
 * @return array{tone:string,bg:string,bar:string,label:string,hint:string,why:string}
 */
function status_banner(string $status): array
{
    return match ($status) {
        'safe', 'whitelisted' => [
            'tone' => 'good',
            'bg' => 'status-good.png',
            'bar' => 'good',
            'label' => $status === 'whitelisted' ? 'Verified Safe' : 'Likely Safe',
            'hint' => 'No strong scam signals found for this result.',
            'why' => 'a strong',
        ],
        'caution' => [
            'tone' => 'caution',
            'bg' => 'status-caution.png',
            'bar' => 'caution',
            'label' => 'Use Caution',
            'hint' => 'Some risk signals are present — verify before you trust it.',
            'why' => 'a mixed',
        ],
        'risky' => [
            'tone' => 'risky',
            'bg' => 'status-risky.png',
            'bar' => 'risky',
            'label' => 'Risky',
            'hint' => 'Elevated risk patterns were detected.',
            'why' => 'a low',
        ],
        'scam', 'blacklisted' => [
            'tone' => 'bad',
            'bg' => 'status-bad.png',
            'bar' => 'bad',
            'label' => $status === 'blacklisted' ? 'Confirmed Scam' : 'Likely Scam',
            'hint' => 'Strong scam / abuse signals — do not trust this.',
            'why' => 'a very low',
        ],
        default => [
            'tone' => 'unknown',
            'bg' => 'status-unknown.png',
            'bar' => 'unknown',
            'label' => 'Unknown',
            'hint' => 'Not enough data for a confident verdict yet.',
            'why' => 'an unclear',
        ],
    };
}

/**
 * Render the ScamAdviser-style hero + trust score block.
 *
 * @param array<int,array{label:string,href:string,class?:string,external?:bool}> $actions
 */
function render_status_banner(string $status, int $score, string $subject, array $actions = []): void
{
    $b = status_banner($status);
    $score = max(0, min(100, $score));
    $base = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
    $bgUrl = $base . '/assets/img/hero/' . $b['bg'];

    $visit = null;
    $report = null;
    $extra = [];
    foreach ($actions as $a) {
        $label = strtolower($a['label'] ?? '');
        if ($visit === null && (str_contains($label, 'visit') || !empty($a['external']))) {
            $visit = $a;
        } elseif ($report === null && str_contains($label, 'report')) {
            $report = $a;
        } else {
            $extra[] = $a;
        }
    }
    ?>
    <div class="sa-result-hero">
        <div class="sa-hero-stage sa-hero-<?= h($b['tone']) ?>" style="background-image:url('<?= h($bgUrl) ?>')">
            <div class="sa-hero-content">
                <h1 class="sa-hero-title"><?= h($b['label']) ?></h1>
                <div class="sa-hero-domain"><?= h($subject) ?></div>
                <div class="sa-hero-actions">
                    <?php if ($visit): ?>
                        <a class="sa-btn-visit" href="<?= h($visit['href']) ?>"<?= !empty($visit['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h($visit['label']) ?></a>
                    <?php endif; ?>
                    <?php if ($report): ?>
                        <a class="sa-btn-report" href="<?= h($report['href']) ?>"><?= h($report['label']) ?></a>
                    <?php endif; ?>
                    <?php foreach ($extra as $a): ?>
                        <a class="sa-btn-extra" href="<?= h($a['href']) ?>"><?= h($a['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="sa-trust-card">
            <p class="sa-trust-why">Why does <?= h($subject) ?> have <?= h($b['why']) ?> trust score?</p>
            <div class="sa-trust-panel">
                <div class="sa-trust-row">
                    <span class="sa-trust-brand"><?= h(get_setting('site_name', 'ScamGuard')) ?></span>
                    <span class="sa-trust-score-label">Trust Score <strong><?= $score ?></strong></span>
                </div>
                <div class="sa-progress sa-progress-<?= h($b['bar']) ?>" role="meter" aria-valuenow="<?= $score ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Trust score <?= $score ?> out of 100">
                    <span style="width:<?= $score ?>%"></span>
                </div>
            </div>
            <p class="sa-trust-hint"><?= h($b['hint']) ?></p>
        </div>
    </div>
    <?php
}

/** Simple activity logger for admin audit trail */
function log_admin_activity(?int $adminId, string $action, ?string $target = null, ?string $details = null): void
{
    $db = Database::getConnection();
    $stmt = $db->prepare('INSERT INTO admin_activity_log (admin_id, action, target, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$adminId, $action, $target, $details]);
}

/** Redirect helper */
function base_path(string $path = ''): string
{
    $base = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . base_path($path));
    exit;
}

/** Public SEO-friendly URL for a checked domain page */
function domain_page_path(string $domain): string
{
    return base_path('site/' . rawurlencode(strtolower($domain)));
}

function domain_page_url(string $domain): string
{
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    return $base . '/site/' . rawurlencode(strtolower($domain));
}

/** Absolute site URL helper */
function absolute_url(string $path = ''): string
{
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
    if ($path === '' || $path === '/') {
        return $base . '/';
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return $base . '/' . ltrim($path, '/');
}
