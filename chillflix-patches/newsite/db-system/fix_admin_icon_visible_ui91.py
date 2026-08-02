#!/usr/bin/env python3
"""Fix admin icon visibility + dashboard gray cleanup (ui91).

Root cause of "you didn't do anything":
- Header admin link started with HTML hidden=
- JS that unhides it lives OUTSIDE the main app.js IIFE and calls authApi,
  which is out of scope → always fails → icon never appears.
- Icon was also on the left (next to language), while .end (right) was empty.
"""
from __future__ import annotations

import os
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui91"
PW = os.environ.get("SSHPASS") or os.environ.get("SG_SSH_PASS") or ""
if not PW:
    raise SystemExit("Set SSHPASS or SG_SSH_PASS")


def ssh(script: str) -> str:
    env = {**os.environ, "SSHPASS": PW}
    p = subprocess.run(
        [
            "sshpass",
            "-e",
            "ssh",
            "-o",
            "StrictHostKeyChecking=no",
            f"root@{HOST}",
            "bash",
            "-s",
        ],
        input=script,
        text=True,
        capture_output=True,
        env=env,
    )
    sys.stdout.write(p.stdout)
    if p.stderr.strip():
        sys.stderr.write(p.stderr[-2000:] + "\n")
    if p.returncode != 0:
        raise SystemExit(f"ssh failed rc={p.returncode}")
    return p.stdout


def upload(remote_path: str, content: str) -> None:
    env = {**os.environ, "SSHPASS": PW}
    p = subprocess.run(
        [
            "sshpass",
            "-e",
            "ssh",
            "-o",
            "StrictHostKeyChecking=no",
            f"root@{HOST}",
            f"cat > {remote_path}",
        ],
        input=content,
        text=True,
        capture_output=True,
        env=env,
    )
    if p.returncode != 0:
        raise SystemExit(f"upload {remote_path} failed: {p.stderr}")


HEADER = textwrap.dedent(
    """\
    <?php
    $headerClass = $headerClass ?? 'absolute';
    $site = (string) config('site_name');
    $headerStaff = class_exists('Auth') ? Auth::isStaff() : false;
    ?>
    <header class="<?= e($headerClass) ?>">
        <div class="container">
            <div class="wrapper justify-content-between align-items-center">
                <div class="start d-flex align-items-center">
                    <div id="menu-toggler" aria-label="Open menu"><i class="uil uil-list-ui-alt"></i></div>
                    <div class="logo">
                        <a href="<?= e(url('/home')) ?>"><img src="<?= e(asset('img/logo.webp')) ?>?v=20260730-browse1" alt="<?= e($site) ?>" width="388" height="208" decoding="async"></a>
                    </div>
                    <div id="language-toggler" title="Language"><span class="lang-code">EN</span></div>
                    <div id="language-menu" style="display:none">
                        <ul>
                            <li class="active"><a href="#" data-lang="en">English</a></li>
                            <li><a href="#" data-lang="es">Español</a></li>
                            <li><a href="#" data-lang="it">Italiano</a></li>
                            <li><a href="#" data-lang="fr">Français</a></li>
                            <li><a href="#" data-lang="de">Deutsch</a></li>
                            <li><a href="#" data-lang="pt">Português</a></li>
                            <li><a href="#" data-lang="ru">Русский</a></li>
                        </ul>
                    </div>
                    <?php /* Same top links as before — desktop only; phone keeps bottom nav */ ?>
                    <nav id="menu" class="header-nav" aria-label="Main">
                        <ul>
                            <li><a href="<?= e(url('/home')) ?>" class="<?= is_active('/home') ? 'active' : '' ?>">Home</a></li>
                            <li><a href="<?= e(url('/movies')) ?>" class="<?= is_active('/movies') ? 'active' : '' ?>">Movies</a></li>
                            <li><a href="<?= e(url('/tv-series')) ?>" class="<?= is_active('/tv-series') ? 'active' : '' ?>">TV Shows</a></li>
                            <li><a href="<?= e(url('/anime')) ?>" class="<?= is_active('/anime') ? 'active' : '' ?>">Anime</a></li>
                            <li><a href="<?= e(url('/top-imdb')) ?>" class="<?= is_active('/top-imdb') ? 'active' : '' ?>">Top IMDB</a></li>
                            <li><a href="<?= e(url('/favorites')) ?>" class="<?= is_active('/favorites') ? 'active' : '' ?>">Favorites <span class="favorites-counter" hidden>0</span></a></li>
                        </ul>
                    </nav>
                </div>

                <div class="step">
                    <div id="search">
                        <button id="show-search" class="btn-header" type="button" aria-label="Toggle search">
                            <i class="uil uil-search"></i>
                        </button>
                        <div id="search-wrapper">
                            <form class="align-items-center" action="<?= e(url('/search')) ?>" method="get" autocomplete="off" role="search">
                                <a class="filter-btn btn btn-sm" href="<?= e(url('/filters')) ?>">
                                    <i class="uil uil-filter"></i> <span>Filter</span>
                                </a>
                                <input type="text" name="keyword" placeholder="Search movies & TV shows..." aria-label="Search movies and TV shows" value="<?= e($_GET['keyword'] ?? '') ?>">
                                <button type="submit" aria-label="Submit search">
                                    <i class="uil uil-search"></i>
                                </button>
                            </form>
                            <div class="search-suggest" role="listbox" aria-label="Search suggestions"></div>
                        </div>
                    </div>
                </div>
                <div class="end">
                    <?php if ($headerStaff): ?>
                    <a id="header-admin-link" class="header-admin-link" href="<?= e(url('/admin')) ?>" title="Admin" aria-label="Admin panel">
                        <i class="uil uil-shield-check" aria-hidden="true"></i>
                    </a>
                    <?php else: ?>
                    <a id="header-admin-link" class="header-admin-link" href="<?= e(url('/admin')) ?>" title="Admin" aria-label="Admin panel" hidden>
                        <i class="uil uil-shield-check" aria-hidden="true"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    """
)

DASHBOARD = textwrap.dedent(
    """\
    <?php
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
          <p class="cf-admin-signed">Signed in as <?= e((string) ($adminUser['name'] ?? '')) ?> · <?= e((string) ($adminUser['role'] ?? '')) ?></p>
        </header>
        <div class="cf-admin-grid" id="admin-stats">
          <div class="cf-admin-stat"><strong>—</strong><span>Users</span></div>
          <div class="cf-admin-stat"><strong>—</strong><span>Favorites</span></div>
          <div class="cf-admin-stat"><strong>—</strong><span>Continue</span></div>
          <div class="cf-admin-stat"><strong>—</strong><span>Sources on</span></div>
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
    """
)

ADMIN_JS = textwrap.dedent(
    """\
    /* newsite-admin-link-ui91 */
    (function () {
      function base() {
        return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\\/$/, '');
      }
      function meUrl() {
        return base() + '/api/auth/me';
      }
      function ensureAdminChrome(user) {
        var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
        var header = document.getElementById('header-admin-link');
        if (header) {
          if (staff) header.removeAttribute('hidden');
          else header.setAttribute('hidden', 'hidden');
        }
        var existing = document.getElementById('browse-admin-link');
        if (!staff) {
          if (existing) existing.remove();
          return;
        }
        if (existing) return;
        var a = document.createElement('a');
        a.id = 'browse-admin-link';
        a.className = 'browse-list-item';
        a.href = base() + '/admin';
        a.innerHTML = '<i class="uil uil-shield-check" aria-hidden="true"></i><span>Admin</span><i class="uil uil-angle-right" aria-hidden="true"></i>';
        var userBox = document.getElementById('browse-auth-user');
        if (userBox && !userBox.hidden) {
          var logout = userBox.querySelector('#browse-auth-logout');
          if (logout) userBox.insertBefore(a, logout);
          else userBox.appendChild(a);
          return;
        }
        var list = document.querySelector('#browse-account-section .browse-list');
        if (list) list.insertBefore(a, list.firstChild);
      }
      function refreshAdminChrome() {
        fetch(meUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
          .catch(function () { ensureAdminChrome(null); });
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshAdminChrome);
      } else {
        refreshAdminChrome();
      }
      if (window.jQuery) {
        jQuery(document).on('click', '.bottom-nav-browse', function () {
          setTimeout(refreshAdminChrome, 180);
        });
      }
      window.__cfEnsureAdminChrome = ensureAdminChrome;
    })();
    """
)

CSS_EXTRA = textwrap.dedent(
    """\
    /* Header admin shield — right .end slot (ui91) */
    header .wrapper .end {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.35rem;
      min-width: auto;
    }
    .header-admin-link {
      display: inline-flex !important;
      align-items: center;
      justify-content: center;
      width: 2.35rem;
      height: 2.35rem;
      margin: 0;
      border-radius: 0.8rem;
      border: 1px solid rgba(219, 105, 55, 0.55);
      background: rgba(219, 105, 55, 0.22);
      color: #ffd2b8 !important;
      text-decoration: none !important;
      flex-shrink: 0;
    }
    .header-admin-link[hidden] { display: none !important; }
    .header-admin-link i { font-size: 1.25rem; line-height: 1; }
    .header-admin-link:hover {
      background: rgba(219, 105, 55, 0.38);
      color: #fff !important;
    }

    /* Dashboard: kill any leftover gray cards / blurbs */
    .cf-admin-blurb,
    .cf-admin-panel > .cf-admin-blurb { display: none !important; }
    .cf-admin-stat,
    .cf-admin-panel {
      border: 0 !important;
      background: transparent !important;
      box-shadow: none !important;
    }
    .cf-admin-stat {
      padding: 0 !important;
      border-radius: 0 !important;
    }
    .cf-admin-signed { color: rgba(255,255,255,.55); font-size: .88rem; margin: 0; }
    """
)

PATCH_REMOTE = r'''
set -euo pipefail
ROOT=/var/www/chillflix-newsite
VER=20260802-ui91

python3 <<'PY'
from pathlib import Path

# --- bottom-nav staff Admin row ---
p = Path("/var/www/chillflix-newsite/app/Views/partials/bottom-nav.php")
t = p.read_text()
if 'id="browse-admin-link"' in t and "Auth::isStaff()" in t:
    print("bottom-nav already has staff Admin row")
else:
    needle = """                    <button type=\"button\" class=\"browse-list-item\" data-browse-open=\"settings\">
                        <i class=\"uil uil-setting\" aria-hidden=\"true\"></i>
                        <span>Settings</span>
                        <i class=\"uil uil-angle-right\" aria-hidden=\"true\"></i>
                    </button>
                    <button type=\"button\" class=\"browse-list-item\" id=\"browse-auth-logout\">"""
    insert = """                    <button type=\"button\" class=\"browse-list-item\" data-browse-open=\"settings\">
                        <i class=\"uil uil-setting\" aria-hidden=\"true\"></i>
                        <span>Settings</span>
                        <i class=\"uil uil-angle-right\" aria-hidden=\"true\"></i>
                    </button>
                    <?php if (class_exists('Auth') && Auth::isStaff()): ?>
                    <a id=\"browse-admin-link\" class=\"browse-list-item\" href=\"<?= e(url('/admin')) ?>\">
                        <i class=\"uil uil-shield-check\" aria-hidden=\"true\"></i>
                        <span>Admin</span>
                        <i class=\"uil uil-angle-right\" aria-hidden=\"true\"></i>
                    </a>
                    <?php endif; ?>
                    <button type=\"button\" class=\"browse-list-item\" id=\"browse-auth-logout\">"""
    # only replace inside browse-auth-user block (second settings button)
    idx = t.find('id="browse-auth-user"')
    if idx < 0:
        raise SystemExit("browse-auth-user missing")
    sub = t[idx:]
    if needle not in sub:
        raise SystemExit("bottom-nav needle not found in user box")
    sub2 = sub.replace(needle, insert, 1)
    p.write_text(t[:idx] + sub2)
    print("bottom-nav Admin row inserted")

# --- app.js replace broken block ---
js = Path("/var/www/chillflix-newsite/public/assets/js/app.js")
t = js.read_text()
new_block = Path("/tmp/ns-admin-ui91.js").read_text()
start = t.find("/* newsite-admin-link")
if start < 0:
    js.write_text(t.rstrip() + "\n" + new_block + "\n")
    print("admin js appended")
else:
    rest = t[start:]
    end_rel = rest.find("window.__cfEnsureAdminChrome")
    if end_rel < 0:
        raise SystemExit("no ensureAdminChrome")
    end_rel2 = rest.find("})();", end_rel)
    if end_rel2 < 0:
        raise SystemExit("no IIFE end")
    end = start + end_rel2 + len("})();")
    js.write_text(t[:start] + new_block + t[end:])
    print("admin js replaced", start, end)

# --- css ---
css = Path("/var/www/chillflix-newsite/public/assets/css/app.css")
t = css.read_text()
extra = Path("/tmp/ns-admin-ui91.css").read_text()
marker = "/* Header admin shield — right .end slot (ui91) */"
if marker in t:
    print("ui91 css already present")
else:
    old = "/* Header admin shield */"
    if old in t:
        i = t.find(old)
        j = t.find(".page-admin .cf-admin", i)
        if i >= 0 and j > i:
            t = t[:i] + t[j:]
    css.write_text(t.rstrip() + "\n\n" + extra + "\n")
    print("ui91 css appended")
PY

sed -i "s/20260801-ui90/${VER}/g; s/20260802-ui91/${VER}/g" "$ROOT/app/Views/layouts/main.php"
# force known asset lines to VER
python3 - <<PY
from pathlib import Path
p=Path("$ROOT/app/Views/layouts/main.php")
t=p.read_text()
import re
t2=re.sub(r'(\?v=)20260[0-9]{2}-ui[0-9]+', r'\g<1>$VER', t)
# only bump style/app/continue/app.js commonly used
for name in ['css/style.css','css/app.css','css/continue-party.css','js/continue-party.js','js/app.js']:
    t2=re.sub(rf'({name}\)?v=)[^\"&]+', rf'\g<1>$VER', t2)
p.write_text(t2)
print('assets:', sorted(set(re.findall(r'\?v=([^\"&]+)', t2)))[:12])
PY

echo "=== HEADER END ==="
grep -n 'header-admin-link\|class="end"' "$ROOT/app/Views/partials/header.php"
echo "=== JS ==="
grep -n 'newsite-admin-link\|authApi(/api/auth/me\|/api/auth/me' "$ROOT/public/assets/js/app.js" | tail -25
echo "=== BOTTOM ==="
grep -n 'browse-admin-link\|isStaff' "$ROOT/app/Views/partials/bottom-nav.php" | head
php -l "$ROOT/app/Views/partials/header.php"
php -l "$ROOT/app/Views/pages/admin/dashboard.php"
php -l "$ROOT/app/Views/partials/bottom-nav.php"

cd "$ROOT"
php -r '
require "app/bootstrap.php";
$GLOBALS["__cf_config_override"] = ["player_providers" => ["vaplayer"]];
$a = PlayerSources::fetch("movie", 550);
$GLOBALS["__cf_config_override"] = ["player_providers" => ["huhu"]];
$b = PlayerSources::fetch("movie", 550);
$GLOBALS["__cf_config_override"] = ["player_providers" => ["notorrent"]];
$c = PlayerSources::fetch("movie", 550);
echo "vaplayer=".count($a)." huhu=".count($b)." notorrent=".count($c)."\n";
if (count($a) === count($b) && count($a) === 5) { fwrite(STDERR, "FAIL still all 5\n"); exit(1); }
echo "OK distinct source-test counts\n";
'
'''


def main() -> None:
    print("== upload header/dashboard/js/css temps ==")
    upload(f"{ROOT}/app/Views/partials/header.php", HEADER)
    upload(f"{ROOT}/app/Views/pages/admin/dashboard.php", DASHBOARD)
    upload("/tmp/ns-admin-ui91.js", ADMIN_JS)
    upload("/tmp/ns-admin-ui91.css", CSS_EXTRA)
    print("== remote patch ==")
    # Fix VER substitution in the embedded python - use actual VER
    remote = PATCH_REMOTE.replace("$VER", VER)
    ssh(remote)
    print("DONE", VER)


if __name__ == "__main__":
    main()
