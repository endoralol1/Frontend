    on = !!on;
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    document.cookie = key + '=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
  }

  function applyBrowsePrefs() {
    var watchlistOn = prefEnabled('cf_pref_watchlist', true);
    var continueOn = prefEnabled('cf_pref_continue', true);
    var perfOn = prefEnabled('cf_pref_performance', false);
    document.body.classList.toggle('cf-watchlist-off', !watchlistOn);
    document.body.classList.toggle('cf-continue-off', !continueOn);
    document.body.classList.toggle('cf-perf-mode', perfOn);
    try { document.documentElement.classList.toggle('cf-perf-mode', perfOn); } catch (ePerf) {}
    $('[data-browse-pref="watchlist"]').toggleClass('is-pref-off', !watchlistOn);
    $('[data-browse-pref="continue"]').toggleClass('is-pref-off', !continueOn);
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
    } catch (e) {}
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e2) {}
    try { if (typeof window.CF_refreshSkyMotion === 'function') window.CF_refreshSkyMotion(); } catch (e3) {}
  }

  function syncBrowseSettingsUi() {
    var lang = 'en';
    try { lang = localStorage.getItem('cf_lang') || 'en'; } catch (e) {}
    var $active = $('#language-menu a[data-lang].active, #language-menu li.active a[data-lang]').first();
    if ($active.length) lang = String($active.data('lang') || lang);
    var code = String($('#language-toggler .lang-code').text() || '').trim().toLowerCase();
    if (code) {
      var map = { en: 'en', es: 'es', it: 'it', fr: 'fr', de: 'de', pt: 'pt', ru: 'ru' };
      if (map[code]) lang = map[code];
    }
    $('#browse-settings-langs .browse-settings-lang').each(function () {
      var on = String($(this).data('lang') || '') === lang;
      $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
    });
    var autoOn = false;
    try { autoOn = localStorage.getItem('cf_watch_autoplay') === '1'; } catch (e) {}
    if (!autoOn) autoOn = /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
    var nextOn = true;
    try { nextOn = localStorage.getItem('cf_np_autonext') !== '0'; } catch (e) {}
    $('#browse-settings-autoplay').attr('aria-checked', autoOn ? 'true' : 'false');
    $('#browse-settings-autonext').attr('aria-checked', nextOn ? 'true' : 'false');
    $('#browse-settings-watchlist').attr('aria-checked', prefEnabled('cf_pref_watchlist', true) ? 'true' : 'false');
    $('#browse-settings-continue').attr('aria-checked', prefEnabled('cf_pref_continue', true) ? 'true' : 'false');
    $('#browse-settings-performance').attr('aria-checked', prefEnabled('cf_pref_performance', false) ? 'true' : 'false');
    applyAccentTheme(getAccentId(), false);
  }

  function openBrowseSettings() {
    closeBrowseAuth();
----
    e.preventDefault();
    var cOn = $(this).attr('aria-checked') !== 'true';
    setPrefEnabled('cf_pref_continue', cOn);
    applyBrowsePrefs();
    syncBrowseSettingsUi();
    try { nsPushPrefs({ continueEnabled: cOn }); } catch (e) {}
  });

  $(document).on('click', '#browse-settings-performance', function (e) {
    e.preventDefault();
    var pOn = $(this).attr('aria-checked') !== 'true';
    setPrefEnabled('cf_pref_performance', pOn);
    applyBrowsePrefs();
    syncBrowseSettingsUi();
  });

  $(document).on('click', '[data-browse-mock]', function (e) {
    var mock = $(this).data('browse-mock');
    if (mock === 'watch-party' || mock === 'history') return; // handled by continue-party.js
    e.preventDefault();
    var label = $.trim($(this).find('.browse-tile-label, .browse-card-copy strong, span').first().text()) || 'This';
