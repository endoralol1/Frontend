#!/usr/bin/env python3
"""Remove the gray Admin title box on newsite admin (ui92).

Root cause: dashboard used <header class="cf-admin-head">, which inherits
site header styles (background #292d38) — the gray bar the user circled.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui92"
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
        raise SystemExit(f"upload failed: {p.stderr}")


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

CSS_KILL = textwrap.dedent(
    """\
    /* ui92: never let admin title inherit site header gray bar */
    header.cf-admin-head,
    .cf-admin-head {
      background: transparent !important;
      border: 0 !important;
      margin: 0 0 1rem !important;
      padding: 0 !important;
      width: auto !important;
      height: auto !important;
      min-height: 0 !important;
      position: static !important;
      z-index: auto !important;
      display: block !important;
    }
    .cf-admin-head h1 {
      font-size: clamp(1.35rem, 3.5vw, 1.7rem) !important;
      margin: 0 0 .2rem !important;
    }
    """
)

REMOTE_PY = textwrap.dedent(
    """\
    from pathlib import Path
    import re

    root = Path("/var/www/chillflix-newsite")
    ver = "20260802-ui92"

    for rel in ["app/Views/pages/admin/users.php", "app/Views/pages/admin/sources.php"]:
        p = root / rel
        t = p.read_text()
        t2 = t.replace('<header class="cf-admin-head">', '<div class="cf-admin-head">')
        # close the admin-head block (first </header> after it)
        i = t2.find('class="cf-admin-head"')
        if i >= 0:
            j = t2.find("</header>", i)
            if j >= 0:
                t2 = t2[:j] + "</div>" + t2[j + len("</header>") :]
        p.write_text(t2)
        left = '<header class="cf-admin-head">' in t2
        print(rel, "STILL_HEADER" if left else "converted")

    css = root / "public/assets/css/app.css"
    t = css.read_text()
    marker = "ui92: never let admin title"
    if marker not in t:
        extra = Path("/tmp/ns-admin-ui92.css").read_text()
        css.write_text(t.rstrip() + "\\n" + extra + "\\n")
        print("css appended")
    else:
        print("css already")

    layout = root / "app/Views/layouts/main.php"
    t = layout.read_text()
    for name in [
        "css/style.css",
        "css/app.css",
        "css/continue-party.css",
        "js/continue-party.js",
        "js/app.js",
    ]:
        t = re.sub(rf"({re.escape(name)}\\?v=)[^\\\"&]+", rf"\\g<1>{ver}", t)
    layout.write_text(t)
    print("assets", sorted(set(re.findall(r"\\?v=([^\\\"&]+)", t)))[:8])

    dash = (root / "app/Views/pages/admin/dashboard.php").read_text()
    print("dashboard has cf-admin-head:", "cf-admin-head" in dash)
    print("dashboard has Signed in:", "Signed in" in dash)
    """
)


def main() -> None:
    print("upload dashboard")
    upload(f"{ROOT}/app/Views/pages/admin/dashboard.php", DASHBOARD)
    upload("/tmp/ns-admin-ui92.css", CSS_KILL)
    upload("/tmp/ns-admin-ui92-patch.py", REMOTE_PY)
    print("remote patch")
    ssh(
        textwrap.dedent(
            f"""\
            set -e
            python3 /tmp/ns-admin-ui92-patch.py
            php -l {ROOT}/app/Views/pages/admin/dashboard.php
            php -l {ROOT}/app/Views/pages/admin/users.php
            php -l {ROOT}/app/Views/pages/admin/sources.php
            grep -n 'cf-admin-head\\|Signed in' {ROOT}/app/Views/pages/admin/dashboard.php || echo 'dashboard clean'
            grep -n 'cf-admin-head' {ROOT}/app/Views/pages/admin/users.php {ROOT}/app/Views/pages/admin/sources.php
            """
        )
    )
    print("DONE", VER)


if __name__ == "__main__":
    main()
