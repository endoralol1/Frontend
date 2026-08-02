#!/usr/bin/env python3
"""ui95: polish admin dropdown + dashboard to feel intentional, not empty/cheap."""
from __future__ import annotations

import os
import re
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui95"
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
                <div class="end">
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
    $name = (string) ($adminUser['name'] ?? 'Admin');
    $role = (string) ($adminUser['role'] ?? 'admin');
    ?>
    <main class="cf-admin page-pad-top">
      <div class="container cf-admin-wrap">
        <nav class="cf-admin-nav" aria-label="Admin">
          <a class="is-active" href="<?= e(url('/admin')) ?>">Dashboard</a>
          <a href="<?= e(url('/admin/users')) ?>">Users</a>
          <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
          <a href="<?= e(url('/home')) ?>">Site</a>
        </nav>

        <section class="cf-admin-hero">
          <div class="cf-admin-hero-copy">
            <p class="cf-admin-kicker">Newsite control</p>
            <h1>Welcome back, <?= e($name) ?></h1>
            <p class="cf-admin-signed">Signed in as <span><?= e($role) ?></span> · manage accounts, sync, and playback sources</p>
          </div>
        </section>

        <section class="cf-admin-metrics" id="admin-stats" aria-label="Site metrics">
          <div class="cf-admin-metric">
            <span class="cf-admin-metric-ico"><i class="uil uil-users-alt" aria-hidden="true"></i></span>
            <div><strong>—</strong><span>Users</span></div>
          </div>
          <div class="cf-admin-metric">
            <span class="cf-admin-metric-ico"><i class="uil uil-heart" aria-hidden="true"></i></span>
            <div><strong>—</strong><span>Favorites</span></div>
          </div>
          <div class="cf-admin-metric">
            <span class="cf-admin-metric-ico"><i class="uil uil-history" aria-hidden="true"></i></span>
            <div><strong>—</strong><span>Continue</span></div>
          </div>
          <div class="cf-admin-metric">
            <span class="cf-admin-metric-ico"><i class="uil uil-server" aria-hidden="true"></i></span>
            <div><strong>—</strong><span>Sources on</span></div>
          </div>
        </section>

        <section class="cf-admin-actions" aria-label="Quick actions">
          <h2>Quick actions</h2>
          <div class="cf-admin-action-list">
            <a class="cf-admin-action" href="<?= e(url('/admin/users')) ?>">
              <span class="cf-admin-action-ico"><i class="uil uil-user-circle" aria-hidden="true"></i></span>
              <span class="cf-admin-action-copy">
                <strong>Manage users</strong>
                <small>Roles, status, and account cleanup</small>
              </span>
              <i class="uil uil-angle-right" aria-hidden="true"></i>
            </a>
            <a class="cf-admin-action" href="<?= e(url('/admin/sources')) ?>">
              <span class="cf-admin-action-ico"><i class="uil uil-sliders-v" aria-hidden="true"></i></span>
              <span class="cf-admin-action-copy">
                <strong>Control sources</strong>
                <small>Enable, reorder, label Alpha/Beta, run tests</small>
              </span>
              <i class="uil uil-angle-right" aria-hidden="true"></i>
            </a>
            <a class="cf-admin-action" href="<?= e(url('/home')) ?>">
              <span class="cf-admin-action-ico"><i class="uil uil-play" aria-hidden="true"></i></span>
              <span class="cf-admin-action-copy">
                <strong>Open site</strong>
                <small>Return to browsing Chillflix</small>
              </span>
              <i class="uil uil-angle-right" aria-hidden="true"></i>
            </a>
          </div>
        </section>

        <section class="cf-admin-note" id="admin-role-note" aria-live="polite">
          <p><i class="uil uil-shield-check" aria-hidden="true"></i> Staff tools stay on newsite only — public viewers still see Alpha/Beta source names.</p>
        </section>
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
          var items=[
            ['uil-users-alt','Users',s.users||0],
            ['uil-heart','Favorites',s.favorites||0],
            ['uil-history','Continue',s.continue||0],
            ['uil-server','Sources on',s.sourcesEnabled||0]
          ];
          el.innerHTML = items.map(function(it){
            return '<div class="cf-admin-metric">'+
              '<span class="cf-admin-metric-ico"><i class="uil '+it[0]+'" aria-hidden="true"></i></span>'+
              '<div><strong>'+it[2]+'</strong><span>'+it[1]+'</span></div></div>';
          }).join('');
        }).catch(function(){});
    })();
    </script>
    """
)

CSS = textwrap.dedent(
    """\
    /* ui95: polished admin dropdown + dashboard */
    :root {
      --adm-ink: #f3f0ea;
      --adm-muted: rgba(243,240,234,.58);
      --adm-line: rgba(255,255,255,.08);
      --adm-hot: #db6937;
      --adm-hot-soft: rgba(219,105,55,.16);
      --adm-deep: #12151c;
      --adm-panel: rgba(20,23,31,.92);
    }

    body.page-admin {
      background:
        radial-gradient(900px 420px at 12% -8%, rgba(219,105,55,.16), transparent 55%),
        radial-gradient(700px 360px at 90% 0%, rgba(220,53,69,.10), transparent 50%),
        #10131a;
    }
    body.page-admin > header.relative,
    body.page-admin header.relative {
      background: transparent !important;
      border-bottom: 0 !important;
      box-shadow: none !important;
    }
    body.page-admin .footer-v3-panel,
    body.page-admin .footer--v3,
    body.page-admin .footer {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
    }

    .page-admin .cf-admin {
      --adm-ink: #f3f0ea;
      --adm-muted: rgba(243,240,234,.58);
      --adm-line: rgba(255,255,255,.08);
      --adm-hot: #db6937;
      position: relative;
      z-index: 1;
      padding: 0.35rem 0 3.5rem;
      padding-bottom: calc(8rem + env(safe-area-inset-bottom,0px));
    }
    .cf-admin-wrap { width: min(920px, 100%); margin: 0 auto; }

    /* Tabs: underline, not cheap pills */
    .cf-admin-nav {
      display: flex;
      flex-wrap: wrap;
      gap: 0.15rem 1.1rem;
      margin: 0 0 1.35rem;
      border-bottom: 1px solid var(--adm-line);
    }
    .cf-admin-nav a {
      position: relative;
      display: inline-flex;
      align-items: center;
      min-height: 2.55rem;
      padding: 0.2rem 0;
      border: 0 !important;
      border-radius: 0 !important;
      background: transparent !important;
      color: rgba(255,255,255,.55) !important;
      text-decoration: none !important;
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    .cf-admin-nav a::after {
      content: "";
      position: absolute;
      left: 0; right: 0; bottom: -1px;
      height: 2px;
      background: transparent;
      border-radius: 2px;
      transition: background .15s ease;
    }
    .cf-admin-nav a.is-active,
    .cf-admin-nav a:hover {
      color: #fff !important;
    }
    .cf-admin-nav a.is-active::after {
      background: linear-gradient(90deg, var(--adm-hot), #dc3545);
    }

    .cf-admin-hero { margin: 0 0 1.5rem; }
    .cf-admin-kicker {
      margin: 0 0 0.35rem;
      color: #ffb08a;
      font-size: 0.72rem;
      font-weight: 750;
      letter-spacing: 0.14em;
      text-transform: uppercase;
    }
    .cf-admin-hero h1,
    .cf-admin-head h1 {
      margin: 0 0 0.35rem;
      color: #fff;
      font-family: Outfit, Poppins, sans-serif;
      font-size: clamp(1.7rem, 4.5vw, 2.15rem);
      font-weight: 800;
      letter-spacing: -0.035em;
      line-height: 1.15;
    }
    .cf-admin-signed {
      margin: 0;
      color: var(--adm-muted);
      font-size: 0.95rem;
      line-height: 1.45;
      max-width: 36rem;
    }
    .cf-admin-signed span {
      color: #ffd2b8;
      text-transform: capitalize;
      font-weight: 650;
    }

    header.cf-admin-head,
    .cf-admin-head {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
      margin: 0 0 1.15rem !important;
      padding: 0 !important;
      width: auto !important;
      height: auto !important;
      min-height: 0 !important;
      position: static !important;
      z-index: auto !important;
      display: block !important;
    }

    /* Metrics: open strip, no gray cards */
    .cf-admin-metrics,
    .cf-admin-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0;
      margin: 0 0 1.75rem;
      border-top: 1px solid var(--adm-line);
      border-bottom: 1px solid var(--adm-line);
    }
    @media (min-width: 720px) {
      .cf-admin-metrics,
      .cf-admin-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .cf-admin-metric,
    .cf-admin-stat {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 1.05rem 0.85rem;
      border: 0 !important;
      border-right: 1px solid var(--adm-line) !important;
      background: transparent !important;
      border-radius: 0 !important;
      box-shadow: none !important;
    }
    .cf-admin-metric:nth-child(2n),
    .cf-admin-stat:nth-child(2n) { border-right: 0 !important; }
    @media (min-width: 720px) {
      .cf-admin-metric,
      .cf-admin-stat { border-right: 1px solid var(--adm-line) !important; }
      .cf-admin-metric:last-child,
      .cf-admin-stat:last-child { border-right: 0 !important; }
    }
    @media (max-width: 719.98px) {
      .cf-admin-metric:nth-child(-n+2),
      .cf-admin-stat:nth-child(-n+2) {
        border-bottom: 1px solid var(--adm-line) !important;
      }
    }
    .cf-admin-metric-ico {
      width: 2.35rem; height: 2.35rem;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 0.7rem;
      background: var(--adm-hot-soft);
      color: #ffd2b8;
      flex-shrink: 0;
    }
    .cf-admin-metric-ico i { font-size: 1.15rem; line-height: 1; }
    .cf-admin-metric strong,
    .cf-admin-stat strong {
      display: block;
      color: #fff;
      font-size: 1.55rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      line-height: 1.1;
    }
    .cf-admin-metric span,
    .cf-admin-stat span {
      color: var(--adm-muted);
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .cf-admin-actions h2 {
      margin: 0 0 0.75rem;
      color: rgba(255,255,255,.5);
      font-size: 0.72rem;
      font-weight: 750;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    .cf-admin-action-list {
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
    }
    .cf-admin-action {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      min-height: 4.1rem;
      padding: 0.85rem 0.95rem;
      border-radius: 1rem;
      border: 1px solid rgba(255,255,255,.08);
      background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.015));
      color: #fff !important;
      text-decoration: none !important;
      transition: border-color .15s ease, transform .15s ease, background .15s ease;
    }
    .cf-admin-action:hover {
      border-color: rgba(219,105,55,.45);
      background: linear-gradient(180deg, rgba(219,105,55,.14), rgba(255,255,255,.02));
      transform: translateY(-1px);
    }
    .cf-admin-action-ico {
      width: 2.55rem; height: 2.55rem;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 0.8rem;
      background: rgba(0,0,0,.28);
      color: #ffd2b8;
      flex-shrink: 0;
    }
    .cf-admin-action-ico i { font-size: 1.25rem; }
    .cf-admin-action-copy { flex: 1; min-width: 0; }
    .cf-admin-action-copy strong {
      display: block;
      font-size: 1rem;
      font-weight: 750;
      letter-spacing: -0.01em;
    }
    .cf-admin-action-copy small {
      display: block;
      margin-top: 0.15rem;
      color: var(--adm-muted);
      font-size: 0.82rem;
    }
    .cf-admin-action > .uil-angle-right {
      color: rgba(255,255,255,.35);
      font-size: 1.25rem;
    }

    .cf-admin-note {
      margin-top: 1.35rem;
      color: rgba(255,255,255,.48);
      font-size: 0.84rem;
      line-height: 1.45;
    }
    .cf-admin-note p { margin: 0; display: flex; gap: 0.45rem; align-items: flex-start; }
    .cf-admin-note i { color: #ffb08a; margin-top: 0.1rem; }

    /* Content tools on users/sources — keep usable, no heavy gray slabs */
    .cf-admin-panel {
      border: 0 !important;
      background: transparent !important;
      border-radius: 0 !important;
      padding: 0 !important;
      box-shadow: none !important;
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
    .cf-admin-btn.ghost { background:rgba(255,255,255,.06); border:1px solid var(--adm-line); }
    .cf-admin-btn.danger { background:linear-gradient(135deg,#a11,#dc3545); }

    /* Dropdown menu — denser, labeled, professional */
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
      width: 2.4rem;
      height: 2.4rem;
      margin: 0;
      padding: 0;
      border-radius: 0.85rem;
      border: 1px solid rgba(219,105,55,.55);
      background: linear-gradient(160deg, rgba(219,105,55,.28), rgba(220,53,69,.14));
      color: #ffd2b8 !important;
      cursor: pointer;
      appearance: none;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    .header-admin-link i { font-size: 1.28rem; line-height: 1; }
    .header-admin-link:hover,
    .header-admin-menu.is-open .header-admin-link {
      color: #fff !important;
      border-color: rgba(255,180,140,.65);
      filter: brightness(1.08);
    }
    .header-admin-dropdown {
      position: absolute;
      top: calc(100% + 0.55rem);
      right: 0;
      width: min(18.5rem, calc(100vw - 1.5rem));
      padding: 0;
      border-radius: 1.05rem;
      border: 1px solid rgba(255,255,255,.1);
      background:
        linear-gradient(180deg, rgba(255,255,255,.03), transparent 34%),
        #161a22;
      box-shadow:
        0 18px 40px rgba(0,0,0,.5),
        0 0 0 1px rgba(219,105,55,.08);
      z-index: 90;
      overflow: hidden;
      animation: headerAdminDrop .18s ease;
    }
    .header-admin-dropdown[hidden] { display: none !important; }
    @keyframes headerAdminDrop {
      from { opacity: 0; transform: translateY(-6px) scale(.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
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
      letter-spacing: -0.01em;
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
    .header-admin-ico i { font-size: 1.1rem; }
    .header-admin-copy { flex: 1; min-width: 0; }
    .header-admin-copy strong {
      display: block;
      font-size: 0.92rem;
      font-weight: 700;
    }
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
    .header-admin-back i { font-size: 1rem; }

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

REMOTE_PY = textwrap.dedent(
    """\
    from pathlib import Path
    import re

    root = Path("/var/www/chillflix-newsite")
    ver = "20260802-ui95"

    css = root / "public/assets/css/app.css"
    t = css.read_text()

    # Replace from first admin panel block / ui90 onward polish markers with a clean ui95 pack
    markers = [
        "/* ——— Admin panel (ui90) ——— */",
        "/* ui93: admin title text stays; gray backgrounds die */",
        "/* ui94: admin shield dropdown menu */",
        "/* ui95: polished admin dropdown + dashboard */",
        "/* Header admin shield — right .end slot (ui91) */",
    ]
    cut = None
    for m in markers:
        i = t.find(m)
        if i >= 0 and (cut is None or i < cut):
            cut = i
    extra = Path("/tmp/ns-admin-ui95.css").read_text()
    if cut is not None:
        # keep anything before first admin polish marker; drop trailing admin CSS and append ui95
        # but preserve non-admin if somehow after — assume admin CSS is at end
        t = t[:cut].rstrip() + "\\n\\n" + extra + "\\n"
        print("replaced admin css from", cut)
    else:
        t = t.rstrip() + "\\n\\n" + extra + "\\n"
        print("appended admin css")
    css.write_text(t)

    layout = root / "app/Views/layouts/main.php"
    lt = layout.read_text()
    lt2 = re.sub(r"(\\?v=)2026080[12]-ui[0-9]+", r"\\g<1>" + ver, lt)
    layout.write_text(lt2)
    print("assets", sorted(set(re.findall(r"\\?v=([^\\\"&]+)", lt2)))[:8])

    h = (root / "app/Views/partials/header.php").read_text()
    d = (root / "app/Views/pages/admin/dashboard.php").read_text()
    print("dropdown top", "header-admin-dropdown-top" in h)
    print("dashboard hero", "cf-admin-hero" in d)
    print("quick actions", "cf-admin-action" in d)
    """
)


def main() -> None:
    upload(f"{ROOT}/app/Views/partials/header.php", HEADER)
    upload(f"{ROOT}/app/Views/pages/admin/dashboard.php", DASHBOARD)
    upload("/tmp/ns-admin-ui95.css", CSS)
    upload("/tmp/ns-admin-ui95-patch.py", REMOTE_PY)
    ssh(
        textwrap.dedent(
            f"""\
            set -e
            python3 /tmp/ns-admin-ui95-patch.py
            # also restyle users/sources nav label Site (remove arrow clutter)
            python3 - <<'PY'
            from pathlib import Path
            for rel in ["app/Views/pages/admin/users.php", "app/Views/pages/admin/sources.php"]:
                p = Path("/var/www/chillflix-newsite") / rel
                t = p.read_text()
                t2 = t.replace(">← Site</a>", ">Site</a>")
                p.write_text(t2)
                print(rel, "site label ok")
            PY
            php -l {ROOT}/app/Views/partials/header.php
            php -l {ROOT}/app/Views/pages/admin/dashboard.php
            """
        )
    )
    print("DONE", VER)


if __name__ == "__main__":
    main()
