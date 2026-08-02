#!/usr/bin/env python3
from __future__ import annotations
import os, subprocess, sys
from pathlib import Path

HOST = "192.142.46.51"
PW = os.environ.get("SSHPASS") or os.environ.get("SG_SSH_PASS") or ""
REMOTE = Path(__file__).with_name("_remote_cw_multi_ui101.py")
if not PW:
    raise SystemExit("Set SSHPASS")
if not REMOTE.exists():
    raise SystemExit(f"missing {REMOTE}")

env = {**os.environ, "SSHPASS": PW}

def run(cmd, input_text=None):
    p = subprocess.run(cmd, input=input_text, text=True, capture_output=True, env=env)
    sys.stdout.write(p.stdout)
    if p.stderr.strip():
        sys.stderr.write(p.stderr[-2000:] + "\n")
    if p.returncode != 0:
        raise SystemExit(f"failed rc={p.returncode}")
    return p.stdout

run(
    ["sshpass", "-e", "ssh", "-o", "StrictHostKeyChecking=no", f"root@{HOST}", "cat > /tmp/ns-cw-ui101.py"],
    input_text=REMOTE.read_text(),
)
run(
    ["sshpass", "-e", "ssh", "-o", "StrictHostKeyChecking=no", f"root@{HOST}", "python3 /tmp/ns-cw-ui101.py"]
)
print("DEPLOYED ui101")
