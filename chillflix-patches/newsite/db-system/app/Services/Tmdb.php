<?php
declare(strict_types=1);

final class Tmdb
{
    private string $key;
    private string $base;
    private string $lang;
    private int $ttl;
    private string $cacheDir;

    public function __construct()
    {
        $this->key = (string) config('tmdb_api_key');
        $this->base = rtrim((string) config('tmdb_base'), '/');
        $this->lang = (string) config('tmdb_lang', 'en-US');
        $this->ttl = (int) config('cache_ttl', 0);
        $this->cacheDir = (string) config('cache_dir');
        if ($this->ttl > 0 && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public function get(string $endpoint, array $query = []): ?array
    {
        $query = array_merge(['api_key' => $this->key, 'language' => $this->lang], $query);
        $url = $this->base . '/' . ltrim($endpoint, '/') . '?' . http_build_query($query);
        $cacheKey = md5($url);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';

        if ($this->ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->ttl) {
            $raw = file_get_contents($cacheFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            return is_array($data) ? $data : null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 12,
                'header'  => "Accept: application/json\r\nUser-Agent: FMovies/1.0\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if ($this->ttl > 0) {
            @file_put_contents($cacheFile, $raw, LOCK_EX);
        }
        return $data;
    }

    public function trending(string $media = 'movie', string $window = 'week'): array
    {
        return $this->get("trending/{$media}/{$window}")['results'] ?? [];
    }

    public function discover(string $type, array $params = []): array
    {
        $endpoint = $type === 'tv' ? 'discover/tv' : 'discover/movie';
        return $this->get($endpoint, $params) ?? ['results' => [], 'total_pages' => 1, 'total_results' => 0, 'page' => 1];
    }

    public function search(string $query, int $page = 1): array
    {
        return $this->get('search/multi', [
            'query' => $query,
            'page' => $page,
            'include_adult' => 'false',
        ]) ?? ['results' => []];
    }

    public function details(string $type, int $id): ?array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        return $this->get("{$type}/{$id}", [
            'append_to_response' => 'videos,credits,similar,recommendations,external_ids,content_ratings,release_dates',
        ]);
    }

    public function person(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return $this->get("person/{$id}", [
            'append_to_response' => 'combined_credits,external_ids,images',
        ]);
    }

    public function topRated(string $type, int $page = 1): array
    {
        $endpoint = $type === 'tv' ? 'tv/top_rated' : 'movie/top_rated';
        return $this->get($endpoint, ['page' => $page]) ?? ['results' => []];
    }

    
    /**
     * Prefer English / language-neutral title logos (matches main Chillflix hero).
     */
    public function pickPreferredLogo(?array $logos): ?string
    {
        if (!$logos) {
            return null;
        }
        $score = static function (array $logo): float {
            return ((float) ($logo['vote_average'] ?? 0)) * 1000 + ((float) ($logo['vote_count'] ?? 0));
        };
        $pick = static function (array $list) use ($score): ?array {
            if (!$list) {
                return null;
            }
            usort($list, static fn ($a, $b) => $score($b) <=> $score($a));
            return $list[0] ?? null;
        };
        $en = [];
        $neutral = [];
        foreach ($logos as $logo) {
            $lang = $logo['iso_639_1'] ?? null;
            if ($lang === 'en') {
                $en[] = $logo;
            } elseif ($lang === null || $lang === '') {
                $neutral[] = $logo;
            }
        }
        $best = $pick($en) ?? $pick($neutral) ?? $pick($logos);
        $path = $best['file_path'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Enrich trending slides with logo, runtime, and status badges for the home hero.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function enrichHeroSlides(array $items, int $limit = 5): array
    {
        $out = [];
        $rank = 0;
        foreach (array_slice($items, 0, $limit) as $item) {
            $rank++;
            $id = (int) ($item['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $details = $this->get("movie/{$id}", [
                'append_to_response' => 'images',
                'include_image_language' => 'en,null',
            ]) ?? [];
            $release = (string) ($details['release_date'] ?? $item['release_date'] ?? '');
            $isNew = false;
            if ($release !== '') {
                $ts = strtotime($release . ' UTC');
                if ($ts !== false) {
                    $diffDays = (time() - $ts) / 86400;
                    $isNew = $diffDays >= -21 && $diffDays <= 45;
                }
            }
            $item['logo_path'] = $this->pickPreferredLogo($details['images']['logos'] ?? null);
            $item['runtime'] = (int) ($details['runtime'] ?? 0);
            $item['trending_rank'] = $rank;
            $item['is_hot'] = $rank <= 5 || ((float) ($item['popularity'] ?? 0)) >= 120;
            $item['is_newly_launched'] = $isNew;
            if (!empty($details['overview'])) {
                $item['overview'] = $details['overview'];
            }
            if (!empty($details['backdrop_path'])) {
                $item['backdrop_path'] = $details['backdrop_path'];
            }
            $out[] = $item;
        }
        return $out;
    }

    public function trailerKey(?array $details): ?string
    {
        $videos = $details['videos']['results'] ?? [];
        $best = null;
        foreach ($videos as $v) {
            if (($v['site'] ?? '') !== 'YouTube') {
                continue;
            }
            $type = $v['type'] ?? '';
            if ($type === 'Trailer') {
                return $v['key'];
            }
            if ($best === null && in_array($type, ['Teaser', 'Clip', 'Featurette'], true)) {
                $best = $v['key'];
            }
        }
        return $best;
    }

    /**
     * Currently-airing shows enriched with last_episode_to_air for homepage rails.
     * Assembled list is cached once so cold loads don't repeat N detail fetches every request.
     *
     * @return list<array{id:int,title:string,poster_path:?string,backdrop_path:?string,vote_average:float|null,season:int,episode:int,episode_name:string,air_date:string,still_path:?string}>
     */
    public function latestEpisodes(int $limit = 12): array
    {
        $limit = max(1, min(24, $limit));
        $bundleKey = md5('latest_episodes_v1_' . $limit . '_' . $this->lang);
        $bundleFile = $this->cacheDir . '/bundle_' . $bundleKey . '.json';

        if ($this->ttl > 0 && is_file($bundleFile) && (time() - filemtime($bundleFile)) < $this->ttl) {
            $raw = file_get_contents($bundleFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }

        $shows = $this->get('tv/on_the_air', ['page' => 1])['results'] ?? [];
        $out = [];
        foreach ($shows as $show) {
            if (count($out) >= $limit) {
                break;
            }
            $id = (int) ($show['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $details = $this->get("tv/{$id}");
            $ep = is_array($details) ? ($details['last_episode_to_air'] ?? null) : null;
            if (!is_array($ep) || empty($ep['season_number']) || empty($ep['episode_number'])) {
                continue;
            }
            $title = (string) ($show['name'] ?? $details['name'] ?? 'Untitled');
            $out[] = [
                'id' => $id,
                'title' => $title,
                'poster_path' => $show['poster_path'] ?? ($details['poster_path'] ?? null),
                'backdrop_path' => $show['backdrop_path'] ?? ($details['backdrop_path'] ?? null),
                'vote_average' => isset($show['vote_average']) ? (float) $show['vote_average'] : null,
                'season' => (int) $ep['season_number'],
                'episode' => (int) $ep['episode_number'],
                'episode_name' => (string) ($ep['name'] ?? ('Episode ' . (int) $ep['episode_number'])),
                'air_date' => (string) ($ep['air_date'] ?? ''),
                'still_path' => $ep['still_path'] ?? null,
            ];
        }

        if ($this->ttl > 0 && $out) {
            @file_put_contents($bundleFile, json_encode($out), LOCK_EX);
        }

        return $out;
    }
}
