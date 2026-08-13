if (!function_exists('cf_resolve_youtube_preview_stream')) {
    function cf_resolve_youtube_preview_stream(string $videoId, bool $quick = false): ?string
    {
        $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId) ?? '';
        if ($videoId === '' || strlen($videoId) < 6 || strlen($videoId) > 20) {
            return null;
        }

        // 1) yt-dlp → clean MP4 (no YouTube player chrome)
        $ytDlp = trim((string) shell_exec('command -v yt-dlp 2>/dev/null'));
        if ($ytDlp !== '') {
            $watch = 'https://www.youtube.com/watch?v=' . $videoId;
            $wait = $quick ? 6 : 8;
            $cmd = 'timeout ' . $wait . ' ' . escapeshellarg($ytDlp)
                . ' -f ' . escapeshellarg('best[height<=480][ext=mp4]/best[height<=480]/best[ext=mp4]/best')
                . ' -g --no-warnings --no-playlist '
                . escapeshellarg($watch) . ' 2>/dev/null';
            $lines = [];
            $code = 1;
            @exec($cmd, $lines, $code);
            if ($code === 0 && !empty($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line !== '' && preg_match('#^https?://#i', $line)) {
                        return $line;
                    }
                }
            }
        }

        // Piped disabled: often returns unusable proxy URLs for unavailable YouTube videos
        return null;
        if ($quick) {
            return null;
        }
        $instances = [
            'https://api.piped.private.coffee',
            'https://pipedapi.adminforge.de',
        ];
        foreach ($instances as $base) {
            $url = rtrim($base, '/') . '/streams/' . rawurlencode($videoId);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: VuflixPreview/1.1'],
            ]);
            $body = curl_exec($ch);
====PREVIEW====
    }

    $item = $tmdb->details($type, $id);
    if (!$item) {
        json_response(['error' => 'not_found'], 404);
    }

    $trailerKeys = array_slice($tmdb->trailerKeys($item), 0, 12);
    $trailer = $trailerKeys[0] ?? null;
    $stream = null;
    foreach ($trailerKeys as $i => $tryKey) {
        $resolved = cf_resolve_youtube_preview_stream($tryKey, $i > 0);
        if (is_string($resolved) && $resolved !== '') {
            $trailer = $tryKey;
            $stream = $resolved;
            break;
        }
    }

    $logoUrl = null;
    try {
        $logoLangs = class_exists('Locale') ? Locale::logoImageLanguages() : 'en,null';
        $imgs = $tmdb->get($type . '/' . $id . '/images', [
            'include_image_language' => $logoLangs,
        ]);
        $logoPath = $tmdb->pickPreferredLogo(
