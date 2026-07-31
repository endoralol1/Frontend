<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'www.chillflix.lol';
$_SERVER['REQUEST_URI'] = '/newsite/movie/supergirl/1081003';
$_SERVER['SCRIPT_NAME'] = '/newsite/index.php';
chdir('/var/www/chillflix-newsite/public');
ob_start();
include '/var/www/chillflix-newsite/public/index.php';
$h = ob_get_clean();
echo 'LEN=' . strlen($h) . PHP_EOL;
foreach ([
    'You may also like',
    'More like this',
    'watch-cast',
    'cast-row',
    'genre-links',
    'Report an issue',
    'Comments coming soon',
    'player-trailer-label',
    'Milly Alcock',
    '"@type": "Person"',
    'with_genres',
] as $n) {
    echo (str_contains($h, $n) ? 'OK' : 'MISS') . " $n" . PHP_EOL;
}
if (preg_match('/application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $h, $m)) {
    echo "SCHEMA_START\n" . $m[1] . "\nSCHEMA_END\n";
}
