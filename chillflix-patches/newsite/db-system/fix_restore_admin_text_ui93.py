#!/usr/bin/env python3
"""ui93: restore Admin title text; kill only the gray backgrounds.

- Put Admin + Signed in text back (as a div, not <header>)
- Keep transparent / no gray card behind it
- On admin pages, remove the gray footer panel under the Chillflix logo
- Make the top site header on admin not paint the gray bar either
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
import textwrap

HOST = "192.142.46.51"
ROOT = "/var/www/chillflix-newsite"
VER = "20260802-ui93"
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
        raise SystemExit(f"upload {path} failed: {p.stderr}")


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
        <div class="cf-admin-head">
          <h1>Admin</h1>
          <p class="cf-admin-signed">Signed in as <?= e((string) ($adminUser['name'] ?? '')) ?> · <?= e((string) ($adminUser['role'] ?? '')) ?></p>
        </div>
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

CSS = textwrap.dedent(
    """\
    /* ui93: admin title text stays; gray backgrounds die */
    header.cf-admin-head,
    .cf-admin-head {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
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
      color: #fff;
      font-weight: 800;
      letter-spacing: -0.03em;
    }
    .cf-admin-signed {
      margin: 0;
      color: rgba(255,255,255,.55);
      font-size: .88rem;
    }

    /* Top site header on admin: no gray bar behind logo */
    body.page-admin > header.relative,
    body.page-admin header.relative {
      background: transparent !important;
      border-bottom: 0 !important;
      box-shadow: none !important;
    }

    /* Footer: remove gray panel under Chillflix logo on admin */
    body.page-admin .footer-v3-panel {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
      backdrop-filter: none !important;
    }
    body.page-admin .footer--v3,
    body.page-admin .footer {
      background: transparent !important;
      border: 0 !important;
      box-shadow: none !important;
    }
    """
)

REMOTE = textwrap.dedent(
    f"""\
    set -e
    python3 <<'PY'
    from pathlib import Path
    import re

    root = Path("{ROOT}")
    ver = "{VER}"

    # replace old ui92 kill-switch block + append ui93
    css = root / "public/assets/css/app.css"
    t = css.read_text()
    # strip previous ui92/ui93 admin gray blocks if re-run
    for marker in [
        "/* ui92: never let admin title inherit site header gray bar */",
        "/* ui93: admin title text stays; gray backgrounds die */",
    ]:
        while marker in t:
            i = t.find(marker)
            # cut until next clearly unrelated section or end — take ~50 lines
            rest = t[i + len(marker) :]
            # stop at next top-level comment that is not nested, or EOF
            # simpler: remove from marker through end of file if last, else through next "/* " at line start after 200 chars
            cut_end = len(t)
            m = re.search(r"\\n/\\* (?!ui9)", rest)
            if m:
                cut_end = i + len(marker) + m.start()
            else:
                # if marker is near end, just truncate from marker
                cut_end = len(t)
            t = t[:i] + t[cut_end:]

    extra = Path("/tmp/ns-admin-ui93.css").read_text()
    css.write_text(t.rstrip() + "\\n\\n" + extra + "\\n")
    print("css updated")

    layout = root / "app/Views/layouts/main.php"
    lt = layout.read_text()
    lt2 = re.sub(r"(\\?v=)2026080[12]-ui[0-9]+", r"\\g<1>" + ver, lt)
    layout.write_text(lt2)
    print("assets", sorted(set(re.findall(r"\\?v=([^\\\"&]+)", lt2)))[:8])

    dash = (root / "app/Views/pages/admin/dashboard.php").read_text()
    print("has Admin h1", "<h1>Admin</h1>" in dash)
    print("has Signed in", "Signed in as" in dash)
    print("uses header.cf-admin-head", '<header class="cf-admin-head">' in dash)
    print("uses div.cf-admin-head", '<div class="cf-admin-head">' in dash)
    PY
    php -l {ROOT}/app/Views/pages/admin/dashboard.php
    """
)


def main() -> None:
    upload(f"{ROOT}/app/Views/pages/admin/dashboard.php", DASHBOARD)
    upload("/tmp/ns-admin-ui93.css", CSS)
    ssh(REMOTE)
    print("DONE", VER)


if __name__ == "__main__":
    main()
