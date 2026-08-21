// Extracted snippets applied live to app.docksearch-1236.js
$(document).on('click', '#btn-play, #btn-watch-now', function (e) {
    e.preventDefault();
    cfPrimeAutoplaySoundNow();
    // Explicit Watch tap = user gesture → allow unmuted playback
    try { if (window.PLAYER) window.PLAYER.autoplay = true; } catch (eAp) {}
    startInlinePlayer();
  });

  $(document).on('click', '#btn-trailer, .js-play-trailer, .player-trailer-label', function (e) {
    e.preventDefault();
    e.stopPropagation();
    playTrailer();
  });

  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    e.preventDefault();
    cfPrimeAutoplaySoundNow();
    try { if (window.PLAYER) window.PLAYER.autoplay = true; } catch (eAp3) {}
    startInlinePlayer();
  });

  // Auto-start handled by newsite-autoplay-toggle (pref + ?play=1)

  /* newsite-inline-player-end */
/* newsite-autoplay-toggle */
  function syncWatchAutoplayUi() {
    if (typeof window.__cfSyncWatchAutoplay === 'function') {
      return window.__cfSyncWatchAutoplay();
    }
    var on = false;
    try { on = localStorage.getItem('cf_watch_autoplay') === '1'; } catch (e) {}
    if (!on) on = /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
    var $btn = $('#btn-autoplay');
    if ($btn.length) {
      $btn.toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false');
      $btn.find('i').attr('class', on ? 'uil uil-check-circle' : 'uil uil-circle');
    }
    return on;
  }
  // Always mount player + search sources on watch page load.
  // Auto Play pref only controls whether playback starts.
  $(function () {
    if (!$('.watch-wrap').length) return;
    var pref = syncWatchAutoplayUi();
    var forced = $('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1';
    try {
      if (window.PLAYER) window.PLAYER.autoplay = !!(pref || forced);
    } catch (eAp2) {}
    if (typeof startInlinePlayer === 'function') {
      setTimeout(startInlinePlayer, 120);
    }
  });
  /* newsite-autoplay-toggle-end */