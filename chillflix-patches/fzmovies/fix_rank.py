from pathlib import Path
import sys

paths = sys.argv[1:] or ["/var/www/chillflix-newsite/app/Services/FzMoviesSources.php"]
old = """    private static function qualityRank(string $q): int
    {
        if (preg_match('/\\b(2160|4k|uhd)\\b/i', $q)) {
            return 2160;
        }
        if (preg_match('/\\b1080\\b/i', $q)) {
            return 1080;
        }
        if (preg_match('/\\b720\\b/i', $q)) {
            return 720;
        }
        if (preg_match('/\\b480\\b/i', $q)) {
            return 480;
        }
        if (preg_match('/\\b360\\b/i', $q)) {
            return 360;
        }
        return 0;
    }
"""
new = """    private static function qualityRank(string $q): int
    {
        if (preg_match('/\\b(2160p?|4k|uhd)\\b/i', $q)) {
            return 2160;
        }
        if (preg_match('/\\b1080p?\\b/i', $q)) {
            return 1080;
        }
        if (preg_match('/\\b720p?\\b/i', $q)) {
            return 720;
        }
        if (preg_match('/\\b480p?\\b/i', $q)) {
            return 480;
        }
        if (preg_match('/\\b360p?\\b/i', $q)) {
            return 360;
        }
        return 0;
    }
"""
for path in paths:
    p = Path(path)
    t = p.read_text()
    if old not in t:
        if "1080p?" in t and "qualityRank" in t:
            print(path, "already fixed?")
            continue
        raise SystemExit(f"block missing in {path}")
    p.write_text(t.replace(old, new, 1))
    print(path, "fixed")
