<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'www.chillflix.lol';
$_SERVER['REQUEST_URI'] = '/newsite/home';
$_SERVER['SCRIPT_NAME'] = '/newsite/index.php';
chdir('/var/www/chillflix-newsite/public');
ob_start();
include 'index.php';
$h = ob_get_clean();
echo 'LEN=' . strlen($h) . PHP_EOL;
foreach ([
    'hero-logo',
    'hero-badges',
    'hero-badge-today',
    'hero-badge-hot',
    'hero-badge-new',
    'Newly launched',
    'HOT',
    'Today',
] as $n) {
    echo (str_contains($h, $n) ? 'OK' : 'MISS') . " $n" . PHP_EOL;
}
if (preg_match('/hero-logo[\s\S]{0,200}<img[^>]+src="([^"]+)"/', $h, $m)) {
    echo 'LOGO_SRC=' . $m[1] . PHP_EOL;
}
if (preg_match('/hero-badge-rank">([^<]+)</', $h, $m)) {
    echo 'RANK=' . $m[1] . PHP_EOL;
}
