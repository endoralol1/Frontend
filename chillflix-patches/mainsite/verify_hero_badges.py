#!/usr/bin/env python3
import re
import urllib.request

req = urllib.request.Request(
    "http://127.0.0.1:3000/",
    headers={"Host": "www.chillflix.lol", "User-Agent": "Mozilla/5.0"},
)
with urllib.request.urlopen(req, timeout=30) as res:
    h = res.read().decode("utf-8", "ignore")

print("len", len(h))
for n in [
    "home-hero-badges",
    "home-hero-badge-today",
    "home-hero-badge-hot",
    "home-hero-badge-new",
    "Newly launched",
    "HOT",
    "Today",
]:
    print(("OK" if n in h else "MISS"), n)

m = re.search(r'src="(https://[^"]+)"[^>]*(?:logo|alt=)|(?:logo)[^>]*src="(https://[^"]+)"', h, re.I)
# Prefer wsrv/tmdb logo paths near home-hero
logos = re.findall(r'https://[^"\']+(?:/t/p/|wsrv\.nl)[^"\']+', h)
print("logo_candidates", len(logos))
if logos:
    print("logo0", logos[0][:160])

m2 = re.search(r"home-hero-badge-rank[^>]*>\s*#?(\d+)", h)
print("rank", m2.group(1) if m2 else None)
