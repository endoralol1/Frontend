# How to see where bots really come from

## Short answer

**User-Agent is not identity.** Anyone can send `SemrushBot` in the header.  
Real source = **visitor IP** (`CF-Connecting-IP`) → reverse DNS / ASN — not the UA string.

On this origin, the default nginx `access.log` only shows **Cloudflare edge IPs** (`104.x`, `172.64.x`, …). Those are Cloudflare PoPs, **not** the bot owner.

## What we already know about the “Semrush” wave

From recent logs (~30k `SemrushBot` hits):

- Paths look like a scraper, not SEO crawl: `/movie/discover`, `/tv/.../watch`, `/similar`, `/api/music/detect-artist`
- Real SemrushBot usually hits content URLs + `robots.txt` and comes from IPs whose reverse DNS is `*.bot.semrush.com`
- Until visitor logging was enabled, every “Semrush” line was a Cloudflare edge — so Semrush could not be confirmed

**Most likely:** someone (or a rented scraper farm) **spoofed** the Semrush UA. That is common.

## Where to look (in order)

### 1) New origin log (visitor IP + country)

File on VPS: `/var/log/nginx/chillflix.cf.log`

Format:

```text
visitor_ip|cf_edge_ip|time|method|uri|status|bytes|referer|user_agent|cf_country|cf_ray
```

Useful commands:

```bash
# Who is claiming to be Semrush right now?
grep -i SemrushBot /var/log/nginx/chillflix.cf.log | awk -F'|' '{print $1,$10,$5}' | sort | uniq -c | sort -rn | head

# Top visitor IPs overall
awk -F'|' '{print $1}' /var/log/nginx/chillflix.cf.log | sort | uniq -c | sort -rn | head

# Top countries
awk -F'|' '{print $10}' /var/log/nginx/chillflix.cf.log | sort | uniq -c | sort -rn | head
```

### 2) Prove / disprove Semrush on an IP

```bash
IP=1.2.3.4
host "$IP"          # expect *.bot.semrush.com for real Semrush
# then forward-confirm:
host "$(host "$IP" | awk '/pointer/{print $5}')"
```

- PTR ends with `bot.semrush.com` **and** forwards back to same IP → real Semrush  
- Anything else (hosting, mobile, random VPS, no PTR) → **fake UA**

Known Semrush-ish nets (examples, not complete): `85.208.96.0/24`, `185.191.171.0/24` (AS209366 SEMrush).

### 3) Cloudflare dashboard (best ASN / bot view)

Zone `chillflix.lol`:

1. **Security → Events** — filter by User-Agent / path / action  
2. **Analytics → Traffic** / **Security** — top **ASNs**, countries, paths  
3. Click a request → see **Client IP**, **ASN**, **Bot score** (if Bot Fight / Bot Management is on), **JA3/JA4** when available  

That is the fastest way to answer “which network is blasting me?”

### 4) Optional: block by ASN once you know it

In Cloudflare WAF (example):

```txt
(ip.src.asnum eq 123456)
```

Action: Block or Managed Challenge.

Or challenge any non-verified Semrush UA:

```txt
(http.user_agent contains "SemrushBot" and not cf.bot_management.verified_bot)
```

(Requires Bot Management / Super Bot Fight fields; on Free use Bot Fight Mode + UA block at origin — already blocking Semrush UA at nginx.)

## Enable visitor log on a new server

1. Install `chillflix-patches/nginx/chillflix-log-format.conf` → `/etc/nginx/conf.d/`
2. In the `www.chillflix.lol` server block:

```nginx
access_log /var/log/nginx/chillflix.cf.log chillflix_cf;
```

3. `nginx -t && systemctl reload nginx`
