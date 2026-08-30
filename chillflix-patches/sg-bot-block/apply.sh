#!/usr/bin/env bash
# Apply SG bot block on VPS (run as root on 192.142.46.51 or via ssh).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
CF_WWW=/var/www/chillflix.lol
VF_WWW=/var/www/chillflix-newsite

echo "== nginx zones =="
cp -a /etc/nginx/conf.d/chillflix-bot-soft-zones.conf "/etc/nginx/conf.d/chillflix-bot-soft-zones.conf.bak-sg-$(date +%Y%m%d%H%M%S)"
cp "$ROOT/nginx/chillflix-bot-soft-zones.conf" /etc/nginx/conf.d/chillflix-bot-soft-zones.conf

echo "== chillflix bot-soft snippet =="
cp -a /etc/nginx/snippets/chillflix.lol-bot-soft.conf "/etc/nginx/snippets/chillflix.lol-bot-soft.conf.bak-sg-$(date +%Y%m%d%H%M%S)"
cp "$ROOT/nginx/chillflix.lol-bot-soft.conf" /etc/nginx/snippets/chillflix.lol-bot-soft.conf

echo "== chillflix location / cache headers =="
# Patch sites-enabled chillflix.lol public Cache-Control → CDN no-store
python3 - <<'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-enabled/chillflix.lol")
t = p.read_text()
old = 'add_header Cache-Control "public, max-age=30, stale-while-revalidate=60" always;'
new = '''add_header Cache-Control "private, max-age=0, must-revalidate" always;
        add_header CDN-Cache-Control "no-store" always;
        add_header Cloudflare-CDN-Cache-Control "no-store" always;'''
if old in t:
    p.write_text(t.replace(old, new))
    print("patched chillflix.lol location /")
else:
    print("chillflix.lol location / already patched or missing marker")
PY

echo "== vuflix sg snippet =="
cp "$ROOT/nginx/vuflix-sg-block.conf" /etc/nginx/snippets/vuflix-sg-block.conf
python3 - <<'PY'
from pathlib import Path
p = Path("/etc/nginx/sites-enabled/vuflix.co")
t = p.read_text()
inc = "    include /etc/nginx/snippets/vuflix-sg-block.conf;\n"
if "vuflix-sg-block.conf" in t:
    print("vuflix already includes sg-block")
else:
    # insert after access_log in main HTTPS server
    needle = "    access_log /var/log/nginx/vuflix.access.log;\n"
    if needle not in t:
        raise SystemExit("vuflix access_log needle missing")
    p.write_text(t.replace(needle, needle + inc, 1))
    print("inserted vuflix-sg-block include")
PY

echo "== chillflix app files =="
cp "$ROOT/chillflix/should-skip-analytics.ts" "$CF_WWW/lib/should-skip-analytics.ts"
cp "$ROOT/chillflix/deferred-google-analytics.tsx" "$CF_WWW/components/deferred-google-analytics.tsx"
cp "$ROOT/chillflix/middleware.ts" "$CF_WWW/middleware.ts"
cp "$ROOT/bot-shield.js" "$CF_WWW/public/bot-shield.js"

echo "== vuflix PHP =="
cp "$ROOT/vuflix/SgBotGate.php" "$VF_WWW/app/Services/SgBotGate.php"
cp "$ROOT/bot-shield.js" "$VF_WWW/public/assets/js/bot-shield.js"
python3 - <<'PY'
from pathlib import Path
# Wire SgBotGate into index.php early
idx = Path("/var/www/chillflix-newsite/public/index.php")
t = idx.read_text()
if "SgBotGate" not in t:
    # after bootstrap require
    for needle in [
        "require dirname(__DIR__) . '/app/bootstrap.php';",
        "require __DIR__ . '/../app/bootstrap.php';",
        "require_once dirname(__DIR__) . '/app/bootstrap.php';",
    ]:
        if needle in t:
            t = t.replace(
                needle,
                needle + "\nrequire_once dirname(__DIR__) . '/app/Services/SgBotGate.php';\nSgBotGate::enforce();",
                1,
            )
            idx.write_text(t)
            print("wired SgBotGate into index.php")
            break
    else:
        print("WARN: could not wire SgBotGate — wire manually")
else:
    print("SgBotGate already wired")

# Defer gtag in main.php
main = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
mt = main.read_text()
old = '''    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-GK376SVTPY"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-GK376SVTPY');
    </script>'''
new = '''    <!-- Google Analytics: human-gated (Singapore bot farm was inflating Realtime) -->
    <script>
    (function () {
      var GA_ID = 'G-GK376SVTPY';
      var KEY = 'cf_bot_shield_v1_human';
      var booted = false;
      function boot() {
        if (booted) return;
        booted = true;
        window.dataLayer = window.dataLayer || [];
        window.gtag = function(){ window.dataLayer.push(arguments); };
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
        s.onload = function () {
          window.gtag('js', new Date());
          window.gtag('config', GA_ID);
        };
        document.head.appendChild(s);
      }
      function onGesture(e) {
        if (e && e.isTrusted === false) return;
        try { sessionStorage.setItem(KEY, '1'); } catch (err) {}
        boot();
        window.removeEventListener('pointerdown', onGesture);
        window.removeEventListener('keydown', onGesture);
        window.removeEventListener('touchstart', onGesture);
        window.removeEventListener('scroll', onGesture);
      }
      try { if (sessionStorage.getItem(KEY) === '1') { boot(); return; } } catch (e0) {}
      window.addEventListener('pointerdown', onGesture, { passive: true });
      window.addEventListener('keydown', onGesture, { passive: true });
      window.addEventListener('touchstart', onGesture, { passive: true });
      window.addEventListener('scroll', onGesture, { passive: true });
    })();
    </script>'''
if old in mt:
    mt = mt.replace(old, new, 1)
    mt = mt.replace('bot-shield.js\')) ?>?v=20260825-bs1', 'bot-shield.js\')) ?>?v=20260830-sg1')
    main.write_text(mt)
    print("deferred vuflix gtag")
elif "human-gated" in mt:
    print("vuflix gtag already deferred")
else:
    print("WARN: vuflix gtag block not found")
PY

echo "== nginx test =="
nginx -t
systemctl reload nginx

echo "== rebuild chillflix next (middleware + GA) =="
cd "$CF_WWW"
# Use existing build script if any
if [ -f package.json ]; then
  npm run build
  pm2 restart chillflix --update-env
fi

echo "DONE"
