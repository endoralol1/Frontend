#!/usr/bin/env python3
"""Compact + polish the floating bottom/top nav pill."""
from pathlib import Path
import re

css_path = Path("/var/www/chillflix-newsite/public/assets/css/app.css")
layout = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
css = css_path.read_text()

# 1) Replace the primary mobile + desktop bottom-nav blocks
old_mobile = """/* ——— Mobile floating bottom nav (Home / Movies / TV) ——— */
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
    padding: 0 1rem calc(0.7rem + env(safe-area-inset-bottom, 0px));
    pointer-events: none;
  }

  .bottom-nav-inner {
    pointer-events: auto;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    align-items: center;
    gap: 0.1rem;
    max-width: 28rem;
    margin: 0 auto;
    padding: 0.35rem;
    border-radius: 1.35rem;
    background: rgba(14, 16, 22, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
      0 10px 30px rgba(0, 0, 0, 0.35),
      inset 0 1px 0 rgba(255, 255, 255, 0.06);
  }

  .bottom-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.12rem;
    min-height: 3.15rem;
    padding: 0.35rem 0.4rem 0.3rem;
    border: 0;
    background: transparent;
    border-radius: 1.05rem;
    color: rgba(255, 255, 255, 0.62);
    text-decoration: none;
    cursor: pointer;
    transition: color 0.08s ease, background 0.08s ease;
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

  .bottom-nav-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.85rem;
    height: 1.85rem;
    border-radius: 0.7rem;
    transition: background 0.08s ease, color 0.08s ease, box-shadow 0.08s ease;
  }

  .bottom-nav-icon i {
    font-size: 1.25rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-size: 0.62rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    line-height: 1;
  }

  .bottom-nav-item.active {
    color: #fff;
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #fff;
    background: linear-gradient(180deg, rgba(219, 105, 55, 0.95) 0%, rgba(220, 53, 69, 0.92) 100%);
    box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
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
    padding-bottom: calc(5.4rem + env(safe-area-inset-bottom, 0px));
  }

  .wrapper > .container .footer {
    padding-bottom: 0.5rem;
  }
}"""

new_mobile = """/* ——— Compact floating nav (Home / Movies / TV / Search / Browse) ——— */
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
}"""

if old_mobile not in css:
    raise SystemExit("primary mobile bottom-nav block not found")
css = css.replace(old_mobile, new_mobile, 1)
print("mobile block replaced")

# 2) Tighten desktop top-right pill (first part of desktop media query)
old_desk_inner = """  .bottom-nav-inner {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 0.18rem;
    max-width: none;
    margin: 0;
    padding: 0.28rem;
    border-radius: 1.15rem;
    background: rgba(14, 16, 22, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
      0 10px 30px rgba(0, 0, 0, 0.35),
      inset 0 1px 0 rgba(255, 255, 255, 0.06);
  }

  .bottom-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.14rem;
    min-width: 4.55rem;
    min-height: 3.05rem;
    padding: 0.28rem 0.55rem 0.22rem;
    border: 0;
    background: transparent;
    border-radius: 0.95rem;
    color: rgba(255, 255, 255, 0.62);
    text-decoration: none;
    cursor: pointer;
    font: inherit;
    transition: color 0.08s ease, background 0.08s ease;
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
    width: 1.85rem;
    height: 1.85rem;
    border-radius: 0.65rem;
  }

  .bottom-nav-icon i {
    font-size: 1.2rem;
    line-height: 1;
  }

  .bottom-nav-label {
    font-size: 0.7rem;
    font-weight: 650;
    letter-spacing: 0.01em;
    line-height: 1;
  }

  .bottom-nav-item.active {
    color: #fff;
  }

  .bottom-nav-item.active .bottom-nav-icon {
    color: #fff;
    background: linear-gradient(180deg, rgba(219, 105, 55, 0.95) 0%, rgba(220, 53, 69, 0.92) 100%);
    box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
  }

  .bottom-nav-item.active .bottom-nav-label {
    color: #ffd2b8;
  }"""

new_desk_inner = """  .bottom-nav-inner {
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
  }"""

if old_desk_inner not in css:
    raise SystemExit("desktop bottom-nav inner block not found")
css = css.replace(old_desk_inner, new_desk_inner, 1)
print("desktop inner replaced")

# 3) Remove conflicting later overrides (anime-main-nav / 5-item leftovers)
css = re.sub(
    r"\n/\* bottom-nav 5 items \*/\n@media \(max-width: 991\.98px\) \{\n"
    r"  \.bottom-nav-inner \{.*?\n  \}\n"
    r"  \.bottom-nav-item \{.*?\n  \}\n"
    r"  \.bottom-nav-label \{.*?\n  \}\n"
    r"  \.bottom-nav-icon \{.*?\n  \}\n"
    r"  \.bottom-nav-icon i \{.*?\n  \}\n"
    r"\}\n@media \(min-width: 992px\) \{\n"
    r"  \.bottom-nav-item \{.*?\n  \}\n"
    r"\}\n",
    "\n",
    css,
    count=1,
    flags=re.S,
)

# favorites-header-fix nested 5-item nav override
css = re.sub(
    r"\n  /\* 5-item nav \*/\n"
    r"  \.bottom-nav-inner \{.*?\n  \}\n"
    r"  \.bottom-nav-item \{.*?\n  \}\n"
    r"  \.bottom-nav-label \{.*?\n  \}\n",
    "\n",
    css,
    count=1,
    flags=re.S,
)
print("legacy overrides cleaned")

# Also shrink desktop top offset a bit for compact bar
css = css.replace(
    """@media (min-width: 992px) {
  .bottom-nav {
    display: block !important;
    position: fixed;
    top: 0.55rem;""",
    """@media (min-width: 992px) {
  .bottom-nav {
    display: block !important;
    position: fixed;
    top: 0.7rem;""",
    1,
)

# Favorites page bottom clearance for shorter nav
css = css.replace(
    "  padding-bottom: calc(5.75rem + env(safe-area-inset-bottom, 0px));\n  color: var(--fav-ink);",
    "  padding-bottom: calc(4.4rem + env(safe-area-inset-bottom, 0px));\n  color: var(--fav-ink);",
    1,
)

# Desktop browse dropdown under shorter nav
css = css.replace("    top: 4.35rem !important;", "    top: 3.55rem !important;", 1)

css_path.write_text(css)

lt = layout.read_text()
lt = re.sub(r"\?v=20260803-ui\d+", "?v=20260803-ui141", lt)
layout.write_text(lt)
print("layout ui141")
print("DONE")
