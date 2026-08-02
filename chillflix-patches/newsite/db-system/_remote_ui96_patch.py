from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
ver = "20260802-ui96"

routes = root / "app/routes.php"
rt = routes.read_text()
old = """    view('pages/admin/dashboard', [
        'adminUser' => $user,
        'seo' => ['title' => 'Admin | ' . config('site_name'), 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);"""
new = """    view('pages/admin/dashboard', [
        'adminUser' => $user,
        'seo' => ['title' => 'Admin | ' . config('site_name'), 'robots' => 'noindex'],
        'bodyClass' => 'page-admin page-admin-dashboard',
    ]);"""
if old in rt:
    routes.write_text(rt.replace(old, new, 1))
    print("routes dashboard class patched")
else:
    print("routes pattern missing; current snippet:")
    i = rt.find("pages/admin/dashboard")
    print(rt[i:i+280] if i >= 0 else "no dashboard view")

for rel in ["app/Views/pages/admin/users.php", "app/Views/pages/admin/sources.php"]:
    p = root / rel
    t = p.read_text()
    t = t.replace(">Site</a>", ">← Site</a>")
    t = t.replace(">← ← Site</a>", ">← Site</a>")
    p.write_text(t)
    print(rel, "arrow site", ">← Site</a>" in t)

css = root / "public/assets/css/app.css"
t = css.read_text()
marker = "/* ui96: restore pill admin nav"
extra = Path("/tmp/ns-admin-ui96.css").read_text()
if marker in t:
    i = t.find(marker)
    t = t[:i].rstrip() + "\n\n" + extra + "\n"
    print("replaced ui96 css")
else:
    t = t.rstrip() + "\n\n" + extra + "\n"
    print("appended ui96 css")
css.write_text(t)

js = root / "public/assets/js/app.js"
jt = js.read_text()
new_js = Path("/tmp/ns-admin-ui96.js").read_text()
start = -1
for m in [
    "/* newsite-admin-menu-ui96 */",
    "/* newsite-admin-menu-ui94 */",
    "/* newsite-admin-link-ui91 */",
    "/* newsite-admin-link",
]:
    start = jt.find(m)
    if start >= 0:
        break
if start < 0:
    js.write_text(jt.rstrip() + "\n" + new_js + "\n")
    print("js appended")
else:
    rest = jt[start:]
    end_rel = rest.find("window.__cfEnsureAdminChrome")
    end_marker = "})();"
    end_rel2 = rest.find(end_marker, end_rel)
    end = start + end_rel2 + len(end_marker)
    js.write_text(jt[:start] + new_js + jt[end:])
    print("js replaced", start, end)

layout = root / "app/Views/layouts/main.php"
lt = layout.read_text()
lt2 = re.sub(r"(\?v=)2026080[12]-ui[0-9]+", r"\g<1>" + ver, lt)
layout.write_text(lt2)
print("assets", sorted(set(re.findall(r"\?v=([^\"&]+)", lt2)))[:8])

h = (root / "app/Views/partials/header.php").read_text()
print("admin after lang", h.find("language-toggler") < h.find("header-admin-menu") < h.find('class="end"'))
print("has fixed dropdown css marker", "position: fixed !important" in css.read_text())
