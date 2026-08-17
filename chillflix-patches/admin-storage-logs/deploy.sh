#!/usr/bin/env bash
# Deploy Vuflix + Chillflix admin storage / nginx log tools.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
HOST="${DEPLOY_HOST:-192.142.46.51}"

echo "==> Deploy SiteStorage helper + PHP to Vuflix"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/newsite/bin/site-storage-tool" \
  "root@${HOST}:/usr/local/sbin/site-storage-tool"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/newsite/sudoers.d/vuflix-storage" \
  "root@${HOST}:/etc/sudoers.d/vuflix-storage"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/newsite/app/Services/SiteStorage.php" \
  "root@${HOST}:/var/www/chillflix-newsite/app/Services/SiteStorage.php"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/newsite/app/Views/pages/admin/storage.php" \
  "root@${HOST}:/var/www/chillflix-newsite/app/Views/pages/admin/storage.php"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/newsite/app/Views/pages/admin/dashboard.php" \
  "root@${HOST}:/var/www/chillflix-newsite/app/Views/pages/admin/dashboard.php"

sshpass -e ssh -o StrictHostKeyChecking=no "root@${HOST}" bash -s <<'REMOTE'
set -euo pipefail
chmod 755 /usr/local/sbin/site-storage-tool
chmod 440 /etc/sudoers.d/vuflix-storage
visudo -cf /etc/sudoers.d/vuflix-storage

# bootstrap require
if ! grep -q 'SiteStorage.php' /var/www/chillflix-newsite/app/bootstrap-services.php; then
  sed -i "/require_once __DIR__ \\. '\\/Services\\/SiteAds.php';/a require_once __DIR__ . '/Services/SiteStorage.php';" \
    /var/www/chillflix-newsite/app/bootstrap-services.php
fi

# routes
if ! grep -q "/admin/storage" /var/www/chillflix-newsite/app/routes.php; then
  python3 - <<'PY'
from pathlib import Path
p = Path('/var/www/chillflix-newsite/app/routes.php')
t = p.read_text()
snip = r'''
$router->get('/admin/storage', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/storage', [
        'adminUser' => $user,
        'disk' => SiteStorage::disk(),
        'items' => SiteStorage::inventory(),
        'seo' => ['title' => 'Storage | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/storage', function () {
    Auth::requireRole('admin', 'moderator');
    json_response([
        'ok' => true,
        'disk' => SiteStorage::disk(),
        'items' => SiteStorage::inventory(),
    ]);
});

$router->map(['POST', 'DELETE'], '/api/admin/storage', function () {
    Auth::requireRole('admin', 'moderator');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') {
        json_response(['ok' => false, 'error' => 'id required'], 400);
    }
    $result = SiteStorage::clean($id);
    json_response($result, !empty($result['ok']) ? 200 : 400);
});

$router->get('/api/admin/storage/log', function () {
    Auth::requireRole('admin', 'moderator');
    $name = (string) ($_GET['name'] ?? '');
    $result = SiteStorage::readLog($name);
    json_response($result, !empty($result['ok']) ? 200 : 400);
});

'''
    marker = "$router->get('/admin/ads'"
    if marker not in t:
        raise SystemExit('ads route marker missing')
    t = t.replace(marker, snip + marker, 1)
    p.write_text(t)
    print('routes patched')
PY
fi

# nav link on other admin pages
python3 - <<'PY'
from pathlib import Path
files = list(Path('/var/www/chillflix-newsite/app/Views/pages/admin').glob('*.php'))
old = '''      <a href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/home')) ?>">Site</a>'''
new = '''      <a href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/admin/storage')) ?>">Storage</a>
      <a href="<?= e(url('/home')) ?>">Site</a>'''
old_active = '''      <a class="is-active" href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/home')) ?>">Site</a>'''
new_active = '''      <a class="is-active" href="<?= e(url('/admin/ads')) ?>">Ads</a>
      <a href="<?= e(url('/admin/storage')) ?>">Storage</a>
      <a href="<?= e(url('/home')) ?>">Site</a>'''
for f in files:
    if f.name in ('storage.php', 'dashboard.php'):
        continue
    t = f.read_text()
    if '/admin/storage' in t:
        print('nav ok', f.name)
        continue
    nt = t.replace(old_active, new_active).replace(old, new)
    if nt != t:
        f.write_text(nt)
        print('nav patched', f.name)
    else:
        print('nav skip', f.name)
PY

php -l /var/www/chillflix-newsite/app/Services/SiteStorage.php
php -l /var/www/chillflix-newsite/app/Views/pages/admin/storage.php
php -l /var/www/chillflix-newsite/app/routes.php
# smoke inventory as www-data
sudo -u www-data php -r 'require "/var/www/chillflix-newsite/app/bootstrap-services.php"; $d=SiteStorage::disk(); echo "disk ".$d["usedPercent"]."%\n"; $i=SiteStorage::inventory(); echo "items ".count($i)."\n";'
sudo -u www-data sudo -n /usr/local/sbin/site-storage-tool clean pm2_logs >/tmp/pm2-clean-test.json || true
head -c 200 /tmp/pm2-clean-test.json; echo
echo 'Vuflix deploy OK'
REMOTE

echo "==> Deploy Chillflix storage + nginx log sources"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/chillflix/lib/admin-logs.ts" \
  "root@${HOST}:/var/www/chillflix.lol/lib/admin-logs.ts"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/chillflix/lib/admin-storage.ts" \
  "root@${HOST}:/var/www/chillflix.lol/lib/admin-storage.ts"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/chillflix/components/admin/admin-storage.tsx" \
  "root@${HOST}:/var/www/chillflix.lol/components/admin/admin-storage.tsx"
sshpass -e ssh -o StrictHostKeyChecking=no "root@${HOST}" 'mkdir -p /var/www/chillflix.lol/app/admin/storage /var/www/chillflix.lol/app/api/admin/storage'
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/chillflix/app/admin/storage/page.tsx" \
  "root@${HOST}:/var/www/chillflix.lol/app/admin/storage/page.tsx"
sshpass -e scp -o StrictHostKeyChecking=no \
  "$ROOT/chillflix/app/api/admin/storage/route.ts" \
  "root@${HOST}:/var/www/chillflix.lol/app/api/admin/storage/route.ts"

sshpass -e ssh -o StrictHostKeyChecking=no "root@${HOST}" bash -s <<'REMOTE'
set -euo pipefail
# nav + i18n
python3 - <<'PY'
from pathlib import Path
import json

shell = Path('/var/www/chillflix.lol/components/admin/admin-shell.tsx')
t = shell.read_text()
if '/admin/storage' not in t:
    needle = '''    {
        href: "/admin/logs",
        labelKey: "admin.logs",
        shortLabelKey: "admin.logs",
        icon: HardDrive,
        minRole: "owner",
        group: "platform",
    },'''
    insert = needle + '''
    {
        href: "/admin/storage",
        labelKey: "admin.storage",
        shortLabelKey: "admin.storage",
        icon: Wrench,
        minRole: "owner",
        group: "platform",
    },'''
    if needle not in t:
        raise SystemExit('nav needle missing')
    shell.write_text(t.replace(needle, insert, 1))
    print('shell nav patched')
else:
    print('shell nav already')

msg = Path('/var/www/chillflix.lol/messages/en.json')
d = json.loads(msg.read_text())
admin = d.setdefault('admin', {})
changed = False
if 'storage' not in admin:
    admin['storage'] = 'Storage'
    changed = True
if 'storageDesc' not in admin:
    admin['storageDesc'] = 'Safe disk cleanup'
    changed = True
if changed:
    msg.write_text(json.dumps(d, indent=2, ensure_ascii=False) + '\n')
    print('en.json patched')
else:
    print('en.json ok')
PY

# Rebuild Chillflix so admin routes pick up new pages
cd /var/www/chillflix.lol
echo 'Building Chillflix (this can take a few minutes)...'
# Prefer existing package script if present
if grep -q '"build"' package.json; then
  NODE_OPTIONS='--max-old-space-size=4096' npm run build
fi
pm2 restart chillflix --update-env || pm2 restart chillflix.lol --update-env || true
pm2 save || true
echo 'Chillflix deploy OK'
REMOTE

echo 'ALL DONE'
