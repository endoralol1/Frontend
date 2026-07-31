#!/usr/bin/env python3
import re
import urllib.request

req = urllib.request.Request(
    "http://127.0.0.1:3000/",
    headers={"Host": "www.chillflix.lol", "User-Agent": "Mozilla/5.0"},
)
h = urllib.request.urlopen(req, timeout=30).read().decode("utf-8", "ignore")
i = h.find("home-hero-badges")
print("BADGES_SNIPPET")
print(h[i : i + 900] if i >= 0 else "missing")
print("PNGS")
print(re.findall(r"https://[^\"']+\.png", h)[:10])
print("LOGO_CLASS")
j = h.find("drop-shadow-[0_4px_18px")
print(h[max(0, j - 200) : j + 250] if j >= 0 else "no drop-shadow class")
