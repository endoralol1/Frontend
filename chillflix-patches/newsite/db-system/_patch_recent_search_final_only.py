#!/usr/bin/env python3
"""Only save completed searches to Recently Searched — not every keystroke."""
from pathlib import Path
import re

root = Path("/var/www/chillflix-newsite")
app_js = root / "public/assets/js/app.js"
main = root / "app/Views/layouts/main.php"
routes = root / "app/routes.php"
player = root / "app/Views/layouts/player.php"
UI = "20260803-ui163"

js = app_js.read_text()

# 1) Stop saving when live search results arrive
old_done = """          if ($sheet) {
            $sheet.addClass('has-results');
            // Remember this query once results arrive
            try { if (q.length >= 2) saveRecentSearch(q); } catch (eRecent) {}
          }"""
new_done = """          if ($sheet) {
            $sheet.addClass('has-results');
          }"""
if old_done not in js:
    if "Remember this query once results arrive" not in js:
        print("results-arrive save already removed")
    else:
        raise SystemExit("results-arrive save block not found")
else:
    js = js.replace(old_done, new_done, 1)
    print("removed results-arrive save")

# 2) Stop debounce-save while typing
old_input = """    runLiveSearch(this.value, $('#search-sheet .search-sheet-suggest'), $sheet);
    // Persist recent after user pauses typing (same as hitting Go)
    clearTimeout(recentSearchTimer);
    if (v.length >= 2) {
      recentSearchTimer = setTimeout(function () {
        try { saveRecentSearch(v); } catch (eS) {}
      }, 400);
    }
  });"""
new_input = """    runLiveSearch(this.value, $('#search-sheet .search-sheet-suggest'), $sheet);
  });"""
if old_input not in js:
    if "Persist recent after user pauses typing" not in js:
        print("typing debounce save already removed")
    else:
        raise SystemExit("typing debounce save block not found")
else:
    js = js.replace(old_input, new_input, 1)
    print("removed typing debounce save")

# Drop unused timer var if present
js = js.replace("  var recentSearchTimer = null;\n", "", 1)

# 3) Stop saving on sheet close
old_close = """    try {
      var qClose = $.trim($('#search-sheet-input').val() || '');
      if (qClose.length >= 2) saveRecentSearch(qClose);
    } catch (eCloseSave) {}
    $sheet.removeClass('is-open has-results is-typing').attr('hidden', true);"""
new_close = """    $sheet.removeClass('is-open has-results is-typing').attr('hidden', true);"""
if old_close not in js:
    if "qClose.length >= 2) saveRecentSearch" not in js:
        print("close save already removed")
    else:
        raise SystemExit("close save block not found")
else:
    js = js.replace(old_close, new_close, 1)
    print("removed close save")

# 4) On suggest link click: save chosen title only (not partial typed query)
old_panel = """    if ($a.hasClass('suggest-item')) {
      try {
        var typed = $.trim($('#search-sheet-input').val() || '');
        var title = $.trim($a.find('strong').first().text() || '');
        if (typed.length >= 2) saveRecentSearch(typed);
        if (title) saveRecentSearch(title);
      } catch (eSave) {}
    }"""
new_panel = """    if ($a.hasClass('suggest-item')) {
      try {
        var title = $.trim($a.find('strong').first().text() || '');
        if (title) saveRecentSearch(title);
      } catch (eSave) {}
    }"""
if old_panel in js:
    js = js.replace(old_panel, new_panel, 1)
    print("panel click: title only")
else:
    print("panel click block skipped")

# 5) Duplicate suggest-item handler: title only
old_sug = """  $(document).on('click', '#search-sheet .suggest-item', function () {
    var typed = $.trim($('#search-sheet-input').val() || '');
    if (typed.length >= 2) saveRecentSearch(typed);
    var t = $(this).find('strong').first().text();
    if (t) saveRecentSearch(t);
  });"""
new_sug = """  $(document).on('click', '#search-sheet .suggest-item', function () {
    var t = $.trim($(this).find('strong').first().text() || '');
    if (t) saveRecentSearch(t);
  });"""
if old_sug in js:
    js = js.replace(old_sug, new_sug, 1)
    print("suggest click: title only")
else:
    print("suggest click block skipped")

# 6) Prune prefix stubs when saving a longer query (cleans old junk)
old_save = """  function saveRecentSearch(q) {
    q = $.trim(q || '');
    if (q.length < 2) return;
    var list = getRecentSearches().filter(function (x) {
      return x.toLowerCase() !== q.toLowerCase();
    });
    list.unshift(q);
    list = list.slice(0, RECENT_SEARCH_MAX);
    recentStoreWriteRaw(JSON.stringify(list));
    renderRecentSearches();
  }"""
new_save = """  function saveRecentSearch(q) {
    q = $.trim(q || '');
    if (q.length < 2) return;
    var qLower = q.toLowerCase();
    var list = getRecentSearches().filter(function (x) {
      var xl = String(x || '').toLowerCase();
      if (!xl || xl === qLower) return false;
      // Drop keystroke stubs that are prefixes/extensions of the final query
      if (qLower.indexOf(xl) === 0 || xl.indexOf(qLower) === 0) return false;
      return true;
    });
    list.unshift(q);
    list = list.slice(0, RECENT_SEARCH_MAX);
    recentStoreWriteRaw(JSON.stringify(list));
    renderRecentSearches();
  }"""
if old_save not in js:
    raise SystemExit("saveRecentSearch block not found")
js = js.replace(old_save, new_save, 1)
print("saveRecentSearch prunes prefixes")

app_js.write_text(js)

for p in (main, routes, player):
    if not p.exists():
        continue
    t = p.read_text()
    nt = re.sub(r"\?v=20260803-ui\d+", f"?v={UI}", t)
    if nt != t:
        p.write_text(nt)
        print("bumped", p.name)

# sanity
js2 = app_js.read_text()
assert "Persist recent after user pauses typing" not in js2
assert "Remember this query once results arrive" not in js2
assert "qClose.length >= 2) saveRecentSearch" not in js2
assert "qLower.indexOf(xl) === 0" in js2
print("OK", UI)
