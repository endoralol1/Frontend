<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'www.chillflix.lol';
$_SERVER['REQUEST_URI'] = '/newsite/movie/supergirl/1081003';
$_SERVER['SCRIPT_NAME'] = '/newsite/index.php';
chdir('/var/www/chillflix-newsite/public');
ob_start();
include 'index.php';
$h = ob_get_clean();

$posPlayer = strpos($h, 'id="movie-player"');
$posInfo = strpos($h, 'id="w-info"');
$posLike = strpos($h, 'You may also like');
$posMore = strpos($h, 'More like this');

echo "player=$posPlayer info=$posInfo like=$posLike more=" . var_export($posMore, true) . PHP_EOL;
echo ($posInfo !== false && $posLike !== false && $posInfo < $posLike ? "ORDER_OK\n" : "ORDER_BAD\n");
echo ($posMore === false ? "NO_MORE_LIKE\n" : "HAS_MORE_LIKE\n");
echo (preg_match('/id="movie-player"[\s\S]{0,12000}You may also like/', $h) ? "STILL_BETWEEN_PLAYER\n" : "NOT_BETWEEN_PLAYER\n");
