#!/usr/bin/env python3
"""Polish the floating nav: richer glass, cleaner active, smoother motion."""
from pathlib import Path
import re

css_path = Path("/var/www/chillflix-newsite/public/assets/css/app.css")
layout = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
css = css_path.read_text()

old = """/* ——— Compact floating nav (Home / Movies / TV / Search / Browse) ——— */
.bottom-nav {
  display: none;
}

@media (max-width: 991.98px) {
  .bottom-nav {
    display: block;
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 120;
    padding: 0 0.75rem calc(0.4rem + env(safe-area-inset-bottom, 0px));
    pointer-events: none;
  }

  .bottom-nav-inner {
    pointer-events: auto;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    align-items: center;
    gap: 0.08rem;
    max-width: 22.5rem;
    margin: 0 auto;
    padding: 0.22rem;
    border-radius: 1.05rem;
    background: rgba(12, 14, 20, 0.82);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow:
      0 8px 22px rgba(0, 0, 0, 0.4),
      inset 0 1px 0 rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(16px) saturate(1.15);
    -webkit-backdrop-filter: blur(16px) saturate(1.15);
  }

  .bottom-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.08rem;
    min-width: 0;
    min-height: 2.55rem;
    padding: 0.28rem 0.12rem 0.22rem;
    border: 0;
    background: transparent;
    border-radius: 0.8rem;
    color: rgba(232, 236, 245, 0.58);
    text-decoration: none;
    cursor: pointer;
    transition: color 0.12s ease, background 0.12s ease, transform 0.12s ease;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
    font: inherit;
  }

  .bottom-nav-item:hover,
  .bottom-nav-item:focus-visible {
    color: #fff;
    background: rgba(255, 255, 255, 0.06);
    outline: none;
  }

  .bottom-nav-item:active {
    transform: scale(0.96);
  }

  .bottom-nav-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.45rem;
    height: 1.45rem;
    border-radius: 0.55rem;
    transition: background 0.12s ease, color 0.12s ease, box-shadow 0.12s ease;
  }

  .bottom-nav-icon i {
    font-size: 1.05rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-family: Outfit, Poppins, sans-serif;
    font-size: 0.58rem;
    font-weight: 650;
    letter-spacing: 0.01em;
    line-height: 1;
    white-space: nowrap;
  }

  .bottom-nav-item.active {
    color: #fff;
    background: rgba(219, 105, 55, 0.14);
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #fff;
    background: linear-gradient(145deg, #db6937 0%, #dc3545 100%);
    box-shadow: 0 4px 10px rgba(220, 53, 69, 0.28);
  }

  .bottom-nav-item.active .bottom-nav-label {
    color: #ffd2b8;
  }

  /* Replace hamburger / top links with bottom nav on phone */
  header .wrapper .start #menu-toggler,
  header #menu,
  header .wrapper #menu,
  header .wrapper .start #menu {
    display: none !important;
  }

  body {
    padding-bottom: calc(4.15rem + env(safe-area-inset-bottom, 0px));
  }

  .wrapper > .container .footer {
    padding-bottom: 0.35rem;
  }
}

/* Desktop: same phone nav pill, pinned to the top-right — slightly shorter */
@media (min-width: 992px) {
  .bottom-nav {
    display: block !important;
    position: fixed;
    top: 0.7rem;
    left: auto;
    /* Keep nav inside content column — right edge matches Most Commented / container */
    right: max(
      15px,
      calc((100vw - min(100vw, 1800px)) / 2 + 15px)
    );
    bottom: auto;
    z-index: 130;
    width: auto;
    padding: 0;
    pointer-events: none;
  }

  .bottom-nav-inner {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 0.12rem;
    max-width: none;
    margin: 0;
    padding: 0.18rem;
    border-radius: 0.95rem;
    background: rgba(12, 14, 20, 0.82);
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow:
      0 8px 22px rgba(0, 0, 0, 0.35),
      inset 0 1px 0 rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(16px) saturate(1.15);
    -webkit-backdrop-filter: blur(16px) saturate(1.15);
  }

  .bottom-nav-item {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    min-width: 0;
    min-height: 2.2rem;
    padding: 0.28rem 0.62rem;
    border: 0;
    background: transparent;
    border-radius: 0.72rem;
    color: rgba(232, 236, 245, 0.62);
    text-decoration: none;
    cursor: pointer;
    font: inherit;
    transition: color 0.12s ease, background 0.12s ease;
  }

  .bottom-nav-item:hover,
  .bottom-nav-item:focus-visible {
    color: #fff;
    background: rgba(255, 255, 255, 0.06);
    outline: none;
  }

  .bottom-nav-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.2rem;
    height: 1.2rem;
    border-radius: 0.4rem;
  }

  .bottom-nav-icon i {
    font-size: 1rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-family: Outfit, Poppins, sans-serif;
    font-size: 0.72rem;
    font-weight: 650;
    letter-spacing: 0.01em;
    line-height: 1;
  }

  .bottom-nav-item.active {
    color: #fff;
    background: rgba(219, 105, 55, 0.16);
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #ffb898;
    background: transparent;
    box-shadow: none;
  }

  .bottom-nav-item.active .bottom-nav-label {
    color: #ffd2b8;
  }

  body {
    padding-bottom: 0 !important;
  }"""

new = """/* ——— Floating nav v2 — compact glass + warm active ——— */
.bottom-nav {
  display: none;
}

@media (max-width: 991.98px) {
  .bottom-nav {
    display: block;
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 120;
    padding: 0 0.85rem calc(0.45rem + env(safe-area-inset-bottom, 0px));
    pointer-events: none;
  }

  .bottom-nav-inner {
    --nav-ink: rgba(244, 246, 252, 0.55);
    --nav-ink-strong: #fff;
    --nav-warm: #ff9a6b;
    --nav-ember: #db6937;
    --nav-hot: #dc3545;
    pointer-events: auto;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    align-items: stretch;
    gap: 0.1rem;
    max-width: 23rem;
    margin: 0 auto;
    padding: 0.28rem;
    border-radius: 1.2rem;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.07), transparent 42%),
      rgba(10, 12, 18, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
      0 14px 36px rgba(0, 0, 0, 0.48),
      0 0 0 1px rgba(0, 0, 0, 0.35),
      inset 0 1px 0 rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(22px) saturate(1.35);
    -webkit-backdrop-filter: blur(22px) saturate(1.35);
  }

  .bottom-nav-item {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.16rem;
    min-width: 0;
    min-height: 2.7rem;
    padding: 0.32rem 0.1rem 0.28rem;
    border: 0;
    background: transparent;
    border-radius: 0.92rem;
    color: var(--nav-ink);
    text-decoration: none;
    cursor: pointer;
    transition:
      color 0.18s ease,
      background 0.18s ease,
      transform 0.16s cubic-bezier(0.22, 1, 0.36, 1),
      box-shadow 0.18s ease;
    -webkit-tap-highlight-color: transparent;
    touch-action: manipulation;
    font: inherit;
    isolation: isolate;
  }

  .bottom-nav-item::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: transparent;
    z-index: -1;
    transition: background 0.18s ease, box-shadow 0.18s ease;
  }

  .bottom-nav-item:hover,
  .bottom-nav-item:focus-visible {
    color: var(--nav-ink-strong);
    outline: none;
  }

  .bottom-nav-item:hover::before,
  .bottom-nav-item:focus-visible::before {
    background: rgba(255, 255, 255, 0.05);
  }

  .bottom-nav-item:active {
    transform: scale(0.94);
  }

  .bottom-nav-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    transition: color 0.18s ease, transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
  }

  .bottom-nav-icon i {
    font-size: 1.12rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-family: Outfit, Poppins, sans-serif;
    font-size: 0.6rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    line-height: 1;
    white-space: nowrap;
    transition: color 0.18s ease;
  }

  .bottom-nav-item.active {
    color: var(--nav-ink-strong);
  }

  .bottom-nav-item.active::before {
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.1), transparent 55%),
      linear-gradient(135deg, rgba(219, 105, 55, 0.28), rgba(220, 53, 69, 0.22));
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.12),
      0 4px 14px rgba(220, 53, 69, 0.16);
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #fff;
    background: transparent;
    box-shadow: none;
    transform: translateY(-0.5px);
  }

  .bottom-nav-item.active .bottom-nav-label {
    color: #ffd7c0;
    font-weight: 700;
  }

  /* Replace hamburger / top links with bottom nav on phone */
  header .wrapper .start #menu-toggler,
  header #menu,
  header .wrapper #menu,
  header .wrapper .start #menu {
    display: none !important;
  }

  body {
    padding-bottom: calc(4.35rem + env(safe-area-inset-bottom, 0px));
  }

  .wrapper > .container .footer {
    padding-bottom: 0.35rem;
  }
}

/* Desktop: glass segmented control, top-right */
@media (min-width: 992px) {
  .bottom-nav {
    display: block !important;
    position: fixed;
    top: 0.65rem;
    left: auto;
    /* Keep nav inside content column — right edge matches Most Commented / container */
    right: max(
      15px,
      calc((100vw - min(100vw, 1800px)) / 2 + 15px)
    );
    bottom: auto;
    z-index: 130;
    width: auto;
    padding: 0;
    pointer-events: none;
  }

  .bottom-nav-inner {
    --nav-ink: rgba(244, 246, 252, 0.58);
    --nav-ink-strong: #fff;
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 0.15rem;
    max-width: none;
    margin: 0;
    padding: 0.22rem;
    border-radius: 0.95rem;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.08), transparent 48%),
      rgba(10, 12, 18, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.13);
    box-shadow:
      0 12px 30px rgba(0, 0, 0, 0.4),
      0 0 0 1px rgba(0, 0, 0, 0.28),
      inset 0 1px 0 rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(22px) saturate(1.35);
    -webkit-backdrop-filter: blur(22px) saturate(1.35);
  }

  .bottom-nav-item {
    position: relative;
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    min-width: 0;
    min-height: 2.05rem;
    padding: 0.3rem 0.78rem;
    border: 0;
    background: transparent;
    border-radius: 0.72rem;
    color: var(--nav-ink);
    text-decoration: none;
    cursor: pointer;
    font: inherit;
    isolation: isolate;
    transition:
      color 0.18s ease,
      background 0.18s ease,
      transform 0.16s cubic-bezier(0.22, 1, 0.36, 1);
  }

  .bottom-nav-item::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    z-index: -1;
    background: transparent;
    transition: background 0.18s ease, box-shadow 0.18s ease;
  }

  .bottom-nav-item:hover,
  .bottom-nav-item:focus-visible {
    color: var(--nav-ink-strong);
    outline: none;
  }

  .bottom-nav-item:hover::before,
  .bottom-nav-item:focus-visible::before {
    background: rgba(255, 255, 255, 0.06);
  }

  .bottom-nav-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.05rem;
    height: 1.05rem;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
  }

  .bottom-nav-icon i {
    font-size: 0.98rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-family: Outfit, Poppins, sans-serif;
    font-size: 0.74rem;
    font-weight: 600;
    letter-spacing: 0.015em;
    line-height: 1;
  }

  .bottom-nav-item.active {
    color: #fff;
  }

  .bottom-nav-item.active::before {
    background:
      linear-gradient(135deg, rgba(219, 105, 55, 0.95) 0%, rgba(220, 53, 69, 0.92) 100%);
    box-shadow:
      0 6px 16px rgba(220, 53, 69, 0.28),
      inset 0 1px 0 rgba(255, 255, 255, 0.22);
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #fff;
    background: transparent;
    box-shadow: none;
  }

  .bottom-nav-item.active .bottom-nav-label {
    color: #fff;
    font-weight: 700;
  }

  body {
    padding-bottom: 0 !important;
  }"""

if old not in css:
    raise SystemExit("current compact nav block not found — abort")
css = css.replace(old, new, 1)

# Browse dropdown sits under slightly different nav height
css = css.replace("    top: 3.55rem !important;", "    top: 3.4rem !important;", 1)

css_path.write_text(css)

lt = layout.read_text()
lt = re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui142", lt)
layout.write_text(lt)
print("navbar nicer + ui142")
print("DONE")
