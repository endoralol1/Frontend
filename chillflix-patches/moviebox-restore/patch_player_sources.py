#!/usr/bin/env python3
"""Restore MovieBox as a local CinePro probe provider in PlayerSources.php."""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

path = Path(sys.argv[1] if len(sys.argv) > 1 else "/var/www/chillflix-newsite/app/Services/PlayerSources.php")
text = path.read_text()

old_local = (
    "$localProviderIds = ['stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', "
    "'hdghar', 'hollybox', 'moviesonlinehd', 'vsembed', 'cineplay', 'vidking', 'vidmoly', "
    "'upcloud', 'byse'];"
)
new_local = (
    "$localProviderIds = ['stremify', 'nxsha', 'castle', 'awsind', 'nitro', 'riveprime', "
    "'hdghar', 'hollybox', 'moviebox', 'moviesonlinehd', 'vsembed', 'cineplay', 'vidking', "
    "'vidmoly', 'upcloud', 'byse'];"
)
if "moviebox', 'moviesonlinehd'" not in text:
    if old_local not in text:
        raise SystemExit("localProviderIds line not found")
    text = text.replace(old_local, new_local, 1)

marker = "            // MoviesOnlineHD — Vuflix-only: hit CinePro probe endpoint directly (not Chillflix allowlist)."
if marker not in text:
    raise SystemExit("MoviesOnlineHD marker not found")

if "if ($provider === 'moviebox')" not in text:
    block = """
            // MovieBox — CinePro provider (moviebox.ph). Was broken when allowlist dropped it
            // and remote /api/cinepro/sources prefetch returned empty. Probe locally + media-proxy.
            if ($provider === 'moviebox') {
                $mb = self::fetchCineproProbeProvider('moviebox', $type, $tmdbId, $season, $episode);
                foreach ($mb['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($mb['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [
                        'Referer' => 'https://moviebox.ph/',
                        'Origin' => 'https://moviebox.ph',
                    ];
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://moviebox.ph/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://moviebox.ph';
                    }
                    $playUrl = $streamUrl;
                    if (class_exists('StremifySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = StremifySources::signedProxyUrl($streamUrl, $cdnHeaders);
                    } elseif (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, false);
                    }
                    $key = 'moviebox|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? 'mp4'),
                        'quality' => $quality,
                        'provider' => 'moviebox',
                        'providerName' => (string) ($src['providerName'] ?? 'MovieBox'),
                        'label' => (string) ($src['label'] ?? ('MovieBox' . ($quality !== '' ? (' · ' . $quality) : ''))),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($mb['ok']) && empty($mb['sources'])) {
                    $diagnostics[] = [
                        'code' => 'MOVIEBOX_EMPTY',
                        'message' => (string) ($mb['error'] ?? 'No MovieBox streams'),
                        'severity' => 'warning',
                        'provider' => 'moviebox',
                    ];
                }
                continue;
            }

"""
    text = text.replace(marker, block + marker, 1)

helper_marker = "    private static function fetchCineproProbeProvider("
if "function unwrapCineproProxyUrl" not in text:
    helper = """
    /**
     * Decode CinePro loopback /v1/proxy?data=... into a direct URL + playback headers.
     *
     * @return array{url:?string,headers:array<string,string>}
     */
    private static function unwrapCineproProxyUrl(string $url): array
    {
        $empty = ['url' => null, 'headers' => []];
        if ($url === '' || !preg_match('#/v1/proxy\\?#i', $url)) {
            return $empty;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $empty;
        }
        parse_str((string) $parts['query'], $qs);
        $raw = $qs['data'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return $empty;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded['url']) || !is_string($decoded['url'])) {
            return $empty;
        }
        $headers = [];
        if (isset($decoded['headers']) && is_array($decoded['headers'])) {
            foreach ($decoded['headers'] as $k => $v) {
                if (is_string($k) && (is_string($v) || is_numeric($v))) {
                    $headers[$k] = (string) $v;
                }
            }
        }
        return ['url' => (string) $decoded['url'], 'headers' => $headers];
    }

"""
    if helper_marker not in text:
        raise SystemExit("fetchCineproProbeProvider marker not found")
    text = text.replace(helper_marker, helper + helper_marker, 1)

path.write_text(text)
r = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
print(r.stdout.strip() or r.stderr.strip())
if r.returncode != 0:
    raise SystemExit(r.returncode)
print("patched", path)
