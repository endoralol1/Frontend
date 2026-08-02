#!/usr/bin/env python3
"""Restore footer.php from the newest .bak-clean-* backup."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
PARTIALS = ROOT / "app/Views/partials"


def main() -> None:
    backups = sorted(PARTIALS.glob("footer.php.bak-clean-*"))
    if not backups:
        raise SystemExit("no footer.bak-clean-* backups found")
    latest = backups[-1]
    target = PARTIALS / "footer.php"
    target.write_text(latest.read_text())
    print("restored from", latest.name)

    # bump cache so clients see the rollback
    layout = ROOT / "app/Views/layouts/main.php"
    text = layout.read_text()
    m = re.search(r"20260801-ui(\d+)", text)
    n = int(m.group(1)) + 1 if m else 81
    layout.write_text(re.sub(r"20260801-ui\d+", f"20260801-ui{n}", text))
    print("asset bumped to", f"20260801-ui{n}")


if __name__ == "__main__":
    main()
