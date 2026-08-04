<?php
declare(strict_types=1);

/**
 * Cineplay / Vidking embed sources.
 *
 * Cineplay does not host HLS on their own domain. Their player (and their
 * public Vidking embed API linked from cineplay.to → "API") loads streams
 * in the browser via Videasy/speedracelight, where server "Yoru"
 * (`cdn/sources-with-title`) is the 4K source.
 *
 * Server-side scraping of that API is CF-blocked from our VPS. The supported
 * Cineplay path for third-party sites is the Vidking iframe embed, which
 * runs the same Yoru/4K stack on the viewer's IP.
 */
final class CineplaySources
{
    public const EMBED_ORIGIN = 'https://www.vidking.net';

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid tmdbId', 'sources' => [], 'diagnostics' => []];
        }

        $qs = [
            'autoPlay' => 'true',
            'color' => 'e50914',
        ];
        if ($type === 'tv') {
            $qs['nextEpisode'] = 'true';
            $qs['episodeSelector'] = 'true';
            $path = '/embed/tv/' . $tmdbId . '/' . max(1, $season) . '/' . max(1, $episode);
        } else {
            $path = '/embed/movie/' . $tmdbId;
        }

        $url = self::EMBED_ORIGIN . $path . '?' . http_build_query($qs);

        return [
            'ok' => true,
            'sources' => [
                [
                    'url' => $url,
                    'type' => 'iframe',
                    'quality' => '4K',
                    'provider' => 'cineplay',
                    'providerName' => 'Cineplay',
                    'label' => 'Cineplay · Yoru 4K',
                    'language' => 'en',
                    'meta' => [
                        'via' => 'vidking',
                        'server' => 'Yoru',
                        'note' => 'Cineplay Vidking embed; pick Yoru inside player for 4K',
                    ],
                ],
            ],
            'diagnostics' => [
                [
                    'code' => 'CINEPLAY_EMBED',
                    'message' => 'Using Cineplay Vidking embed (Yoru = 4K). Select Yoru in the embedded player.',
                    'severity' => 'info',
                    'provider' => 'cineplay',
                ],
            ],
        ];
    }
}
