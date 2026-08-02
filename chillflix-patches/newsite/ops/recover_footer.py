#!/usr/bin/env python3
"""Restore footer.php from the newest recoverable backup."""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")
PARTIALS = ROOT / "app/Views/partials"


def main() -> None:
    backups = sorted(
        list(PARTIALS.glob("footer.php.bak-clean-*"))
        + list(PARTIALS.glob("footer.php.bak-v2-*"))
        + list(PARTIALS.glob("footer.php.bak-v3-*"))
        + list(PARTIALS.glob("footer.php.bak-*"))
    )
    # Prefer the pre-v3 classic backup if present
    classic = sorted(PARTIALS.glob("footer.php.bak-clean-*"))
    if classic:
        latest = classic[-1]
    elif backups:
        latest = backups[-1]
    else:
        raise SystemExit("no footer backups found")

    target = PARTIALS / "footer.php"
    target.write_text(latest.read_text())
    print("restored from", latest.name)

    layout = ROOT / "app/Views/layouts/main.php"
    text = layout.read_text()
    m = re.search(r"20260801-ui(\d+)", text)
    n = int(m.group(1)) + 1 if m else 84
    layout.write_text(re.sub(r"20260801-ui\d+", f"20260801-ui{n}", text))
    print("asset bumped to", f"20260801-ui{n}")


if __name__ == "__main__":
    main()
