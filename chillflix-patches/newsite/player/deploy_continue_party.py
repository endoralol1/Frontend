#!/usr/bin/env python3
"""Deploy trailer fix + Continue Watching + Watch Party."""
from __future__ import annotations

import re
from pathlib import Path

LOCAL = Path(__file__).resolve().parent
ROOT = Path("/var/www/chillflix-newsite")
ASSET_V = "20260801-ui15"


def write_core() -> None:
    (ROOT / "app/Services/WatchParty.php").write_text((LOCAL / "WatchParty.php").read_text())
    (ROOT / "public/assets/js/player.js").write_text((LOCAL / "player.js").read_text())
    (ROOT / "public/assets/css/player.css").write_text((LOCAL / "player.css").read_text())
    (ROOT / "public/assets/js/continue-party.js").write_text((LOCAL / "continue-party.js").read_text())
    (ROOT / "public/assets/css/continue-party.css").write_text((LOCAL / "continue-party.css").read_text())
    party_dir = ROOT / "storage/party"
    party_dir.mkdir(parents=True, exist_ok=True)
    import os
    try:
        import pwd

        uid = pwd.getpwnam("www-data").pw_uid
        gid = pwd.getpwnam("www-data").pw_gid
        os.chown(party_dir, uid, gid)
        os.chmod(party_dir, 0o775)
    except Exception:
        os.chmod(party_dir, 0o777)
    # avoid 404 if layout still references legacy filename
    legacy = ROOT / "public/assets/css/continue-watching.css"
    if not legacy.exists():
        try:
            legacy.symlink_to("continue-party.css")
        except Exception:
            legacy.write_text("/* alias -> continue-party.css */\n")
    print("wrote core party/continue assets")


def patch_bootstrap() -> None:
    boot = ROOT / "app/bootstrap.php"
    text = boot.read_text()
    if "WatchParty.php" in text:
        print("bootstrap already loads WatchParty")
        return
    text = text.replace(
        "require_once __DIR__ . '/Services/PlayerSources.php';\n",
        "require_once __DIR__ . '/Services/PlayerSources.php';\nrequire_once __DIR__ . '/Services/WatchParty.php';\n",
    )
    boot.write_text(text)
    print("bootstrap loads WatchParty")


def patch_routes() -> None:
    routes = ROOT / "app/routes.php"
    text = routes.read_text()

    if "/api/party" not in text:
        marker = "$router->get('/api/player/caption', function () {"
        idx = text.find(marker)
        if idx < 0:
            # insert after subtitles block end
            idx = text.find("$router->get('/api/player/subtitles'")
        if idx < 0:
            raise SystemExit("player api routes missing")
        # find end of caption route
        end = text.find("});\n", text.find("/api/player/caption", idx) if "/api/player/caption" in text[idx:idx+500] else idx)
        if "/api/player/caption" in text:
            end = text.find("});\n", text.find("$router->get('/api/player/caption'"))
            end += len("});\n")
        else:
            end = text.find("});\n", idx) + len("});\n")
        insert = """
$router->map(['POST'], '/api/party', function () {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::create($body), 200);
});
$router->get('/api/party/{code}', function (array $p) {
    json_response(WatchParty::get((string) $p['code']));
});
$router->map(['POST'], '/api/party/{code}', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::update((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::join((string) $p['code'], $body));
});
"""
        text = text[:end] + insert + text[end:]
        print("added party API routes")
    else:
        print("party routes exist")

    # partyApi on playerConfig
    if "'partyApi'" not in text:
        text = text.replace(
            "'captionApi' => url('/api/player/caption'),",
            "'captionApi' => url('/api/player/caption'),\n        'partyApi' => url('/api/party'),",
        )
        print("added partyApi to playerConfig")

    # bump player assets + inject continue-party on home via layout later
    text = re.sub(
        r"asset\('css/player\.css'\) \. '\?v=[^']+'",
        f"asset('css/player.css') . '?v={ASSET_V}'",
        text,
    )
    text = re.sub(
        r"asset\('js/player\.js'\) \. '\?v=[^']+'",
        f"asset('js/player.js') . '?v={ASSET_V}'",
        text,
    )
    routes.write_text(text)
    print("patched routes")


def patch_home() -> None:
    home = ROOT / "app/Views/pages/home.php"
    text = home.read_text()
    if 'id="continue-watching"' in text:
        print("home continue rail exists")
        return
    needle = '                <div class="section section-top10">'
    # insert AFTER the whole top10 section — find closing before latest episodes
    marker = "                <?php if (!empty($latestEpisodes)): ?>"
    rail = """
                <div class="section section-rail" id="continue-watching" hidden>
                    <div class="head">
                        <div class="start">
                            <h2 class="title">Continue Watching</h2>
                        </div>
                    </div>
                    <div class="media-rail-wrap">
                        <button type="button" class="media-rail-nav media-rail-nav-prev" aria-label="Scroll left"><i class="uil uil-angle-left"></i></button>
                        <div class="scaff movies items media-rail-items"></div>
                        <button type="button" class="media-rail-nav media-rail-nav-next" aria-label="Scroll right"><i class="uil uil-angle-right"></i></button>
                    </div>
                </div>

"""
    if marker not in text:
        # fallback: after top10 block's last tab-content close — insert before first section-rail episodes
        idx = text.find('class="section section-rail')
        if idx < 0:
            raise SystemExit("home insert point missing")
        text = text[:idx] + rail + text[idx:]
    else:
        text = text.replace(marker, rail + marker, 1)
    home.write_text(text)
    print("added continue watching rail on home")


def patch_browse() -> None:
    nav = ROOT / "app/Views/partials/bottom-nav.php"
    text = nav.read_text()
    text2 = text.replace(
        """                    <button type="button" class="browse-tile" data-browse-mock="watch-party">
                        <span class="browse-tile-icon tone-yellow"><i class="uil uil-users-alt"></i></span>
                        <span class="browse-tile-label">Watch Party</span>
                        <span class="browse-tile-badge">Soon</span>
                    </button>""",
        """                    <button type="button" class="browse-tile" data-browse-open="watch-party">
                        <span class="browse-tile-icon tone-yellow"><i class="uil uil-users-alt"></i></span>
                        <span class="browse-tile-label">Watch Party</span>
                        <span class="browse-tile-badge">Live</span>
                    </button>""",
    )
    text2 = text2.replace(
        """                    <button type="button" class="browse-card" data-browse-mock="history">
                        <span class="browse-card-icon"><i class="uil uil-history"></i></span>
                        <span class="browse-card-copy">
                            <strong>History</strong>
                            <em>Mock for now</em>
                        </span>
                    </button>""",
        """                    <a class="browse-card" href="<?= e(url('/home')) ?>#continue-watching" data-browse-open="history">
                        <span class="browse-card-icon"><i class="uil uil-history"></i></span>
                        <span class="browse-card-copy">
                            <strong>History</strong>
                            <em>Continue watching</em>
                        </span>
                    </a>""",
    )
    if text2 == text:
        print("browse tiles already patched or markup changed")
    else:
        nav.write_text(text2)
        print("patched browse Watch Party + History")


def patch_layout_assets() -> None:
    layout = ROOT / "app/Views/layouts/main.php"
    text = layout.read_text()
    text = re.sub(r"20260801-ui\d+", ASSET_V, text)
    app_js = f"<script src=\"<?= e(asset('js/app.js')) ?>?v={ASSET_V}\" defer></script>"
    inject = (
        f"<link rel=\"stylesheet\" href=\"<?= e(asset('css/continue-party.css')) ?>?v={ASSET_V}\">\n"
        f"<script src=\"<?= e(asset('js/continue-party.js')) ?>?v={ASSET_V}\" defer></script>\n"
        + app_js
    )
    if "continue-party.js" in text:
        text = re.sub(
            r"<link rel=\"stylesheet\" href=\"<?= e\(asset\('css/continue-party\.css'\)\) \?>\?v=[^\"]+\">\s*"
            r"<script src=\"<?= e\(asset\('js/continue-party\.js'\)\) \?>\?v=[^\"]+\" defer></script>\s*",
            f"<link rel=\"stylesheet\" href=\"<?= e(asset('css/continue-party.css')) ?>?v={ASSET_V}\">\n"
            f"<script src=\"<?= e(asset('js/continue-party.js')) ?>?v={ASSET_V}\" defer></script>\n",
            text,
            count=1,
        )
    elif app_js in text:
        text = text.replace(app_js, inject, 1)
    else:
        # looser match
        text2, n = re.subn(
            r"<script src=\"<?= e\(asset\('js/app\.js'\)\) \?>\?v=[^\"]+\" defer></script>",
            inject,
            text,
            count=1,
        )
        if not n:
            raise SystemExit("app.js tag missing in layout")
        text = text2
    layout.write_text(text)
    print("layout assets bumped")


def patch_trailer_fix() -> None:
    app = ROOT / "public/assets/js/app.js"
    text = app.read_text()
    old = """  // Watch page player (official YouTube trailers)
  function playTrailer() {
    var key = $('.watch-wrap').data('trailer');
    var $frame = $('#player-frame');
    var $btn = $('#btn-play');
    if (!key) {
      $('#player .message').removeClass('d-none');
      $btn.addClass('d-none');
      return;
    }
    $btn.addClass('d-none');
    $('.player-bg').addClass('d-none');
    $frame.removeClass('d-none').html(
      '<iframe src="https://www.youtube.com/embed/' + key + '?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Trailer"></iframe>'
    );
    $('#movie-player').removeClass('no-player').addClass('playing');
  }"""
    new = """  // Watch page player (official YouTube trailers)
  function ensureWatchPlayerChrome() {
    var $player = $('#player');
    if (!$player.length) return;
    if ($('#player-frame').length) return;
    var key = $('.watch-wrap').attr('data-trailer') || '';
    var trailerBtn = key
      ? '<button type="button" class="player-trailer-label js-play-trailer">Trailer</button>'
      : '';
    $player.html(
      '<div class="message d-none"><i class="uil uil-exclamation-triangle"></i><div>Unable to load trailer. Please try again.</div></div>' +
      '<button type="button" class="btn-play" id="btn-play" aria-label="Watch now" title="Watch now"><i></i></button>' +
      trailerBtn +
      '<div id="player-frame" class="d-none"></div>'
    );
  }

  function playTrailer() {
    ensureWatchPlayerChrome();
    var key = $('.watch-wrap').attr('data-trailer') || $('.watch-wrap').data('trailer');
    var $frame = $('#player-frame');
    var $btn = $('#btn-play');
    if (!key) {
      $('#player .message').removeClass('d-none');
      $btn.addClass('d-none');
      return;
    }
    // tear down inline stream player first
    try { $('#np-shell').remove(); } catch (e) {}
    $('#movie-player').removeClass('np-active');
    $btn.addClass('d-none');
    $('.player-bg').addClass('d-none');
    $frame.removeClass('d-none').html(
      '<iframe src="https://www.youtube.com/embed/' + key + '?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Trailer"></iframe>'
    );
    $('#movie-player').removeClass('no-player').addClass('playing');
  }"""
    if "ensureWatchPlayerChrome" in text:
        print("trailer fix already present")
    elif old not in text:
        raise SystemExit("playTrailer block missing")
    else:
        text = text.replace(old, new, 1)
        print("fixed playTrailer chrome restore")

    # also fix trailer click handler to restore chrome
    text = text.replace(
        """  $(document).on('click', '#btn-trailer, .js-play-trailer, .player-trailer-label', function (e) {
    e.preventDefault();
    // if inline player active, tear it down for trailer
    $('#movie-player').removeClass('np-active');
    $('#np-shell').remove();
    playTrailer();
  });""",
        """  $(document).on('click', '#btn-trailer, .js-play-trailer, .player-trailer-label', function (e) {
    e.preventDefault();
    e.stopPropagation();
    playTrailer();
  });""",
    )

    # stop mock handler from eating watch-party / history when still mock attrs remain
    text = text.replace(
        """  $(document).on('click', '[data-browse-mock]', function (e) {
    e.preventDefault();
    var label = $.trim($(this).find('.browse-tile-label, .browse-card-copy strong, span').first().text()) || 'This';
""",
        """  $(document).on('click', '[data-browse-mock]', function (e) {
    var mock = $(this).data('browse-mock');
    if (mock === 'watch-party' || mock === 'history') return; // handled by continue-party.js
    e.preventDefault();
    var label = $.trim($(this).find('.browse-tile-label, .browse-card-copy strong, span').first().text()) || 'This';
""",
    )

    if "window.closeBrowseSheet = closeBrowseSheet" not in text:
        text = text.replace(
            "  $(document).on('click', '.browse-sheet-backdrop, .browse-sheet-close', function (e) {",
            "  window.closeBrowseSheet = closeBrowseSheet;\n\n  $(document).on('click', '.browse-sheet-backdrop, .browse-sheet-close', function (e) {",
            1,
        )

    app.write_text(text)
    print("patched app.js trailer + browse")


def patch_overlay_trailer_css() -> None:
    css = ROOT / "public/assets/css/app.css"
    text = css.read_text()
    # managers Trailer must receive clicks; overlay badge can stay non-interactive
    if "/* trailer-btn-fix */" not in text:
        text += """

/* trailer-btn-fix */
#btn-trailer.movie-manager {
  pointer-events: auto !important;
  cursor: pointer;
}
.player-trailer-label.js-play-trailer {
  pointer-events: auto !important;
  cursor: pointer;
  border: 0;
  font: inherit;
}
"""
        css.write_text(text)
        print("trailer pointer-events fix")


def main() -> None:
    write_core()
    patch_bootstrap()
    patch_routes()
    patch_home()
    patch_browse()
    patch_layout_assets()
    patch_trailer_fix()
    patch_overlay_trailer_css()
    print("deploy_continue_party done", ASSET_V)


if __name__ == "__main__":
    main()
