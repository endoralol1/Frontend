        'nextEpisode' => $nextEpisode,
        'extraCss' => [
            asset('css/player.css') . '?v=20260816-theme1',
            asset('css/watch-player.css') . '?v=20260816-watch-footer1',
        ],
        'extraJs' => [
            'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js',
            asset('js/player.js') . '?v=20260821-rescan1',
        ],
        'bodyClass' => 'page-watch',
        'headerClass' => 'absolute',
