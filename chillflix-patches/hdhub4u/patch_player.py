#!/usr/bin/env python3
from pathlib import Path
import re

p = Path("/var/www/chillflix-newsite/public/assets/js/player.js")
t = p.read_text()
if "hdhub4u" in t:
    print("already has hdhub4u", t.count("hdhub4u"))
    raise SystemExit(0)

bak = Path(str(p) + ".bak-hdhub4u")
bak.write_text(t)

m = re.search(r"const slowProviders = new Set\(\[([^\]]*)\]\)", t)
if m and "hdhub4u" not in m.group(1):
    inner = m.group(1)
    if '"yesmovies"' in inner:
        inner2 = inner.replace('"yesmovies"', '"yesmovies",\n        "hdhub4u"')
    else:
        inner2 = inner.rstrip() + ',\n        "hdhub4u"'
    t = t[: m.start(1)] + inner2 + t[m.end(1) :]
    print("slowProviders ok")

old = (
    'pid === "yesmovies" || pid === "onlyflix" || pid === "moviebox" '
    '|| pid === "megasource" || pid === "vaplayer"'
)
new = (
    'pid === "yesmovies" || pid === "hdhub4u" || pid === "onlyflix" || pid === "moviebox" '
    '|| pid === "megasource" || pid === "vaplayer"'
)
if old in t:
    t = t.replace(old, new)
    print("viaProxy ok")
elif 'pid === "fzmovies"' in t and 'hdhub4u' not in t:
    # alternate pattern including fzmovies
    old2 = (
        'pid === "yesmovies" || pid === "onlyflix" || pid === "moviebox" '
        '|| pid === "megasource" || pid === "vaplayer"'
    )
    # try with fzmovies nearby
    pass

# Also extend file-source list that includes fzmovies if present
old3 = 'pid === "fzmovies" || pid === "yesmovies"'
if old3 in t:
    t = t.replace(old3, 'pid === "fzmovies" || pid === "hdhub4u" || pid === "yesmovies"')
    print("fzmovies chain ok")

p.write_text(t)
print("done matches", p.read_text().count("hdhub4u"))
