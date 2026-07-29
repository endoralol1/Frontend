<?php
/**
 * Offline checks for site-type expectations + login detection.
 * php cron/selftest-site-type.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$config = $root . '/config/database.php';
$createdStub = false;
if (!is_file($config)) {
    if (!is_dir(dirname($config))) {
        mkdir(dirname($config), 0775, true);
    }
    file_put_contents($config, "<?php\nclass Database {\n    public static function getConnection(): PDO { throw new RuntimeException('stub'); }\n}\n");
    $createdStub = true;
}

try {
    require_once $root . '/includes/functions.php';

    $fail = 0;
    $pass = 0;
    $expect = static function (bool $ok, string $msg) use (&$fail, &$pass): void {
        if ($ok) {
            echo "[PASS] $msg\n";
            $pass++;
        } else {
            echo "[FAIL] $msg\n";
            $fail++;
        }
    };

    $streamHtml = '<html><head><title>Chillflix - Watch Movies Online Free</title></head><body>'
        . '<a href="/login">Login</a><a href="/signup">Sign Up</a>'
        . '<h1>Popular Movies</h1><p>Stream TV shows and anime online free in HD.</p>'
        . '</body></html>';
    $p = infer_site_expectations('Chillflix - Watch Movies Online Free', 'Stream movies and TV shows free', $streamHtml);
    $expect($p['category'] === 'free_media', 'free streaming classified as free_media');
    $expect($p['expects_payment'] === false, 'free streaming does not expect payment');
    $expect($p['expects_business_contact'] === false, 'free streaming does not expect business contact');
    $expect(detect_login_ui($streamHtml) === true, 'detects login link on streaming homepage');
    $expect(detect_payment_ui($streamHtml) === false, 'no payment UI on free streaming page');

    $shopHtml = '<html><title>Buy Shoes Online</title><body>Add to cart. Checkout with PayPal. Contact us 555-0100.</body></html>';
    $p2 = infer_site_expectations('Buy Shoes Online', 'Shop sneakers with free shipping', $shopHtml);
    $expect($p2['category'] === 'commerce', 'shop classified as commerce');
    $expect($p2['expects_payment'] === true, 'shop expects payment');
    $expect($p2['expects_business_contact'] === true, 'shop expects business contact');

    $fin = infer_site_expectations('Daily ROI Crypto', 'Guaranteed investment returns trading broker', '');
    $expect($fin['category'] === 'finance', 'finance classification');
    $expect($fin['expects_payment'] === true, 'finance expects payment');

    // ruleBrief should not go negative from soft hygiene on free_media.
    require_once $root . '/includes/AiAnalyst.php';
    $brief = AiAnalyst::ruleBrief('chillflix.lol', [
        'expects_business_contact' => 0,
        'expects_payment' => 0,
        'site_category' => 'free_media',
        'ssl_valid' => 1,
        'domain_age_days' => 186,
        'domain_age_scope' => 'exact',
    ], [
        ['label' => 'Contact info', 'value' => 'Not found', 'tone' => 'warn'],
        ['label' => 'Phone / WhatsApp', 'value' => 'Not found', 'tone' => 'warn'],
        ['label' => 'Privacy policy', 'value' => 'Not found', 'tone' => 'warn'],
        ['label' => 'Valid SSL', 'value' => 'Yes', 'tone' => 'good'],
        ['label' => 'Malware lists', 'value' => 'Clean', 'tone' => 'good'],
        ['label' => 'Phishing lists', 'value' => 'Clean', 'tone' => 'good'],
    ]);
    $expect($brief['lean'] !== 'negative', 'ruleBrief not negative from soft hygiene on free_media');

    echo "\nResult: $pass passed, $fail failed\n";
    $code = $fail > 0 ? 1 : 0;
} finally {
    if ($createdStub && is_file($config)) {
        @unlink($config);
    }
}

exit($code ?? 1);
