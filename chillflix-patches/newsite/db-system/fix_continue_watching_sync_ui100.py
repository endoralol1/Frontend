#!/usr/bin/env python3
"""Deploy ui100 continue-watching sync fix to VPS."""
from __future__ import annotations

import os
import subprocess
import sys
import textwrap
from pathlib import Path

HOST = "192.142.46.51"
VER = "20260802-ui100"
PW = os.environ.get("SSHPASS") or os.environ.get("SG_SSH_PASS") or ""
REMOTE_PY = Path(__file__).with_name("_remote_cw_ui100.py")

if not PW:
    raise SystemExit("Set SSHPASS or SG_SSH_PASS")
if not REMOTE_PY.exists():
    raise SystemExit(f"missing {REMOTE_PY}")


def run(cmd: list[str], input_text: str | None = None) -> str:
    env = {**os.environ, "SSHPASS": PW}
    p = subprocess.run(
        cmd,
        input=input_text,
        text=True,
        capture_output=True,
        env=env,
    )
    sys.stdout.write(p.stdout)
    if p.stderr.strip():
        sys.stderr.write(p.stderr[-2000:] + "\n")
    if p.returncode != 0:
        raise SystemExit(f"cmd failed rc={p.returncode}: {' '.join(cmd[:6])}")
    return p.stdout


def main() -> None:
    content = REMOTE_PY.read_text()
    run(
        [
            "sshpass",
            "-e",
            "ssh",
            "-o",
            "StrictHostKeyChecking=no",
            f"root@{HOST}",
            "cat > /tmp/ns-cw-ui100.py",
        ],
        input_text=content,
    )
    run(
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
        input_text=textwrap.dedent(
            """\
            set -e
            python3 /tmp/ns-cw-ui100.py
            php -d display_errors=0 -r '
            ob_start();
            require "/var/www/chillflix-newsite/app/bootstrap.php";
            ob_end_clean();
            $pdo = Database::pdo();
            $u = $pdo->query("SELECT id FROM users WHERE email=\\"admin@gmail.com\\" LIMIT 1")->fetch();
            if (!$u) { fwrite(STDERR, "no user\\n"); exit(1); }
            $uid = $u["id"];
            UserData::upsertContinue($uid, ["type"=>"movie","id"=>550,"title"=>"Fight Club","t"=>120,"d"=>800,"key"=>"movie:550"]);
            UserData::upsertContinue($uid, ["type"=>"tv","id"=>1399,"season"=>1,"episode"=>2,"title"=>"GOT","t"=>200,"d"=>3600,"key"=>"tv:1399:s1e2"]);
            UserData::upsertContinue($uid, ["type"=>"tv","id"=>1399,"season"=>1,"episode"=>3,"title"=>"GOT","t"=>50,"d"=>3600,"key"=>"tv:1399:s1e3"]);
            $n = (int)$pdo->query("SELECT COUNT(*) FROM continue_watching WHERE user_id=".$pdo->quote($uid))->fetchColumn();
            echo "rows_for_admin=$n\\n";
            foreach (UserData::listContinue($uid) as $row) {
              echo $row["key"]," t=",$row["t"],"\\n";
            }
            '
            """
        ),
    )
    print("DEPLOYED", VER)


if __name__ == "__main__":
    main()
