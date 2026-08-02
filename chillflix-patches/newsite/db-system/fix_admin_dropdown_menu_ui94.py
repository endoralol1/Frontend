#!/usr/bin/env python3
"""ui94: Admin shield opens a real dropdown menu (Dashboard/Users/Sources/Site)."""
from __future__ import annotations

import os
import re
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui94"
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
    $adminPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
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
                    <div class="header-admin-menu<?= $headerStaff ? ' is-staff' : '' ?>" id="header-admin-menu"<?= $headerStaff ? '' : ' hidden' ?>>
                        <button type="button" id="header-admin-link" class="header-admin-link" title="Admin" aria-label="Admin menu" aria-haspopup="menu" aria-expanded="false" aria-controls="header-admin-dropdown">
                            <i class="uil uil-shield-check" aria-hidden="true"></i>
                        </button>
                        <div id="header-admin-dropdown" class="header-admin-dropdown" role="menu" hidden>
                            <a role="menuitem" href="<?= e(url('/admin')) ?>" class="<?= str_ends_with($adminPath, '/admin') ? 'is-active' : '' ?>">
                                <i class="uil uil-dashboard" aria-hidden="true"></i><span>Dashboard</span>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/users')) ?>" class="<?= str_contains($adminPath, '/admin/users') ? 'is-active' : '' ?>">
                                <i class="uil uil-users-alt" aria-hidden="true"></i><span>Users</span>
                            </a>
                            <a role="menuitem" href="<?= e(url('/admin/sources')) ?>" class="<?= str_contains($adminPath, '/admin/sources') ? 'is-active' : '' ?>">
                                <i class="uil uil-server" aria-hidden="true"></i><span>Sources</span>
                            </a>
                            <a role="menuitem" class="is-muted" href="<?= e(url('/home')) ?>">
                                <i class="uil uil-arrow-left" aria-hidden="true"></i><span>Back to site</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    """
)

CSS = textwrap.dedent(
    """\
    /* ui94: admin shield dropdown menu */
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
      width: 2.35rem;
      height: 2.35rem;
      margin: 0;
      padding: 0;
      border-radius: 0.8rem;
      border: 1px solid rgba(219, 105, 55, 0.55);
      background: rgba(219, 105, 55, 0.22);
      color: #ffd2b8 !important;
      text-decoration: none !important;
      flex-shrink: 0;
      cursor: pointer;
      appearance: none;
    }
    .header-admin-link i { font-size: 1.25rem; line-height: 1; }
    .header-admin-link:hover,
    .header-admin-menu.is-open .header-admin-link {
      background: rgba(219, 105, 55, 0.38);
      color: #fff !important;
    }
    .header-admin-dropdown {
      position: absolute;
      top: calc(100% + 0.45rem);
      right: 0;
      min-width: 11.5rem;
      padding: 0.35rem;
      border-radius: 0.9rem;
      border: 1px solid rgba(255,255,255,.12);
      background: #1a1d24;
      box-shadow: 0 12px 28px rgba(0,0,0,.45);
      z-index: 80;
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      animation: headerAdminDrop .16s ease;
    }
    .header-admin-dropdown[hidden] { display: none !important; }
    @keyframes headerAdminDrop {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .header-admin-dropdown a {
      display: flex;
      align-items: center;
      gap: 0.55rem;
      min-height: 2.35rem;
      padding: 0.4rem 0.7rem;
      border-radius: 0.65rem;
      color: rgba(255,255,255,.9) !important;
      text-decoration: none !important;
      font-size: 0.88rem;
      font-weight: 600;
    }
    .header-admin-dropdown a i {
      font-size: 1.05rem;
      color: #ffd2b8;
      width: 1.1rem;
      text-align: center;
    }
    .header-admin-dropdown a:hover,
    .header-admin-dropdown a.is-active {
      background: rgba(219,105,55,.18);
      color: #fff !important;
    }
    .header-admin-dropdown a.is-muted {
      margin-top: 0.15rem;
      border-top: 1px solid rgba(255,255,255,.08);
      border-radius: 0 0 0.65rem 0.65rem;
      color: rgba(255,255,255,.62) !important;
      font-weight: 500;
    }
    .header-admin-dropdown a.is-muted i { color: rgba(255,255,255,.45); }

    header .wrapper .end {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.35rem;
      min-width: auto;
      position: relative;
      z-index: 60;
    }
    """
)

JS = textwrap.dedent(
    """\
    /* newsite-admin-menu-ui94 */
    (function () {
      function base() {
        return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\\/$/, '');
      }
      function menuRoot() {
        return document.getElementById('header-admin-menu');
      }
      function dropdown() {
        return document.getElementById('header-admin-dropdown');
      }
      function toggleBtn() {
        return document.getElementById('header-admin-link');
      }
      function closeMenu() {
        var root = menuRoot();
        var dd = dropdown();
        var btn = toggleBtn();
        if (!root || !dd || !btn) return;
        dd.hidden = true;
        root.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
      }
      function openMenu() {
        var root = menuRoot();
        var dd = dropdown();
        var btn = toggleBtn();
        if (!root || !dd || !btn || root.hidden) return;
        dd.hidden = false;
        root.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
      }
      function ensureAdminChrome(user) {
        var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
        var root = menuRoot();
        if (root) {
          if (staff) root.removeAttribute('hidden');
          else {
            root.setAttribute('hidden', 'hidden');
            closeMenu();
          }
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
        fetch(base() + '/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
          .catch(function () { ensureAdminChrome(null); });
      }
      document.addEventListener('click', function (e) {
        var root = menuRoot();
        var btn = toggleBtn();
        if (!root || !btn || root.hidden) return;
        var t = e.target;
        if (btn === t || btn.contains(t)) {
          e.preventDefault();
          if (root.classList.contains('is-open')) closeMenu();
          else openMenu();
          return;
        }
        if (!root.contains(t)) closeMenu();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
      });
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

REMOTE_PY = textwrap.dedent(
    """\
    from pathlib import Path
    import re

    root = Path("/var/www/chillflix-newsite")
    ver = "20260802-ui94"

    css = root / "public/assets/css/app.css"
    t = css.read_text()
    bad = ".page-admin .cf-admin { padding-bottom:calc(8rem + env(safe-area-inset-bottom,0px)); }\\n}"
    good = ".page-admin .cf-admin { padding-bottom:calc(8rem + env(safe-area-inset-bottom,0px)); }"
    if bad in t:
        t = t.replace(bad, good)
        print("fixed stray brace")

    # Drop old ui91 shield block if present (avoid duplicate conflicting rules)
    marker91 = "/* Header admin shield — right .end slot (ui91) */"
    if marker91 in t:
        i = t.find(marker91)
        # remove until ui93 or end-ish
        j = t.find("/* ui93:", i)
        if j < 0:
            j = t.find("/* Footer:", i)
        if j > i:
            t = t[:i] + t[j:]
            print("removed ui91 shield css")

    marker94 = "/* ui94: admin shield dropdown menu */"
    extra = Path("/tmp/ns-admin-ui94.css").read_text()
    if marker94 in t:
        i = t.find(marker94)
        t = t[:i].rstrip() + "\\n\\n" + extra + "\\n"
        print("replaced ui94 css")
    else:
        t = t.rstrip() + "\\n\\n" + extra + "\\n"
        print("appended ui94 css")
    css.write_text(t)

    js = root / "public/assets/js/app.js"
    jt = js.read_text()
    new_js = Path("/tmp/ns-admin-ui94.js").read_text()
    start = jt.find("/* newsite-admin-link")
    if start < 0:
        start = jt.find("/* newsite-admin-menu")
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
    print("has dropdown", "header-admin-dropdown" in h)
    print("has menu button", 'aria-haspopup="menu"' in h)
    """
)


def main() -> None:
    upload(f"{ROOT}/app/Views/partials/header.php", HEADER)
    upload("/tmp/ns-admin-ui94.css", CSS)
    upload("/tmp/ns-admin-ui94.js", JS)
    upload("/tmp/ns-admin-ui94-patch.py", REMOTE_PY)
    ssh(
        textwrap.dedent(
            f"""\
            set -e
            python3 /tmp/ns-admin-ui94-patch.py
            php -l {ROOT}/app/Views/partials/header.php
            """
        )
    )
    print("DONE", VER)


if __name__ == "__main__":
    main()
