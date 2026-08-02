#!/usr/bin/env python3
"""Fix single-provider source tests, add header admin icon, clean admin gray boxes."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui90"


def bump(text: str) -> str:
    return re.sub(r"20260801-ui\d+", ASSET_V, text)


def fix_player_sources() -> None:
    path = ROOT / "app/Services/PlayerSources.php"
    text = path.read_text()
    old = """$providers = config('player_providers', ['vaplayer', 'huhu']);
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
    new = """$providers = config('player_providers', ['vaplayer', 'huhu']);
        // Config override (admin single-provider test) must win over DB order.
        $providerOverride = isset($GLOBALS['__cf_config_override'])
            && is_array($GLOBALS['__cf_config_override'])
            && array_key_exists('player_providers', $GLOBALS['__cf_config_override']);
        if (!$providerOverride) {
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
        }
        if (!is_array($providers) || !$providers) {
            $providers = ['vaplayer', 'huhu'];
        }"""
    if "providerOverride" not in text:
        if old not in text:
            raise SystemExit("PlayerSources provider block not found")
        text = text.replace(old, new, 1)
        path.write_text(text)
        print("PlayerSources: respect test override")
    else:
        print("PlayerSources already patched")


def fix_sources_test_filter() -> None:
    path = ROOT / "app/Services/SourcesService.php"
    text = path.read_text()
    old = """        $ok = !empty($result['ok']) && !empty($result['sources']);
        $count = is_array($result['sources'] ?? null) ? count($result['sources']) : 0;
        $message = $ok
            ? ("OK — {$count} stream(s)")
            : (string) ($result['error'] ?? 'No sources');"""
    new = """        // Count only streams that actually belong to the tested provider.
        $all = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $matched = [];
        foreach ($all as $src) {
            if (!is_array($src)) {
                continue;
            }
            $pid = strtolower((string) ($src['provider'] ?? ''));
            if ($pid === $sourceId || str_starts_with($pid, $sourceId)) {
                $matched[] = $src;
            }
        }
        $ok = !empty($matched);
        $count = count($matched);
        $message = $ok
            ? ("OK — {$count} stream(s) from {$sourceId}")
            : (string) ($result['error'] ?? ("No streams from {$sourceId}"));
        $result['sources'] = $matched;"""
    if "OK — {$count} stream(s) from {$sourceId}" not in text:
        if old not in text:
            raise SystemExit("SourcesService test count block not found")
        text = text.replace(old, new, 1)
        path.write_text(text)
        print("SourcesService: filter test results by provider")
    else:
        print("SourcesService already filtered")


def add_header_admin_icon() -> None:
    path = ROOT / "app/Views/partials/header.php"
    text = path.read_text()
    if 'id="header-admin-link"' in text:
        print("header admin icon already present")
        return
    # Place next to language toggler (top bar — common mobile pointer spot)
    needle = """                <div id="language-toggler" title="Language"><span class="lang-code">EN</span></div>"""
    insert = """                <div id="language-toggler" title="Language"><span class="lang-code">EN</span></div>
                <a id="header-admin-link" class="header-admin-link" href="<?= e(url('/admin')) ?>" title="Admin" aria-label="Admin panel" hidden>
                    <i class="uil uil-shield-check" aria-hidden="true"></i>
                </a>"""
    if needle not in text:
        raise SystemExit("language toggler not found in header")
    path.write_text(text.replace(needle, insert, 1))
    print("header admin icon added")


def patch_admin_css() -> None:
    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    marker = "/* ——— Admin panel (ui88) ——— */"
    # Also replace later ui versions
    for m in (
        "/* ——— Admin panel (ui88) ——— */",
        "/* ——— Admin panel (ui90) ——— */",
    ):
        if m in css:
            css = re.sub(re.escape(m) + r"[\s\S]*?(?=\n/\* ———|\Z)", "", css, count=1)

    new = r"""
/* ——— Admin panel (ui90) ——— */
.page-admin .cf-admin {
  --adm-ink: #f4f1ec;
  --adm-muted: rgba(244,241,236,.62);
  --adm-line: rgba(255,255,255,.1);
  --adm-hot: #db6937;
  position: relative;
  z-index: 1;
  padding: 1rem 0 3rem;
}
.cf-admin-wrap { width: min(1100px, 100%); margin: 0 auto; }
.cf-admin-nav {
  display:flex; flex-wrap:wrap; gap:.45rem; margin:0 0 1.1rem;
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
.cf-admin-head { margin:0 0 1.1rem; }
.cf-admin-head h1 {
  margin:0 0 .25rem; color:#fff; font-size:clamp(1.5rem,4vw,1.9rem);
  font-weight:800; letter-spacing:-.03em;
}
.cf-admin-head p { margin:0; color:var(--adm-muted); font-size:.9rem; }

/* Stats: no gray cards — plain typography grid */
.cf-admin-grid {
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:1rem 1.25rem;
  margin:0 0 1.5rem;
  padding:0;
}
@media (min-width:700px){
  .cf-admin-grid { grid-template-columns:repeat(4, minmax(0,1fr)); }
}
.cf-admin-stat {
  padding:0;
  border:0 !important;
  background:transparent !important;
  border-radius:0 !important;
  box-shadow:none !important;
}
.cf-admin-stat strong {
  display:block; color:#fff; font-size:1.7rem; font-weight:800; letter-spacing:-.03em; line-height:1.1;
}
.cf-admin-stat span {
  color:var(--adm-muted); font-size:.72rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
}

/* Content areas: no heavy gray panel behind copy */
.cf-admin-panel {
  border:0 !important;
  background:transparent !important;
  border-radius:0 !important;
  padding:0 !important;
  box-shadow:none !important;
}
.cf-admin-blurb { display:none !important; }

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
  text-align:left; padding:.7rem .2rem; border-bottom:1px solid rgba(255,255,255,.08);
  color:rgba(255,255,255,.86); font-size:.86rem; vertical-align:middle;
}
.cf-admin-table th { color:rgba(255,255,255,.45); font-size:.72rem; letter-spacing:.05em; text-transform:uppercase; border-bottom-color:rgba(255,255,255,.12); }
.cf-admin-role {
  display:inline-flex; padding:.15rem .5rem; border-radius:999px; font-size:.7rem; font-weight:750;
  border:1px solid rgba(255,255,255,.12); text-transform:uppercase; letter-spacing:.04em;
}
.cf-admin-role.admin { color:#ffd2b8; border-color:rgba(219,105,55,.45); background:rgba(219,105,55,.14); }
.cf-admin-role.moderator { color:#b8e0ff; border-color:rgba(80,160,255,.4); background:rgba(80,160,255,.12); }
.cf-admin-role.user { color:rgba(255,255,255,.7); }
.cf-admin-source {
  display:flex; flex-wrap:wrap; gap:.65rem; align-items:center;
  padding:.75rem 0; border-radius:0; border:0; border-bottom:1px solid rgba(255,255,255,.08);
  background:transparent; margin-bottom:0; cursor:grab;
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
  margin-top:1rem; padding:1rem 0 0; border-radius:0; border:0; border-top:1px solid rgba(255,255,255,.1); background:transparent;
}
.cf-admin-field { display:flex; flex-direction:column; gap:.3rem; margin-bottom:.7rem; }
.cf-admin-field span { color:rgba(255,255,255,.5); font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.cf-admin-msg { margin:.5rem 0; color:#ffd2b8; font-size:.85rem; }
.cf-admin-msg.ok { color:#7dffb0; }

/* Header admin shield */
.header-admin-link {
  display:inline-flex; align-items:center; justify-content:center;
  width:2.15rem; height:2.15rem; margin-left:.25rem;
  border-radius:.75rem; border:1px solid rgba(219,105,55,.45);
  background:rgba(219,105,55,.16); color:#ffd2b8 !important;
  text-decoration:none !important; flex-shrink:0;
}
.header-admin-link[hidden] { display:none !important; }
.header-admin-link i { font-size:1.15rem; line-height:1; }
.header-admin-link:hover { background:rgba(219,105,55,.28); color:#fff !important; }

@media (max-width:991.98px){
  .page-admin .cf-admin { padding-bottom:calc(8rem + env(safe-area-inset-bottom,0px)); }
}
"""
    css_path.write_text(css.rstrip() + "\n\n" + new.strip() + "\n")
    print("admin css cleaned + header icon styles")


def patch_dashboard_view() -> None:
    path = ROOT / "app/Views/pages/admin/dashboard.php"
    path.write_text(r'''<?php
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
''')
    print("dashboard view cleaned")


def patch_js_admin_visibility() -> None:
    js_path = ROOT / "public/assets/js/app.js"
    js = js_path.read_text()

    # Always reveal header admin icon for staff; improve browse admin row
    block = """
/* newsite-admin-link */
(function () {
  function ensureAdminChrome(user) {
    var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
    var header = document.getElementById('header-admin-link');
    if (header) {
      if (staff) header.removeAttribute('hidden');
      else header.setAttribute('hidden', 'hidden');
    }
    var mount = document.getElementById('browse-auth-user') || document.getElementById('browse-account-section');
    if (!mount) return;
    var existing = document.getElementById('browse-admin-link');
    if (!staff) { if (existing) existing.remove(); return; }
    if (existing) return;
    var a = document.createElement('a');
    a.id = 'browse-admin-link';
    a.className = 'browse-list-item';
    a.href = (window.CF_BASE || (window.APP && APP.baseUrl) || '') + '/admin';
    a.innerHTML = '<i class="uil uil-shield-check" aria-hidden="true"></i><span>Admin</span><i class="uil uil-angle-right" aria-hidden="true"></i>';
    // Prefer under signed-in account controls (above Contact/Request)
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
    try {
      authApi('/api/auth/me', { method: 'GET' }).then(function (r) { return r.json(); }).then(function (d) {
        ensureAdminChrome(d && d.user ? d.user : null);
      }).catch(function () { ensureAdminChrome(null); });
    } catch (e) { ensureAdminChrome(null); }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshAdminChrome);
  } else {
    refreshAdminChrome();
  }
  $(document).on('click', '.bottom-nav-browse', function () {
    setTimeout(refreshAdminChrome, 180);
  });
  window.__cfEnsureAdminChrome = ensureAdminChrome;
})();
"""
    js = re.sub(
        r"\n/\* newsite-admin-link \*/[\s\S]*?\n\}\)\(\);\n?",
        "\n",
        js,
        count=1,
    )
    js = js.rstrip() + "\n" + block
    js_path.write_text(js)
    print("admin icon JS: header + browse")


def main() -> None:
    fix_player_sources()
    fix_sources_test_filter()
    add_header_admin_icon()
    patch_admin_css()
    patch_dashboard_view()
    patch_js_admin_visibility()
    layout = ROOT / "app/Views/layouts/main.php"
    layout.write_text(bump(layout.read_text()))
    print("asset", ASSET_V)


if __name__ == "__main__":
    main()
