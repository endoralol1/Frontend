#!/usr/bin/env python3
"""Deploy inbox polls/notifications to newsite."""
from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
SRC = Path("/tmp/inbox-polls")


def run(cmd: list[str]) -> None:
    print("+", " ".join(cmd))
    subprocess.check_call(cmd)


def patch_once(path: Path, needle: str, insert: str, label: str) -> None:
    text = path.read_text(encoding="utf-8")
    if insert.strip()[:40] in text and label.split()[0] in text:
        # crude already-present check via unique snippet
        pass
    if needle not in text:
        raise SystemExit(f"{path}: needle not found for {label}")
    if label in text and "inbox" in label.lower():
        # already patched markers
        pass
    marker = f"/* {label} */" if path.suffix == ".css" else f"// {label}"
    if path.suffix == ".php":
        marker = f"<!-- {label} -->"
    # Use unique content check
    unique = insert.strip().splitlines()[0][:60]
    if unique in text:
        print(f"skip {label}")
        return
    path.write_text(text.replace(needle, insert + needle, 1), encoding="utf-8")
    print(f"patched {label}")


def main() -> None:
    # Copy service + views + assets
    shutil.copy2(SRC / "Services/InboxService.php", ROOT / "app/Services/InboxService.php")
    (ROOT / "app/Views/pages/admin").mkdir(parents=True, exist_ok=True)
    shutil.copy2(SRC / "Views/pages/admin/inbox.php", ROOT / "app/Views/pages/admin/inbox.php")
    shutil.copy2(SRC / "public/assets/js/inbox.js", ROOT / "public/assets/js/inbox.js")
    shutil.copy2(SRC / "public/assets/css/inbox.css", ROOT / "public/assets/css/inbox.css")
    print("copied files")

    # bootstrap
    boot = ROOT / "app/bootstrap-services.php"
    bt = boot.read_text(encoding="utf-8")
    if "InboxService.php" not in bt:
        boot.write_text(
            bt.replace(
                "require_once __DIR__ . '/Services/HuhuLiveTv.php';\n",
                "require_once __DIR__ . '/Services/HuhuLiveTv.php';\n"
                "require_once __DIR__ . '/Services/InboxService.php';\n",
            ),
            encoding="utf-8",
        )
        print("bootstrap ok")
    else:
        print("bootstrap already")

    # header: add inbox widget in .end before party chip
    header = ROOT / "app/Views/partials/header.php"
    ht = header.read_text(encoding="utf-8")
    if 'id="cf-inbox"' not in ht:
        widget = '''            <div class="end">
                <div class="cf-inbox" id="cf-inbox" hidden>
                    <button type="button" id="cf-inbox-btn" class="cf-inbox-btn" aria-label="Polls & notifications" aria-haspopup="menu" aria-expanded="false" aria-controls="cf-inbox-dropdown">
                        <i class="uil uil-bell" aria-hidden="true"></i>
                        <span id="cf-inbox-badge" class="cf-inbox-badge" hidden>0</span>
                    </button>
                    <div id="cf-inbox-dropdown" class="cf-inbox-dropdown" role="menu" hidden>
                        <div class="cf-inbox-top">
                            <strong>Inbox</strong>
                            <em>Polls &amp; notifications</em>
                        </div>
                        <div id="cf-inbox-list" class="cf-inbox-list"></div>
                    </div>
                </div>
                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>
'''
        old = '''            <div class="end">
                <div id="cf-party-chip-bar" class="cf-party-chip-bar" hidden></div>
'''
        if old not in ht:
            raise SystemExit("header .end block not found")
        header.write_text(ht.replace(old, widget, 1), encoding="utf-8")
        print("header widget ok")
    else:
        print("header widget already")

    # admin menu link in header
    ht = header.read_text(encoding="utf-8")
    if "/admin/inbox" not in ht:
        needle = '''                            <a role="menuitem" href="<?= e(url('/admin/sources')) ?>" class="<?= str_contains($adminPath, '/admin/sources') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong><?= e(t('admin.sources')) ?></strong><small><?= e(t('admin.sources_help')) ?></small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
'''
        insert = needle + '''                            <a role="menuitem" href="<?= e(url('/admin/inbox')) ?>" class="<?= str_contains($adminPath, '/admin/inbox') ? 'is-active' : '' ?>">
                                <span class="header-admin-ico"><i class="uil uil-bell" aria-hidden="true"></i></span>
                                <span class="header-admin-copy"><strong>Inbox</strong><small>Polls &amp; notifications</small></span>
                                <i class="uil uil-angle-right" aria-hidden="true"></i>
                            </a>
'''
        if needle not in ht:
            raise SystemExit("admin sources menu link not found")
        # replace sources block with sources+inbox (avoid double)
        header.write_text(ht.replace(needle, insert, 1), encoding="utf-8")
        print("header admin link ok")
    else:
        print("header admin link already")

    # admin nav on sources/users/dashboard
    for rel in (
        "app/Views/pages/admin/sources.php",
        "app/Views/pages/admin/users.php",
        "app/Views/pages/admin/dashboard.php",
    ):
        p = ROOT / rel
        if not p.exists():
            continue
        t = p.read_text(encoding="utf-8")
        if "/admin/inbox" in t:
            print(rel, "nav already")
            continue
        old = '<a href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>'
        # sources page has is-active on sources
        variants = [
            (
                '<a class="is-active" href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
                '<a class="is-active" href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/admin/inbox\')) ?>">Inbox</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
            ),
            (
                '<a href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
                '<a href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/admin/inbox\')) ?>">Inbox</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
            ),
            (
                '<a class="is-active" href="<?= e(url(\'/admin\')) ?>">Dashboard</a>\n      <a href="<?= e(url(\'/admin/users\')) ?>">Users</a>\n      <a href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
                '<a class="is-active" href="<?= e(url(\'/admin\')) ?>">Dashboard</a>\n      <a href="<?= e(url(\'/admin/users\')) ?>">Users</a>\n      <a href="<?= e(url(\'/admin/sources\')) ?>">Sources</a>\n      <a href="<?= e(url(\'/admin/inbox\')) ?>">Inbox</a>\n      <a href="<?= e(url(\'/home\')) ?>">Site</a>',
            ),
        ]
        done = False
        for a, b in variants:
            if a in t:
                p.write_text(t.replace(a, b, 1), encoding="utf-8")
                print(rel, "nav patched")
                done = True
                break
        if not done:
            print(rel, "nav pattern not found — manual check")

    # layout CSS/JS
    layout = ROOT / "app/Views/layouts/main.php"
    lt = layout.read_text(encoding="utf-8")
    if "js/inbox.js" not in lt:
        lt = lt.replace(
            '<script src="<?= e(asset(\'js/continue-party.js\')) ?>?v=1232" defer></script>\n',
            '<script src="<?= e(asset(\'js/continue-party.js\')) ?>?v=1232" defer></script>\n'
            '<link rel="stylesheet" href="<?= e(asset(\'css/inbox.css\')) ?>?v=20260814-inbox2">\n'
            '<script src="<?= e(asset(\'js/inbox.js\')) ?>?v=20260814-inbox2" defer></script>\n',
            1,
        )
        layout.write_text(lt, encoding="utf-8")
        print("layout assets ok")
    else:
        print("layout assets already")

    # routes
    routes = ROOT / "app/routes.php"
    rt = routes.read_text(encoding="utf-8")
    if "/api/inbox" not in rt:
        block = r'''
$router->get('/admin/inbox', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    if (class_exists('InboxService')) {
        InboxService::ensureTables();
    }
    view('pages/admin/inbox', [
        'adminUser' => $user,
        'seo' => ['title' => 'Inbox | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/inbox', function () {
    Auth::requireRole('admin', 'moderator');
    InboxService::ensureTables();
    json_response(['ok' => true, 'items' => InboxService::adminList()]);
});

$router->post('/api/admin/inbox', function () {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $item = InboxService::adminCreate(is_array($body) ? $body : [], (string) $actor['id']);
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['PATCH', 'POST'], '/api/admin/inbox/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $item = InboxService::adminUpdate((string) $p['id'], is_array($body) ? $body : []);
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['DELETE', 'POST'], '/api/admin/inbox/{id}/delete', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    InboxService::adminDelete((string) $p['id']);
    json_response(['ok' => true]);
});

// Allow DELETE on /api/admin/inbox/{id}
$router->map(['DELETE'], '/api/admin/inbox/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    InboxService::adminDelete((string) $p['id']);
    json_response(['ok' => true]);
});

$router->post('/api/admin/inbox/{id}/ramp', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $ramps = InboxService::adminStartRamps((string) $p['id'], is_array($body) ? $body : []);
        json_response(['ok' => true, 'ramps' => $ramps]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/admin/inbox/{id}/ramp/cancel', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $group = is_array($body) ? ($body['group'] ?? $body['kind'] ?? null) : null;
    $n = InboxService::adminCancelRamps((string) $p['id'], is_string($group) ? $group : null);
    json_response(['ok' => true, 'cancelled' => $n]);
});

$router->get('/api/inbox', function () {
    if (!class_exists('InboxService')) {
        json_response(['ok' => false, 'error' => 'Inbox unavailable'], 500);
    }
    $feed = InboxService::feedForViewer(Auth::user());
    json_response(['ok' => true] + $feed);
});

$router->post('/api/inbox/{id}/vote', function (array $p) {
    $body = json_body();
    $optionIds = $body['optionIds'] ?? $body['options'] ?? [];
    if (!is_array($optionIds)) {
        $optionIds = [];
    }
    try {
        $item = InboxService::vote((string) $p['id'], $optionIds, Auth::user());
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/inbox/{id}/react', function (array $p) {
    $body = json_body();
    $reaction = $body['reaction'] ?? null;
    if ($reaction === '' || $reaction === 'null') {
        $reaction = null;
    }
    try {
        $item = InboxService::react((string) $p['id'], $reaction !== null ? (string) $reaction : null, Auth::user());
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/inbox/{id}/read', function (array $p) {
    InboxService::markRead((string) $p['id'], Auth::user());
    json_response(['ok' => true]);
});

$router->post('/api/inbox/read-all', function () {
    $n = InboxService::markAllRead(Auth::user());
    json_response(['ok' => true, 'count' => $n]);
});

'''
        # Insert before admin sources page route so admin pages cluster — actually after sources is fine
        needle = "$router->get('/admin/sources', function () {"
        if needle not in rt:
            raise SystemExit("admin sources route not found")
        routes.write_text(rt.replace(needle, block + needle, 1), encoding="utf-8")
        print("routes ok")
    else:
        print("routes already")

    # Ensure tables + php lint
    run(["php", "-l", str(ROOT / "app/Services/InboxService.php")])
    run(["php", "-l", str(ROOT / "app/Views/pages/admin/inbox.php")])
    run([
        "php", "-r",
        "require '/var/www/chillflix-newsite/app/bootstrap-services.php'; InboxService::ensureTables(); echo \"tables ok\\n\";",
    ])

    # Create a sample active notification if empty (optional demo — skip if any exist)
    run([
        "php", "-r",
        r'''
require "/var/www/chillflix-newsite/app/bootstrap-services.php";
$n = (int) Database::pdo()->query("SELECT COUNT(*) FROM inbox_items")->fetchColumn();
echo "items=$n\n";
''',
    ])

    run(["systemctl", "restart", "php8.1-fpm"])
    print("done")


if __name__ == "__main__":
    main()
