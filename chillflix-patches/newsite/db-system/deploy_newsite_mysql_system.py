#!/usr/bin/env python3
"""Install newsite MySQL schema, auth/sync APIs, sources control, and admin panel."""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
from datetime import datetime
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
APP = ROOT / "app"
ASSET_V = "20260801-ui88"
HERE = Path(__file__).resolve().parent

DB_PASS = os.environ.get("NS_DB_PASS", "")
AUTH_SECRET = os.environ.get("NS_AUTH_SECRET", "")
TURNSTILE_SECRET = os.environ.get("NS_TURNSTILE_SECRET", "0x4AAAAAADkt69d2CxrD59E7-eZ8HBwkOJ0")
ADMIN_EMAIL = os.environ.get("NS_ADMIN_EMAIL", "admin@chillflix.lol")
ADMIN_PASS = os.environ.get("NS_ADMIN_PASS", "")
ADMIN_NAME = os.environ.get("NS_ADMIN_NAME", "Admin")


def run(cmd: list[str]) -> str:
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit(f"cmd failed: {' '.join(cmd)}\n{r.stderr}\n{r.stdout}")
    return r.stdout


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def patch_config() -> None:
    cfg_path = APP / "config.php"
    text = cfg_path.read_text()
    if "'db'" in text and "newsite" in text:
        # refresh password block
        text = re.sub(
            r"'db'\s*=>\s*\[[\s\S]*?\],\n",
            "",
            text,
            count=1,
        )
    insert = f"""
    // Newsite MySQL (accounts, favorites, continue watching, sources)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'newsite',
        'user' => 'newsite',
        'password' => {json.dumps(DB_PASS)},
        'charset' => 'utf8mb4',
    ],
    'auth_secret' => {json.dumps(AUTH_SECRET)},
    'turnstile_secret_key' => {json.dumps(TURNSTILE_SECRET)},

"""
    # insert after turnstile_site_key line if present
    if "turnstile_site_key" in text:
        text = re.sub(
            r"('turnstile_site_key'\s*=>\s*'[^']*',\n)",
            r"\1" + insert,
            text,
            count=1,
        )
    else:
        text = text.replace("return [", "return [" + insert, 1)
    cfg_path.write_text(text)
    print("config.php db + secrets")


def patch_helpers() -> None:
    path = APP / "helpers.php"
    text = path.read_text()
    old = """function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg;
    $cfg ??= require __DIR__ . '/config.php';
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}"""
    new = """function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg;
    $cfg ??= require __DIR__ . '/config.php';
    if ($key !== null && isset($GLOBALS['__cf_config_override']) && is_array($GLOBALS['__cf_config_override'])
        && array_key_exists($key, $GLOBALS['__cf_config_override'])) {
        return $GLOBALS['__cf_config_override'][$key];
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}"""
    if "json_body" not in text:
        if old in text:
            text = text.replace(old, new, 1)
        else:
            text = text.replace(
                "return $cfg[$key] ?? $default;\n}",
                """if ($key !== null && isset($GLOBALS['__cf_config_override']) && is_array($GLOBALS['__cf_config_override'])
        && array_key_exists($key, $GLOBALS['__cf_config_override'])) {
        return $GLOBALS['__cf_config_override'][$key];
    }
    return $cfg[$key] ?? $default;
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}""",
                1,
            )
    path.write_text(text)
    print("helpers.php patched")


def patch_bootstrap() -> None:
    path = APP / "bootstrap.php"
    text = path.read_text()
    needs = [
        "Database.php",
        "Auth.php",
        "UserData.php",
        "SourcesService.php",
    ]
    for name in needs:
        line = f"require_once __DIR__ . '/Services/{name}';"
        if line not in text:
            text = text.replace(
                "require_once __DIR__ . '/Services/PlayerSources.php';",
                "require_once __DIR__ . '/Services/PlayerSources.php';\n"
                + "\n".join(
                    f"require_once __DIR__ . '/Services/{n}';"
                    for n in needs
                ),
                1,
            )
            break
    path.write_text(text)
    print("bootstrap.php services loaded")


def copy_services() -> None:
    for name in ("Database.php", "Auth.php", "UserData.php", "SourcesService.php"):
        src = HERE / "Services" / name
        dst = APP / "Services" / name
        shutil.copy2(src, dst)
        print("copied", name)


def patch_player_sources() -> None:
    path = APP / "Services" / "PlayerSources.php"
    text = path.read_text()
    # Use DB-enabled provider order when available
    needle = "$providers = config('player_providers', ['vaplayer', 'huhu']);\n        if (!is_array($providers) || !$providers) {\n            $providers = ['vaplayer', 'huhu'];\n        }"
    replacement = """$providers = config('player_providers', ['vaplayer', 'huhu']);
        try {
            if (class_exists('SourcesService')) {
                $dbProviders = SourcesService::enabledIds();
                if (is_array($dbProviders) && $dbProviders) {
                    $providers = $dbProviders;
                }
            }
        } catch (Throwable $e) {
            // keep config fallback
        }
        if (!is_array($providers) || !$providers) {
            $providers = ['vaplayer', 'huhu'];
        }"""
    if "SourcesService::enabledIds" not in text:
        if needle in text:
            text = text.replace(needle, replacement, 1)
        else:
            raise SystemExit("PlayerSources provider block not found")

    # Apply public labels before successful return
    marker = "'sources' => $sources,"
    if "SourcesService::applyPublicLabels" not in text:
        text = text.replace(
            marker,
            """'sources' => (class_exists('SourcesService')
                ? SourcesService::applyPublicLabels($sources, class_exists('Auth') && Auth::isStaff())
                : $sources),""",
            1,
        )
    path.write_text(text)
    print("PlayerSources.php DB order + public labels")


def write_routes_fragment() -> str:
    return r'''
// ——— Newsite auth + sync + admin APIs ———
$router->post('/api/auth/register', function () {
    $body = json_body();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha failed'], 400);
    }
    try {
        $user = Auth::register((string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        json_response(['ok' => true, 'user' => $user]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'Register failed'], 500);
    }
});

$router->post('/api/auth/login', function () {
    $body = json_body();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha failed'], 400);
    }
    try {
        $user = Auth::login((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        json_response(['ok' => true, 'user' => $user]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 401);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'Login failed'], 500);
    }
});

$router->post('/api/auth/logout', function () {
    Auth::logout();
    json_response(['ok' => true]);
});

$router->get('/api/auth/me', function () {
    $user = Auth::user();
    json_response(['ok' => true, 'user' => $user]);
});

$router->get('/api/user/library', function () {
    $user = Auth::requireUser();
    json_response(array_merge(['ok' => true], UserData::library((string) $user['id'])));
});

$router->post('/api/user/favorites', function () {
    $user = Auth::requireUser();
    $body = json_body();
    try {
        UserData::upsertFavorite((string) $user['id'], $body);
        json_response(['ok' => true, 'favorites' => UserData::listFavorites((string) $user['id'])]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['DELETE', 'POST'], '/api/user/favorites/remove', function () {
    $user = Auth::requireUser();
    $body = json_body();
    $type = (string) ($body['type'] ?? 'movie');
    $id = (int) ($body['id'] ?? 0);
    UserData::removeFavorite((string) $user['id'], $type, $id);
    json_response(['ok' => true, 'favorites' => UserData::listFavorites((string) $user['id'])]);
});

$router->post('/api/user/favorites/clear', function () {
    $user = Auth::requireUser();
    UserData::clearFavorites((string) $user['id']);
    json_response(['ok' => true, 'favorites' => []]);
});

$router->post('/api/user/continue', function () {
    $user = Auth::requireUser();
    $body = json_body();
    try {
        UserData::upsertContinue((string) $user['id'], $body);
        json_response(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/user/continue/remove', function () {
    $user = Auth::requireUser();
    $body = json_body();
    UserData::removeContinue((string) $user['id'], (string) ($body['key'] ?? ''));
    json_response(['ok' => true]);
});

$router->map(['PATCH', 'POST'], '/api/user/prefs', function () {
    $user = Auth::requireUser();
    $body = json_body();
    $updated = UserData::updatePrefs((string) $user['id'], $body);
    json_response(['ok' => true, 'user' => $updated]);
});

// Admin pages
$router->get('/admin', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/dashboard', [
        'adminUser' => $user,
        'seo' => ['title' => 'Admin | ' . config('site_name'), 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/admin/users', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/users', [
        'adminUser' => $user,
        'seo' => ['title' => 'Users | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/admin/sources', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/sources', [
        'adminUser' => $user,
        'seo' => ['title' => 'Sources | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

// Admin APIs
$router->get('/api/admin/users', function () {
    Auth::requireRole('admin', 'moderator');
    $q = trim((string) ($_GET['q'] ?? ''));
    $pdo = Database::pdo();
    if ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT * FROM users
             WHERE email LIKE ? OR name LIKE ? OR id = ?
             ORDER BY created_at DESC LIMIT 200'
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $q]);
    } else {
        $stmt = $pdo->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 200');
    }
    $users = array_map([Auth::class, 'publicUser'], $stmt->fetchAll() ?: []);
    json_response(['ok' => true, 'users' => $users]);
});

$router->get('/api/admin/users/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(string) $p['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Not found'], 404);
    }
    $uid = (string) $row['id'];
    json_response([
        'ok' => true,
        'user' => Auth::publicUser($row),
        'favorites' => UserData::listFavorites($uid),
        'continueWatching' => UserData::listContinue($uid),
    ]);
});

$router->map(['PATCH', 'POST'], '/api/admin/users/{id}', function (array $p) {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(string) $p['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Not found'], 404);
    }

    $fields = [];
    $vals = [];
    if (isset($body['name'])) {
        $fields[] = 'name = ?';
        $vals[] = substr(trim((string) $body['name']), 0, 120);
    }
    if (isset($body['email'])) {
        $email = strtolower(trim((string) $body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Invalid email'], 400);
        }
        $fields[] = 'email = ?';
        $vals[] = $email;
    }
    if (isset($body['status']) && in_array($body['status'], ['active', 'suspended'], true)) {
        $fields[] = 'status = ?';
        $vals[] = $body['status'];
    }
    if (isset($body['role']) && in_array($body['role'], ['admin', 'moderator', 'user'], true)) {
        if (($actor['role'] ?? '') !== 'admin') {
            json_response(['ok' => false, 'error' => 'Only admin can change roles'], 403);
        }
        $fields[] = 'role = ?';
        $vals[] = $body['role'];
    }
    if (!empty($body['password'])) {
        if (strlen((string) $body['password']) < 6) {
            json_response(['ok' => false, 'error' => 'Password too short'], 400);
        }
        $fields[] = 'password_hash = ?';
        $vals[] = password_hash((string) $body['password'], PASSWORD_DEFAULT);
    }
    if (!$fields) {
        json_response(['ok' => true, 'user' => Auth::publicUser($row)]);
    }
    $fields[] = 'updated_at = ?';
    $vals[] = Auth::now();
    $vals[] = (string) $p['id'];
    Database::pdo()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    $stmt->execute([(string) $p['id']]);
    json_response(['ok' => true, 'user' => Auth::publicUser($stmt->fetch() ?: $row)]);
});

$router->map(['DELETE', 'POST'], '/api/admin/users/{id}/delete', function (array $p) {
    $actor = Auth::requireRole('admin');
    if ((string) $actor['id'] === (string) $p['id']) {
        json_response(['ok' => false, 'error' => 'Cannot delete yourself'], 400);
    }
    Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([(string) $p['id']]);
    json_response(['ok' => true]);
});

$router->get('/api/admin/sources', function () {
    Auth::requireRole('admin', 'moderator');
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);
});

$router->post('/api/admin/sources/reorder', function () {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $order = $body['order'] ?? [];
    if (!is_array($order)) {
        json_response(['ok' => false, 'error' => 'Invalid order'], 400);
    }
    SourcesService::reorder(array_map('strval', $order));
    json_response(['ok' => true, 'sources' => SourcesService::all()]);
});

$router->map(['PATCH', 'POST'], '/api/admin/sources/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $id = (string) $p['id'];
    if (array_key_exists('enabled', $body)) {
        SourcesService::setEnabled($id, !empty($body['enabled']));
    }
    SourcesService::updateMeta(
        $id,
        isset($body['publicLabel']) ? (string) $body['publicLabel'] : null,
        isset($body['notes']) ? (string) $body['notes'] : null,
        isset($body['name']) ? (string) $body['name'] : null
    );
    json_response(['ok' => true, 'sources' => SourcesService::all()]);
});

$router->post('/api/admin/sources/{id}/test', function (array $p) {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $type = (($body['type'] ?? '') === 'tv') ? 'tv' : 'movie';
    $tmdbId = (int) ($body['tmdbId'] ?? 0);
    $season = max(1, (int) ($body['season'] ?? 1));
    $episode = max(1, (int) ($body['episode'] ?? 1));
    if ($tmdbId < 1) {
        json_response(['ok' => false, 'error' => 'tmdbId required'], 400);
    }
    $result = SourcesService::test((string) $p['id'], $type, $tmdbId, $season, $episode, (string) $actor['id']);
    json_response(['ok' => true] + $result);
});

$router->get('/api/admin/stats', function () {
    Auth::requireRole('admin', 'moderator');
    $pdo = Database::pdo();
    $stats = [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
        'moderators' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'moderator'")->fetchColumn(),
        'favorites' => (int) $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn(),
        'continue' => (int) $pdo->query('SELECT COUNT(*) FROM continue_watching')->fetchColumn(),
        'sourcesEnabled' => (int) $pdo->query('SELECT COUNT(*) FROM sources WHERE enabled = 1')->fetchColumn(),
    ];
    json_response(['ok' => true, 'stats' => $stats]);
});
'''


def patch_routes() -> None:
    path = APP / "routes.php"
    text = path.read_text()
    marker = "// ——— Newsite auth + sync + admin APIs ———"
    if marker in text:
        text = re.sub(
            re.escape(marker) + r"[\s\S]*?(?=\n\$router->|\nfunction |\Z)",
            "",
            text,
            count=1,
        )
    # append before final helper functions if present, else end
    frag = write_routes_fragment().strip() + "\n"
    # insert after player caption route block
    anchor = "$router->get('/api/player/caption', function () {\n    $url = trim((string) ($_GET['url'] ?? ''));\n    PlayerSources::proxyCaption($url);\n});"
    if anchor in text:
        text = text.replace(anchor, anchor + "\n\n" + frag, 1)
    else:
        text += "\n" + frag
    path.write_text(text)
    print("routes.php auth/admin APIs")


def write_admin_views() -> None:
    admin_dir = APP / "Views" / "pages" / "admin"
    admin_dir.mkdir(parents=True, exist_ok=True)

    shell_css = r'''
/* ——— Admin panel (ui88) ——— */
.page-admin .cf-admin {
  --adm-ink: #f4f1ec;
  --adm-muted: rgba(244,241,236,.62);
  --adm-line: rgba(255,255,255,.1);
  --adm-hot: #db6937;
  --adm-panel: rgba(255,255,255,.035);
  position: relative;
  z-index: 1;
  padding: 1rem 0 3rem;
}
.cf-admin-wrap { width: min(1100px, 100%); margin: 0 auto; }
.cf-admin-nav {
  display:flex; flex-wrap:wrap; gap:.45rem; margin:0 0 1.25rem;
}
.cf-admin-nav a {
  display:inline-flex; align-items:center; gap:.35rem;
  min-height:2.2rem; padding:.35rem .85rem; border-radius:999px;
  border:1px solid var(--adm-line); background:rgba(0,0,0,.22);
  color:rgba(255,255,255,.82)!important; text-decoration:none!important;
  font-size:.78rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase;
}
.cf-admin-nav a.is-active, .cf-admin-nav a:hover {
  border-color: rgba(219,105,55,.5); background:rgba(219,105,55,.16); color:#fff!important;
}
.cf-admin-head { margin:0 0 1.2rem; }
.cf-admin-head h1 {
  margin:0 0 .35rem; color:#fff; font-size:clamp(1.5rem,4vw,1.9rem);
  font-weight:800; letter-spacing:-.03em;
}
.cf-admin-head p { margin:0; color:var(--adm-muted); font-size:.92rem; }
.cf-admin-grid {
  display:grid; grid-template-columns:repeat(auto-fit,minmax(10.5rem,1fr)); gap:.75rem; margin-bottom:1.25rem;
}
.cf-admin-stat {
  padding:1rem; border-radius:1.1rem; border:1px solid var(--adm-line); background:var(--adm-panel);
}
.cf-admin-stat strong { display:block; color:#fff; font-size:1.45rem; font-weight:800; }
.cf-admin-stat span { color:var(--adm-muted); font-size:.75rem; font-weight:650; letter-spacing:.04em; text-transform:uppercase; }
.cf-admin-panel {
  border-radius:1.25rem; border:1px solid var(--adm-line); background:var(--adm-panel); padding:1rem;
}
.cf-admin-toolbar { display:flex; flex-wrap:wrap; gap:.55rem; margin-bottom:.85rem; }
.cf-admin-toolbar input, .cf-admin-toolbar select, .cf-admin-field input, .cf-admin-field select, .cf-admin-field textarea {
  min-height:2.5rem; padding:.55rem .75rem; border-radius:.85rem;
  border:1px solid var(--adm-line); background:rgba(0,0,0,.28); color:#fff; font:inherit;
}
.cf-admin-toolbar input { flex:1; min-width:12rem; }
.cf-admin-btn {
  appearance:none; border:0; border-radius:.85rem; min-height:2.5rem; padding:.55rem .95rem;
  background:linear-gradient(135deg,#db6937,#dc3545); color:#fff; font:inherit; font-weight:750; cursor:pointer;
}
.cf-admin-btn.ghost {
  background:rgba(255,255,255,.06); border:1px solid var(--adm-line);
}
.cf-admin-btn.danger { background:linear-gradient(135deg,#a11,#dc3545); }
.cf-admin-table { width:100%; border-collapse:collapse; }
.cf-admin-table th, .cf-admin-table td {
  text-align:left; padding:.65rem .45rem; border-bottom:1px solid rgba(255,255,255,.07);
  color:rgba(255,255,255,.86); font-size:.86rem; vertical-align:middle;
}
.cf-admin-table th { color:rgba(255,255,255,.45); font-size:.72rem; letter-spacing:.05em; text-transform:uppercase; }
.cf-admin-role {
  display:inline-flex; padding:.15rem .5rem; border-radius:999px; font-size:.7rem; font-weight:750;
  border:1px solid rgba(255,255,255,.12); text-transform:uppercase; letter-spacing:.04em;
}
.cf-admin-role.admin { color:#ffd2b8; border-color:rgba(219,105,55,.45); background:rgba(219,105,55,.14); }
.cf-admin-role.moderator { color:#b8e0ff; border-color:rgba(80,160,255,.4); background:rgba(80,160,255,.12); }
.cf-admin-role.user { color:rgba(255,255,255,.7); }
.cf-admin-source {
  display:flex; flex-wrap:wrap; gap:.65rem; align-items:center;
  padding:.85rem; border-radius:1rem; border:1px solid var(--adm-line);
  background:rgba(0,0,0,.18); margin-bottom:.55rem; cursor:grab;
}
.cf-admin-source.is-off { opacity:.55; }
.cf-admin-source .meta { flex:1; min-width:10rem; }
.cf-admin-source .meta strong { display:block; color:#fff; }
.cf-admin-source .meta em { color:var(--adm-muted); font-style:normal; font-size:.78rem; }
.cf-admin-switch {
  width:2.6rem; height:1.45rem; border-radius:999px; border:0; background:rgba(255,255,255,.16); position:relative; cursor:pointer;
}
.cf-admin-switch.on { background:linear-gradient(135deg,#db6937,#dc3545); }
.cf-admin-switch::after {
  content:""; position:absolute; top:.16rem; left:.16rem; width:1.12rem; height:1.12rem; border-radius:999px; background:#fff; transition:transform .15s ease;
}
.cf-admin-switch.on::after { transform:translateX(1.1rem); }
.cf-admin-drawer {
  margin-top:1rem; padding:1rem; border-radius:1.1rem; border:1px solid var(--adm-line); background:rgba(0,0,0,.22);
}
.cf-admin-field { display:flex; flex-direction:column; gap:.3rem; margin-bottom:.7rem; }
.cf-admin-field span { color:rgba(255,255,255,.5); font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.cf-admin-msg { margin:.5rem 0; color:#ffd2b8; font-size:.85rem; }
.cf-admin-msg.ok { color:#7dffb0; }
@media (max-width:991.98px){
  .page-admin .cf-admin { padding-bottom:calc(8rem + env(safe-area-inset-bottom,0px)); }
}
'''

    # inject admin css into app.css
    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Admin panel (ui88) ——— */"
    if marker in css:
        css = re.sub(re.escape(marker) + r"[\s\S]*?(?=\n/\* ———|\Z)", "", css, count=1)
    css_path.write_text(css.rstrip() + "\n\n" + shell_css.strip() + "\n")

    dashboard = r'''<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a class="is-active" href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">← Site</a>
    </nav>
    <header class="cf-admin-head">
      <h1>Admin</h1>
      <p>Signed in as <?= e((string) ($adminUser['name'] ?? '')) ?> · <?= e((string) ($adminUser['role'] ?? '')) ?></p>
    </header>
    <div class="cf-admin-grid" id="admin-stats">
      <div class="cf-admin-stat"><strong>—</strong><span>Users</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Favorites</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Continue</span></div>
      <div class="cf-admin-stat"><strong>—</strong><span>Sources on</span></div>
    </div>
    <div class="cf-admin-panel">
      <p style="margin:0;color:rgba(255,255,255,.6);font-size:.9rem;line-height:1.45;">
        Manage accounts, sync’d watch data, and playback source order. Guests and normal users see sources as Alpha / Beta / … — staff see real names.
      </p>
    </div>
  </div>
</main>
<script>
(function(){
  fetch(<?= json_encode(url('/api/admin/stats')) ?>, {credentials:'same-origin'})
    .then(r=>r.json()).then(d=>{
      if(!d||!d.ok) return;
      var s=d.stats||{};
      var el=document.getElementById('admin-stats');
      if(!el) return;
      el.innerHTML =
        '<div class="cf-admin-stat"><strong>'+(s.users||0)+'</strong><span>Users</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.favorites||0)+'</strong><span>Favorites</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.continue||0)+'</strong><span>Continue</span></div>'+
        '<div class="cf-admin-stat"><strong>'+(s.sourcesEnabled||0)+'</strong><span>Sources on</span></div>';
    }).catch(function(){});
})();
</script>
'''

    users = r'''<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
$isAdmin = Auth::isAdmin($adminUser);
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a class="is-active" href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">← Site</a>
    </nav>
    <header class="cf-admin-head">
      <h1>Users</h1>
      <p>Preview, edit role/status, or remove accounts.</p>
    </header>
    <div class="cf-admin-panel">
      <div class="cf-admin-toolbar">
        <input id="users-q" type="search" placeholder="Search name, email, id…">
        <button type="button" class="cf-admin-btn" id="users-search">Search</button>
      </div>
      <div class="cf-admin-msg" id="users-msg" hidden></div>
      <div style="overflow:auto;">
        <table class="cf-admin-table">
          <thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead>
          <tbody id="users-body"><tr><td colspan="4">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="cf-admin-drawer" id="user-drawer" hidden></div>
    </div>
  </div>
</main>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/users')) ?>;
  var canRole = <?= $isAdmin ? 'true' : 'false' ?>;
  var msg = document.getElementById('users-msg');
  var body = document.getElementById('users-body');
  var drawer = document.getElementById('user-drawer');
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function load(q){
    fetch(API + (q ? ('?q='+encodeURIComponent(q)) : ''), {credentials:'same-origin'})
      .then(r=>r.json()).then(d=>{
        if(!d||!d.ok){ body.innerHTML='<tr><td colspan="4">Failed</td></tr>'; return; }
        body.innerHTML = (d.users||[]).map(function(u){
          return '<tr>'+
            '<td><strong style="color:#fff">'+(u.name||'')+'</strong><div style="color:rgba(255,255,255,.45);font-size:.75rem">'+(u.email||'')+'</div></td>'+
            '<td><span class="cf-admin-role '+(u.role||'')+'">'+(u.role||'')+'</span></td>'+
            '<td>'+(u.status||'')+'</td>'+
            '<td><button type="button" class="cf-admin-btn ghost" data-open="'+(u.id||'')+'">Open</button></td>'+
          '</tr>';
        }).join('') || '<tr><td colspan="4">No users</td></tr>';
      });
  }
  function openUser(id){
    fetch(API+'/'+encodeURIComponent(id), {credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ show('Load failed'); return; }
      var u=d.user||{};
      drawer.hidden=false;
      drawer.innerHTML =
        '<h3 style="margin:0 0 .75rem;color:#fff">Edit user</h3>'+
        '<label class="cf-admin-field"><span>Name</span><input id="eu-name" value="'+(u.name||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Email</span><input id="eu-email" value="'+(u.email||'').replace(/"/g,'&quot;')+'"></label>'+
        '<label class="cf-admin-field"><span>Status</span><select id="eu-status"><option value="active">active</option><option value="suspended">suspended</option></select></label>'+
        (canRole ? '<label class="cf-admin-field"><span>Role</span><select id="eu-role"><option value="user">user</option><option value="moderator">moderator</option><option value="admin">admin</option></select></label>' : '')+
        '<label class="cf-admin-field"><span>New password</span><input id="eu-pass" type="password" placeholder="Leave blank to keep"></label>'+
        '<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin:.4rem 0 1rem">'+
          '<button type="button" class="cf-admin-btn" id="eu-save">Save</button>'+
          (canRole ? '<button type="button" class="cf-admin-btn danger" id="eu-del">Delete</button>' : '')+
        '</div>'+
        '<p style="color:rgba(255,255,255,.5);font-size:.8rem;margin:0 0 .35rem">Favorites: '+((d.favorites||[]).length)+' · Continue: '+((d.continueWatching||[]).length)+'</p>'+
        '<pre style="max-height:14rem;overflow:auto;font-size:.72rem;color:rgba(255,255,255,.55);background:rgba(0,0,0,.25);padding:.7rem;border-radius:.8rem">'+(
          JSON.stringify({favorites:d.favorites||[], continueWatching:d.continueWatching||[]}, null, 2)
        )+'</pre>';
      drawer.querySelector('#eu-status').value = u.status || 'active';
      if (canRole) drawer.querySelector('#eu-role').value = u.role || 'user';
      drawer.querySelector('#eu-save').onclick = function(){
        var payload = {
          name: drawer.querySelector('#eu-name').value,
          email: drawer.querySelector('#eu-email').value,
          status: drawer.querySelector('#eu-status').value,
          password: drawer.querySelector('#eu-pass').value
        };
        if (canRole) payload.role = drawer.querySelector('#eu-role').value;
        fetch(API+'/'+encodeURIComponent(id), {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        }).then(r=>r.json()).then(function(res){
          if(!res||!res.ok){ show((res&&res.error)||'Save failed'); return; }
          show('Saved', true); load(document.getElementById('users-q').value.trim()); openUser(id);
        });
      };
      var del = drawer.querySelector('#eu-del');
      if (del) del.onclick = function(){
        if(!confirm('Delete this user permanently?')) return;
        fetch(API+'/'+encodeURIComponent(id)+'/delete', {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'}, body:'{}'
        }).then(r=>r.json()).then(function(res){
          if(!res||!res.ok){ show((res&&res.error)||'Delete failed'); return; }
          show('Deleted', true); drawer.hidden=true; load('');
        });
      };
    });
  }
  document.getElementById('users-search').onclick = function(){ load(document.getElementById('users-q').value.trim()); };
  document.getElementById('users-q').addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); load(this.value.trim()); }});
  body.addEventListener('click', function(e){
    var btn = e.target.closest('[data-open]'); if(!btn) return;
    openUser(btn.getAttribute('data-open'));
  });
  load('');
})();
</script>
'''

    sources = r'''<?php
$headerClass = 'relative';
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a class="is-active" href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a href="<?= e(url('/home')) ?>">← Site</a>
    </nav>
    <header class="cf-admin-head">
      <h1>Sources</h1>
      <p>Enable/disable, drag order (top = tested first), and probe with any movie/TV TMDB id. Users see Alpha/Beta labels.</p>
    </header>
    <div class="cf-admin-panel">
      <div class="cf-admin-toolbar">
        <select id="test-type"><option value="movie">Movie</option><option value="tv">TV</option></select>
        <input id="test-tmdb" type="number" min="1" placeholder="TMDB id (e.g. 550)" style="flex:0 1 10rem">
        <input id="test-season" type="number" min="1" value="1" placeholder="S" style="flex:0 0 4.5rem" title="Season">
        <input id="test-episode" type="number" min="1" value="1" placeholder="E" style="flex:0 0 4.5rem" title="Episode">
      </div>
      <div class="cf-admin-msg" id="src-msg" hidden></div>
      <div id="src-list">Loading…</div>
      <div style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="cf-admin-btn" id="src-save-order">Save order</button>
      </div>
    </div>
  </div>
</main>
<script>
(function(){
  var API = <?= json_encode(url('/api/admin/sources')) ?>;
  var list = document.getElementById('src-list');
  var msg = document.getElementById('src-msg');
  var dragId = null;
  function show(t, ok){ msg.hidden=false; msg.textContent=t; msg.className='cf-admin-msg'+(ok?' ok':''); }
  function render(sources){
    list.innerHTML = (sources||[]).map(function(s){
      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<div class="meta"><strong>'+s.name+' <span style="color:rgba(255,255,255,.4)">('+s.id+')</span></strong>'+
        '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em></div>'+
        '<button type="button" class="cf-admin-switch'+(s.enabled?' on':'')+'" data-toggle="'+s.id+'" aria-label="Toggle"></button>'+
        '<button type="button" class="cf-admin-btn ghost" data-test="'+s.id+'">Test</button>'+
        '<input data-label="'+s.id+'" value="'+(s.publicLabel||'').replace(/"/g,'&quot;')+'" style="width:7rem;min-height:2.3rem;border-radius:.7rem;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.28);color:#fff;padding:.35rem .5rem" title="Public label">'+
      '</div>';
    }).join('');
  }
  function load(){
    fetch(API,{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      if(!d||!d.ok){ list.textContent='Failed'; return; }
      render(d.sources||[]);
    });
  }
  function orderIds(){
    return Array.prototype.map.call(list.querySelectorAll('.cf-admin-source'), function(el){ return el.getAttribute('data-id'); });
  }
  list.addEventListener('dragstart', function(e){
    var row=e.target.closest('.cf-admin-source'); if(!row) return;
    dragId=row.getAttribute('data-id'); e.dataTransfer.effectAllowed='move';
  });
  list.addEventListener('dragover', function(e){
    e.preventDefault();
    var row=e.target.closest('.cf-admin-source'); if(!row||!dragId) return;
    var dragging=list.querySelector('[data-id="'+dragId+'"]');
    if(!dragging||dragging===row) return;
    var rect=row.getBoundingClientRect();
    var before=(e.clientY-rect.top) < rect.height/2;
    list.insertBefore(dragging, before?row:row.nextSibling);
  });
  list.addEventListener('click', function(e){
    var t=e.target.closest('[data-toggle]');
    if(t){
      var id=t.getAttribute('data-toggle');
      var on=!t.classList.contains('on');
      fetch(API+'/'+encodeURIComponent(id), {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({enabled:on})
      }).then(r=>r.json()).then(function(d){ if(d&&d.ok){ render(d.sources); show('Updated', true);} else show('Toggle failed'); });
      return;
    }
    var test=e.target.closest('[data-test]');
    if(test){
      var id=test.getAttribute('data-test');
      var type=document.getElementById('test-type').value;
      var tmdbId=parseInt(document.getElementById('test-tmdb').value,10)||0;
      if(!tmdbId){ show('Enter a TMDB id'); return; }
      show('Testing '+id+'…');
      fetch(API+'/'+encodeURIComponent(id)+'/test', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          type:type, tmdbId:tmdbId,
          season:parseInt(document.getElementById('test-season').value,10)||1,
          episode:parseInt(document.getElementById('test-episode').value,10)||1
        })
      }).then(r=>r.json()).then(function(d){
        if(!d){ show('Test failed'); return; }
        show((d.message|| (d.ok?'OK':'Fail')) + (d.sourceCount!=null?(' · '+d.sourceCount+' streams'):''), !!d.ok);
      }).catch(function(){ show('Test failed'); });
    }
  });
  list.addEventListener('change', function(e){
    var inp=e.target.closest('[data-label]'); if(!inp) return;
    var id=inp.getAttribute('data-label');
    fetch(API+'/'+encodeURIComponent(id), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({publicLabel: inp.value})
    }).then(r=>r.json()).then(function(d){ if(d&&d.ok) show('Label saved', true); });
  });
  document.getElementById('src-save-order').onclick=function(){
    fetch(API+'/reorder', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({order: orderIds()})
    }).then(r=>r.json()).then(function(d){
      if(d&&d.ok){ render(d.sources); show('Order saved — top is tested first', true); }
      else show('Save order failed');
    });
  };
  load();
})();
</script>
'''

    (admin_dir / "dashboard.php").write_text(dashboard)
    (admin_dir / "users.php").write_text(users)
    (admin_dir / "sources.php").write_text(sources)
    print("admin views written")


def patch_auth_js() -> None:
    """Point browse auth + sync at newsite APIs."""
    js_path = ROOT / "public/assets/js/app.js"
    js = js_path.read_text()

    # Replace authApi origin to use relative newsite base
    old_auth = """  function authApi(path, options) {
    var origin = (window.APP && APP.mainOrigin) || (location.origin || '');
    return fetch(origin + path, Object.assign({
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    }, options || {}));
  }"""
    new_auth = """  function authApi(path, options) {
    // Newsite owns auth/sync (MySQL `newsite`). Paths are under CF_BASE.
    var base = (window.CF_BASE || '');
    var url = path.indexOf('http') === 0 ? path : (base + path);
    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    }, options || {}));
  }

  function nsSyncLibrary() {
    return authApi('/api/user/library', { method: 'GET' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.user) return null;
        try {
          // Favorites → local map
          if (typeof favStore === 'function' && typeof saveFavs === 'function') {
            var map = {};
            (data.favorites || []).forEach(function (f) {
              var type = f.type === 'tv' ? 'tv' : 'movie';
              var id = String(f.id || '');
              if (!id) return;
              map[id] = {
                id: id,
                type: type,
                mediaType: type,
                title: f.title || '',
                name: f.title || '',
                poster: f.poster || '',
                poster_path: '',
                year: f.year || '',
                saved_at: new Date((f.updated || Date.now()) * (String(f.updated).length < 13 ? 1000 : 1)).toISOString()
              };
            });
            try { saveFavs(map); } catch (eSave) {
              try { localStorage.setItem('user_bookmarks', JSON.stringify(map)); } catch (e) {}
            }
            try { if (typeof renderFavoritesPage === 'function') renderFavoritesPage(); } catch (e2) {}
          }
          // Continue watching
          var cw = {};
          (data.continueWatching || []).forEach(function (item) {
            if (!item || !item.key) return;
            cw[item.key] = {
              id: item.id, type: item.type, title: item.title || '', poster: item.poster || '',
              backdrop: item.backdrop || '', year: item.year || '',
              season: item.season, episode: item.episode,
              t: item.t || 0, d: item.d || 0, updated: item.updated || Date.now()
            };
          });
          try { localStorage.setItem('cf_continue_v1', JSON.stringify(cw)); } catch (e3) {}
          try {
            if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
              window.ChillflixContinue.render();
            }
          } catch (e4) {}
          // Prefs
          var u = data.user;
          try { localStorage.setItem('cf_watch_autoplay', u.autoplayEnabled ? '1' : '0'); } catch (e5) {}
          try { localStorage.setItem('cf_np_autonext', u.autoNextEnabled ? '1' : '0'); } catch (e6) {}
          try { localStorage.setItem('cf_pref_watchlist', u.watchlistEnabled ? '1' : '0'); } catch (e7) {}
          try { localStorage.setItem('cf_pref_continue', u.continueEnabled ? '1' : '0'); } catch (e8) {}
          try { if (u.language) localStorage.setItem('cf_lang', u.language); } catch (e9) {}
          try { if (typeof applyBrowsePrefs === 'function') applyBrowsePrefs(); } catch (e10) {}
          try { if (typeof syncBrowseSettingsUi === 'function') syncBrowseSettingsUi(); } catch (e11) {}
        } catch (err) { console.warn('ns sync failed', err); }
        return data;
      });
  }

  function nsPushPrefs(partial) {
    return authApi('/api/user/prefs', { method: 'POST', body: JSON.stringify(partial || {}) })
      .catch(function () {});
  }

  function nsPushFavorite(item, removing) {
    if (removing) {
      return authApi('/api/user/favorites/remove', {
        method: 'POST', body: JSON.stringify({ type: item.type, id: item.id })
      }).catch(function () {});
    }
    return authApi('/api/user/favorites', {
      method: 'POST', body: JSON.stringify(item)
    }).catch(function () {});
  }

  window.nsSyncLibrary = nsSyncLibrary;
  window.nsPushPrefs = nsPushPrefs;
  window.nsPushFavorite = nsPushFavorite;
  window.nsPushContinue = function (item) {
    return authApi('/api/user/continue', { method: 'POST', body: JSON.stringify(item || {}) })
      .catch(function () {});
  };
"""
    if "nsSyncLibrary" not in js:
        if old_auth in js:
            js = js.replace(old_auth, new_auth, 1)
        else:
            raise SystemExit("authApi block not found")

    # After applyBrowseAuthUser when user present, sync library
    if "nsSyncLibrary()" not in js:
        js = js.replace(
            "try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}\n  }",
            "try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}\n"
            "    if (user) { try { nsSyncLibrary(); } catch (eSync) {} }\n  }",
            1,
        )

    # Login/register already use authApi('/api/auth/...') - good once authApi uses CF_BASE

    # Prefs push hooks
    if "nsPushPrefs({ autoplayEnabled" not in js:
        js = js.replace(
            "if (typeof syncWatchAutoplayUi === 'function') syncWatchAutoplayUi();\n    if (typeof window.__cfSyncWatchAutoplay === 'function') window.__cfSyncWatchAutoplay();\n    syncBrowseSettingsUi();\n  }",
            "if (typeof syncWatchAutoplayUi === 'function') syncWatchAutoplayUi();\n    if (typeof window.__cfSyncWatchAutoplay === 'function') window.__cfSyncWatchAutoplay();\n    syncBrowseSettingsUi();\n    try { nsPushPrefs({ autoplayEnabled: on }); } catch (e) {}\n  }",
            1,
        )
        js = js.replace(
            "function setBrowseAutoNext(on) {\n    on = !!on;\n    try { localStorage.setItem('cf_np_autonext', on ? '1' : '0'); } catch (e) {}\n    syncBrowseSettingsUi();\n  }",
            "function setBrowseAutoNext(on) {\n    on = !!on;\n    try { localStorage.setItem('cf_np_autonext', on ? '1' : '0'); } catch (e) {}\n    syncBrowseSettingsUi();\n    try { nsPushPrefs({ autoNextEnabled: on }); } catch (e2) {}\n  }",
            1,
        )
        js = js.replace(
            "setPrefEnabled('cf_pref_watchlist', $(this).attr('aria-checked') !== 'true');\n    applyBrowsePrefs();\n    syncBrowseSettingsUi();\n  });",
            "var wOn = $(this).attr('aria-checked') !== 'true';\n    setPrefEnabled('cf_pref_watchlist', wOn);\n    applyBrowsePrefs();\n    syncBrowseSettingsUi();\n    try { nsPushPrefs({ watchlistEnabled: wOn }); } catch (e) {}\n  });",
            1,
        )
        js = js.replace(
            "setPrefEnabled('cf_pref_continue', $(this).attr('aria-checked') !== 'true');\n    applyBrowsePrefs();\n    syncBrowseSettingsUi();\n  });",
            "var cOn = $(this).attr('aria-checked') !== 'true';\n    setPrefEnabled('cf_pref_continue', cOn);\n    applyBrowsePrefs();\n    syncBrowseSettingsUi();\n    try { nsPushPrefs({ continueEnabled: cOn }); } catch (e) {}\n  });",
            1,
        )

    # Favorites push - hook near saveFavs if present
    if "nsPushFavorite(" not in js.split("function toggleFav")[0] and "function toggleFav" in js:
        # After toggleFav save, push - find showFavToast lines after toggle
        js = js.replace(
            "showFavToast(removing ? 'Removed from favorites' : 'Added to favorites');\n  }",
            "showFavToast(removing ? 'Removed from favorites' : 'Added to favorites');\n"
            "    try {\n"
            "      if (browseAuthUser && typeof nsPushFavorite === 'function') {\n"
            "        var entry = map[id] || {};\n"
            "        var numId = parseInt(String(entry.id != null ? entry.id : id).replace(/^.*:/, ''), 10);\n"
            "        if (numId) {\n"
            "          nsPushFavorite({\n"
            "            type: type,\n"
            "            id: numId,\n"
            "            title: entry.title || entry.name || '',\n"
            "            poster: entry.poster || entry.poster_path || '',\n"
            "            year: entry.year || ''\n"
            "          }, removing);\n"
            "        }\n"
            "      }\n"
            "    } catch (ePush) {}\n  }",
            1,
        )

    # Admin link in browse for staff
    if 'data-browse-admin' not in js:
        js += """

/* newsite-admin-link */
(function () {
  function ensureAdminBrowseLink(user) {
    var mount = document.getElementById('browse-account-section');
    if (!mount) return;
    var existing = document.getElementById('browse-admin-link');
    var staff = user && (user.role === 'admin' || user.role === 'moderator');
    if (!staff) { if (existing) existing.remove(); return; }
    if (existing) return;
    var a = document.createElement('a');
    a.id = 'browse-admin-link';
    a.className = 'browse-list-item';
    a.href = (window.CF_BASE || '') + '/admin';
    a.innerHTML = '<i class="uil uil-shield"></i><span>Admin panel</span><i class="uil uil-angle-right"></i>';
    var list = mount.querySelector('.browse-list') || mount;
    list.appendChild(a);
  }
  var _apply = window.applyBrowseAuthUser;
  // hook via jQuery applyBrowseAuthUser is local; observe refresh
  $(document).on('click', '.bottom-nav-browse', function () {
    setTimeout(function () {
      try {
        authApi('/api/auth/me', { method: 'GET' }).then(function (r) { return r.json(); }).then(function (d) {
          ensureAdminBrowseLink(d && d.user ? d.user : null);
        });
      } catch (e) {}
    }, 200);
  });
})();
"""

    js_path.write_text(js)
    print("app.js newsite auth + sync")


def patch_player_js_continue() -> None:
    path = ROOT / "public/assets/js/player.js"
    if not path.exists():
        print("player.js missing, skip continue push")
        return
    js = path.read_text()
    if "nsPushContinue" in js:
        print("player.js already pushes continue")
        return
    # Hook after local cw write - look for cf_continue_v1 setItem
    if "cf_continue_v1" not in js:
        print("no cf_continue_v1 in player.js")
        return
    # Add helper + call near cwSave end
    hook = """
  function nsMaybePushContinue(item) {
    try {
      if (typeof window.nsPushContinue === 'function' && item) window.nsPushContinue(item);
    } catch (e) {}
  }
"""
    if "function nsMaybePushContinue" not in js:
        # insert before first function cwSave or after continue helpers
        m = re.search(r"function cwSave\s*\(", js)
        if m:
            js = js[: m.start()] + hook + js[m.start() :]
        else:
            js = hook + js

    # After writing continue, push - find localStorage.setItem('cf_continue_v1'
    js = js.replace(
        "localStorage.setItem('cf_continue_v1'",
        "try { nsMaybePushContinue(typeof item !== 'undefined' ? item : (typeof entry !== 'undefined' ? entry : null)); } catch (_ns) {}\n"
        "      localStorage.setItem('cf_continue_v1'",
        1,
    )
    path.write_text(js)
    print("player.js continue push hooked")


def apply_schema_and_seed() -> None:
    schema = (HERE / "sql" / "schema.sql").read_text()
    run(["mysql", "-unewsite", f"-p{DB_PASS}", "newsite", "-e", schema])
    print("schema applied")

    # seed via php
    php = f'''<?php
require '{APP}/helpers.php';
require '{APP}/Services/Database.php';
require '{APP}/Services/Auth.php';
require '{APP}/Services/SourcesService.php';
SourcesService::seedDefaults();
$email = {json.dumps(ADMIN_EMAIL)};
$pass = {json.dumps(ADMIN_PASS)};
$name = {json.dumps(ADMIN_NAME)};
$pdo = Database::pdo();
$st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$st->execute([$email]);
if (!$st->fetch()) {{
  $id = Auth::uuid();
  $now = Auth::now();
  $hash = password_hash($pass, PASSWORD_DEFAULT);
  $pdo->prepare('INSERT INTO users (id,email,password_hash,name,role,status,created_at,updated_at) VALUES (?,?,?,?,\\'admin\\',\\'active\\',?,?)')
    ->execute([$id,$email,$hash,$name,$now,$now]);
  echo "admin_created\\n";
}} else {{
  $pdo->prepare('UPDATE users SET role=\\'admin\\', status=\\'active\\', password_hash=?, name=?, updated_at=? WHERE email=?')
    ->execute([password_hash($pass, PASSWORD_DEFAULT), $name, Auth::now(), $email]);
  echo "admin_updated\\n";
}}
echo "sources=" . $pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn() . "\\n";
'''
    Path("/tmp/ns_seed.php").write_text(php)
    print(run(["php", "/tmp/ns_seed.php"]))


def main() -> None:
    if not DB_PASS or not AUTH_SECRET or not ADMIN_PASS:
        raise SystemExit("NS_DB_PASS, NS_AUTH_SECRET, NS_ADMIN_PASS required")

    stamp = datetime.now().strftime("%Y%m%d%H%M%S")
    for rel in ("config.php", "helpers.php", "bootstrap.php", "routes.php", "Services/PlayerSources.php"):
        p = APP / rel
        if p.exists():
            shutil.copy2(p, p.with_suffix(p.suffix + f".bak-db-{stamp}"))

    copy_services()
    patch_config()
    patch_helpers()
    patch_bootstrap()
    apply_schema_and_seed()
    patch_player_sources()
    patch_routes()
    write_admin_views()
    patch_auth_js()
    patch_player_js_continue()

    layout = APP / "Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)
    print("ADMIN_EMAIL", ADMIN_EMAIL)
    print("ADMIN_PASS", ADMIN_PASS)
    print("DONE")


if __name__ == "__main__":
    main()
