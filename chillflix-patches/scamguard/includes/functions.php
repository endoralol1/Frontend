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

/** Common multi-part public suffixes (not a full PSL, covers frequent cases). */
function multi_part_tlds(): array
{
    return [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk',
        'com.au', 'net.au', 'org.au', 'edu.au',
        'co.nz', 'net.nz', 'org.nz',
        'co.jp', 'or.jp', 'ne.jp',
        'com.br', 'com.mx', 'com.ar', 'com.co', 'com.pe',
        'co.in', 'com.sg', 'com.my', 'com.ph', 'com.hk', 'com.tw', 'com.vn',
        'co.za', 'com.ng', 'com.pk', 'com.tr', 'com.ua', 'co.kr',
        'com.cn', 'com.sa', 'co.il',
    ];
}

/**
 * Registrable / apex domain for WHOIS (eTLD+1 style).
 * www is ignored; other subdomains resolve to the parent registrable name.
 */
function registrable_domain(string $host): ?string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $parts = array_values(array_filter(explode('.', $host), static fn($p) => $p !== ''));
    if (count($parts) < 2) {
        return null;
    }
    $last2 = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    if (count($parts) >= 3 && in_array($last2, multi_part_tlds(), true)) {
        return $parts[count($parts) - 3] . '.' . $last2;
    }
    return $last2;
}

function is_subdomain_hostname(string $host): bool
{
    $host = strtolower(trim(preg_replace('/^www\./', '', $host) ?? $host));
    $apex = registrable_domain($host);
    return $apex !== null && strcasecmp($apex, $host) !== 0;
}

/** Shared cloud / SaaS hostnames where tenant age ≠ platform domain age. */
function is_platform_hosted_domain(string $host): bool
{
    $host = strtolower(trim($host));
    $suffixes = [
        '.amazonaws.com', '.cloudfront.net', '.elasticbeanstalk.com',
        '.azurewebsites.net', '.azurefd.net', '.cloudapp.azure.com', '.blob.core.windows.net',
        '.appspot.com', '.firebaseapp.com', '.web.app', '.googleusercontent.com',
        '.github.io', '.githubusercontent.com',
        '.vercel.app', '.netlify.app', '.pages.dev', '.workers.dev',
        '.herokuapp.com', '.onrender.com', '.fly.dev',
        '.myshopify.com', '.wordpress.com', '.blogspot.com',
        '.webflow.io', '.carrd.co', '.ghost.io',
        '.ngrok.io', '.ngrok-free.app', '.trycloudflare.com',
    ];
    foreach ($suffixes as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }
    return false;
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
        'unavailable'  => ['label' => 'Website Down',   'class' => 'badge-unknown'],
        default        => ['label' => 'Unknown',        'class' => 'badge-unknown'],
    };
}

/**
 * True when the stored report is a discovery shortcut (DNS/lists only), not a finished live check.
 */
function record_is_provisional(array $record): bool
{
    $signals = json_decode((string) ($record['signals_json'] ?? ''), true);
    if (!is_array($signals)) {
        return false;
    }
    foreach ($signals as $s) {
        if (($s['label'] ?? '') === 'Scan depth'
            && stripos((string) ($s['value'] ?? ''), 'provisional') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Infer rough site category + which “business hygiene” checks actually apply.
 *
 * Free media / hobby sites should NOT be dinged for missing payments, phone,
 * or corporate contact pages. Shops, finance, hosting, and official services should.
 *
 * @return array{
 *   category:string,
 *   expects_payment:bool,
 *   expects_business_contact:bool,
 *   label:string
 * }
 */
function infer_site_expectations(string $title = '', string $excerpt = '', string $html = ''): array
{
    $plain = strtolower(trim($title . ' ' . $excerpt));
    if ($html !== '') {
        $chunk = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $chunk = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $chunk) ?? $chunk;
        $chunk = strtolower(trim(strip_tags($chunk)));
        if (function_exists('mb_substr')) {
            $plain .= ' ' . mb_substr($chunk, 0, 3500);
        } else {
            $plain .= ' ' . substr($chunk, 0, 3500);
        }
    }
    $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

    $commerce = (bool) preg_match(
        '/\b(add to cart|buy now|checkout|shopping cart|order now|free shipping|sku\b|in stock|shop now|store\.|ecommerce|e-commerce)\b/i',
        $plain
    );
    $finance = (bool) preg_match(
        '/\b(bank(ing)?|invest(ment|ing)?|trading|broker|loan|mortgage|crypto exchange|wallet connect|forex|roi|guaranteed returns?)\b/i',
        $plain
    );
    $hosting = (bool) preg_match(
        '/\b(web hosting|vps|dedicated server|cloud hosting|domain registrar|cpanel)\b/i',
        $plain
    );
    $freeMedia = (bool) preg_match(
        '/\b(watch\s+online|stream(ing)?|movies?|tv\s*shows?|anime|series|episode|free\s+(movies?|streams?|anime|films?)|film\s+online|hd\s+movies?|download\s+(movies?|episodes?))\b/i',
        $plain
    );
    // Official streamers still expect business contact; free/unofficial catalogs do not.
    $officialMedia = (bool) preg_match(
        '/\b(netflix|hulu|disney\+|disney plus|prime video|hbo max|max\.com|paramount\+|apple tv\+)\b/i',
        $plain
    );
    $forumBlog = (bool) preg_match(
        '/\b(forum|community|blog|wiki|fan\s*site|portfolio|personal\s+site)\b/i',
        $plain
    );

    if ($finance) {
        return [
            'category' => 'finance',
            'expects_payment' => true,
            'expects_business_contact' => true,
            'label' => 'Finance / money site',
        ];
    }
    if ($commerce) {
        return [
            'category' => 'commerce',
            'expects_payment' => true,
            'expects_business_contact' => true,
            'label' => 'Shop / commerce site',
        ];
    }
    if ($hosting) {
        return [
            'category' => 'hosting',
            'expects_payment' => true,
            'expects_business_contact' => true,
            'label' => 'Hosting / infrastructure',
        ];
    }
    if ($freeMedia && !$officialMedia) {
        return [
            'category' => 'free_media',
            'expects_payment' => false,
            'expects_business_contact' => false,
            'label' => 'Free / unofficial media site',
        ];
    }
    if ($forumBlog) {
        return [
            'category' => 'community',
            'expects_payment' => false,
            'expects_business_contact' => false,
            'label' => 'Community / blog style site',
        ];
    }

    return [
        'category' => 'general',
        'expects_payment' => false,
        'expects_business_contact' => true,
        'label' => 'General website',
    ];
}

/**
 * Detect login/account UI cues (links, copy, password fields) — not proof of safety.
 */
function detect_login_ui(string $html): bool
{
    if ($html === '') {
        return false;
    }
    if (preg_match('/type\s*=\s*[\'"]password[\'"]/i', $html)) {
        return true;
    }
    // JSON i18n / Next.js dictionaries often encode auth copy as "signIn":"Sign in".
    if (preg_match('/["\']signIn["\']\s*:\s*["\'][^"\']*sign\s*in/i', $html)
        || preg_match('/["\']signInTitle["\']\s*:/i', $html)) {
        return true;
    }
    // Markdown / plain fallbacks (Jina reader).
    if (preg_match('/\[(?:log\s*in|sign\s*in|sign\s*up|register|create account|my account)\]\([^)]+\)/i', $html)) {
        return true;
    }
    if (preg_match('/\b(log[\s-]?in|sign[\s-]?in|sign[\s-]?up|create account|register|my account)\b/i', $html)) {
        return true;
    }
    if (preg_match('/(?:href|action)\s*=\s*[\'"][^\'"]*(?:login|log-in|signin|sign-in|signup|sign-up|register|auth|account|\/user)[^\'"]*[\'"]/i', $html)) {
        return true;
    }
    return false;
}

/**
 * Detect commerce / payment UI cues.
 */
function detect_payment_ui(string $html): bool
{
    if ($html === '') {
        return false;
    }
    return (bool) preg_match(
        '/\b(checkout|add to cart|payment|paypal|credit card|billing|buy now|order now|shopping cart)\b/i',
        $html
    );
}

/**
 * Trust-band theme for modern ScamGuard result hero.
 *
 * @return array{tone:string,accent:string,bar:string,label:string,why:string,hint:string,icon:string}
 */
function rating_score_theme(int $score): array
{
    $score = max(0, min(100, $score));

    if ($score > 80) {
        return [
            'tone' => 'safe',
            'accent' => '#2fbf71',
            'bar' => 'progress-bar-green',
            'label' => 'Very Likely Safe',
            'why' => 'an average to good',
            'hint' => 'No strong scam signals found for this result.',
            'icon' => 'check',
        ];
    }
    if ($score > 60) {
        return [
            'tone' => 'safe',
            'accent' => '#63b100',
            'bar' => 'progress-bar-light-green',
            'label' => 'Likely Safe',
            'why' => 'a decent',
            'hint' => 'Mostly positive signals, but stay alert.',
            'icon' => 'check',
        ];
    }
    if ($score > 40) {
        return [
            'tone' => 'caution',
            'accent' => '#ff8a00',
            'bar' => 'progress-bar-orange',
            'label' => 'Suspicious',
            'why' => 'a mixed',
            'hint' => 'Some risk signals are present — verify before you trust it.',
            'icon' => 'warn',
        ];
    }
    if ($score > 20) {
        return [
            'tone' => 'risky',
            'accent' => '#ff6712',
            'bar' => 'progress-bar-dark-orange',
            'label' => 'Likely Unsafe',
            'why' => 'a low',
            'hint' => 'Elevated risk patterns were detected.',
            'icon' => 'warn',
        ];
    }
    if ($score > 0) {
        return [
            'tone' => 'danger',
            'accent' => '#ee3e41',
            'bar' => 'progress-bar-red',
            'label' => 'Very Likely Unsafe',
            'why' => 'a very low',
            'hint' => 'Strong scam / abuse signals — do not trust this.',
            'icon' => 'x',
        ];
    }

    return [
        'tone' => 'unknown',
        'accent' => '#6b7a8d',
        'bar' => 'progress-bar-bg-none',
        'label' => 'Unknown',
        'why' => 'an unclear',
        'hint' => 'Not enough data for a confident verdict yet.',
        'icon' => 'unknown',
    ];
}

/**
 * @deprecated Prefer rating_score_theme()
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
        'tone' => $t['tone'],
        'bg' => '',
        'bar' => $t['bar'],
        'label' => $t['label'],
        'hint' => $t['hint'],
        'why' => $t['why'],
    ];
}

function render_verdict_icon(string $icon): void
{
    if ($icon === 'check') {
        ?>
        <svg class="sg-verdict-svg" viewBox="0 0 96 96" aria-hidden="true">
            <circle cx="48" cy="48" r="40" fill="currentColor"/>
            <path d="M30 49.5 42.5 62 66 35" fill="none" stroke="#0b1018" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?php
        return;
    }
    if ($icon === 'warn') {
        ?>
        <svg class="sg-verdict-svg" viewBox="0 0 96 96" aria-hidden="true">
            <path d="M48 10 88 80H8Z" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round"/>
            <path d="M48 34v24" fill="none" stroke="#0b1018" stroke-width="7" stroke-linecap="round"/>
            <circle cx="48" cy="70" r="4.5" fill="#0b1018"/>
        </svg>
        <?php
        return;
    }
    if ($icon === 'x') {
        ?>
        <svg class="sg-verdict-svg" viewBox="0 0 96 96" aria-hidden="true">
            <circle cx="48" cy="48" r="40" fill="currentColor"/>
            <path d="M34 34 62 62M62 34 34 62" fill="none" stroke="#0b1018" stroke-width="7" stroke-linecap="round"/>
        </svg>
        <?php
        return;
    }
    ?>
    <svg class="sg-verdict-svg" viewBox="0 0 96 96" aria-hidden="true">
        <circle cx="48" cy="48" r="40" fill="currentColor"/>
        <path d="M48 30v28" fill="none" stroke="#0b1018" stroke-width="7" stroke-linecap="round"/>
        <circle cx="48" cy="68" r="4.5" fill="#0b1018"/>
    </svg>
    <?php
}

/**
 * Modern ScamGuard result hero + trust score panel.
 *
 * @param array<int,array{label:string,href:string,class?:string,external?:bool}> $actions
 * @param array{last_update?:string} $meta
 */
function render_status_banner(string $status, int $score, string $subject, array $actions = [], array $meta = []): void
{
    $score = max(0, min(100, $score));
    $t = rating_score_theme($score);
    $unavailable = ($status === 'unavailable');

    if ($status === 'whitelisted' && $score >= 80) {
        $t['label'] = 'Verified Safe';
        $t['icon'] = 'check';
        $t['tone'] = 'safe';
        $t['accent'] = '#2fbf71';
    } elseif ($status === 'blacklisted') {
        $t = rating_score_theme(10);
        $t['label'] = 'Confirmed Scam';
        $t['icon'] = 'x';
        $t['tone'] = 'danger';
    } elseif ($unavailable) {
        $t = [
            'tone' => 'unknown',
            'accent' => '#6b7a8d',
            'bar' => 'progress-bar-bg-none',
            'label' => 'Website Down',
            'why' => 'no usable',
            'hint' => 'The live site could not be reached, so a trust score is not available right now.',
            'icon' => 'unknown',
        ];
        $score = 0;
    } elseif ($status === 'scam') {
        $t = rating_score_theme(min($score, 18));
        $t['label'] = 'Likely Scam';
    } elseif ($status === 'risky') {
        $t = rating_score_theme(min(max($score, 21), 40));
        $t['label'] = 'Risky';
        $t['tone'] = 'risky';
        $t['icon'] = 'warn';
    } elseif ($status === 'caution') {
        // Don't show "Likely Safe" just because the numeric band is mid/high.
        $t['label'] = 'Use Caution';
        $t['tone'] = 'caution';
        $t['accent'] = '#ff8a00';
        $t['bar'] = 'progress-bar-orange';
        $t['icon'] = 'warn';
        $t['why'] = 'a mixed';
    } elseif ($status === 'safe') {
        $t['label'] = $score > 80 ? 'Very Likely Safe' : 'Likely Safe';
        $t['tone'] = 'safe';
        $t['icon'] = 'check';
    }

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
    <div class="sg-result sg-result--<?= h($t['tone']) ?>" style="--sg-accent: <?= h($t['accent']) ?>">
        <div class="sg-result-hero">
            <div class="sg-result-atmosphere" aria-hidden="true"></div>
            <div class="sg-result-grid" aria-hidden="true"></div>
            <div class="sg-result-arc" aria-hidden="true"></div>

            <div class="sg-verdict">
                <div class="sg-verdict-mark">
                    <span class="sg-verdict-ring" aria-hidden="true"></span>
                    <span class="sg-verdict-glow" aria-hidden="true"></span>
                    <?php render_verdict_icon($t['icon']); ?>
                </div>
                <h1 class="sg-verdict-title"><?= h($subject) ?></h1>
                <p class="sg-verdict-status"><?php if ($unavailable): ?>Website Down · score N/A<?php else: ?><?= h($t['label']) ?> · <?= (int) $score ?>/100<?php endif; ?></p>
                <p class="sg-verdict-subject"><?= h($brand) ?> scam check &amp; trust report</p>
                <div class="sg-verdict-actions">
                    <?php if ($visit && !$unavailable): ?>
                        <a class="sg-btn sg-btn-ghost" href="<?= h($visit['href']) ?>"<?= !empty($visit['external']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h(strtoupper($visit['label'])) ?></a>
                    <?php endif; ?>
                    <?php if ($report): ?>
                        <a class="sg-btn sg-btn-danger" href="<?= h($report['href']) ?>"><?= h(strtoupper($report['label'])) ?></a>
                    <?php endif; ?>
                    <?php foreach ($extra as $a): ?>
                        <a class="sg-btn sg-btn-ghost" href="<?= h($a['href']) ?>"><?= h($a['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="sg-trust">
            <p class="sg-trust-lead"><?php if ($unavailable): ?><?= h($subject) ?> looks down or unreachable right now — we can’t finish a live trust test. Try again later.<?php else: ?><?= h($subject) ?> has <?= h($t['why']) ?> trust score. Why?<?php endif; ?></p>
            <div class="sg-trust-panel">
                <div class="sg-trust-row">
                    <span class="sg-trust-brand"><?= h($brand) ?></span>
                    <span class="sg-trust-score-wrap">
                        <span class="sg-trust-score-label">Trust Score</span>
                        <strong class="sg-trust-score" data-sa-score="<?= $score ?>"><?= $unavailable ? 'N/A' : $score ?></strong>
                    </span>
                </div>
                <div class="progress mt-40" role="meter" aria-valuenow="<?= $score ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= $unavailable ? 'Trust score unavailable' : ('Trust score ' . $score . ' out of 100') ?>">
                    <div class="progress-bar <?= h($t['bar']) ?>" data-sa-bar="<?= $score ?>" style="width:<?= $unavailable ? 0 : $score ?>%"></div>
                </div>
            </div>
            <?php if ($lastUpdate !== ''): ?>
                <p class="sg-trust-meta">Last Update: <?= h($lastUpdate) ?></p>
            <?php endif; ?>
            <p class="sg-trust-hint"><?= h($t['hint']) ?></p>
        </div>
    </div>
    <script>
    (function () {
      var n = document.querySelector('[data-sa-score]');
      var bar = document.querySelector('[data-sa-bar]');
      if (!n || !bar) return;
      var target = parseInt(n.getAttribute('data-sa-score'), 10) || 0;
      // Animate from 0 even though final width is already set for no-JS fallback
      var cur = 0;
      bar.style.width = '0%';
      var tick = setInterval(function () {
        cur = cur >= target ? target : cur + 2;
        if (cur > target) cur = target;
        n.textContent = String(cur);
        bar.style.width = cur + '%';
        if (cur >= target) clearInterval(tick);
      }, 16);
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
