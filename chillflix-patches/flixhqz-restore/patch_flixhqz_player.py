#!/usr/bin/env python3
"""Wire Flixhqz as local CinePro probe + HLS media-proxy (like VSEmbed)."""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

path = Path(sys.argv[1] if len(sys.argv) > 1 else "/var/www/chillflix-newsite/app/Services/PlayerSources.php")
text = path.read_text()

old_local = (
    "$localProviderIds = ['stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', "
    "'hdghar', 'hollybox', 'moviebox', 'moviesonlinehd', 'vsembed', 'cineplay', 'vidking', "
    "'vidmoly', 'upcloud', 'byse'];"
)
new_local = (
    "$localProviderIds = ['stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', "
    "'hdghar', 'hollybox', 'moviebox', 'flixhqz', 'moviesonlinehd', 'vsembed', 'cineplay', "
    "'vidking', 'vidmoly', 'upcloud', 'byse'];"
)
if "flixhqz', 'moviesonlinehd'" not in text and "flixhqz" not in text.split("localProviderIds", 1)[1][:400]:
    if old_local not in text:
        raise SystemExit("localProviderIds line not found / unexpected")
    text = text.replace(old_local, new_local, 1)
elif "flixhqz', 'moviesonlinehd'" not in text:
    # try insert after moviebox if present
    if "'moviebox', 'moviesonlinehd'" in text:
        text = text.replace("'moviebox', 'moviesonlinehd'", "'moviebox', 'flixhqz', 'moviesonlinehd'", 1)
    else:
        raise SystemExit("could not update localProviderIds")

if "if ($provider === 'flixhqz')" not in text:
    marker = "            // MovieBox — CinePro provider (moviebox.ph). Was broken when allowlist dropped it"
    if marker not in text:
        # insert before MoviesOnlineHD if MovieBox marker moved
        marker = "            // MoviesOnlineHD — Vuflix-only: hit CinePro probe endpoint directly (not Chillflix allowlist)."
    block = r'''
            // Flixhqz — CinePro scraper (flixhqz.com → IP-bound HLS). Not on allowlist, so remote
            // /api/cinepro/sources prefetch returns empty; probe locally + rewrite via media-proxy.
            if ($provider === 'flixhqz') {
                $fz = self::fetchCineproProbeProvider('flixhqz', $type, $tmdbId, $season, $episode);
                foreach ($fz['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($fz['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Referer' => 'https://cloudorchestranova.com/',
                            'Origin' => 'https://cloudorchestranova.com',
                        ];
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl);
                    // CDN JWTs are IP-bound to the VPS — must rewrite playlists for browser play.
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'flixhqz|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $lang = trim((string) ($src['language'] ?? ''));
                    if ($lang === '') {
                        $qLower = strtolower($quality);
                        if (str_contains($qLower, 'english') || $qLower === 'en') {
                            $lang = 'en';
                        } elseif ($qLower !== '' && $qLower !== 'auto' && $qLower !== 'unknown') {
                            $lang = $qLower;
                        } else {
                            $lang = 'en';
                        }
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'flixhqz',
                        'providerName' => (string) ($src['providerName'] ?? 'Flixhqz'),
                        'label' => (string) ($src['label'] ?? ('Flixhqz' . ($quality !== '' ? (' · ' . $quality) : ''))),
                        'language' => $lang,
                        'hasEnglish' => $lang === 'en' || str_contains(strtolower($quality), 'english'),
                        'audioTracks' => [],
                    ];
                }
                if (empty($fz['ok']) && empty($fz['sources'])) {
                    $diagnostics[] = [
                        'code' => 'FLIXHQZ_EMPTY',
                        'message' => (string) ($fz['error'] ?? 'No Flixhqz streams'),
                        'severity' => 'warning',
                        'provider' => 'flixhqz',
                    ];
                }
                continue;
            }

'''
    if marker not in text:
        raise SystemExit("insert marker not found")
    text = text.replace(marker, block + marker, 1)

path.write_text(text)
r = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
print(r.stdout.strip() or r.stderr.strip())
if r.returncode != 0:
    raise SystemExit(r.returncode)
print("patched", path)
