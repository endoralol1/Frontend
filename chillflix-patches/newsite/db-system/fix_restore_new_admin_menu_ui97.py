from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui97"

# Remove ui96 pill-restore block entirely (it undid the new menu)
css = root / "public/assets/css/app.css"
t = css.read_text()
marker96 = "/* ui96: restore pill admin nav"
if marker96 in t:
    t = t[: t.find(marker96)].rstrip() + "\n"
    print("removed ui96 pill overrides")

# Soften ui95 rules that scoped dashboard-only atmosphere away from users/sources
# Ensure shared underline nav + new chrome apply to all admin pages
extra = r'''
/* ui97: keep NEW admin menu everywhere; users/sources get the new chrome too */
/* Kill any leftover pill-nav forcing */
.cf-admin-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem 1.1rem;
  margin: 0 0 1.35rem;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.cf-admin-nav a {
  position: relative;
  display: inline-flex;
  align-items: center;
  min-height: 2.55rem;
  padding: 0.2rem 0 !important;
  border: 0 !important;
  border-radius: 0 !important;
  background: transparent !important;
  color: rgba(255,255,255,.55) !important;
  text-decoration: none !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.08em !important;
  text-transform: uppercase !important;
}
.cf-admin-nav a::after {
  content: "" !important;
  display: block !important;
  position: absolute;
  left: 0; right: 0; bottom: -1px;
  height: 2px;
  background: transparent;
  border-radius: 2px;
}
.cf-admin-nav a.is-active,
.cf-admin-nav a:hover { color: #fff !important; }
.cf-admin-nav a.is-active::after {
  background: linear-gradient(90deg, #db6937, #dc3545) !important;
}

/* All admin pages share the new atmosphere */
body.page-admin {
  background:
    radial-gradient(900px 420px at 12% -8%, rgba(219,105,55,.16), transparent 55%),
    radial-gradient(700px 360px at 90% 0%, rgba(220,53,69,.10), transparent 50%),
    #10131a !important;
}

/* Users/Sources titles match new admin type */
body.page-admin .cf-admin-head h1 {
  margin: 0 0 0.35rem !important;
  color: #fff !important;
  font-family: Outfit, Poppins, sans-serif !important;
  font-size: clamp(1.7rem, 4.5vw, 2.15rem) !important;
  font-weight: 800 !important;
  letter-spacing: -0.035em !important;
  line-height: 1.15 !important;
}
body.page-admin .cf-admin-head p {
  margin: 0 !important;
  color: rgba(243,240,234,.58) !important;
  font-size: 0.95rem !important;
  line-height: 1.45 !important;
  max-width: 40rem;
}

/* Dropdown stays NEW + fully visible (fixed, next to logo) */
header .wrapper .start .header-admin-menu {
  margin-left: 0.25rem;
  position: relative;
  z-index: 80;
}
body.page-admin header,
header:has(.header-admin-menu.is-open),
header .wrapper:has(.header-admin-menu.is-open),
header .wrapper .start:has(.header-admin-menu.is-open),
header .container:has(.header-admin-menu.is-open) {
  overflow: visible !important;
}
header:has(.header-admin-menu.is-open) { z-index: 120 !important; }

.header-admin-dropdown {
  position: fixed !important;
  top: 0;
  left: 0;
  width: min(18.5rem, calc(100vw - 1.25rem));
  max-height: min(70vh, 28rem);
  overflow-x: hidden;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  z-index: 10050 !important;
  border-radius: 1.05rem;
  border: 1px solid rgba(255,255,255,.12);
  background:
    linear-gradient(180deg, rgba(255,255,255,.03), transparent 34%),
    #161a22;
  box-shadow:
    0 18px 40px rgba(0,0,0,.55),
    0 0 0 1px rgba(219,105,55,.1);
}
.header-admin-dropdown[hidden] { display: none !important; }
'''

marker97 = "/* ui97: keep NEW admin menu everywhere"
if marker97 in t:
    t = t[: t.find(marker97)].rstrip() + "\n\n" + extra + "\n"
    print("replaced ui97")
else:
    t = t.rstrip() + "\n\n" + extra + "\n"
    print("appended ui97")
css.write_text(t)

# Users/Sources: Site label like new dashboard (no arrow clutter), keep content
for rel in ["app/Views/pages/admin/users.php", "app/Views/pages/admin/sources.php"]:
    p = root / rel
    txt = p.read_text()
    txt2 = txt.replace(">← Site</a>", ">Site</a>")
    # ensure head uses same structure (already does)
    p.write_text(txt2)
    print(rel, "Site label", ">Site</a>" in txt2 and ">← Site</a>" not in txt2)

# Keep placeDropdown JS (ui96) — still needed for full menu visibility
js = root / "public/assets/js/app.js"
jt = js.read_text()
if "placeDropdown" not in jt:
    print("WARN: placeDropdown missing")
else:
    print("placeDropdown ok")

# Ensure header still has NEW dropdown markup next to logo
h = (root / "app/Views/partials/header.php").read_text()
print("new dropdown markup", "header-admin-dropdown-top" in h and "Overview & health" in h)
print("next to logo", h.find("language-toggler") < h.find("header-admin-menu") < h.find('class="end"'))

layout = root / "app/Views/layouts/main.php"
lt = layout.read_text()
lt2 = re.sub(r"(\?v=)2026080[12]-ui[0-9]+", r"\g<1>" + ver, lt)
layout.write_text(lt2)
print("assets", sorted(set(re.findall(r"\?v=([^\"&]+)", lt2)))[:8])
