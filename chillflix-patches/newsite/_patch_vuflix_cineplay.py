#!/usr/bin/env python3
"""Deploy Cineplay/Vidking 4K embed source onto chillflix-newsite VPS tree."""
from __future__ import annotations

import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SRC = ROOT / "db-system"
DST = Path("/var/www/chillflix-newsite")


def copy(rel: str) -> None:
    src = SRC / rel
    dst = DST / rel
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(src, dst)
    print("copied", rel, "->", dst)


def main() -> None:
    copy("app/Services/CineplaySources.php")
    copy("app/Services/PlayerSources.php")
    copy("app/Services/SourcesService.php")
    copy("app/bootstrap.php")
    copy("public/assets/js/player.js")
    copy("public/assets/css/player.css")

    # Ensure catalog row exists (disabled by default; enable in admin)
    php = r"""
<?php
require_once '/var/www/chillflix-newsite/app/bootstrap.php';
if (class_exists('SourcesService')) {
    SourcesService::ensureCatalogRows();
    $pdo = Database::pdo();
    $st = $pdo->prepare("SELECT id, enabled FROM sources WHERE id = 'cineplay'");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    echo json_encode($row ?: ['missing' => true]), PHP_EOL;
}
"""
    import subprocess

    r = subprocess.run(["php", "-r", php], capture_output=True, text=True)
    print(r.stdout.strip() or r.stderr.strip())


if __name__ == "__main__":
    main()
