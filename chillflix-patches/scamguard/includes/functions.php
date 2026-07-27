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
 * ScamAdviser RatingScore mapping: hero BG + progress bar by trust score.
 * Source: scamadviser.com/build/assets/RatingScore-BbWfQDpQ.js
 *
 * @return array{heroBg:string,progressBar:string,bgFile:string,label:string,why:string,hint:string}
 */
function rating_score_theme(int $score): array
{
    $score = max(0, min(100, $score));

    if ($score > 80) {
        return [
            'heroBg' => 'container-result-page-hero-bg-image-green',
            'progressBar' => 'progress-bar-green',
            'bgFile' => 'result_page_hero_bg_green.png',
            'label' => 'Very Likely Safe',
            'why' => 'an average to good',
            'hint' => 'No strong scam signals found for this result.',
        ];
    }
    if ($score > 60) {
        return [
            'heroBg' => 'container-result-page-hero-bg-image-light-green',
            'progressBar' => 'progress-bar-light-green',
            'bgFile' => 'result_page_hero_bg_light_green.png',
            'label' => 'Likely Safe',
            'why' => 'a decent',
            'hint' => 'Mostly positive signals, but stay alert.',
        ];
    }
    if ($score > 40) {
        return [
            'heroBg' => 'container-result-page-hero-bg-image-orange',
            'progressBar' => 'progress-bar-orange',
            'bgFile' => 'result_page_hero_bg_orange.png',
            'label' => 'Suspicious',
            'why' => 'a mixed',
            'hint' => 'Some risk signals are present — verify before you trust it.',
        ];
    }
    if ($score > 20) {
        return [
            'heroBg' => 'container-result-page-hero-bg-image-dark-orange',
            'progressBar' => 'progress-bar-dark-orange',
            'bgFile' => 'result_page_hero_bg_dark_orange.png',
            'label' => 'Likely Unsafe',
            'why' => 'a low',
            'hint' => 'Elevated risk patterns were detected.',
        ];
    }
    if ($score > 0) {
        return [
            'heroBg' => 'container-result-page-hero-bg-image-red',
            'progressBar' => 'progress-bar-red',
            'bgFile' => 'result_page_hero_bg_red.png',
            'label' => 'Very Likely Unsafe',
            'why' => 'a very low',
            'hint' => 'Strong scam / abuse signals — do not trust this.',
        ];
    }

    return [
        'heroBg' => 'container-result-page-hero-bg-image-gray',
        'progressBar' => 'progress-bar-bg-none',
        'bgFile' => 'result_page_hero_bg_gray.png',
        'label' => 'Unknown',
        'why' => 'an unclear',
        'hint' => 'Not enough data for a confident verdict yet.',
    ];
}

/**
 * @deprecated Prefer rating_score_theme(); kept for callers that only have a status.
 * @return array{tone:string,bg:string,bar:string,label:string,hint:string,why:string}
 */
function status_banner(string $status): array
{
    $score = match ($status) {
        'safe', 'whitelisted' => 90,
        'caution' => 50,
        'risky' => 30,
        'scam', 'blacklisted' => 10,
        default => 0,
    };
    $t = rating_score_theme($score);
    if ($status === 'whitelisted') {
        $t['label'] = 'Verified Safe';
    } elseif ($status === 'blacklisted') {
        $t['label'] = 'Confirmed Scam';
    }

    return [
        'tone' => $t['heroBg'],
        'bg' => $t['bgFile'],
        'bar' => $t['progressBar'],
        'label' => $t['label'],
        'hint' => $t['hint'],
        'why' => $t['why'],
    ];
}

/**
 * Render ScamAdviser result hero (Detail-_xxkMTOR.js) + TrustScore panel.
 *
 * @param array<int,array{label:string,href:string,class?:string,external?:bool}> $actions
 * @param array{last_update?:string} $meta
 */
function render_status_banner(string $status, int $score, string $subject, array $actions = [], array $meta = []): void
{
    $score = max(0, min(100, $score));
    $t = rating_score_theme($score);

    // Status overrides for explicit list hits
    if ($status === 'whitelisted' && $score >= 80) {
        $t['label'] = 'Verified Safe';
    } elseif ($status === 'blacklisted') {
        $t = rating_score_theme(10);
        $t['label'] = 'Confirmed Scam';
    }

    $base = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '';
    $bgUrl = $base . '/assets/img/hero/' . $t['bgFile'];
    $brand = get_setting('site_name', 'ScamGuard');
    $lastUpdate = $meta['last_update'] ?? '';

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
    <div class="result-page-main-container pb-40">
        <div class="headers-inner text-center">
            <div class="result-page-container <?= h($t['heroBg']) ?>" style="background-image:url('<?= h($bgUrl) ?>')">
                <div class="container-result-page-hero">
                    <div class="pb-15">
                        <div class="result-page-hero-title-outer">
                            <h1 class="result-page-hero-title"><?= h($t['label']) ?></h1>
                        </div>
                    </div>
                    <h5 class="result-page-hero-domain"><?= h($subject) ?></h5>
                    <div class="mt-30 sa-hero-btns">
                        <?php if ($visit): ?>
                            <a class="btn-grey-responsive-xs-result-page-hero __link domain-link" href="<?= h($visit['href']) ?>"<?= !empty($visit['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h(strtoupper($visit['label'])) ?></a>
                        <?php endif; ?>
                        <?php if ($report): ?>
                            <a class="btn-primary-responsive-xs-result-page-hero __link" href="<?= h($report['href']) ?>"><?= h(strtoupper($report['label'])) ?></a>
                        <?php endif; ?>
                        <?php foreach ($extra as $a): ?>
                            <a class="btn-grey-responsive-xs-result-page-hero __link sa-hero-extra" href="<?= h($a['href']) ?>"><?= h($a['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <section>
            <div class="sa-result-score-wrap">
                <div class="result-score">
                    <div class="text-heading-section">
                        <p><?= h($subject) ?> has <?= h($t['why']) ?> trust score. Why?</p>
                    </div>
                    <div class="trust-score">
                        <div class="row text-base-line">
                            <div class="col-5 result-page-text-heading-block"><?= h($brand) ?></div>
                            <div class="col-7 text-right">
                                <span class="result-page-text-tile-title-regular">Trust Score</span>
                                <span class="text-heading-section-700" data-sa-score="<?= $score ?>"><?= $score ?></span>
                            </div>
                        </div>
                        <div class="progress mt-40" role="meter" aria-valuenow="<?= $score ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Trust score <?= $score ?> out of 100">
                            <div class="progress-bar <?= h($t['progressBar']) ?>" data-sa-bar="<?= $score ?>" style="width:0%"></div>
                        </div>
                    </div>
                    <?php if ($lastUpdate !== ''): ?>
                        <p class="text-tile-title text-little-dark-grey mt-24">Last Update: <?= h($lastUpdate) ?></p>
                    <?php endif; ?>
                    <p class="sa-trust-hint"><?= h($t['hint']) ?></p>
                </div>
            </div>
        </section>
    </div>
    <script>
    (function () {
      var n = document.querySelector('[data-sa-score]');
      var bar = document.querySelector('[data-sa-bar]');
      if (!n || !bar) return;
      var target = parseInt(n.getAttribute('data-sa-score'), 10) || 0;
      var cur = 0;
      var tick = setInterval(function () {
        cur = cur >= target ? target : cur + 1;
        n.textContent = String(cur);
        bar.style.width = cur + '%';
        if (cur >= target) clearInterval(tick);
      }, 25);
    })();
    </script>
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
