<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= e($seo['title'] ?? (config('site_name') . ' Player')) ?></title>
    <meta name="robots" content="<?= e($seo['robots'] ?? 'noindex, follow') ?>">
    <meta name="theme-color" content="#07080c">
    <link rel="icon" href="<?= e(logo_url()) ?>" type="image/webp">
    <link rel="stylesheet" href="<?= e(asset('css/player.css')) ?>?v=20260804-cineplay2">
    <script>
        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode((string) config('site_name'), JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), JSON_UNESCAPED_SLASHES) ?>
        };
        window.PLAYER = <?= json_encode([
            'type' => $type,
            'id' => (int) $item['id'],
            'title' => $title,
            'year' => $year ?? '',
            'poster' => $poster ?? '',
            'backdrop' => $backdrop ?? '',
            'backUrl' => $backUrl ?? url('/home'),
            'season' => (int) ($season ?? 1),
            'episode' => (int) ($episode ?? 1),
            'next' => $nextEpisode ?? null,
            'sourcesApi' => url('/api/player/sources'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body class="page-player">
<?php require $content; ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js" defer></script>
<script src="<?= e(asset('js/player.js')) ?>?v=20260804-cineplay4" defer></script>
</body>
</html>
