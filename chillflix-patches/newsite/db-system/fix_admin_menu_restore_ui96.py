#!/usr/bin/env python3
"""ui96: restore Users/Sources look; fix admin dropdown next to logo (full menu visible)."""
from __future__ import annotations

import os
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui96"
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


def upload(path: str, content: str) -> None:
    env = {**os.environ, "SSHPASS": PW}
    p = subprocess.run(
        [
            "sshpass",
            "-e",
            "ssh",
            "-o",
            "StrictHostKeyChecking=no",
            f"root@{HOST}",
            f"cat > {path}",
        ],
        input=content,
        text=True,
        capture_output=True,
        env=env,
    )
    if p.returncode != 0:
        raise SystemExit(f"upload failed: {p.stderr}")


HEADER = textwrap.dedent(
    """\
    <?php
    $headerClass = $headerClass ?? 'absolute';
    $site = (string) config('site_name');
    $headerStaff = class_exists('Auth') ? Auth::isStaff() : false;
    $adminUser = $headerStaff && class_exists('Auth') ? Auth::user() : null;
    $adminPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $adminRole = (string) (($adminUser['role'] ?? '') ?: 'staff');
    $adminName = (string) (($adminUser['name'] ?? '') ?: 'Admin');
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
                    <div class="header-admin-menu<?= $headerStaff ? ' is-staff' : '' ?>" id="header-admin-menu"<?= $headerStaff ? '' : ' hidden' ?>>
                        <button type="button" id="header-admin-link" class="header-admin-link" title="Admin" aria-label="Admin menu" aria-haspopup="menu" aria-expanded="false" aria-controls="header-admin-dropdown">
                            <i class="uil uil-shield-check" aria-hidden="true"></i>
                        </button>
                        <div id="header-admin-dropdown" class="header-admin-dropdown" role="menu" hidden>
                            <div class="header-admin-dropdown-top">
                                <span class="header-admin-dropdown-kicker">Control</span>
                                <strong><?= e($adminName) ?></strong>
                                <em><?= e(ucfirst($adminRole)) ?></em>
                            </div>
                            <div class="header-admin-dropdown-list">
                                <a role="menuitem" href="<?= e(url('/admin')) ?>" class="<?= preg_match('#/admin/?$#', $adminPath) ? 'is-active' : '' ?>">
                                    <span class="header-admin-ico"><i class="uil uil-chart" aria-hidden="true"></i></span>
                                    <span class="header-admin-copy"><strong>Dashboard</strong><small>Overview & health</small></span>
                                    <i class="uil uil-angle-right" aria-hidden="true"></i>
                                </a>
                                <a role="menuitem" href="<?= e(url('/admin/users')) ?>" class="<?= str_contains($adminPath, '/admin/users') ? 'is-active' : '' ?>">
                                    <span class="header-admin-ico"><i class="uil uil-users-alt" aria-hidden="true"></i></span>
                                    <span class="header-admin-copy"><strong>Users</strong><small>Roles & accounts</small></span>
                                    <i class="uil uil-angle-right" aria-hidden="true"></i>
                                </a>
                                <a role="menuitem" href="<?= e(url('/admin/sources')) ?>" class="<?= str_contains($adminPath, '/admin/sources') ? 'is-active' : '' ?>">
                                    <span class="header-admin-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
                                    <span class="header-admin-copy"><strong>Sources</strong><small>Order, labels, tests</small></span>
                                    <i class="uil uil-angle-right" aria-hidden="true"></i>
                                </a>
                            </div>
                            <a role="menuitem" class="header-admin-back" href="<?= e(url('/home')) ?>">
                                <i class="uil uil-arrow-left" aria-hidden="true"></i><span>Back to site</span>
                            </a>
                        </div>
                    </div>
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
                <div class="end"></div>
            </div>
        </div>
    </header>
    """
)

# Keep polished dashboard; restore Site arrow on users/sources via remote patch
USERS_SITE = ">← Site</a>"
SOURCES_SITE = ">← Site</a>"

CSS_PATCH = textwrap.dedent(
    """\
    /* ui96: restore pill admin nav (Users/Sources liked this); fix dropdown clip */

    /* Shared admin nav — original pill style */
    .cf-admin-nav {
      display: flex !important;
      flex-wrap: wrap !important;
      gap: 0.45rem !important;
      margin: 0 0 1.1rem !important;
      border-bottom: 0 !important;
    }
    .cf-admin-nav a {
      position: static !important;
      display: inline-flex !important;
      align-items: center !important;
      gap: 0.35rem !important;
      min-height: 2.2rem !important;
      padding: 0.35rem 0.85rem !important;
      border-radius: 999px !important;
      border: 1px solid rgba(255,255,255,.1) !important;
      background: rgba(0,0,0,.22) !important;
      color: rgba(255,255,255,.82) !important;
      text-decoration: none !important;
      font-size: 0.78rem !important;
      font-weight: 700 !important;
      letter-spacing: 0.03em !important;
      text-transform: uppercase !important;
    }
    .cf-admin-nav a::after { display: none !important; content: none !important; }
    .cf-admin-nav a.is-active,
    .cf-admin-nav a:hover {
      border-color: rgba(219,105,55,.5) !important;
      background: rgba(219,105,55,.16) !important;
      color: #fff !important;
    }

    /* Users/Sources page titles — keep previous quieter style */
    body.page-admin:not(.page-admin-dashboard) .cf-admin-head h1 {
      font-size: clamp(1.5rem, 4vw, 1.9rem) !important;
      letter-spacing: -0.03em;
    }
    body.page-admin:not(.page-admin-dashboard) .cf-admin-head p {
      color: rgba(244,241,236,.62);
      font-size: 0.9rem;
      max-width: none;
    }

    /* Dashboard-only atmosphere (don't force it onto users/sources feel) */
    body.page-admin-dashboard {
      background:
        radial-gradient(900px 420px at 12% -8%, rgba(219,105,55,.16), transparent 55%),
        radial-gradient(700px 360px at 90% 0%, rgba(220,53,69,.10), transparent 50%),
        #10131a;
    }
    body.page-admin:not(.page-admin-dashboard) {
      background: #1e2129;
    }

    /* Admin icon sits next to logo/EN — visible on mobile ( .end is hidden on phone ) */
    header .wrapper .start .header-admin-menu {
      margin-left: 0.25rem;
      position: relative;
      z-index: 80;
    }

    /* Stop header chrome from clipping the menu */
    body.page-admin header,
    header:has(.header-admin-menu.is-open),
    header .wrapper:has(.header-admin-menu.is-open),
    header .wrapper .start:has(.header-admin-menu.is-open),
    header .container:has(.header-admin-menu.is-open) {
      overflow: visible !important;
    }
    header:has(.header-admin-menu.is-open) {
      z-index: 120 !important;
    }

    .header-admin-menu {
      position: relative;
      display: inline-flex;
      align-items: center;
    }
    .header-admin-menu[hidden] { display: none !important; }

    .header-admin-link {
      display: inline-flex !important;
      align-items: center;
      justify-content: center;
      width: 2.25rem;
      height: 2.25rem;
      margin: 0;
      padding: 0;
      border-radius: 0.75rem;
      border: 1px solid rgba(219,105,55,.55);
      background: linear-gradient(160deg, rgba(219,105,55,.28), rgba(220,53,69,.14));
      color: #ffd2b8 !important;
      cursor: pointer;
      appearance: none;
      flex-shrink: 0;
    }
    .header-admin-link i { font-size: 1.2rem; line-height: 1; }
    .header-admin-link:hover,
    .header-admin-menu.is-open .header-admin-link {
      color: #fff !important;
      border-color: rgba(255,180,140,.65);
    }

    /* Fixed positioning escapes header overflow / height clip */
    .header-admin-dropdown {
      position: fixed !important;
      top: 0;
      left: 0;
      width: min(18.5rem, calc(100vw - 1.25rem));
      max-height: min(70vh, 28rem);
      overflow-x: hidden;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      padding: 0;
      border-radius: 1.05rem;
      border: 1px solid rgba(255,255,255,.12);
      background:
        linear-gradient(180deg, rgba(255,255,255,.03), transparent 34%),
        #161a22;
      box-shadow:
        0 18px 40px rgba(0,0,0,.55),
        0 0 0 1px rgba(219,105,55,.1);
      z-index: 10050 !important;
      animation: headerAdminDrop .16s ease;
    }
    .header-admin-dropdown[hidden] { display: none !important; }
    @keyframes headerAdminDrop {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .header-admin-dropdown-top {
      padding: 0.95rem 1rem 0.85rem;
      border-bottom: 1px solid rgba(255,255,255,.08);
      background: linear-gradient(135deg, rgba(219,105,55,.18), rgba(220,53,69,.06));
    }
    .header-admin-dropdown-kicker {
      display: block;
      margin-bottom: 0.2rem;
      color: #ffb08a;
      font-size: 0.65rem;
      font-weight: 750;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }
    .header-admin-dropdown-top strong {
      display: block;
      color: #fff;
      font-size: 1rem;
      font-weight: 750;
    }
    .header-admin-dropdown-top em {
      display: block;
      margin-top: 0.1rem;
      color: rgba(255,255,255,.55);
      font-style: normal;
      font-size: 0.78rem;
      text-transform: capitalize;
    }
    .header-admin-dropdown-list {
      display: flex;
      flex-direction: column;
      padding: 0.4rem;
      gap: 0.15rem;
    }
    .header-admin-dropdown-list a {
      display: flex;
      align-items: center;
      gap: 0.7rem;
      min-height: 3.15rem;
      padding: 0.55rem 0.6rem;
      border-radius: 0.75rem;
      color: #fff !important;
      text-decoration: none !important;
    }
    .header-admin-dropdown-list a:hover,
    .header-admin-dropdown-list a.is-active {
      background: rgba(219,105,55,.14);
    }
    .header-admin-ico {
      width: 2.15rem; height: 2.15rem;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 0.7rem;
      background: rgba(255,255,255,.05);
      color: #ffd2b8;
      flex-shrink: 0;
    }
    .header-admin-copy { flex: 1; min-width: 0; }
    .header-admin-copy strong { display: block; font-size: 0.92rem; font-weight: 700; }
    .header-admin-copy small {
      display: block;
      margin-top: 0.1rem;
      color: rgba(255,255,255,.48);
      font-size: 0.72rem;
    }
    .header-admin-dropdown-list a > .uil-angle-right {
      color: rgba(255,255,255,.28);
      font-size: 1.15rem;
    }
    .header-admin-back {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.8rem 1rem;
      border-top: 1px solid rgba(255,255,255,.08);
      color: rgba(255,255,255,.62) !important;
      text-decoration: none !important;
      font-size: 0.84rem;
      font-weight: 600;
    }
    .header-admin-back:hover { color: #fff !important; background: rgba(255,255,255,.03); }
    """
)

JS = textwrap.dedent(
    """\
    /* newsite-admin-menu-ui96 */
    (function () {
      function base() {
        return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\\/$/, '');
      }
      function menuRoot() { return document.getElementById('header-admin-menu'); }
      function dropdown() { return document.getElementById('header-admin-dropdown'); }
      function toggleBtn() { return document.getElementById('header-admin-link'); }

      function placeDropdown() {
        var btn = toggleBtn();
        var dd = dropdown();
        if (!btn || !dd || dd.hidden) return;
        var r = btn.getBoundingClientRect();
        var pad = 8;
        var width = Math.min(296, window.innerWidth - pad * 2);
        var left = Math.min(Math.max(pad, r.left), window.innerWidth - width - pad);
        // Prefer below; if not enough room, flip above
        var below = r.bottom + 8;
        var estHeight = Math.min(dd.scrollHeight || 320, window.innerHeight * 0.7);
        var top = below;
        if (below + estHeight > window.innerHeight - pad && r.top > estHeight + pad) {
          top = Math.max(pad, r.top - estHeight - 8);
        }
        dd.style.position = 'fixed';
        dd.style.top = Math.round(top) + 'px';
        dd.style.left = Math.round(left) + 'px';
        dd.style.right = 'auto';
        dd.style.width = width + 'px';
        dd.style.zIndex = '10050';
      }

      function closeMenu() {
        var root = menuRoot();
        var dd = dropdown();
        var btn = toggleBtn();
        if (!root || !dd || !btn) return;
        dd.hidden = true;
        root.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('admin-menu-open');
      }
      function openMenu() {
        var root = menuRoot();
        var dd = dropdown();
        var btn = toggleBtn();
        if (!root || !dd || !btn || root.hidden) return;
        dd.hidden = false;
        root.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        document.documentElement.classList.add('admin-menu-open');
        placeDropdown();
        // second pass after layout for accurate height
        requestAnimationFrame(placeDropdown);
      }
      function ensureAdminChrome(user) {
        var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
        var root = menuRoot();
        if (root) {
          if (staff) root.removeAttribute('hidden');
          else { root.setAttribute('hidden', 'hidden'); closeMenu(); }
        }
        var existing = document.getElementById('browse-admin-link');
        if (!staff) { if (existing) existing.remove(); return; }
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
        fetch(base() + '/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
          .catch(function () { ensureAdminChrome(null); });
      }
      document.addEventListener('click', function (e) {
        var root = menuRoot();
        var btn = toggleBtn();
        var dd = dropdown();
        if (!root || !btn || root.hidden) return;
        var t = e.target;
        if (btn === t || btn.contains(t)) {
          e.preventDefault();
          if (root.classList.contains('is-open')) closeMenu();
          else openMenu();
          return;
        }
        if (dd && !dd.hidden && dd.contains(t)) return;
        if (!root.contains(t)) closeMenu();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
      });
      window.addEventListener('resize', function () {
        if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
      });
      window.addEventListener('scroll', function () {
        if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
      }, true);
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

REMOTE = textwrap.dedent(
    """\
    from pathlib import Path
    import re

    root = Path("/var/www/chillflix-newsite")
    ver = "20260802-ui96"

    # body class page-admin-dashboard only on dashboard route — set in dashboard view via... 
    # easiest: add class on <main> and also inject via layout if bodyClass supports it.
    # Patch routes to pass bodyClass page-admin page-admin-dashboard
    routes = root / "app/routes.php"
    rt = routes.read_text()
    rt2 = rt.replace(
        "'bodyClass' => 'page-admin',\n    ]);\n});\n\n$router->get('/admin/users'",
        "'bodyClass' => 'page-admin page-admin-dashboard',\n    ]);\n});\n\n$router->get('/admin/users'",
    )
    if rt2 == rt:
        # try looser
        rt2 = rt.replace(
            "view('pages/admin/dashboard', [\n        'adminUser' => $user,\n        'title' => 'Admin',\n        'bodyClass' => 'page-admin',",
            "view('pages/admin/dashboard', [\n        'adminUser' => $user,\n        'title' => 'Admin',\n        'bodyClass' => 'page-admin page-admin-dashboard',",
        )
    routes.write_text(rt2)
    print("routes dashboard class", "page-admin-dashboard" in routes.read_text())

    # restore ← Site on users/sources
    for rel in ["app/Views/pages/admin/users.php", "app/Views/pages/admin/sources.php"]:
        p = root / rel
        t = p.read_text()
        t = t.replace(">Site</a>", ">← Site</a>")
        # avoid double arrow
        t = t.replace(">← ← Site</a>", ">← Site</a>")
        p.write_text(t)
        print(rel, "← Site" in t)

    # CSS: append ui96 overrides at end (wins)
    css = root / "public/assets/css/app.css"
    t = css.read_text()
    marker = "/* ui96: restore pill admin nav"
    extra = Path("/tmp/ns-admin-ui96.css").read_text()
    if marker in t:
        i = t.find(marker)
        t = t[:i].rstrip() + "\\n\\n" + extra + "\\n"
        print("replaced ui96 css")
    else:
        t = t.rstrip() + "\\n\\n" + extra + "\\n"
        print("appended ui96 css")
    css.write_text(t)

    # JS replace admin menu block
    js = root / "public/assets/js/app.js"
    jt = js.read_text()
    new_js = Path("/tmp/ns-admin-ui96.js").read_text()
    start = -1
    for m in ["/* newsite-admin-menu-ui96 */", "/* newsite-admin-menu-ui94 */", "/* newsite-admin-link-ui91 */", "/* newsite-admin-link"]:
        start = jt.find(m)
        if start >= 0:
            break
    if start < 0:
        js.write_text(jt.rstrip() + "\\n" + new_js + "\\n")
        print("js appended")
    else:
        rest = jt[start:]
        end_rel = rest.find("window.__cfEnsureAdminChrome")
        end_marker = "})();"
        end_rel2 = rest.find(end_marker, end_rel)
        end = start + end_rel2 + len(end_marker)
        js.write_text(jt[:start] + new_js + jt[end:])
        print("js replaced", start, end)

    layout = root / "app/Views/layouts/main.php"
    lt = layout.read_text()
    lt2 = re.sub(r"(\\?v=)2026080[12]-ui[0-9]+", r"\\g<1>" + ver, lt)
    layout.write_text(lt2)
    print("assets", sorted(set(re.findall(r"\\?v=([^\\\"&]+)", lt2)))[:8])

    h = (root / "app/Views/partials/header.php").read_text()
    print("admin next to lang", h.find("language-toggler") < h.find("header-admin-menu"))
    print("admin not only in end", 'class="end">\\n                <div class="header-admin-menu"' not in h)
    """
)


def main() -> None:
    upload(f"{ROOT}/app/Views/partials/header.php", HEADER)
    upload("/tmp/ns-admin-ui96.css", CSS_PATCH)
    upload("/tmp/ns-admin-ui96.js", JS)
    upload("/tmp/ns-admin-ui96-patch.py", REMOTE)
    ssh(
        textwrap.dedent(
            f"""\
            set -e
            python3 /tmp/ns-admin-ui96-patch.py
            # show route dashboard bodyClass
            grep -n "page-admin" {ROOT}/app/routes.php | head -20
            php -l {ROOT}/app/Views/partials/header.php
            php -l {ROOT}/app/routes.php
            """
        )
    )
    print("DONE", VER)


if __name__ == "__main__":
    main()
