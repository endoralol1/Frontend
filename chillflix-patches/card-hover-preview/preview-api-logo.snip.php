
    $trailer = $tmdb->trailerKey($item);
    $stream = null;
    if (is_string($trailer) && $trailer !== '') {
        $stream = cf_resolve_youtube_preview_stream($trailer);
    }

    $logoUrl = null;
    try {
        $logoLangs = class_exists('Locale') ? Locale::logoImageLanguages() : 'en,null';
        $imgs = $tmdb->get($type . '/' . $id . '/images', [
            'include_image_language' => $logoLangs,
        ]);
        $logoPath = $tmdb->pickPreferredLogo(
            is_array($imgs) ? ($imgs['logos'] ?? null) : null,
            class_exists('Locale') ? Locale::iso639() : 'en'
        );
        if (is_string($logoPath) && $logoPath !== '') {
            $logoUrl = img_url($logoPath, 'w500');
        }
    } catch (Throwable $e) {
        $logoUrl = null;
    }

    $payload = [
        'id' => $id,
        'type' => $type,
        'title' => title_of($item),
        'year' => year_of(date_of($item)),
        'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
        'overview' => truncate((string) ($item['overview'] ?? ''), 140),
        'url' => media_url($type, $id, title_of($item)),
        'poster' => img_url($item['poster_path'] ?? null, 'w500'),
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w780'),
        'logo' => $logoUrl,
        'trailer' => $trailer,
        // Same-origin proxy — raw googlevideo URLs are IP-bound to the server
        'stream' => $stream ? url('/api/preview-media/' . $type . '/' . $id) : null,
        'has_stream' => (bool) $stream,
    ];
    // Keep raw upstream URL server-side for the proxy
    if ($stream) {
        @file_put_contents($cacheDir . '/' . $type . '_' . $id . '.upstream', $stream);
    }
    // Only cache successful stream lookups long-term; short-cache misses
    $ttlOk = !empty($payload['stream']);
    if ($ttlOk || !is_file($cacheFile) || (time() - filemtime($cacheFile)) > 120) {
        @file_put_contents($cacheFile, json_encode($payload));
    }
    json_response($payload);
});


$router->get('/api/preview-media/{type}/{id}', function (array $p) use ($tmdb) {
    $type = ($p['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($p['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        exit;
    }

    // Drop any prior output so we can stream clean binary
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

