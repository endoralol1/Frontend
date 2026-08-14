#!/usr/bin/env python3
"""Fix VidmolySources media-proxy for Sirius/HDGHAR-style disguised TS segments.

Bingr (Sirius) serves MPEG-TS as .jpg with Content-Type: image/jpeg.
With X-Content-Type-Options: nosniff, HLS.js refuses to demux → player stuck at 0:00/0:00.
Also strip dangling SUBTITLES="subs" when no subtitle tracks exist.
"""
from __future__ import annotations

import re
import subprocess
from pathlib import Path

PATH = Path("/var/www/chillflix-newsite/app/Services/VidmolySources.php")


def main() -> None:
    text = PATH.read_text(encoding="utf-8")
    changed = False

    old_ctype = """        if ($ctype !== '') {
            header('Content-Type: ' . $ctype);
        } elseif ($isPlaylist) {
            header('Content-Type: application/vnd.apple.mpegurl');
        }
        if (!empty($result['contentLength'])) {
            header('Content-Length: ' . (string) $result['contentLength']);
        }
        echo $body;
        exit;
    }
"""
    new_ctype = """        // Sirius/HDGHAR CDN lies with image/jpeg for MPEG-TS (.jpg) segments.
        // nosniff + wrong type leaves HLS.js stuck at 0:00.
        $outType = self::demuxerFriendlyContentType($ctype, $url, $body, (bool) $isPlaylist);
        if ($outType !== '') {
            header('Content-Type: ' . $outType);
        }
        if (!empty($result['contentLength'])) {
            header('Content-Length: ' . (string) $result['contentLength']);
        }
        echo $body;
        exit;
    }
"""
    if "demuxerFriendlyContentType($ctype, $url, $body" not in text:
        if old_ctype not in text:
            raise SystemExit("content-type block not found")
        text = text.replace(old_ctype, new_ctype, 1)
        changed = True
        print("patched content-type branch")
    else:
        print("content-type branch already patched")

    abs_fn = "    private static function absolutize(string $maybeRelative, string $base): string"
    ri = text.find("    private static function rewritePlaylist")
    ai = text.find(abs_fn, ri if ri >= 0 else 0)
    if ri < 0 or ai < 0:
        raise SystemExit(f"rewrite/absolutize markers missing ri={ri} ai={ai}")

    rewrite_fn = text[ri:ai]
    if "sanitizeHlsMaster" in rewrite_fn and "function demuxerFriendlyContentType" in text:
        print("helpers already present")
    else:
        # Replace rewritePlaylist return + insert helpers before absolutize.
        m = re.search(
            r"        return implode\(\"(?:\\n)\", \$out\) \. \"(?:\\n)\";\n    }\n\n",
            rewrite_fn,
        )
        if not m:
            # Show debug
            idx = rewrite_fn.find("return implode")
            raise SystemExit(f"return implode not matched; got {rewrite_fn[idx:idx+80]!r}")

        helpers = """        return self::sanitizeHlsMaster(implode("\\n", $out) . "\\n");
    }

    /**
     * CDNs (Sirius/HDGHAR) often label MPEG-TS segments as image/jpeg (.jpg).
     * Force a demuxer-friendly type so HLS.js can play under nosniff.
     */
    private static function demuxerFriendlyContentType(
        string $ctype,
        string $url,
        string $body,
        bool $isPlaylist
    ): string {
        if ($isPlaylist || str_starts_with(ltrim($body), '#EXTM3U')) {
            return 'application/vnd.apple.mpegurl';
        }
        if ($body !== '' && ord($body[0]) === 0x47) {
            return 'video/mp2t';
        }
        if (strlen($body) >= 8) {
            $box = substr($body, 4, 4);
            if (in_array($box, ['ftyp', 'moof', 'mdat', 'styp'], true)) {
                return 'video/mp4';
            }
        }
        $ctype = strtolower(trim($ctype));
        if (
            $ctype === ''
            || str_starts_with($ctype, 'image/')
            || str_contains($ctype, 'octet-stream')
            || str_contains($ctype, 'text/html')
        ) {
            if (preg_match('/\\.(jpg|jpeg|png|html|txt|bin)(\\?|$)/i', $url)) {
                return 'video/mp2t';
            }
        }
        if ($ctype !== '') {
            return $ctype;
        }
        return 'application/octet-stream';
    }

    /**
     * Drop dangling subtitle group refs and prefer English DEFAULT on multi-audio masters.
     */
    private static function sanitizeHlsMaster(string $body): string
    {
        if (!preg_match('/TYPE=SUBTITLES/i', $body)) {
            $body = preg_replace('/,?\\s*SUBTITLES="[^"]*"/i', '', $body) ?? $body;
        }
        if (preg_match('/TYPE=AUDIO/i', $body) && preg_match('/LANGUAGE="English"/i', $body)) {
            $body = preg_replace(
                '/(#EXT-X-MEDIA:TYPE=AUDIO[^\\n]*?)DEFAULT=YES/i',
                '$1DEFAULT=NO',
                $body
            ) ?? $body;
            $body = preg_replace(
                '/(#EXT-X-MEDIA:TYPE=AUDIO[^\\n]*LANGUAGE="English"[^\\n]*?)DEFAULT=NO/i',
                '$1DEFAULT=YES',
                $body,
                1
            ) ?? $body;
        }
        return $body;
    }

"""
        # In the helpers string above, \\n in implode becomes \n in output file — correct for PHP.
        # But for preg_replace PHP patterns we need \\n in the PHP source for regex newline.
        # When this Python string is written:
        #   implode("\\n"  -> file has implode("\n"  ✓
        #   '[^\\n]*?'     -> file has '[^\n]*?'     ✓ for PHP single-quoted regex... 
        # Actually in PHP single-quoted '[^\n]*' the \n is backslash+n not newline — correct for regex.

        new_rewrite = rewrite_fn[: m.start()] + helpers
        text = text[:ri] + new_rewrite + text[ai:]
        changed = True
        print("patched rewritePlaylist + helpers")

    if changed:
        PATH.write_text(text, encoding="utf-8")
        print("wrote", PATH)
    else:
        print("no file changes needed")

    # Sanity: helpers exist and return line uses sanitize
    final = PATH.read_text(encoding="utf-8")
    if "function demuxerFriendlyContentType" not in final:
        raise SystemExit("missing demuxerFriendlyContentType")
    if "sanitizeHlsMaster(implode" not in final:
        raise SystemExit("missing sanitizeHlsMaster call")

    try:
        r = subprocess.run(["php", "-l", str(PATH)], capture_output=True, text=True)
        print((r.stdout or r.stderr).strip())
        if r.returncode != 0:
            raise SystemExit(r.returncode)
    except FileNotFoundError:
        print("php not available locally; skipping lint")
    print("ok")


if __name__ == "__main__":
    main()
