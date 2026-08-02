#!/usr/bin/env python3
"""Soften cinematic aurora background intensity."""
from __future__ import annotations
import re
from pathlib import Path
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui79"

def main() -> None:
    css_path = ROOT / "public/assets/css/app.css"
    css = css_path.read_text()
    # idempotent-ish: ensure aurora opacity is soft
    css2 = css
    css2 = re.sub(r"(\.cf-bgfx-aurora \{[\s\S]*?opacity:\s*)0\.\d+", r"\g<1>0.42", css2, count=1)
    css2 = re.sub(r"(body\.home \.cf-bgfx-aurora \{[\s\S]*?opacity:\s*)0\.\d+", r"\g<1>0.48", css2, count=1)
    (ROOT / "app/Views/layouts/main.php").write_text(
        re.sub(r"20260801-ui\d+", ASSET_V, (ROOT / "app/Views/layouts/main.php").read_text())
    )
    if css2 != css:
        css_path.write_text(css2)
        print("softened")
    else:
        print("already soft or pattern miss")
    print("asset", ASSET_V)

if __name__ == "__main__":
    main()
