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
