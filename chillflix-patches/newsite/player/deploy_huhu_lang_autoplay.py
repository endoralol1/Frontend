#!/usr/bin/env python3
"""Patch Huhu lang=de|en support + fix newsite Auto Play toggle."""
from __future__ import annotations

import re
import shutil
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
NEWSITE = Path("/var/www/chillflix-newsite")
MAIN = Path("/var/www/chillflix.lol")
ASSET_V = "20260801-ui10"


def backup(path: Path) -> None:
    bak = path.with_suffix(path.suffix + ".bak-lang-autoplay")
    if not bak.exists():
        shutil.copy2(path, bak)


def patch_huhu_source_ts() -> None:
    idx = MAIN / "lib/providers/huhu/index.ts"
    text = idx.read_text()
    if "preferredLang" in text and "args.lang" in text:
        print("huhu index.ts already has lang")
    else:
        text = text.replace(
            """export type HuhuStreamArgs = {
    tmdbId: string
    type: HuhuMediaType
    season?: string
    episode?: string
}""",
            """export type HuhuStreamArgs = {
    tmdbId: string
    type: HuhuMediaType
    season?: string
    episode?: string
    /** Preferred audio language (e.g. de, en). */
    lang?: string
}""",
        )
        text = text.replace(
            """function buildAvailabilityKey(args: HuhuStreamArgs) {
    return [args.type, args.tmdbId, args.season ?? "", args.episode ?? ""].join(":")
}""",
            """function buildAvailabilityKey(args: HuhuStreamArgs) {
    return [args.type, args.tmdbId, args.season ?? "", args.episode ?? "", args.lang ?? ""].join(":")
}

function normalizeLang(lang?: string | null) {
    if (!lang) return ""
    return String(lang).trim().toLowerCase().slice(0, 2)
}

function preferLanguage(entries: HuhuSourceEntry[], lang?: string | null) {
    const want = normalizeLang(lang)
    if (!want || !entries.length) return entries
    const matched = entries.filter((entry) =>
        (entry.languages ?? []).some((l) => String(l).toLowerCase().startsWith(want))
    )
    if (matched.length) return matched
    // Keep full list as fallback when preferred lang missing
    return entries
}""",
        )
        text = text.replace(
            """export async function resolveHuhuStream(args: HuhuStreamArgs): Promise<HuhuResolvedStream> {
    const sources = await fetchHuhuSources(args)

    if (sources.length === 0) {
        markHuhuUnavailable(args)
        throw new Error("Huhu has no sources for this title")
    }

    clearHuhuUnavailable(args)

    for (const source of sources) {""",
            """export async function resolveHuhuStream(args: HuhuStreamArgs): Promise<HuhuResolvedStream> {
    const sources = preferLanguage(await fetchHuhuSources(args), args.lang)

    if (sources.length === 0) {
        markHuhuUnavailable(args)
        throw new Error("Huhu has no sources for this title")
    }

    clearHuhuUnavailable(args)

    for (const source of sources) {""",
        )
        text = text.replace(
            """export function buildHuhuStreamUrl(args: {
    requestOrigin: string
    type: HuhuMediaType
    tmdbId: string
    season?: string
    episode?: string
}) {
    const params = new URLSearchParams({
        type: args.type,
        tmdbId: args.tmdbId,
    })

    if (args.type === "tv") {
        params.set("season", args.season ?? "")
        params.set("episode", args.episode ?? "")
    pruneAvailabilityCache()
    }

    return `${resolvePlaybackProxyOrigin(args.requestOrigin)}/api/huhu/stream?${params.toString()}`
}""",
            """export function buildHuhuStreamUrl(args: {
    requestOrigin: string
    type: HuhuMediaType
    tmdbId: string
    season?: string
    episode?: string
    lang?: string
}) {
    const params = new URLSearchParams({
        type: args.type,
        tmdbId: args.tmdbId,
    })

    if (args.type === "tv") {
        params.set("season", args.season ?? "")
        params.set("episode", args.episode ?? "")
    }
    const lang = normalizeLang(args.lang)
    if (lang) {
        params.set("lang", lang)
    }
    pruneAvailabilityCache()

    return `${resolvePlaybackProxyOrigin(args.requestOrigin)}/api/huhu/stream?${params.toString()}`
}""",
        )
        # fix accidental double prune if old broken brace structure remained
        idx.write_text(text)
        print("patched huhu index.ts")

    route = MAIN / "app/api/huhu/stream/route.ts"
    rt = route.read_text()
    if 'searchParams.get("lang")' in rt:
        print("huhu stream route.ts already has lang")
    else:
        rt = rt.replace(
            """function getCacheKey(params: {
    type: "movie" | "tv"
    tmdbId: string
    season?: string | null
    episode?: string | null
}) {
    return [params.type, params.tmdbId, params.season ?? "", params.episode ?? ""].join(":")
}""",
            """function getCacheKey(params: {
    type: "movie" | "tv"
    tmdbId: string
    season?: string | null
    episode?: string | null
    lang?: string | null
}) {
    return [params.type, params.tmdbId, params.season ?? "", params.episode ?? "", params.lang ?? ""].join(":")
}""",
        )
        rt = rt.replace(
            """async function resolvePlaylistWithCache(params: {
    type: "movie" | "tv"
    tmdbId: string
    season?: string | null
    episode?: string | null
}) {""",
            """async function resolvePlaylistWithCache(params: {
    type: "movie" | "tv"
    tmdbId: string
    season?: string | null
    episode?: string | null
    lang?: string | null
}) {""",
        )
        rt = rt.replace(
            """    const resolution = resolveHuhuStream({
        tmdbId: params.tmdbId,
        type: params.type,
        season: params.season ?? undefined,
        episode: params.episode ?? undefined,
    })""",
            """    const resolution = resolveHuhuStream({
        tmdbId: params.tmdbId,
        type: params.type,
        season: params.season ?? undefined,
        episode: params.episode ?? undefined,
        lang: params.lang ?? undefined,
    })""",
        )
        # GET handler params
        old_get = """    const type = requestUrl.searchParams.get("type")
    const tmdbId = requestUrl.searchParams.get("tmdbId")
    const season = requestUrl.searchParams.get("season")
    const episode = requestUrl.searchParams.get("episode")"""
        new_get = """    const type = requestUrl.searchParams.get("type")
    const tmdbId = requestUrl.searchParams.get("tmdbId")
    const season = requestUrl.searchParams.get("season")
    const episode = requestUrl.searchParams.get("episode")
    const langRaw = requestUrl.searchParams.get("lang")
    const lang = langRaw ? langRaw.trim().toLowerCase().slice(0, 2) : null"""
        if old_get not in rt:
            raise SystemExit("stream route GET params missing")
        rt = rt.replace(old_get, new_get, 1)
        rt = rt.replace(
            "const cacheKey = getCacheKey({ type, tmdbId, season, episode })",
            "const cacheKey = getCacheKey({ type, tmdbId, season, episode, lang })",
        )
        rt = rt.replace(
            """            tmdbId,
            type,
            season,
            episode,
        })""",
            """            tmdbId,
            type,
            season,
            episode,
            lang,
        })""",
            1,
        )
        route.write_text(rt)
        print("patched huhu stream route.ts")


def patch_huhu_compiled() -> None:
    """Hot-patch running Next bundles so lang works without full rebuild."""
    files = [
        MAIN / ".next/server/chunks/9023.js",
        MAIN / ".next/server/chunks/91.js",
        MAIN / ".next/server/app/api/huhu/stream/route.js",
    ]
    for path in files:
        if not path.exists():
            print("skip missing", path)
            continue
        backup(path)
        text = path.read_text()
        original = text

        # availability / cache key include lang
        text = text.replace(
            'return[e.type,e.tmdbId,e.season??"",e.episode??""].join(":")',
            'return[e.type,e.tmdbId,e.season??"",e.episode??"",e.lang??""].join(":")',
        )
        text = text.replace(
            'return[e.type,e.tmdbId,e.season??"",e.episode??""].join(":")',
            'return[e.type,e.tmdbId,e.season??"",e.episode??"",e.lang??""].join(":")',
        )

        # buildHuhuStreamUrl — add lang query
        if 't.set("lang"' not in text and "t.set('lang'" not in text:
            text = text.replace(
                'return"tv"!==e.type||(t.set("season",e.season??""),t.set("episode",e.episode??"")',
                'if(e.lang){t.set("lang",String(e.lang).toLowerCase().slice(0,2))}return"tv"!==e.type||(t.set("season",e.season??""),t.set("episode",e.episode??"")',
            )
            # alternate minified form in route bundle
            text = text.replace(
                'let t=new URLSearchParams({type:e.type,tmdbId:e.tmdbId});return"tv"!==e.type||(t.set("season",e.season??""),t.set("episode",e.episode??"")',
                'let t=new URLSearchParams({type:e.type,tmdbId:e.tmdbId});if(e.lang){t.set("lang",String(e.lang).toLowerCase().slice(0,2))}return"tv"!==e.type||(t.set("season",e.season??""),t.set("episode",e.episode??"")',
            )

        # Prefer language inside resolve loops: after fetch sources, reorder/filter
        # Pattern A (chunk): async function m(e){let t=await y(e);if(0===t.length)
        for fetch_name, resolve_name in [("y", "m"), ("m", "g")]:
            old = f"async function {resolve_name}(e){{let t=await {fetch_name}(e);if(0===t.length)"
            new = (
                f"async function {resolve_name}(e){{let t=await {fetch_name}(e);"
                f'if(e.lang){{let w=String(e.lang).toLowerCase().slice(0,2);'
                f"let m=t.filter(s=>(s.languages||[]).some(l=>String(l).toLowerCase().startsWith(w)));"
                f"if(m.length)t=m;else t=[...t].sort((a,b)=>{{"
                f"let al=(a.languages&&a.languages[0]||'').toLowerCase().startsWith(w)?0:1;"
                f"let bl=(b.languages&&b.languages[0]||'').toLowerCase().startsWith(w)?0:1;"
                f"return al-bl}})}}"
                f"if(0===t.length)"
            )
            if old in text and "e.lang){let w=String(e.lang)" not in text:
                text = text.replace(old, new, 1)

        # Route GET: read lang + pass through
        if 'searchParams.get("lang")' not in text:
            text = text.replace(
                'r.searchParams.get("episode");if(!i||"movie"!==i&&"tv"!==i)',
                'r.searchParams.get("episode"),langParam=r.searchParams.get("lang"),lang=langParam?langParam.trim().toLowerCase().slice(0,2):null;if(!i||"movie"!==i&&"tv"!==i)',
            )
            text = text.replace(
                "let l=v({type:i,tmdbId:o,season:a,episode:s})",
                "let l=v({type:i,tmdbId:o,season:a,episode:s,lang:lang})",
            )
            text = text.replace(
                "await R({tmdbId:o,type:i,season:a,episode:s})",
                "await R({tmdbId:o,type:i,season:a,episode:s,lang:lang})",
            )
            text = text.replace(
                "(0,s.oB)({tmdbId:e.tmdbId,type:e.type,season:e.season??void 0,episode:e.episode??void 0})",
                "(0,s.oB)({tmdbId:e.tmdbId,type:e.type,season:e.season??void 0,episode:e.episode??void 0,lang:e.lang??void 0})",
            )

        if text != original:
            path.write_text(text)
            print("patched compiled", path)
        else:
            print("no compiled changes", path)


def write_newsite_assets() -> None:
    (NEWSITE / "app/Services/PlayerSources.php").write_text((LOCAL / "PlayerSources.php").read_text())
    (NEWSITE / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    print("wrote newsite PlayerSources + player.js")


def patch_watch_autoplay() -> None:
    watch = NEWSITE / "app/Views/pages/watch.php"
    text = watch.read_text()
    old = """                            <div class="movie-manager auto-play no-tooltip" id="btn-autoplay"><i class="uil uil-circle"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span></div>"""
    new = """                            <button type="button" class="movie-manager auto-play no-tooltip no-progress" id="btn-autoplay" aria-pressed="false">
                                <i class="uil uil-circle" aria-hidden="true"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span>
                            </button>"""
    if old in text:
        text = text.replace(old, new, 1)
        print("watch.php autoplay -> button")
    elif 'id="btn-autoplay"' in text and "<button" in text[max(0, text.find('id="btn-autoplay"') - 80) : text.find('id="btn-autoplay"')]:
        print("watch.php autoplay already button")
    else:
        # force replace any div variant
        text2, n = re.subn(
            r'<div class="movie-manager auto-play[^"]*" id="btn-autoplay">.*?</div>',
            new.strip(),
            text,
            count=1,
            flags=re.S,
        )
        if n:
            text = text2
            print("watch.php autoplay replaced via regex")
        else:
            raise SystemExit("btn-autoplay not found")

    # Inline bootstrap so toggle works even if app.js cache is stale
    marker = "/* newsite-autoplay-inline */"
    if marker not in text:
        inline = """
    <script>
    /* newsite-autoplay-inline */
    (function () {
      function readPref() {
        try { if (localStorage.getItem('cf_watch_autoplay') === '1') return true; } catch (e) {}
        return /(?:^|;\\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
      }
      function writePref(on) {
        try { localStorage.setItem('cf_watch_autoplay', on ? '1' : '0'); } catch (e) {}
        document.cookie = 'cf_watch_autoplay=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
      }
      function sync() {
        var on = readPref();
        var btn = document.getElementById('btn-autoplay');
        if (!btn) return on;
        btn.classList.toggle('is-on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        var icon = btn.querySelector('i');
        if (icon) icon.className = on ? 'uil uil-check-circle' : 'uil uil-circle';
        return on;
      }
      function toggle(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var on = !readPref();
        writePref(on);
        sync();
        if (on && window.PLAYER) {
          window.PLAYER.autoplay = true;
          if (!document.getElementById('np-shell') && window.ChillflixPlayer && window.ChillflixPlayer.startOnWatch) {
            window.ChillflixPlayer.startOnWatch(window.PLAYER);
          }
        }
      }
      function bind() {
        var btn = document.getElementById('btn-autoplay');
        if (!btn || btn.dataset.cfBound === '1') return;
        btn.dataset.cfBound = '1';
        btn.addEventListener('click', toggle, true);
        sync();
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
      else bind();
      window.__cfSyncWatchAutoplay = sync;
    })();
    </script>
"""
        anchor = "        window.PLAYER = <?= json_encode($playerConfig ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;"
        if anchor not in text:
            raise SystemExit("PLAYER config missing in watch.php")
        text = text.replace(anchor, anchor + "\n" + inline, 1)
        print("added inline autoplay bootstrap")

    watch.write_text(text)

    # CSS for on state
    css = NEWSITE / "public/assets/css/app.css"
    c = css.read_text()
    if "#btn-autoplay.is-on" not in c:
        c += """

/* Auto Play toggle visible on-state */
#btn-autoplay {
  appearance: none;
  -webkit-appearance: none;
  border: 0;
  font: inherit;
  color: inherit;
  cursor: pointer;
}
#btn-autoplay.is-on,
#btn-autoplay[aria-pressed="true"] {
  color: #e50914;
}
#btn-autoplay.is-on i,
#btn-autoplay[aria-pressed="true"] i {
  color: #e50914;
}
"""
        css.write_text(c)
        print("added autoplay CSS")


def bump_assets() -> None:
    routes = NEWSITE / "app/routes.php"
    text = routes.read_text()
    text, n1 = re.subn(
        r"asset\('css/player\.css'\) \. '\?v=[^']+'",
        f"asset('css/player.css') . '?v={ASSET_V}'",
        text,
        count=2,
    )
    text, n2 = re.subn(
        r"asset\('js/player\.js'\) \. '\?v=[^']+'",
        f"asset('js/player.js') . '?v={ASSET_V}'",
        text,
        count=2,
    )
    routes.write_text(text)
    layout = NEWSITE / "app/Views/layouts/main.php"
    lt = layout.read_text()
    lt = re.sub(r"js/app\.js'\)\) \?>\?v=20260801-ui\d+", f"js/app.js')) ?>?v={ASSET_V}", lt, count=1)
    lt = re.sub(r"css/app\.css'\)\) \?>\?v=20260801-ui\d+", f"css/app.css')) ?>?v={ASSET_V}", lt, count=1)
    lt = re.sub(r"css/style\.css'\)\) \?>\?v=20260801-ui\d+", f"css/style.css')) ?>?v={ASSET_V}", lt, count=1)
    layout.write_text(lt)
    print(f"bumped assets -> {ASSET_V} (player css/js {n1}/{n2})")


def patch_app_js_toggle() -> None:
    path = NEWSITE / "public/assets/js/app.js"
    text = path.read_text()
    block = """
  /* newsite-autoplay-toggle */
  function syncWatchAutoplayUi() {
    if (typeof window.__cfSyncWatchAutoplay === 'function') {
      return window.__cfSyncWatchAutoplay();
    }
    var on = false;
    try { on = localStorage.getItem('cf_watch_autoplay') === '1'; } catch (e) {}
    if (!on) on = /(?:^|;\\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
    var $btn = $('#btn-autoplay');
    if ($btn.length) {
      $btn.toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false');
      $btn.find('i').attr('class', on ? 'uil uil-check-circle' : 'uil uil-circle');
    }
    return on;
  }
  // Click handled by inline watch bootstrap (capture). Keep start-on-load here.
  $(function () {
    var pref = syncWatchAutoplayUi();
    var forced = $('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1';
    if ((pref || forced) && typeof startInlinePlayer === 'function') {
      setTimeout(startInlinePlayer, 120);
    }
  });
  /* newsite-autoplay-toggle-end */
"""
    text = re.sub(
        r"/\* newsite-autoplay-toggle \*/.*?/\* newsite-autoplay-toggle-end \*/\n?",
        "",
        text,
        flags=re.S,
    )
    anchor = "  $(document).on('click', '#btn-share', function () {"
    if anchor not in text:
        raise SystemExit("share handler missing")
    text = text.replace(anchor, block + "\n" + anchor, 1)
    path.write_text(text)
    print("refreshed app.js autoplay helpers")


def restart_next() -> None:
    import subprocess

    subprocess.check_call(["pm2", "restart", "chillflix", "--update-env"])
    print("restarted chillflix pm2")


def main() -> None:
    patch_huhu_source_ts()
    patch_huhu_compiled()
    write_newsite_assets()
    patch_watch_autoplay()
    patch_app_js_toggle()
    bump_assets()
    restart_next()
    print("deploy_huhu_lang_autoplay done")


if __name__ == "__main__":
    main()
