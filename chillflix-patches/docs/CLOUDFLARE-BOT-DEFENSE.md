# Cloudflare bot defense (replace permanent Under Attack Mode)

## Why Under Attack Mode works (and hurts)

**I'm Under Attack Mode** forces a JS challenge on *every* visitor. Real browsers pass; dumb scrapers die. That’s why bots disappear when you enable it.

When UAM is off, scrapers spoof Chrome and reach origin. Nginx rate limits only slow them — they do not JS-challenge.

You should **not** leave UAM on permanently (hurts SEO, apps, embeds, and first paint). Use the settings below instead.

## Cloudflare dashboard (required)

Zone: `chillflix.lol` → **Security**

1. **Security → Settings → Security level** → `High`  
   Challenges suspicious visitors without challenging everyone.

2. **Security → Bots → Bot Fight Mode** → **On** (Free)  
   Or **Super Bot Fight Mode** if on Pro+:  
   - Definitely automated → **Block** or **Managed Challenge**  
   - Likely automated → **Managed Challenge**  
   - Verified bots → allow (or tightly allowlist Google/Bing only)

3. **Security → Settings → Browser Integrity Check** → **On**

4. **Security → Settings → Challenge Passage** → `30 minutes` (or `1 hour`)

5. **Turn Off** Under Attack Mode once Bot Fight Mode + rules below are on.

## WAF custom rules (best UAM substitute)

**Security → WAF → Custom rules** → Create rule

### Rule A — Managed Challenge on scrape HTML

- Name: `Challenge movie/tv/people scrapers`
- Expression:

```txt
(http.request.uri.path matches "^/(movie|tv|people)/") or
(http.request.uri.path eq "/search") or
(http.request.uri.path matches "^/(movie|tv)/discover")
```

- Action: **Managed Challenge**

### Rule B — Block cheap API scrapers (optional, stronger)

- Name: `Challenge titles/recommendations storms`
- Expression:

```txt
(http.request.uri.path starts_with "/api/media/titles") or
(http.request.uri.path starts_with "/api/recommendations") or
(http.request.uri.path starts_with "/api/similar")
```

- Action: **Managed Challenge** (or Block if abuse continues)

### Rule C — Skip challenges for playback (so streams keep working)

Put this rule **above** A/B with action **Skip** (WAF + Super Bot Fight if available):

```txt
(http.request.uri.path starts_with "/api/cinepro/") or
(http.request.uri.path starts_with "/api/iptv/") or
(http.request.uri.path starts_with "/play/") or
(http.request.uri.path starts_with "/_next/")
```

## Origin backup (already on VPS via nginx patches)

- Block obvious scraper UAs (`python-requests`, Semrush, Ahrefs, etc.)
- Rate-limit browse/scrape paths by `CF-Connecting-IP`
- `410` absurd `?page=` on similar/recommendations

Origin cannot replace CF Managed Challenge for Chrome-spoofing bots. Keep Bot Fight Mode / WAF rules on.

## Quick verify

1. UAM **Off**, Bot Fight Mode **On**, Security Level **High**
2. Open site in a normal browser → should load without interstitial (or rare Managed Challenge)
3. `curl -I https://www.chillflix.lol/movie/123` → challenge/block/403, not a full HTML page storm
4. Watch Security → Events for Managed Challenge / Bot Fight hits

## “Is this really Semrush?”

Usually **no** — UA spoofing is trivial. See **[IDENTIFY-BOT-SOURCES.md](./IDENTIFY-BOT-SOURCES.md)**  
(visitor IP via `/var/log/nginx/chillflix.cf.log` + Cloudflare ASN / reverse DNS).
