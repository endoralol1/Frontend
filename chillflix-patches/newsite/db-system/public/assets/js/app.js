(function ($) {
  'use strict';

  var FAV_KEY = 'user_bookmarks';
  var FAV_COOKIE = 'cf_favs_v1';
  var favMemStore = null;
  var favPersistMode = 'local';

  function favCookieGet() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_favs_v1=([^;]*)/);
      if (!m) return null;
      return decodeURIComponent(m[1]);
    } catch (e) {
      return null;
    }
  }

  function favCookieSet(raw) {
    try {
      // 1 year; keep under typical 4kb cookie budget by compacting before call
      document.cookie = FAV_COOKIE + '=' + encodeURIComponent(raw) +
        '; path=/; max-age=31536000; SameSite=Lax';
      return true;
    } catch (e) {
      return false;
    }
  }

  function favCompact(map) {
    var out = {};
    Object.keys(map || {}).forEach(function (id) {
      var it = map[id] || {};
      var poster = String(it.poster || '');
      var path = it.poster_path || null;
      if (!path && poster) {
        var m = poster.match(/\/t\/p\/[^/]+(\/.+)$/);
        if (m) path = m[1];
      }
      out[id] = {
        id: String(it.id || id),
        type: (it.type || it.mediaType) === 'tv' ? 'tv' : 'movie',
        mediaType: (it.type || it.mediaType) === 'tv' ? 'tv' : 'movie',
        title: it.title || it.name || '',
        name: it.name || it.title || '',
        poster: path ? '' : poster,
        poster_path: path,
        year: it.year || '',
        saved_at: it.saved_at || new Date().toISOString()
      };
    });
    return out;
  }

  function favExpand(map) {
    var imgBase = (window.APP && APP.imgBase) ? APP.imgBase : 'https://image.tmdb.org/t/p';
    Object.keys(map || {}).forEach(function (id) {
      var it = map[id];
      if (!it) return;
      if (!it.poster && it.poster_path) {
        it.poster = imgBase + '/w185' + it.poster_path;
      }
      if (!it.mediaType) it.mediaType = it.type || 'movie';
      if (!it.type) it.type = it.mediaType || 'movie';
      if (!it.name) it.name = it.title || '';
      if (!it.title) it.title = it.name || '';
    });
    return map || {};
  }

  function favReadRaw() {
    // Prefer last-known good source order
    try {
      var ls = localStorage.getItem(FAV_KEY);
      if (ls) { favPersistMode = 'local'; return ls; }
    } catch (e) {}
    try {
      var ss = sessionStorage.getItem(FAV_KEY);
      if (ss) { favPersistMode = 'session'; return ss; }
    } catch (e) {}
    var ck = favCookieGet();
    if (ck) { favPersistMode = 'cookie'; return ck; }
    if (favMemStore) { favPersistMode = 'memory'; return favMemStore; }
    return null;
  }

  function favWriteRaw(raw) {
    favMemStore = raw;
    // 1) localStorage
    try {
      localStorage.setItem(FAV_KEY, raw);
      favPersistMode = 'local';
      return true;
    } catch (e1) {
      // Quota / blocked: retry once without wiping continue-watching
      try {
        localStorage.setItem(FAV_KEY, raw);
        favPersistMode = 'local';
        return true;
      } catch (e2) {}
    }
    // 2) sessionStorage
    try {
      sessionStorage.setItem(FAV_KEY, raw);
      favPersistMode = 'session';
      return true;
    } catch (e3) {}
    // 3) cookie (compact already)
    if (favCookieSet(raw)) {
      favPersistMode = 'cookie';
      return true;
    }
    // 4) memory-only for this page session
    favPersistMode = 'memory';
    return true;
  }

  function favStore() {
    try {
      var raw = favReadRaw();
      if (!raw) return {};
      return favExpand(JSON.parse(raw) || {});
    } catch (e) {
      return {};
    }
  }

  function saveFavs(map) {
    var compact = favCompact(map);
    var raw = JSON.stringify(compact);
    // If payload is huge, drop poster fields and retry compact
    if (raw.length > 3500) {
      Object.keys(compact).forEach(function (id) {
        compact[id].poster = '';
      });
      raw = JSON.stringify(compact);
    }
    var ok = favWriteRaw(raw);
    if (!ok) {
      showFavToast('Could not save favorite');
      return false;
    }
    updateFavCounter();
    return true;
  }

  function favIdFromBtn($btn) {
    var raw = $btn.attr('data-id');
    if (raw == null || raw === '') raw = $btn.data('id');
    return String(raw == null ? '' : raw);
  }

  function syncFavButton($btn) {
    var id = favIdFromBtn($btn);
    if (!id) return;
    var on = !!favStore()[id];
    $btn.toggleClass('is-fav', on);
    $btn.attr('aria-pressed', on ? 'true' : 'false');
    $btn.attr('aria-label', on ? 'Remove from favorites' : 'Add to favorites');
    var $icon = $btn.find('i').first();
    if ($icon.length) {
      $icon.removeClass('uil-plus-circle uil-minus-circle uil-heart uil-heart-break');
      $icon.addClass(on ? 'uil-heart' : 'uil-plus-circle');
    }
    var $label = $btn.find('span.ml-1, .fav-label').first();
    if ($label.length) {
      $label.text(on ? 'Favorited' : 'Favorite');
    }
  }

  function showFavToast(msg) {
    var $note = $('#cf-fav-toast');
    if (!$note.length) {
      $note = $('<div id="cf-fav-toast" class="cf-fav-toast" role="status" aria-live="polite"></div>').appendTo('body');
    }
    $note.text(msg).addClass('is-visible');
    clearTimeout(window.__cfFavToastTimer);
    window.__cfFavToastTimer = setTimeout(function () {
      $note.removeClass('is-visible');
    }, 1600);
  }

  function updateFavCounter() {
    var n = Object.keys(favStore()).length;
    var $c = $('.favorites-counter');
    if (!n) {
      $c.attr('hidden', true).text('0');
    } else {
      $c.removeAttr('hidden').text(String(n));
    }
    $('.user-bookmark-toggle').each(function () {
      syncFavButton($(this));
    });
  }

  function clearFavStorage() {
    favMemStore = null;
    try { localStorage.removeItem(FAV_KEY); } catch (e) {}
    try { sessionStorage.removeItem(FAV_KEY); } catch (e) {}
    try {
      document.cookie = FAV_COOKIE + '=; path=/; max-age=0; SameSite=Lax';
    } catch (e) {}
  }

  function toggleFav($btn) {
    if (typeof prefEnabled === 'function' && !prefEnabled('cf_pref_watchlist', true)) {
      showFavToast('Watchlist is turned off in Settings');
      return;
    }
    var id = favIdFromBtn($btn);
    if (!id) {
      showFavToast('Could not favorite this title');
      return;
    }
    var type = $btn.attr('data-media-type') || $btn.data('media-type') || $btn.data('mediaType') || 'movie';
    type = String(type) === 'tv' ? 'tv' : 'movie';
    var map = favStore();
    var removing = !!map[id];
    if (removing) {
      delete map[id];
    } else {
      var poster = $btn.attr('data-poster') || $btn.data('poster') || '';
      var posterPath = null;
      var pm = String(poster).match(/\/t\/p\/[^/]+(\/.+)$/);
      if (pm) posterPath = pm[1];
      map[id] = {
        id: id,
        type: type,
        mediaType: type,
        title: $btn.attr('data-title') || $btn.data('title') || '',
        name: $btn.attr('data-title') || $btn.data('title') || '',
        poster: posterPath ? '' : poster,
        poster_path: posterPath,
        year: $btn.attr('data-year') || $btn.data('year') || '',
        saved_at: new Date().toISOString()
      };
    }
    if (!saveFavs(map)) return;
    syncFavButton($btn);
    showFavToast(removing ? 'Removed from favorites' : 'Added to favorites');
    try {
      if (browseAuthUser && typeof nsPushFavorite === 'function') {
        var entry = map[id] || {};
        var numId = parseInt(String(entry.id != null ? entry.id : id).replace(/^.*:/, ''), 10);
        if (numId) {
          nsPushFavorite({
            type: type,
            id: numId,
            title: entry.title || entry.name || '',
            poster: entry.poster || entry.poster_path || '',
            year: entry.year || ''
          }, removing);
        }
      }
    } catch (ePush) {}
  }



  // Mobile / nav menu (CSS defaults #menu to display:none)
  $(document).on('click', '#menu-toggler', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $menu = $('#menu');
    if ($menu.is(':visible')) $menu.hide();
    else $menu.show();
  });
  // Language menu
  $(document).on('click', '#language-toggler', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $('#language-menu').toggle();
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('#menu, #menu-toggler').length) {
      $('#menu').hide();
    }
    if (!$(e.target).closest('#language-menu, #language-toggler').length) {
      $('#language-menu').hide();
    }
  });

  // Search toggle + live movie/TV suggestions
  $(document).on('click', '#show-search', function (e) {
    e.preventDefault();
    e.stopPropagation();
    // Same search sheet as phone (desktop header bar is hidden).
    if ($('#search-sheet').length) {
      openSearchSheet();
      return;
    }
    var $search = $('#search');
    var $wrap = $('#search-wrapper');
    $search.toggleClass('active');
    $wrap.toggleClass('active show');
    if ($wrap.hasClass('active')) {
      $wrap.find('input[name=keyword]').trigger('focus');
    } else {
      $wrap.find('.search-suggest').removeClass('open').empty();
    }
  });

  var suggestTimer = null;
  var suggestReq = null;
  function renderSearchSuggest(results) {
    var movies = [];
    var shows = [];
    (results || []).forEach(function (r) {
      if (r.type === 'tv') shows.push(r);
      else movies.push(r);
    });

    function rows(list, label) {
      if (!list.length) return '';
      var html = '<div class="suggest-group-label">' + label + '</div>';
      list.forEach(function (r) {
        var typeLabel = r.type === 'tv' ? 'TV Show' : 'Movie';
        var poster = r.poster || '';
        html +=
          '<a href="' +
          r.url +
          '" role="option" class="suggest-item">' +
          '<img src="' +
          poster +
          '" alt="" loading="lazy">' +
          '<span class="suggest-text">' +
          '<strong>' +
          $('<div>').text(r.title || '').html() +
          '</strong>' +
          '<div class="meta-line">' +
          '<em class="type-pill">' +
          typeLabel +
          '</em>' +
          (r.year ? ' · ' + r.year : '') +
          (r.rating != null ? ' · ★ ' + r.rating : '') +
          '</div></span></a>';
      });
      return html;
    }

    var html = rows(movies, 'Movies') + rows(shows, 'TV Shows');
    if (!html) html = '<div class="suggest-empty">No movies or TV shows found</div>';
    return html;
  }

  function getSearchFilterParams() {
    try {
      if (typeof window.__getSearchFilters === 'function') {
        return window.__getSearchFilters();
      }
    } catch (e) {}
    return { type: 'all', with_genres: '', year_from: '', year_to: '', rating_from: '' };
  }

  function runLiveSearch(q, $box, $sheet) {
    clearTimeout(suggestTimer);
    if (suggestReq && suggestReq.abort) {
      try {
        suggestReq.abort();
      } catch (err) {}
      suggestReq = null;
    }
    q = $.trim(q || '');
    if (q.length < 2) {
      $box.removeClass('open').empty();
      if ($sheet) $sheet.removeClass('has-results');
      return;
    }
    suggestTimer = setTimeout(function () {
      $box.html('<div class="suggest-empty">Searching movies &amp; TV…</div>').addClass('open');
      if ($sheet) $sheet.addClass('has-results');
      var f = getSearchFilterParams();
      suggestReq = $.getJSON((window.APP && APP.baseUrl ? APP.baseUrl : '') + '/api/search', {
        q: q,
        type: f.type || 'all',
        with_genres: f.with_genres || '',
        year_from: f.year_from || '',
        year_to: f.year_to || '',
        rating_from: f.rating_from || '',
        rating_to: f.rating_to || ''
      })
        .done(function (res) {
          $box.html(renderSearchSuggest(res && res.results)).addClass('open');
          if ($sheet) {
            $sheet.addClass('has-results');
            // Remember this query once results arrive
            try { if (q.length >= 2) saveRecentSearch(q); } catch (eRecent) {}
          }
        })
        .fail(function (xhr, status) {
          if (status === 'abort') return;
          $box
            .html('<div class="suggest-empty">Search failed — try again</div>')
            .addClass('open');
        });
    }, 160);
  }

  $(document).on('input', '#search-wrapper input[name=keyword]', function () {
    runLiveSearch(this.value, $('#search-wrapper .search-suggest'), null);
  });

  var recentSearchTimer = null;
  $(document).on('input', '#search-sheet-input', function () {
    var v = $.trim(this.value || '');
    var $sheet = $('#search-sheet');
    if (v.length >= 2) $sheet.addClass('is-typing');
    else {
      $sheet.removeClass('is-typing has-results');
      // Back to idle — show recently searched again
      try { renderRecentSearches(); } catch (eR) {}
    }
    runLiveSearch(this.value, $('#search-sheet .search-sheet-suggest'), $sheet);
    // Persist recent after user pauses typing (same as hitting Go)
    clearTimeout(recentSearchTimer);
    if (v.length >= 2) {
      recentSearchTimer = setTimeout(function () {
        try { saveRecentSearch(v); } catch (eS) {}
      }, 400);
    }
  });

  $(document).on('click', function (e) {
    if ($(e.target).closest('#search, #search-sheet').length) return;
    $('#search-wrapper .search-suggest').removeClass('open').empty();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') {
      $('#search-wrapper .search-suggest').removeClass('open').empty();
      closeSearchSheet();
      closeBrowseSheet();
      try { closeSearchFiltersSheet(); } catch (eSf) {}
    }
  });

  // Tabs
  $(document).on('click', '[data-tabs] .tab', function (e) {
    var $tab = $(this);
    if ($tab.is('a')) return; // real links
    e.preventDefault();
    var name = $tab.data('name');
    var $wrap = $tab.closest('.section, .head').parent();
    if (!$wrap.find('.tab-content').length) {
      $wrap = $tab.closest('.section');
    }
    $tab.addClass('active').siblings('.tab').removeClass('active');
    var $section = $tab.closest('.section');
    $section.find('.tab-content').each(function () {
      var $pane = $(this);
      var on = $pane.data('name') === name;
      if (!on) {
        $pane.hide();
        return;
      }
      // Sidebar lists need flex so row gaps render; rails can use block/flex fine
      if ($pane.hasClass('movie-sidebar')) $pane.css('display', 'flex').show();
      else $pane.show();
    });
    setTimeout(function () {
      bindRailScrollListeners();
      syncAllRails();
      // Cinema-reveal cards in the newly shown rail
      $section.find('.tab-content:visible .cf-cinema-item').addClass('cf-cinema-in');
    }, 0);
  });

  // Horizontal rails (Top 10 + homepage Recommended/Movies/TV)
  function syncRailNav($rail) {
    if (!$rail || !$rail.length) return;
    var el = $rail.get(0);
    var $wrap = $rail.closest('.top10-rail-wrap, .media-rail-wrap');
    if (!$wrap.length) return;
    var max = Math.max(0, el.scrollWidth - el.clientWidth);
    var atStart = el.scrollLeft <= 2;
    var atEnd = el.scrollLeft >= max - 2;
    // If content fits, hide both
    if (max <= 2) {
      atStart = true;
      atEnd = true;
    }
    $wrap.find('.top10-nav-prev, .media-rail-nav-prev').prop('disabled', atStart).attr('aria-disabled', atStart ? 'true' : 'false');
    $wrap.find('.top10-nav-next, .media-rail-nav-next').prop('disabled', atEnd).attr('aria-disabled', atEnd ? 'true' : 'false');
  }

  function syncAllRails() {
    $('.top10-items, .media-rail-items').each(function () {
      syncRailNav($(this));
    });
  }

  // Scroll events do NOT bubble — bind directly so prev/next enable correctly
  function bindRailScrollListeners() {
    $('.top10-items, .media-rail-items').each(function () {
      if (this.__railScrollBound) return;
      this.__railScrollBound = true;
      var el = this;
      var onScroll = function () { syncRailNav($(el)); };
      el.addEventListener('scroll', onScroll, { passive: true });
      el.addEventListener('scrollend', onScroll);
    });
  }

  function watchRailScrollSettle($rail) {
    var el = $rail.get(0);
    if (!el) return;
    var frames = 0;
    var last = el.scrollLeft;
    function tick() {
      syncRailNav($rail);
      frames += 1;
      if (el.scrollLeft !== last) {
        last = el.scrollLeft;
        frames = 0;
      }
      // keep syncing while smooth-scroll animates (~400ms)
      if (frames < 24) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  function initCinemaReveal() {
    if (!$('body').hasClass('home')) return;

    var $rails = $('body.home .top10-items, body.home .media-rail-items, body.home .episode-rail-items');
    if (!$rails.length) return;

    $rails.each(function () {
      $(this).children('.movie-item, .episode-card').each(function (idx) {
        $(this)
          .addClass('cf-cinema-item')
          .css('--cf-i', String(Math.min(idx, 10)));
      });
    });

    if (prefersReducedMotion()) {
      $rails.children('.cf-cinema-item').addClass('cf-cinema-in');
      return;
    }

    if (window.__cinemaRevealIO) {
      try {
        window.__cinemaRevealIO.disconnect();
      } catch (e) {}
    }

    if (!('IntersectionObserver' in window)) {
      $rails.children('.cf-cinema-item').addClass('cf-cinema-in');
      return;
    }

    window.__cinemaRevealIO = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          $(entry.target).children('.cf-cinema-item').addClass('cf-cinema-in');
          window.__cinemaRevealIO.unobserve(entry.target);
        });
      },
      { rootMargin: '48px 0px', threshold: 0.1 }
    );

    $rails.each(function () {
      var rect = this.getBoundingClientRect();
      var onScreen = rect.top < window.innerHeight * 0.94 && rect.bottom > 40;
      if (onScreen) {
        var rail = this;
        // Slight beat after paint, then cascade 1→2→3…
        setTimeout(function () {
          $(rail).children('.cf-cinema-item').addClass('cf-cinema-in');
        }, 120);
      } else {
        window.__cinemaRevealIO.observe(this);
      }
    });
  }

  $(document).on('click', '.top10-nav, .media-rail-nav', function (e) {
    e.preventDefault();
    var $btn = $(this);
    if ($btn.prop('disabled') || $btn.attr('aria-disabled') === 'true') return;
    var $wrap = $btn.closest('.top10-rail-wrap, .media-rail-wrap');
    var $rail = $wrap.find('.top10-items, .media-rail-items').filter(':visible').first();
    if (!$rail.length) $rail = $wrap.find('.top10-items, .media-rail-items').first();
    if (!$rail.length) return;
    var el = $rail.get(0);
    var step = Math.max($rail.innerWidth() * 0.85, 260);
    var goingNext = $btn.hasClass('top10-nav-next') || $btn.hasClass('media-rail-nav-next');
    // Clamp so we always land cleanly at edges
    var max = Math.max(0, el.scrollWidth - el.clientWidth);
    var target = goingNext ? Math.min(el.scrollLeft + step, max) : Math.max(el.scrollLeft - step, 0);
    if (typeof el.scrollTo === 'function') {
      el.scrollTo({ left: target, behavior: 'smooth' });
    } else {
      el.scrollLeft = target;
    }
    watchRailScrollSettle($rail);
  });

  bindRailScrollListeners();
  syncAllRails();
  // Images can change scrollWidth after load
  $(window).on('load resize', function () {
    bindRailScrollListeners();
    syncAllRails();
  });

  // Favorites buttons
  $(document).on('click', '.user-bookmark-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    toggleFav($(this));
  });

  function isLowEndDevice() {
    try {
      if (navigator.connection && (navigator.connection.saveData || /2g/.test(navigator.connection.effectiveType || ''))) {
        return true;
      }
    } catch (e) {}
    return (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 4) ||
      (navigator.deviceMemory && navigator.deviceMemory <= 4);
  }

  function prefersReducedMotion() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  // Featured swiper (Swiper 5 uses .swiper-container)
  function initSwiper() {
    if (!window.Swiper || !$('#featured').length) return;
    if (window.__featuredSwiper) {
      try {
        window.__featuredSwiper.destroy(true, true);
      } catch (e) {}
      window.__featuredSwiper = null;
    }
    if (window.__featuredSwiperIO) {
      try {
        window.__featuredSwiperIO.disconnect();
      } catch (e) {}
      window.__featuredSwiperIO = null;
    }

    var lowEnd = isLowEndDevice();
    var reduceMotion = prefersReducedMotion();
    var useAutoplay = !reduceMotion && !lowEnd;

    window.__featuredSwiper = new Swiper('#featured', {
      loop: true,
      effect: lowEnd ? 'slide' : 'fade',
      fadeEffect: { crossFade: true },
      speed: lowEnd ? 450 : 900,
      autoplay: useAutoplay ? { delay: 6500, disableOnInteraction: false } : false,
      pagination: { el: '#featured .swiper-pagination', clickable: true },
      navigation: {
        nextEl: '#featured .swiper-button-next',
        prevEl: '#featured .swiper-button-prev'
      },
      on: {
        slideChange: function () {
          // Unveil nearby hero backgrounds only when needed
          var swiper = this;
          [swiper.realIndex, swiper.realIndex + 1].forEach(function (idx) {
            var slide = swiper.slides[idx];
            if (!slide) return;
            var bg = slide.getAttribute('data-bgset');
            if (bg && !slide.style.backgroundImage) {
              slide.style.backgroundImage = 'url(' + bg + ')';
              slide.classList.add('lazyloaded');
              slide.classList.remove('lazyload');
            }
          });
        }
      }
    });

    // Pause autoplay when hero leaves viewport (saves GPU while scrolling rails)
    if (useAutoplay && 'IntersectionObserver' in window) {
      window.__featuredSwiperIO = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (!window.__featuredSwiper || !window.__featuredSwiper.autoplay) return;
            if (entry.isIntersecting) window.__featuredSwiper.autoplay.start();
            else window.__featuredSwiper.autoplay.stop();
          });
        },
        { threshold: 0.2 }
      );
      window.__featuredSwiperIO.observe(document.getElementById('featured'));
    }
  }

  // Recently updated sidebar scroller
  function initRecentlyUpdated() {
    var $wrap = $('.recently-updated-scrollable');
    var $list = $('#recently-updated-list');
    if (!$wrap.length || !$list.length) return;
    var step = 280;
    var offset = 0;
    function maxOffset() {
      return Math.max(0, $list.outerHeight() - $wrap.height());
    }
    function apply() {
      offset = Math.max(0, Math.min(offset, maxOffset()));
      $list.css('transform', 'translateY(' + (-offset) + 'px)');
      $('#recently-updated-prev').prop('disabled', offset <= 0);
      $('#recently-updated-next').prop('disabled', offset >= maxOffset() - 1);
    }
    $('#recently-updated-prev').off('click.softnav').on('click.softnav', function () {
      offset -= step;
      apply();
    });
    $('#recently-updated-next').off('click.softnav').on('click.softnav', function () {
      offset += step;
      apply();
    });
    apply();
  }

  // Keep filter dropdown open when clicking inside (noclose) — legacy
  $(document).on('click', '#site-filters .noclose', function (e) {
    e.stopPropagation();
  });

  // Filter dropdowns (works with BS4 data-toggle or as fallback) — legacy
  $(document).on('click', '#site-filters .dropdown-toggle', function (e) {
    if ($(this).hasClass('reset-filters')) return;
    e.preventDefault();
    e.stopPropagation();
    var $dd = $(this).closest('.dropdown');
    var open = $dd.hasClass('show');
    $('#site-filters .dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
    if (!open) {
      $dd.addClass('show').find('.dropdown-menu').addClass('show');
    }
  });
  $(document).on('click', function (e) {
    if (!$(e.target).closest('#site-filters .dropdown').length) {
      $('#site-filters .dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
    }
  });

  function updateFilterLabel($input) {
    var $btn = $input.closest('.dropdown').find('.site-filter .value');
    if (!$btn.length) return;
    var ph = $btn.data('placeholder') || 'Select';
    var $menu = $input.closest('.dropdown-menu, .noclose');
    var checked = $menu.find('input:checked').filter(function () {
      return this.name !== 'genre_mode';
    });
    if (!checked.length) {
      $btn.text(ph);
      return;
    }
    if (checked.first().attr('type') === 'checkbox') {
      var n = checked.length;
      $btn.text(n + (ph.toLowerCase().indexOf('genre') >= 0 ? ' Genre(s)' : ' selected'));
    } else {
      $btn.text($.trim(checked.first().next('label').text()) || $.trim(checked.first().closest('label').find('span').text()));
    }
  }

  function openFiltersSheet() {
    var $sheet = $('#filters-sheet');
    if (!$sheet.length) return;
    closeBrowseSheet();
    closeSearchSheet();
    clearTimeout(window.__filtersCloseTimer);
    $sheet.removeClass('is-closing').removeAttr('hidden');
    // Force reflow so enter transition plays
    void $sheet[0].offsetWidth;
    $sheet.addClass('is-open');
    $('body').addClass('filters-open');
    $('#filters-open').attr('aria-expanded', 'true');
    syncTriInputs();
    syncYearTimeline();
    syncRatingTimeline();
  }

  function closeFiltersSheet() {
    var $sheet = $('#filters-sheet');
    if (!$sheet.length || (!$sheet.hasClass('is-open') && !$sheet.hasClass('is-closing'))) return;
    closeFilterPicker();
    $sheet.removeClass('is-open').addClass('is-closing');
    $('#filters-open').attr('aria-expanded', 'false');
    $('body').removeClass('filters-open');
    clearTimeout(window.__filtersCloseTimer);
    window.__filtersCloseTimer = setTimeout(function () {
      $sheet.removeClass('is-closing').attr('hidden', true);
    }, 380);
  }

  $(document).on('click', '#filters-open', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($('#filters-sheet').hasClass('is-open')) closeFiltersSheet();
    else openFiltersSheet();
  });

  $(document).on('click', '.filters-sheet-backdrop, .filters-sheet-close', function (e) {
    e.preventDefault();
    var $sheet = $(this).closest('.filters-sheet');
    if ($sheet.attr('id') === 'search-filters-sheet') {
      try { closeSearchFiltersSheet(); } catch (err) {}
    } else {
      closeFiltersSheet();
    }
  });

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var $open = activeFiltersSheet();
    if (!$open.length) return;
    if ($open.hasClass('has-picker')) closeFilterPicker();
    else if ($open.attr('id') === 'search-filters-sheet') {
      try { closeSearchFiltersSheet(); } catch (err) {}
    } else closeFiltersSheet();
  });

  var filterSubmitTimer = null;
  function submitFiltersSoon(delay) {
    clearTimeout(filterSubmitTimer);
    filterSubmitTimer = setTimeout(function () {
      var $form = $('#site-filters');
      if (!$form.length) return;
      // Drop type[] so it doesn't pollute the query string
      $form.find('input[name="type[]"]').prop('disabled', true);
      // Reset to page 1 when filters change
      $form.find('input[name="page"]').remove();
      closeFiltersSheet();
      $form.trigger('submit');
    }, delay || 280);
  }

  $(document).on('change', '#site-filters input[type=radio], #site-filters input[type=checkbox]', function () {
    var $input = $(this);
    // Type switches between Movies / TV pages
    if ($input.attr('name') === 'type[]') {
      var href = $input.data('href');
      if (href) {
        location.href = href;
      }
      return;
    }
    updateFilterLabel($input);
    // While the sheet is open, let the user pick multiple filters and hit Apply
    if ($('#filters-sheet').hasClass('is-open')) {
      return;
    }
    // Legacy dropdown mode: auto-submit
    if ($input.attr('type') === 'radio') {
      $('#site-filters .dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
      submitFiltersSoon(120);
    } else {
      submitFiltersSoon(650);
    }
  });

  $(document).on('submit', '#site-filters', function () {
    $(this).find('input[name="type[]"]').prop('disabled', true);
    closeFiltersSheet();
  });

  function updatePickerSummary(pickerId) {
    var $group = $('#' + pickerId + ' [data-summary-target]');
    var $box = $('[data-summary-for="' + pickerId + '"]');
    if (!$group.length || !$box.length) return;
    var html = '';
    $group.find('.filter-tri').each(function () {
      var $btn = $(this);
      var label = String($btn.data('label') || $.trim($btn.find('.filter-list-label, span').last().text()) || '');
      var val = String($btn.data('value'));
      if (!label) return;
      var tone = $btn.hasClass('is-in') ? 'in' : ($btn.hasClass('is-out') ? 'out' : '');
      if (!tone) return;
      var lead = '';
      var flag = $btn.data('flag');
      var mark = $btn.data('mark');
      var iconTone = $btn.data('tone');
      if (flag) {
        lead = '<span class="filter-bubble-lead" aria-hidden="true">' + String(flag) + '</span>';
      } else if (mark != null && String(mark) !== '') {
        lead = '<span class="filter-bubble-lead tone-' + $('<div>').text(String(iconTone || 'default')).html() + '" aria-hidden="true">' + $('<div>').text(String(mark)).html() + '</span>';
      } else if (iconTone) {
        lead = '<span class="filter-bubble-lead tone-' + $('<div>').text(String(iconTone)).html() + '" aria-hidden="true"></span>';
      }
      html += '<button type="button" class="filter-bubble is-' + tone + '" data-picker="' + pickerId + '" data-value="' + $('<div>').text(val).html() + '" title="Clear">' +
        lead +
        '<span>' + $('<div>').text(label).html() + '</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    });
    $box.html(html);
    if (html) $box.removeAttr('hidden');
    else $box.attr('hidden', true);
  }

  $(document).on('click', '.filter-bubble', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var pickerId = String($(this).data('picker') || '');
    var value = String($(this).data('value'));
    var $tri = $('#' + pickerId + ' .filter-tri').filter(function () {
      return String($(this).data('value')) === value;
    }).first();
    if ($tri.length) setTriState($tri, 'off');
  });

  function activeFiltersSheet() {
    return $('.filters-sheet.is-open').first();
  }

  function openFilterPicker(id) {
    var $picker = $('#' + id);
    if (!$picker.length) return;
    var $sheet = $picker.closest('.filters-sheet');
    if (!$sheet.length) $sheet = activeFiltersSheet();
    if (!$sheet.length) $sheet = $('#filters-sheet');
    $sheet.find('.filter-picker').removeClass('is-open').attr('hidden', true);
    $picker.removeAttr('hidden');
    void $picker[0].offsetWidth;
    $picker.addClass('is-open');
    $sheet.addClass('has-picker');
    $sheet.find('.filter-home').addClass('is-away');
  }

  function closeFilterPicker() {
    var $sheet = activeFiltersSheet();
    if (!$sheet.length) $sheet = $('#filters-sheet, #search-filters-sheet');
    $sheet.find('.filter-picker').removeClass('is-open');
    $sheet.removeClass('has-picker');
    $sheet.find('.filter-home').removeClass('is-away');
    setTimeout(function () {
      $sheet.find('.filter-picker').not('.is-open').attr('hidden', true);
    }, 280);
  }

  $(document).on('click', '[data-open-picker]', function (e) {
    e.preventDefault();
    openFilterPicker($(this).data('open-picker'));
  });

  $(document).on('click', '.filter-picker-back, .filter-picker-done', function (e) {
    e.preventDefault();
    closeFilterPicker();
  });

  function syncTriInputs() {
    var $box = $('#filter-tri-inputs');
    if (!$box.length) return;
    $box.empty();
    $('.filter-tri').each(function () {
      var $btn = $(this);
      var state = $btn.hasClass('is-in') ? 'in' : ($btn.hasClass('is-out') ? 'out' : 'off');
      if (state === 'off') return;
      var name = state === 'in' ? $btn.data('include') : $btn.data('exclude');
      var val = $btn.data('value');
      if (!name) return;
      $('<input>', { type: 'hidden', name: String(name), value: String(val) }).appendTo($box);
    });
  }

  function setTriState($btn, state) {
    $btn.removeClass('is-off is-in is-out').addClass('is-' + state);
    $btn.attr('aria-pressed', state === 'off' ? 'false' : 'true');
    syncTriInputs();
    var pickerId = $btn.closest('.filter-picker').attr('id');
    if (pickerId) updatePickerSummary(pickerId);
  }

  $(document).on('click', '.filter-tri', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var state = $btn.hasClass('is-in') ? 'in' : ($btn.hasClass('is-out') ? 'out' : 'off');
    var next = state === 'off' ? 'in' : (state === 'in' ? 'out' : 'off');
    setTriState($btn, next);
  });

  syncTriInputs();

  function formatTimelineValue(n, step) {
    if (step < 1) {
      var s = (Math.round(n * 10) / 10).toFixed(1);
      return s.replace(/\.0$/, '');
    }
    return String(Math.round(n));
  }

  function syncRangeTimeline(opts) {
    var $wrap = $(opts.wrap);
    if (!$wrap.length) return;
    var min = parseFloat($wrap.data('min'));
    var max = parseFloat($wrap.data('max'));
    var step = parseFloat($wrap.data('step')) || 1;
    var $from = $(opts.fromRange);
    var $to = $(opts.toRange);
    var from = parseFloat($from.val());
    var to = parseFloat($to.val());
    if (isNaN(from) || isNaN(to)) return;
    if (from > to) {
      if (document.activeElement === $from[0]) {
        to = from;
        $to.val(String(to));
      } else {
        from = to;
        $from.val(String(from));
      }
    }
    var span = Math.max(step, max - min);
    var left = ((from - min) / span) * 100;
    var right = ((to - min) / span) * 100;
    $(opts.fill).css({ left: left + '%', width: Math.max(0, right - left) + '%' });
    $(opts.fromLabel).text(formatTimelineValue(from, step));
    $(opts.toLabel).text(formatTimelineValue(to, step));
    if (from <= min && to >= max) {
      $(opts.fromHidden).val('');
      $(opts.toHidden).val('');
    } else {
      $(opts.fromHidden).val(formatTimelineValue(from, step));
      $(opts.toHidden).val(formatTimelineValue(to, step));
    }
  }

  function syncYearTimeline() {
    syncRangeTimeline({
      wrap: '#year-timeline',
      fromRange: '#year-from-range',
      toRange: '#year-to-range',
      fill: '#year-timeline-range',
      fromLabel: '#year-timeline-from-label',
      toLabel: '#year-timeline-to-label',
      fromHidden: '#year-from',
      toHidden: '#year-to'
    });
  }

  function syncRatingTimeline() {
    syncRangeTimeline({
      wrap: '#rating-timeline',
      fromRange: '#rating-from-range',
      toRange: '#rating-to-range',
      fill: '#rating-timeline-range',
      fromLabel: '#rating-timeline-from-label',
      toLabel: '#rating-timeline-to-label',
      fromHidden: '#rating-from',
      toHidden: '#rating-to'
    });
  }

  $(document).on('input change', '#year-from-range, #year-to-range', syncYearTimeline);
  $(document).on('input change', '#rating-from-range, #rating-to-range', syncRatingTimeline);
  syncYearTimeline();
  syncRatingTimeline();

  // Watch page player (official YouTube trailers)
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
  }





  /* cw-seed-on-watch-now */
  function seedContinueFromPlayer() {
    try {
      try { if (localStorage.getItem('cf_pref_continue') === '0') return; } catch (e0) {}
      var cfg = window.PLAYER;
      if (!cfg || !cfg.id) return;
      var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
      var map = {};
      try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
      if (Array.isArray(map)) {
        var tmp = {};
        map.forEach(function (row) {
          if (!row || !row.id) return;
          var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
          tmp[k] = row;
        });
        map = tmp;
      }
      try {
        var cm = document.cookie.match(/(?:^|;\s*)cf_continue_v1=([^;]+)/);
        if (cm) {
          var carr = JSON.parse(decodeURIComponent(cm[1]));
          if (Array.isArray(carr)) {
            carr.forEach(function (row) {
              if (!row || !row.id) return;
              var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
              if (!map[k]) map[k] = row;
            });
          }
        }
      } catch (eC) {}
      var prev = map[key] || {};
      map[key] = {
        id: Number(cfg.id) || 0,
        type: cfg.type === 'tv' ? 'tv' : 'movie',
        title: cfg.title || prev.title || 'Untitled',
        poster: cfg.poster || prev.poster || '',
        year: cfg.year || prev.year || '',
        season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
        episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
        t: Math.max(1, Number(prev.t) || 0),
        d: Number(prev.d) || 0,
        updated: Date.now(),
        url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
      };
      localStorage.setItem('cf_continue_v1', JSON.stringify(map));
      try {
        var keys = Object.keys(map).sort(function (a, b) {
          return (map[b].updated || 0) - (map[a].updated || 0);
        });
        var compact = keys.slice(0, 5).map(function (k) { return map[k]; });
        document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
          + ';path=/;max-age=31536000;SameSite=Lax';
      } catch (eCookieSet) {}
      try {
        if (typeof window.nsPushContinue === 'function') {
          window.nsPushContinue(Object.assign({ key: key }, map[key]));
        }
      } catch (ePush) {}
    } catch (e) {}
  }

  /* newsite-inline-player */
  function loadScriptOnce(src) {
    return new Promise(function (resolve, reject) {
      var found = null;
      document.querySelectorAll('script[src]').forEach(function (el) {
        if (!found && el.getAttribute('src') && el.getAttribute('src').indexOf(src.split('?')[0]) >= 0) found = el;
      });
      if (found) {
        if (found.getAttribute('data-loaded') === '1') { resolve(); return; }
        found.addEventListener('load', function () { resolve(); }, { once: true });
        found.addEventListener('error', function () { reject(new Error('script')); }, { once: true });
        return;
      }
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () { s.setAttribute('data-loaded', '1'); resolve(); };
      s.onerror = function () { reject(new Error('script load failed')); };
      document.head.appendChild(s);
    });
  }

  function ensurePlayerAssets() {
    var base = (window.APP && APP.baseUrl) ? APP.baseUrl : '';
    var hls = 'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js';
    var player = base + '/assets/js/player.js?v=20260801-ui36';
    var chain = Promise.resolve();
    if (typeof window.Hls === 'undefined') chain = chain.then(function () { return loadScriptOnce(hls); });
    if (!window.ChillflixPlayer) chain = chain.then(function () { return loadScriptOnce(player); });
    return chain;
  }

  function startInlinePlayer() {
    if (!window.PLAYER) {
      console.warn('Player config missing');
      return;
    }
    function go() {
      if (!window.ChillflixPlayer) {
        console.warn('Player not ready');
        return;
      }
      var $frame = $('#player-frame');
      if ($frame.length) {
        $frame.addClass('d-none').empty();
      }
      seedContinueFromPlayer();
      window.ChillflixPlayer.startOnWatch(window.PLAYER);
    }
    if (window.ChillflixPlayer) {
      go();
      return;
    }
    ensurePlayerAssets().then(go).catch(function () {
      console.warn('Player assets failed to load');
    });
  }

  $(document).on('click', '#btn-play, #btn-watch-now', function (e) {
    e.preventDefault();
    startInlinePlayer();
  });

  $(document).on('click', '#btn-trailer, .js-play-trailer, .player-trailer-label', function (e) {
    e.preventDefault();
    e.stopPropagation();
    playTrailer();
  });

  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    e.preventDefault();
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
  // Click handled by inline watch bootstrap (capture). Keep start-on-load here.
  $(function () {
    var pref = syncWatchAutoplayUi();
    var forced = $('.watch-wrap').data('autoplay') == 1 || $('.watch-wrap').attr('data-autoplay') === '1';
    if ((pref || forced) && typeof startInlinePlayer === 'function') {
      setTimeout(startInlinePlayer, 120);
    }
  });
  /* newsite-autoplay-toggle-end */

  $(document).on('click', '#btn-share', function () {
    var url = location.href;
    if (navigator.clipboard) navigator.clipboard.writeText(url);
    else prompt('Copy link', url);
  });

  // Watch: season dropdown + episode play
  $(document).on('click', '#movie-episode .dropdown-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $dd = $(this).closest('.dropdown');
    $dd.toggleClass('show').find('.dropdown-menu').toggleClass('show');
  });
  $(document).on('click', function (e) {
    if (!$(e.target).closest('#movie-episode .dropdown').length) {
      $('#movie-episode .dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
    }
  });





  // Favorites page render
  function favPosterUrl(it) {
    var imgBase = (window.APP && APP.imgBase) || 'https://image.tmdb.org/t/p';
    if (it.poster) return it.poster;
    if (it.poster_path) return imgBase + '/w600_and_h900_bestv2' + it.poster_path;
    return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%23151821'/%3E%3C/svg%3E";
  }

  function favHref(it, title, type) {
    var slug = String(title).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'item';
    return (window.APP && APP.baseUrl ? APP.baseUrl : '') + '/' + (type === 'tv' ? 'tv' : 'movie') + '/' + slug + '/' + it.id;
  }

  function renderFavoritesCollage(items) {
    /* collage hero removed in ui70 */
  }

  function renderFavoritesPage() {
    var $grid = $('#favorites-grid');
    if (!$grid.length) return;
    var typeFilter = $('.favorites-tabs[data-id="fav-type"] .favorites-tab.active').data('name') || 'all';
    var map = favStore();
    var allItems = Object.keys(map).map(function (k) { return map[k]; });
    allItems.sort(function (a, b) {
      return new Date(b.saved_at || 0) - new Date(a.saved_at || 0);
    });
    var movieCount = allItems.filter(function (i) { return (i.type || i.mediaType) !== 'tv'; }).length;
    var tvCount = allItems.length - movieCount;
    $('#fav-stat-total, #favorites-count-num').text(String(allItems.length));
    $('#fav-stat-movies').text(String(movieCount));
    $('#fav-stat-tv').text(String(tvCount));
    $('#fav-tab-all').text(String(allItems.length));
    $('#fav-tab-movie').text(String(movieCount));
    $('#fav-tab-tv').text(String(tvCount));
    renderFavoritesCollage(allItems);

    var items = allItems;
    if (typeFilter !== 'all') {
      items = items.filter(function (i) { return (i.type || i.mediaType) === typeFilter; });
    }

    var $spot = $('#favorites-featured');
    if (!items.length) {
      $grid.empty();
      $spot.attr('hidden', true).empty();
      $('#favorites-empty').removeAttr('hidden');
      return;
    }
    $('#favorites-empty').attr('hidden', true);

    var featured = items[0];
    var rest = items.slice(1);
    var fType = featured.type || featured.mediaType || 'movie';
    var fTitle = featured.title || featured.name || 'Untitled';
    var fYear = featured.year || '';
    var fPoster = favPosterUrl(featured);
    var fHref = favHref(featured, fTitle, fType);
    var fSafe = $('<div>').text(fTitle).html();
    var fLabel = fType === 'tv' ? 'TV Show' : 'Movie';
    $spot.html(
      '<a class="fav-spot" href="' + fHref + '" aria-label="Play ' + fSafe + '">' +
        '<span class="fav-spot-bg" style="background-image:url(\'' + fPoster.replace(/'/g, '%27') + '\')"></span>' +
        '<span class="fav-spot-poster"><img src="' + fPoster + '" alt="" width="300" height="450" loading="eager" decoding="async"></span>' +
        '<span class="fav-spot-body">' +
          '<p class="fav-spot-kicker">Recently saved</p>' +
          '<h2 class="fav-spot-title">' + fSafe + '</h2>' +
          '<p class="fav-spot-meta">' + fLabel + (fYear ? ' · ' + fYear : '') + '</p>' +
          '<span class="fav-spot-cta"><i class="uil uil-play" aria-hidden="true"></i> Watch now</span>' +
        '</span>' +
      '</a>' +
      '<button type="button" class="fav-spot-remove remove-fav" data-id="' + String(featured.id) + '" aria-label="Remove ' + fSafe + ' from favorites">' +
        '<i class="uil uil-heart" aria-hidden="true"></i>' +
      '</button>'
    ).removeAttr('hidden');

    var html = rest.map(function (it, idx) {
      var type = it.type || it.mediaType || 'movie';
      var title = it.title || it.name || 'Untitled';
      var year = it.year || '';
      var poster = favPosterUrl(it);
      var href = favHref(it, title, type);
      var safeTitle = $('<div>').text(title).html();
      var typeLabel = type === 'tv' ? 'TV' : 'Movie';
      var delay = Math.min(idx, 10) * 0.04;
      return '' +
        '<article class="fav-card" data-id="' + String(it.id) + '" style="animation-delay:' + delay + 's">' +
          '<a class="fav-card-poster" href="' + href + '" aria-label="' + safeTitle + '">' +
            '<span class="fav-card-badge">' + typeLabel + '</span>' +
            '<span class="fav-card-play" aria-hidden="true"><i class="uil uil-play"></i></span>' +
            '<img src="' + poster + '" alt="" loading="lazy" decoding="async" width="300" height="450">' +
            '<span class="fav-card-meta">' +
              '<strong>' + safeTitle + '</strong>' +
              '<em>' + typeLabel + (year ? ' · ' + year : '') + '</em>' +
            '</span>' +
          '</a>' +
          '<button type="button" class="fav-card-remove remove-fav" data-id="' + String(it.id) + '" aria-label="Remove ' + safeTitle + ' from favorites">' +
            '<i class="uil uil-heart" aria-hidden="true"></i>' +
          '</button>' +
        '</article>';
    }).join('');
    $grid.html(html);
  }

  $(document).on('click', '.favorites-tabs[data-id="fav-type"] .favorites-tab', function (e) {
    e.preventDefault();
    var $tab = $(this);
    $tab.addClass('active').attr('aria-selected', 'true')
      .siblings('.favorites-tab').removeClass('active').attr('aria-selected', 'false');
    renderFavoritesPage();
  });

  $(document).on('click', '[data-tabs][data-id="fav-type"] .tab', function () {
    setTimeout(renderFavoritesPage, 0);
  });

  $(document).on('click', '#clearAllFavorites', function () {
    if (confirm('Clear your entire watchlist?')) {
      clearFavStorage();
      updateFavCounter();
      renderFavoritesPage();
    }
  });

  $(document).on('click', '.remove-fav', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var id = String($(this).data('id'));
    var $card = $(this).closest('.fav-card, #favorites-featured');
    var finish = function () {
      var map = favStore();
      delete map[id];
      saveFavs(map);
      updateFavCounter();
      renderFavoritesPage();
    };
    if ($card.hasClass('fav-card')) {
      $card.addClass('is-leaving');
      setTimeout(finish, 240);
    } else {
      finish();
    }
  });

  var tipCache = {};

  function esc(s) {
    return $('<div>').text(s == null ? '' : String(s)).html();
  }

  function buildTipHtml(d) {
    var metaRows = '';
    if (d.country) {
      metaRows += '<div><span>Country:</span> <span>' + esc(d.country) + '</span></div>';
    }
    if (d.genre) {
      metaRows += '<div><span>Genre:</span> <span>' + esc(d.genre) + '</span></div>';
    }
    if (d.rating != null) {
      metaRows += '<div><span>Scores:</span> <span> ' + esc(d.rating) + ' by ' + esc(d.votes_label || d.votes || '0') + ' reviews</span></div>';
    }
    return '' +
      '<div class="head">' +
        '<div class="name">' + esc(d.title) + '</div>' +
        '<div class="info">' +
          '<span class="rating status-icon">' + esc(d.cert || (d.type === 'tv' ? 'TV' : 'Movie')) + '</span>' +
          (d.rating != null ? '<span><i class="uil uil-star"></i> ' + esc(d.rating) + '</span>' : '') +
          (d.year ? '<span><i class="uil uil-calender"></i> ' + esc(d.year) + '</span>' : '') +
          (d.runtime ? '<span dir="ltr"><i class="uil uil-clock"></i> ' + esc(d.runtime) + '</span>' : '') +
        '</div>' +
        '<div class="dropdown limit-h limit-w user-bookmark">' +
          '<button type="button" class="favo user-bookmark-toggle" aria-label="Add to favorites" data-id="' + esc(d.id) + '" data-media-type="' + esc(d.type) + '" data-title="' + esc(d.title) + '" data-poster="' + esc(d.poster || '') + '" data-year="' + esc(d.year || '') + '">' +
            '<i class="uil uil-plus-circle"></i>' +
          '</button>' +
        '</div>' +
      '</div>' +
      '<div class="body">' +
        '<div class="meta">' + metaRows + '</div>' +
        (d.overview ? '<div class="text-muted">' + esc(d.overview) + '</div>' : '') +
      '</div>' +
      '<a href="' + esc(d.url) + '" class="btn btn-lg w-100 mt-2 btn-gardient">Watch Now <i class="uil uil-play"></i></a>';
  }

  function initMediaTips() {
    if (!$.fn.tooltipster) return;
    // Tooltips are desktop-hover UX — skip on touch / narrow screens
    try {
      if (window.matchMedia('(hover: none), (max-width: 991.98px)').matches) return;
    } catch (e) {}

    var $targets = $('.movies.items .item-poster[data-tip], .scaff.movies .item-poster[data-tip]');
    if (!$targets.length) return;

    $targets.tooltipster({
      theme: 'tooltipster-sidetip',
      animation: 'fade',
      delay: 180,
      side: ['right', 'left', 'top', 'bottom'],
      interactive: true,
      contentAsHTML: true,
      minWidth: 260,
      maxWidth: 260,
      trigger: 'hover',
      content: '<div class="body"><div class="text-muted">Loading…</div></div>',
      functionBefore: function (instance, helper) {
        var $el = $(helper.origin);
        var id = String($el.data('tip') || '');
        var type = $el.data('media-type') || 'movie';
        var key = type + ':' + id;
        if (!id) return false;

        if (tipCache[key]) {
          instance.content(buildTipHtml(tipCache[key]));
          return true;
        }

        instance.content('<div class="body"><div class="text-muted">Loading…</div></div>');
        var base = (window.APP && APP.baseUrl) ? APP.baseUrl : '';
        $.getJSON(base + '/api/tip/' + encodeURIComponent(type) + '/' + encodeURIComponent(id))
          .done(function (data) {
            if (!data || data.error) {
              instance.content('<div class="body"><div class="text-muted">Details unavailable</div></div>');
              return;
            }
            tipCache[key] = data;
            instance.content(buildTipHtml(data));
            try { updateFavCounter(); } catch (e) {}
          })
          .fail(function () {
            instance.content('<div class="body"><div class="text-muted">Details unavailable</div></div>');
          });
      }
    });
  }

  // Instant Home / Movies / TV switching (prefetch + soft navigation)
  var pageCache = Object.create(null);
  var pageInflight = Object.create(null);
  var softNavBusy = false;
  var softNavToken = 0;

  function absoluteUrl(href) {
    try {
      return new URL(href, window.location.href).href;
    } catch (e) {
      return href;
    }
  }

  function samePage(a, b) {
    try {
      var ua = new URL(absoluteUrl(a));
      var ub = new URL(absoluteUrl(b));
      return ua.pathname.replace(/\/$/, '') === ub.pathname.replace(/\/$/, '') && ua.search === ub.search;
    } catch (e) {
      return absoluteUrl(a) === absoluteUrl(b);
    }
  }

  function prefetchPage(url) {
    url = absoluteUrl(url);
    if (pageCache[url]) return Promise.resolve(pageCache[url]);
    if (pageInflight[url]) return pageInflight[url];
    pageInflight[url] = fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' }
    })
      .then(function (res) {
        if (!res.ok) throw new Error('prefetch failed');
        return res.text();
      })
      .then(function (html) {
        pageCache[url] = html;
        delete pageInflight[url];
        return html;
      })
      .catch(function () {
        delete pageInflight[url];
        return null;
      });
    return pageInflight[url];
  }

  function prefetchBottomNavTargets() {
    $('.bottom-nav-item[href]').each(function () {
      prefetchPage(this.href);
    });
  }

  function isLiveTvUrl(href) {
    try {
      var path = new URL(absoluteUrl(href)).pathname || '';
      return /\/live\/?$/.test(path) || /\/live\/(?!party)/.test(path);
    } catch (e) { return false; }
  }

  function isWatchMediaUrl(href) {
    try {
      var path = new URL(absoluteUrl(href)).pathname || '';
      // /movie|/tv|/player/* need a full page load so hls.js + player.js + inline boot run
      return /\/(movie|tv|player)\//.test(path);
    } catch (e) {
      return false;
    }
  }

  function isInternalSoftUrl(href) {
    try {
      var u = new URL(absoluteUrl(href));
      if (u.origin !== window.location.origin) return false;
      var path = u.pathname || '';
      // Watch pages excluded — soft-nav only swaps .wrapper and never loads player boot
      if (isWatchMediaUrl(href)) return false;
      // Stay inside newsite app routes only (includes /live — instant soft swap)
      return /\/(home|movies|tv-series|anime|search|favorites|top-imdb|filters|contact|request|live|person)(\/|$|\?)/.test(path)
        || /\/person\//.test(path)
        || path === '/'
        || /\/newsite\/?$/.test(path)
        || path.endsWith('/newsite')
        || path.endsWith('/newsite/');
    } catch (e) {
      return false;
    }
  }

  /** Pull page-specific CSS/JS that live outside .wrapper (e.g. live.css / hls / live.js). */
  function ensureSoftNavPageAssets(doc) {
    if (!doc) return;
    var sel = [
      'link[rel="stylesheet"][href*="live.css"]',
      'link[rel="stylesheet"][href*="/css/player.css"]',
      'script[src*="hls.js"]',
      'script[src*="/js/live.js"]'
    ].join(',');
    doc.querySelectorAll(sel).forEach(function (node) {
      var href = node.getAttribute('href') || node.getAttribute('src') || '';
      if (!href) return;
      if (node.tagName === 'LINK') {
        if (document.querySelector('link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        document.head.appendChild(l);
        return;
      }
      if (document.querySelector('script[src="' + href.replace(/"/g, '\\"') + '"]')) return;
      var s = document.createElement('script');
      s.src = href;
      s.async = false;
      document.body.appendChild(s);
    });
  }

  var mediaSoftLinkSelector = [
    '.movie-item a.item-poster[href]',
    '.movie-item a.name[href]',
    '.media-card a.card-meta-title[href]',
    '#featured a.btn[href]',
    '.search-suggest a.suggest-item[href]',
    '.movie-sidebar .movie-item a[href]',
    '.browse-sheet a[href]',
    'header #menu a[href]'
  ].join(', ');

  function prefetchVisibleMediaLinks() {
    $(mediaSoftLinkSelector).each(function () {
      if (this.href && isInternalSoftUrl(this.href)) prefetchPage(this.href);
    });
  }

  function bindMediaPrefetchObserver() {
    // Scroll-time HTML prefetch fights image loading on weak devices — disabled.
    // Intent prefetch still runs on pointerdown (see handlers below).
    if (window.__mediaPrefetchObs) {
      try {
        window.__mediaPrefetchObs.disconnect();
      } catch (e) {}
      window.__mediaPrefetchObs = null;
    }
  }


  function refreshLazyMedia() {
    // Only ask lazysizes to check viewport — never force-load every image
    if (window.lazySizes && lazySizes.loader && typeof lazySizes.loader.checkElems === 'function') {
      lazySizes.loader.checkElems();
    }
  }

  function afterSoftNav() {
    $('.movie-item.is-opening').removeClass('is-opening');
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    try {
      /* ns-softnav-cw-sync-ui101 */
      if (typeof window.nsSyncLibrary === 'function' && /\/(home)(\/|$|\?)/.test(location.pathname || '')) {
        window.nsSyncLibrary();
      } else if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
      window.dispatchEvent(new Event('cf:softnav'));
    } catch (e) {}
    if ($.fn.tooltipster) {
      try {
        $('.tooltipstered').tooltipster('destroy');
      } catch (e) {}
    }
    initMediaTips();
    renderFavoritesPage();
    syncAllRails();
    refreshLazyMedia();
    initCinemaReveal();
    prefetchBottomNavTargets();
    bindMediaPrefetchObserver();
    syncTriInputs();
    syncYearTimeline();
    syncRatingTimeline();
  }

  function applySoftNavHtml(html, url, push) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var nextWrapper = doc.querySelector('.wrapper');
    var curWrapper = document.querySelector('.wrapper');
    if (!nextWrapper || !curWrapper) {
      window.location.href = url;
      return;
    }
    try { window.dispatchEvent(new Event('cf:before-softnav')); } catch (eBefore) {}
    try { if (window.ChillflixPlayer && typeof window.ChillflixPlayer.prepareLeave === 'function') window.ChillflixPlayer.prepareLeave(); } catch (ePrep) {}
    if ($.fn.tooltipster) {
      try {
        $('.tooltipstered').tooltipster('destroy');
      } catch (e) {}
    }
    if (window.__featuredSwiper) {
      try {
        window.__featuredSwiper.destroy(true, true);
      } catch (e) {}
      window.__featuredSwiper = null;
    }
    document.title = doc.title || document.title;
    if (doc.body && doc.body.className != null) {
      document.body.className = doc.body.className;
    }
    curWrapper.innerHTML = nextWrapper.innerHTML;
    /* softnav-run-scripts */
    try {
      var scripts = curWrapper.querySelectorAll('script');
      scripts.forEach(function (old) {
        var s = document.createElement('script');
        if (old.src) {
          s.src = old.src;
          s.async = false;
        } else {
          s.textContent = old.textContent;
        }
        old.parentNode.replaceChild(s, old);
      });
    } catch (e) {}
    try { ensureSoftNavPageAssets(doc); } catch (eA) {}
    if (push) {
      history.pushState({ softnav: 1 }, '', url);
    }
    window.scrollTo(0, 0);
    afterSoftNav();
  }

  function softNavigate(url, push) {
    url = absoluteUrl(url);
    var token = ++softNavToken;

    function done(html) {
      if (token !== softNavToken) return;
      softNavBusy = false;
      if (!html) {
        window.location.href = url;
        return;
      }
      applySoftNavHtml(html, url, push);
    }

    softNavBusy = true;
    if (pageCache[url]) {
      done(pageCache[url]);
      // Refresh cache in background for next time
      delete pageCache[url];
      prefetchPage(url);
      return;
    }

    prefetchPage(url).then(done);
  }

  $(document).on('pointerdown', '.bottom-nav-item[href]', function () {
    prefetchPage(this.href);
  });

  // Prefetch Live TV HTML as soon as browse opens (instant soft-nav)
  $(document).on('click', '.bottom-nav-browse', function () {
    try {
      var live = (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\/$/, '') + '/live';
      prefetchPage(live);
    } catch (e) {}
  });
  $(document).on('pointerdown', '.browse-sheet a[href*="/live"]', function () {
    if (this.href) prefetchPage(this.href);
  });

  $(document).on('pointerdown', mediaSoftLinkSelector, function () {
    if (this.href && isInternalSoftUrl(this.href)) prefetchPage(this.href);
  });

  $(document).on('click', '.bottom-nav-item[href]', function (e) {
    var href = this.href;
    if (!href) return;
    try {
      if (new URL(absoluteUrl(href)).origin !== window.location.origin) return;
    } catch (err) {
      return;
    }
    e.preventDefault();
    closeSearchSheet();
    closeBrowseSheet();
    if (samePage(href, window.location.href)) return;

    $('.bottom-nav-item').removeClass('active').removeAttr('aria-current');
    $(this).addClass('active').attr('aria-current', 'page');

    softNavigate(href, true);
  });

  $(document).on('click', mediaSoftLinkSelector, function (e) {
    var href = this.href;
    if (!href || !isInternalSoftUrl(href)) return;
    // Let modified clicks behave normally
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.which === 2) return;
    e.preventDefault();
    closeSearchSheet();
    closeBrowseSheet();
    if (samePage(href, window.location.href)) return;

    var $card = $(this).closest('.movie-item');
    $('.movie-item.is-opening').removeClass('is-opening');
    if ($card.length) $card.addClass('is-opening');

    softNavigate(href, true);
  });


  function positionBrowseDropdown() {
    if (window.innerWidth < 992) {
      var p0 = document.querySelector('.browse-sheet-panel');
      if (p0) {
        p0.style.top = '';
        p0.style.right = '';
        p0.style.left = '';
        p0.style.transform = '';
      }
      return;
    }
    var btn = document.querySelector('.bottom-nav-browse');
    var panel = document.querySelector('.browse-sheet-panel');
    if (!btn || !panel) return;
    var r = btn.getBoundingClientRect();
    panel.style.top = Math.round(r.bottom + 10) + 'px';
    panel.style.right = Math.round(Math.max(12, window.innerWidth - r.right)) + 'px';
    panel.style.left = 'auto';
    panel.style.transform = 'none';
  }

  function openBrowseSheet() {
    closeSearchSheet();
    closeFiltersSheet();
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
    var $sheet = $('#browse-sheet');
    if (!$sheet.length) return;
    $sheet.removeAttr('hidden').addClass('is-open');
    $('body').addClass('browse-open');
    $('.bottom-nav-browse').addClass('is-open').attr('aria-expanded', 'true');
      try { positionBrowseDropdown(); } catch (e) {}
  }

  function closeBrowseSheet() {
    var $sheet = $('#browse-sheet');
    if (!$sheet.length) return;
    $sheet.removeClass('is-open').attr('hidden', true);
    $('body').removeClass('browse-open');
    $('.bottom-nav-browse').removeClass('is-open').attr('aria-expanded', 'false');
  }



    function closeSearchSheet() {
    var $sheet = $('#search-sheet');
    if (!$sheet.length) return;
    try {
      var qClose = $.trim($('#search-sheet-input').val() || '');
      if (qClose.length >= 2) saveRecentSearch(qClose);
    } catch (eCloseSave) {}
    $sheet.removeClass('is-open has-results is-typing').attr('hidden', true);
    $('body').removeClass('search-open');
    $('.bottom-nav-search').removeClass('is-open').attr('aria-expanded', 'false');
    // Delay wipe so click handlers can still read suggest-item text/title
    setTimeout(function () {
      $('#search-sheet .search-sheet-suggest').removeClass('open').empty();
    }, 0);
  }

  $(document).on('click', '.bottom-nav-browse', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($('#browse-sheet').hasClass('is-open')) closeBrowseSheet();
    else openBrowseSheet();
  });

  $(document).on('click', '.bottom-nav-search', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($('#search-sheet').hasClass('is-open')) closeSearchSheet();
    else openSearchSheet();
  });

  window.closeBrowseSheet = closeBrowseSheet;

  $(document).on('click', '.browse-sheet-backdrop, .browse-sheet-close', function (e) {
    e.preventDefault();
    closeBrowseSheet();
  });

  $(document).on('click', '.search-sheet-backdrop, .search-sheet-close', function (e) {
    e.preventDefault();
    closeSearchSheet();
  });

  $(document).on('click', '.browse-sheet-panel a, .search-sheet-panel a', function () {
    var $a = $(this);
    // Save BEFORE closeSearchSheet empties suggestions (that was wiping recent saves)
    if ($a.hasClass('suggest-item')) {
      try {
        var typed = $.trim($('#search-sheet-input').val() || '');
        var title = $.trim($a.find('strong').first().text() || '');
        if (typed.length >= 2) saveRecentSearch(typed);
        if (title) saveRecentSearch(title);
      } catch (eSave) {}
    }
    closeBrowseSheet();
    closeSearchSheet();
  });

  // ——— Search sheet: recently searched ———
  var RECENT_SEARCH_KEY = 'cf_recent_searches_v1';
  var RECENT_SEARCH_MAX = 8;

  function recentStoreReadRaw() {
    try {
      var ls = localStorage.getItem(RECENT_SEARCH_KEY);
      if (ls) return ls;
    } catch (e0) {}
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_recent_searches_v1=([^;]*)/);
      if (m) return decodeURIComponent(m[1]);
    } catch (e1) {}
    return '[]';
  }

  function recentStoreWriteRaw(raw) {
    try { localStorage.setItem(RECENT_SEARCH_KEY, raw); } catch (e0) {}
    try {
      document.cookie = 'cf_recent_searches_v1=' + encodeURIComponent(raw)
        + '; path=/; max-age=31536000; SameSite=Lax';
    } catch (e1) {}
  }

  function getRecentSearches() {
    try {
      var raw = JSON.parse(recentStoreReadRaw() || '[]');
      return Array.isArray(raw) ? raw.filter(function (x) { return typeof x === 'string' && x.trim(); }).slice(0, RECENT_SEARCH_MAX) : [];
    } catch (e) {
      return [];
    }
  }

  function saveRecentSearch(q) {
    q = $.trim(q || '');
    if (q.length < 2) return;
    var list = getRecentSearches().filter(function (x) {
      return x.toLowerCase() !== q.toLowerCase();
    });
    list.unshift(q);
    list = list.slice(0, RECENT_SEARCH_MAX);
    recentStoreWriteRaw(JSON.stringify(list));
    renderRecentSearches();
  }

  function clearRecentSearches() {
    try { localStorage.removeItem(RECENT_SEARCH_KEY); } catch (e) {}
    try { document.cookie = 'cf_recent_searches_v1=; path=/; max-age=0; SameSite=Lax'; } catch (e2) {}
    renderRecentSearches();
  }

  function renderRecentSearches() {
    var $list = $('#search-recent-list');
    var $empty = $('#search-recent-empty');
    var $clear = $('#search-recent-clear');
    if (!$list.length) return;
    var items = getRecentSearches();
    if (!items.length) {
      $list.empty();
      $empty.removeAttr('hidden').show();
      $clear.attr('hidden', true);
      return;
    }
    $empty.attr('hidden', true).hide();
    $clear.removeAttr('hidden');
    $list.html(items.map(function (q) {
      return (
        '<button type="button" class="search-recent-item" data-recent-q="' +
        $('<div>').text(q).html().replace(/"/g, '&quot;') +
        '">' +
        '<i class="uil uil-history" aria-hidden="true"></i>' +
        '<span>' + $('<div>').text(q).html() + '</span>' +
        '<i class="uil uil-arrow-up-left" aria-hidden="true"></i>' +
        '</button>'
      );
    }).join(''));
  }

  function openSearchSheet() {
    closeBrowseSheet();
    closeFiltersSheet();
    var $sheet = $('#search-sheet');
    if (!$sheet.length) return;
    $sheet.removeAttr('hidden').addClass('is-open');
    $('body').addClass('search-open');
    $('.bottom-nav-search').addClass('is-open').attr('aria-expanded', 'true');
    renderRecentSearches();
    setTimeout(function () {
      $('#search-sheet-input').trigger('focus');
    }, 80);
  }

  $(document).on('click', '#search-recent-clear', function (e) {
    e.preventDefault();
    clearRecentSearches();
  });

  $(document).on('click', '[data-recent-q]', function (e) {
    e.preventDefault();
    var q = String($(this).attr('data-recent-q') || '');
    var $input = $('#search-sheet-input');
    $input.val(q).trigger('input').trigger('focus');
  });

  $(document).on('submit', '.search-sheet-form', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var q = $.trim($('#search-sheet-input').val() || '');
    saveRecentSearch(q);
    var $sheet = $('#search-sheet');
    if (q.length >= 2) {
      $sheet.addClass('is-typing');
      runLiveSearch(q, $sheet.find('.search-sheet-suggest'), $sheet);
    } else {
      $sheet.removeClass('is-typing has-results');
      $sheet.find('.search-sheet-suggest').removeClass('open').empty();
    }
    return false;
  });

  $(document).on('click', '#search-sheet .suggest-item', function () {
    var typed = $.trim($('#search-sheet-input').val() || '');
    if (typed.length >= 2) saveRecentSearch(typed);
    var t = $(this).find('strong').first().text();
    if (t) saveRecentSearch(t);
  });

  // ——— Search filters (Movies/TV-style sheet) ———
  var SEARCH_FILTER_KEY = 'cf_search_filters_v1';
  var searchFiltersDraft = null;

  function defaultSearchFilters() {
    return { type: 'all', genres: [], year_from: '', year_to: '', rating_from: '', rating_to: '' };
  }

  function normalizeSearchFilters(f) {
    f = f || defaultSearchFilters();
    var yf = f.year_from != null && f.year_from !== '' ? parseInt(f.year_from, 10) : NaN;
    var yt = f.year_to != null && f.year_to !== '' ? parseInt(f.year_to, 10) : NaN;
    // Full slider span = no year filter
    if ((!isNaN(yf) && !isNaN(yt) && yf <= 1970 && yt >= 2026) || (yf === 1970 && yt === 2026)) {
      f.year_from = '';
      f.year_to = '';
    }
    var rf = f.rating_from != null && f.rating_from !== '' ? parseFloat(f.rating_from) : NaN;
    var rt = f.rating_to != null && f.rating_to !== '' ? parseFloat(f.rating_to) : NaN;
    // Full rating span or bare 0 = no rating filter
    if ((!isNaN(rf) && !isNaN(rt) && rf <= 0 && rt >= 10) || (rf === 0 && (isNaN(rt) || rt >= 10))) {
      f.rating_from = '';
      f.rating_to = '';
    }
    if (!isNaN(rf) && rf <= 0 && (f.rating_to === '' || f.rating_to == null)) {
      f.rating_from = '';
    }
    if (!Array.isArray(f.genres)) f.genres = [];
    f.genres = f.genres.map(function (n) { return parseInt(n, 10); }).filter(Boolean);
    if (['all', 'movie', 'tv'].indexOf(f.type) < 0) f.type = 'all';
    return f;
  }

  function loadSearchFilters() {
    try {
      var raw = JSON.parse(localStorage.getItem(SEARCH_FILTER_KEY) || 'null');
      if (!raw || typeof raw !== 'object') return defaultSearchFilters();
      // migrate old shape {year, rating}
      var yf = raw.year_from || '';
      var yt = raw.year_to || '';
      if (!yf && !yt && raw.year) {
        if (String(raw.year).indexOf('-') >= 0) {
          var p = String(raw.year).split('-');
          yf = p[0] || ''; yt = p[1] || '';
        } else { yf = yt = String(raw.year); }
      }
      return normalizeSearchFilters({
        type: ['all', 'movie', 'tv'].indexOf(raw.type) >= 0 ? raw.type : 'all',
        genres: Array.isArray(raw.genres) ? raw.genres : [],
        year_from: yf ? String(yf) : '',
        year_to: yt ? String(yt) : '',
        rating_from: raw.rating_from != null && raw.rating_from !== '' ? String(raw.rating_from) : (raw.rating ? String(raw.rating) : ''),
        rating_to: raw.rating_to != null && raw.rating_to !== '' ? String(raw.rating_to) : ''
      });
    } catch (e) {
      return defaultSearchFilters();
    }
  }

  function saveSearchFilters(f) {
    f = normalizeSearchFilters(f);
    try { localStorage.setItem(SEARCH_FILTER_KEY, JSON.stringify(f)); } catch (e) {}
    return f;
  }

  function filtersToParams(f) {
    return {
      type: f.type || 'all',
      with_genres: (f.genres || []).join(','),
      year_from: f.year_from || '',
      year_to: f.year_to || '',
      rating_from: f.rating_from || '',
      rating_to: f.rating_to || ''
    };
  }

  window.__getSearchFilters = function () {
    return filtersToParams(loadSearchFilters());
  };

  function syncSearchFilterInputs(f) {
    var p = filtersToParams(f);
    $('#sf-type').val(p.type);
    $('#sf-genres').val(p.with_genres);
    $('#sf-year-from').val(p.year_from);
    $('#sf-year-to').val(p.year_to);
    $('#sf-rating').val(p.rating_from);
    if (!$('#sf-rating-to').length) {
      $('#search-sheet-form').append('<input type="hidden" name="rating_to" id="sf-rating-to" value="">');
    }
    $('#sf-rating-to').val(p.rating_to);
  }

  function countSearchFilters(f) {
    var n = 0;
    if (f.type && f.type !== 'all') n += 1;
    if (f.genres && f.genres.length) n += 1;
    if (f.year_from || f.year_to) n += 1;
    if (f.rating_from || f.rating_to) n += 1;
    return n;
  }

  function genreLabel(id) {
    var $btn = $('#search-filters-sheet [data-sf-genre="' + id + '"]').first();
    return $btn.length ? ($.trim($btn.data('label') || $btn.find('.filter-list-label').text()) || String(id)) : String(id);
  }

  function renderSearchFilterChips(f) {
    var $chips = $('#search-filters-chips');
    var $count = $('#search-filters-count');
    var $reset = $('#search-filters-reset');
    if (!$chips.length) return;
    var html = '';
    if (f.type === 'movie') html += '<button type="button" class="filters-chip" data-sf-clear="type"><span>Movies</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    if (f.type === 'tv') html += '<button type="button" class="filters-chip" data-sf-clear="type"><span>TV Shows</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    (f.genres || []).forEach(function (gid) {
      html += '<button type="button" class="filters-chip" data-sf-clear-genre="' + gid + '"><span>' + $('<div>').text(genreLabel(gid)).html() + '</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    });
    if (f.year_from || f.year_to) {
      var yl = (f.year_from || '…') + '–' + (f.year_to || '…');
      html += '<button type="button" class="filters-chip" data-sf-clear="year"><span>' + yl + '</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    }
    if (f.rating_from || f.rating_to) {
      var rl = (f.rating_from || '0') + '–' + (f.rating_to || '10');
      html += '<button type="button" class="filters-chip" data-sf-clear="rating"><span>★ ' + rl + '</span><i class="uil uil-times" aria-hidden="true"></i></button>';
    }
    $chips.html(html);
    var n = countSearchFilters(f);
    if (n > 0) { $count.text(String(n)).removeAttr('hidden'); $reset.removeAttr('hidden'); }
    else { $count.attr('hidden', true); $reset.attr('hidden', true); }
    syncSearchFilterInputs(f);
  }

  function syncSfTimeline($wrap, fromVal, toVal, fromLabel, toLabel, fillId) {
    if (!$wrap.length) return;
    var min = parseFloat($wrap.data('min'));
    var max = parseFloat($wrap.data('max'));
    var $from = $wrap.find('.range-timeline-thumb-from');
    var $to = $wrap.find('.range-timeline-thumb-to');
    var a = fromVal === '' || fromVal == null ? min : parseFloat(fromVal);
    var b = toVal === '' || toVal == null ? max : parseFloat(toVal);
    if (isNaN(a)) a = min;
    if (isNaN(b)) b = max;
    if (a > b) { var t = a; a = b; b = t; }
    $from.val(a);
    $to.val(b);
    $(fromLabel).text(String(a));
    $(toLabel).text(String(b));
    var left = ((a - min) / (max - min)) * 100;
    var right = ((b - min) / (max - min)) * 100;
    $wrap.find(fillId).css({ left: left + '%', width: Math.max(0, right - left) + '%' });
  }

  function readSfTimelinesInto(draft) {
    var ymin = parseFloat($('#sf-year-timeline').data('min'));
    var ymax = parseFloat($('#sf-year-timeline').data('max'));
    var yf = parseFloat($('#sf-year-from-range').val());
    var yt = parseFloat($('#sf-year-to-range').val());
    draft.year_from = (yf <= ymin && yt >= ymax) ? '' : String(Math.round(yf));
    draft.year_to = (yf <= ymin && yt >= ymax) ? '' : String(Math.round(yt));
    if (draft.year_from && draft.year_to && parseInt(draft.year_from, 10) > parseInt(draft.year_to, 10)) {
      var swap = draft.year_from; draft.year_from = draft.year_to; draft.year_to = swap;
    }

    var rmin = parseFloat($('#sf-rating-timeline').data('min'));
    var rmax = parseFloat($('#sf-rating-timeline').data('max'));
    var rf = parseFloat($('#sf-rating-from-range').val());
    var rt = parseFloat($('#sf-rating-to-range').val());
    draft.rating_from = (rf <= rmin && rt >= rmax) ? '' : String(rf);
    draft.rating_to = (rf <= rmin && rt >= rmax) ? '' : String(rt);
  }

  function updateSfGenreVisibility(type) {
    var $movie = $('#sf-genre-list [data-sf-genre-type="movie"]');
    var $tv = $('#sf-genre-list [data-sf-genre-type="tv"]');
    if (type === 'tv') {
      $movie.attr('hidden', true);
      $tv.removeAttr('hidden');
    } else if (type === 'movie') {
      $tv.attr('hidden', true);
      $movie.removeAttr('hidden');
    } else {
      // All: show movie list primarily (shared ids overlap); hide tv-only duplicates visually by showing movie first
      $movie.removeAttr('hidden');
      $tv.attr('hidden', true);
    }
  }

  function paintSearchFiltersSheet(f) {
    searchFiltersDraft = {
      type: f.type || 'all',
      genres: (f.genres || []).slice(),
      year_from: f.year_from || '',
      year_to: f.year_to || '',
      rating_from: f.rating_from || '',
      rating_to: f.rating_to || ''
    };

    $('#sf-picker-type .filter-list-item').removeClass('is-on');
    $('#sf-picker-type [data-sf-type="' + searchFiltersDraft.type + '"]').addClass('is-on');
    var typeLabel = searchFiltersDraft.type === 'movie' ? 'Movies' : (searchFiltersDraft.type === 'tv' ? 'TV Shows' : 'All');
    $('#sf-type-hint').text(typeLabel);
    $('#sf-type-bubbles').html(searchFiltersDraft.type === 'all' ? '' : ('<span class="filter-bubble">' + typeLabel + '</span>'));

    updateSfGenreVisibility(searchFiltersDraft.type);
    $('#sf-genre-list [data-sf-genre]').removeClass('is-on');
    searchFiltersDraft.genres.forEach(function (gid) {
      $('#sf-genre-list [data-sf-genre="' + gid + '"]').addClass('is-on');
    });
    if (searchFiltersDraft.genres.length) {
      $('#sf-genre-hint').text(searchFiltersDraft.genres.length + ' selected');
      $('#sf-genre-bubbles').html(searchFiltersDraft.genres.map(function (gid) {
        return '<span class="filter-bubble">' + $('<div>').text(genreLabel(gid)).html() + '</span>';
      }).join(''));
    } else {
      $('#sf-genre-hint').text('Add genres');
      $('#sf-genre-bubbles').empty();
    }

    syncSfTimeline($('#sf-year-timeline'), searchFiltersDraft.year_from || 1970, searchFiltersDraft.year_to || 2026, '#sf-year-from-label', '#sf-year-to-label', '#sf-year-range');
    syncSfTimeline($('#sf-rating-timeline'), searchFiltersDraft.rating_from || 0, searchFiltersDraft.rating_to || 10, '#sf-rating-from-label', '#sf-rating-to-label', '#sf-rating-range');
  }

  function openSearchFiltersSheet() {
    var $sheet = $('#search-filters-sheet');
    if (!$sheet.length) return;
    closeBrowseSheet();
    // keep search sheet open underneath
    clearTimeout(window.__searchFiltersCloseTimer);
    paintSearchFiltersSheet(loadSearchFilters());
    $sheet.removeClass('is-closing').removeAttr('hidden');
    void $sheet[0].offsetWidth;
    $sheet.addClass('is-open');
    $('body').addClass('filters-open');
    $('#search-filters-open').attr('aria-expanded', 'true');
  }

  function closeSearchFiltersSheet() {
    var $sheet = $('#search-filters-sheet');
    if (!$sheet.length || (!$sheet.hasClass('is-open') && !$sheet.hasClass('is-closing'))) return;
    closeFilterPicker();
    $sheet.removeClass('is-open').addClass('is-closing');
    $('#search-filters-open').attr('aria-expanded', 'false');
    // only remove filters-open if discover sheet isn't open
    if (!$('#filters-sheet').hasClass('is-open')) $('body').removeClass('filters-open');
    clearTimeout(window.__searchFiltersCloseTimer);
    window.__searchFiltersCloseTimer = setTimeout(function () {
      $sheet.removeClass('is-closing').attr('hidden', true);
    }, 380);
  }

  function applySearchFilters(f) {
    f = saveSearchFilters(f);
    renderSearchFilterChips(f);
    closeSearchFiltersSheet();
    var q = $('#search-sheet-input').val();
    var $sheet = $('#search-sheet');
    // Stay in the search sheet — never bounce to full-page search
    if (!$sheet.hasClass('is-open')) {
      try { openSearchSheet(); } catch (eOpen) {}
    }
    if ($.trim(q || '').length >= 2) {
      $sheet.addClass('is-typing');
      runLiveSearch(q, $sheet.find('.search-sheet-suggest'), $sheet);
    }
  }

  var _openSearchSheet = openSearchSheet;
  openSearchSheet = function () {
    _openSearchSheet();
    renderSearchFilterChips(loadSearchFilters());
    try { renderRecentSearches(); } catch (e) {}
  };

  $(document).on('click', '#search-filters-open', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($('#search-filters-sheet').hasClass('is-open')) closeSearchFiltersSheet();
    else openSearchFiltersSheet();
  });

  $(document).on('click', '#sf-picker-type [data-sf-type]', function (e) {
    e.preventDefault();
    if (!searchFiltersDraft) searchFiltersDraft = defaultSearchFilters();
    searchFiltersDraft.type = String($(this).data('sf-type') || 'all');
    // prune genres not in visible list
    updateSfGenreVisibility(searchFiltersDraft.type);
    searchFiltersDraft.genres = searchFiltersDraft.genres.filter(function (gid) {
      return $('#sf-genre-list [data-sf-genre="' + gid + '"]:not([hidden])').length > 0;
    });
    paintSearchFiltersSheet(searchFiltersDraft);
  });

  $(document).on('click', '#sf-genre-list [data-sf-genre]', function (e) {
    e.preventDefault();
    if (!searchFiltersDraft) searchFiltersDraft = defaultSearchFilters();
    var gid = parseInt($(this).data('sf-genre'), 10);
    if (!gid) return;
    var idx = searchFiltersDraft.genres.indexOf(gid);
    if (idx >= 0) searchFiltersDraft.genres.splice(idx, 1);
    else searchFiltersDraft.genres.push(gid);
    paintSearchFiltersSheet(searchFiltersDraft);
  });

  $(document).on('input change', '#sf-year-from-range, #sf-year-to-range, #sf-rating-from-range, #sf-rating-to-range', function () {
    if (!searchFiltersDraft) searchFiltersDraft = defaultSearchFilters();
    var $wrap = $(this).closest('.range-timeline');
    var $from = $wrap.find('.range-timeline-thumb-from');
    var $to = $wrap.find('.range-timeline-thumb-to');
    var a = parseFloat($from.val());
    var b = parseFloat($to.val());
    if (a > b) {
      if (this === $from[0]) $to.val(a);
      else $from.val(b);
    }
    if ($wrap.is('#sf-year-timeline')) {
      syncSfTimeline($wrap, $from.val(), $to.val(), '#sf-year-from-label', '#sf-year-to-label', '#sf-year-range');
    } else {
      syncSfTimeline($wrap, $from.val(), $to.val(), '#sf-rating-from-label', '#sf-rating-to-label', '#sf-rating-range');
    }
  });

  $(document).on('click', '#search-filters-apply', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (!searchFiltersDraft) searchFiltersDraft = defaultSearchFilters();
    readSfTimelinesInto(searchFiltersDraft);
    applySearchFilters(normalizeSearchFilters(searchFiltersDraft));
  });

  $(document).on('click', '#search-filters-sheet-reset, #search-filters-reset', function (e) {
    e.preventDefault();
    applySearchFilters(defaultSearchFilters());
  });

  $(document).on('click', '#search-filters-chips [data-sf-clear]', function (e) {
    e.preventDefault();
    var f = loadSearchFilters();
    var key = String($(this).data('sf-clear') || '');
    if (key === 'type') f.type = 'all';
    if (key === 'year') { f.year_from = ''; f.year_to = ''; }
    if (key === 'rating') { f.rating_from = ''; f.rating_to = ''; }
    applySearchFilters(f);
  });

  $(document).on('click', '#search-filters-chips [data-sf-clear-genre]', function (e) {
    e.preventDefault();
    var f = loadSearchFilters();
    var gid = parseInt($(this).data('sf-clear-genre'), 10);
    f.genres = (f.genres || []).filter(function (x) { return x !== gid; });
    applySearchFilters(f);
  });

  $(function () { renderSearchFilterChips(loadSearchFilters()); });


  // ——— Browse auth (main-site /api/auth) ———
  var browseAuthUser = null;
  var browseTurnstile = { login: null, register: null, loginToken: '', registerToken: '' };

  function authApi(path, options) {
    // Newsite owns auth/sync (MySQL `newsite`). Paths are under CF_BASE.
    var base = (window.CF_BASE || (window.APP && APP.baseUrl) || '');
    var url = path.indexOf('http') === 0 ? path : (base + path);
    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    }, options || {}));
  }


  function nsNormUpdated(v) {
    var n = Number(v) || 0;
    if (n > 0 && n < 1e12) n *= 1000; // seconds → ms
    return n;
  }

  function nsPushContinueItem(item) {
    if (!item) return Promise.resolve();
    var payload = {
      key: item.key || item._key || undefined,
      type: item.type === 'tv' ? 'tv' : 'movie',
      id: item.id,
      season: item.season,
      episode: item.episode,
      title: item.title || '',
      poster: item.poster || '',
      backdrop: item.backdrop || '',
      year: item.year || '',
      t: item.t || 0,
      d: item.d || 0
    };
    return authApi('/api/user/continue', { method: 'POST', body: JSON.stringify(payload) })
      .catch(function () {});
  }

  function nsRemoveContinue(key) {
    if (!key) return Promise.resolve();
    return authApi('/api/user/continue/remove', {
      method: 'POST', body: JSON.stringify({ key: key })
    }).catch(function () {});
  }

  function nsTitleContinueKey(row) {
    if (!row || !row.id) return '';
    return (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
  }

  function nsMergeContinueMaps(localMap, serverItems) {
    var local = localMap && typeof localMap === 'object' ? localMap : {};
    var server = {};
    (serverItems || []).forEach(function (item) {
      if (!item || !item.id) return;
      var type = item.type === 'tv' ? 'tv' : 'movie';
      var k = type + ':' + item.id;
      server[k] = {
        id: item.id,
        type: type,
        title: item.title || '',
        poster: item.poster || '',
        backdrop: item.backdrop || '',
        year: item.year || '',
        season: item.season,
        episode: item.episode,
        t: item.t || 0,
        d: item.d || 0,
        updated: nsNormUpdated(item.updated)
      };
    });
    var localTitles = {};
    Object.keys(local).forEach(function (k) {
      var row = local[k];
      if (!row || !row.id) return;
      var tk = nsTitleContinueKey(row);
      if (!tk) return;
      var prev = localTitles[tk];
      if (!prev || (nsNormUpdated(row.updated) >= nsNormUpdated(prev.updated))) localTitles[tk] = row;
    });
    local = localTitles;
    var keys = {};
    Object.keys(local).forEach(function (k) { keys[k] = 1; });
    Object.keys(server).forEach(function (k) { keys[k] = 1; });
    var merged = {};
    var toPush = [];
    Object.keys(keys).forEach(function (k) {
      var L = local[k];
      var S = server[k];
      if (L && !S) { merged[k] = L; toPush.push(Object.assign({ key: k }, L)); return; }
      if (S && !L) { merged[k] = S; return; }
      var lt = Number(L.t) || 0, st = Number(S.t) || 0;
      var lu = nsNormUpdated(L.updated), su = nsNormUpdated(S.updated);
      // Prefer more progress; tie-break by newer update. Keeps per-episode rows intact.
      var pickLocal = (lt > st + 2) || (Math.abs(lt - st) <= 2 && lu >= su);
      merged[k] = pickLocal ? L : Object.assign({}, S, { url: L.url || S.url });
      if (pickLocal && lu > su) toPush.push(Object.assign({ key: k }, L));
    });
    var ordered = Object.keys(merged).sort(function (a, b) {
      return (nsNormUpdated(merged[b].updated) || 0) - (nsNormUpdated(merged[a].updated) || 0);
    });
    ordered.slice(5).forEach(function (k) { delete merged[k]; });
    return { merged: merged, toPush: toPush };
  }

  function nsSyncLibrary() {
    return authApi('/api/user/library', { method: 'GET' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.user) return null;
        try {
          if (typeof favStore === 'function' && typeof saveFavs === 'function') {
            var map = {};
            (data.favorites || []).forEach(function (f) {
              var type = f.type === 'tv' ? 'tv' : 'movie';
              var id = String(f.id || '');
              if (!id) return;
              map[id] = {
                id: id,
                type: type,
                mediaType: type,
                title: f.title || '',
                name: f.title || '',
                poster: f.poster || '',
                poster_path: '',
                year: f.year || '',
                saved_at: new Date((f.updated || Date.now()) * (String(f.updated).length < 13 ? 1000 : 1)).toISOString()
              };
            });
            try { saveFavs(map); } catch (eSave) {
              try { localStorage.setItem('user_bookmarks', JSON.stringify(map)); } catch (e) {}
            }
            try { if (typeof renderFavoritesPage === 'function') renderFavoritesPage(); } catch (e2) {}
          }

          var localCw = {};
          try { localCw = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (eL) { localCw = {}; }
          // Normalize array / flat-row shapes left by cookie mirrors
          if (Array.isArray(localCw) || (localCw && localCw.id && !localCw.movie && !localCw.tv)) {
            var norm = {};
            var list = Array.isArray(localCw) ? localCw : [localCw];
            list.forEach(function (row) {
              if (!row || !row.id) return;
              var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
              norm[k] = row;
            });
            localCw = norm;
          }
          // Also merge cookie mirror before sync so server merge cannot drop local-only history
          try {
            var cm = document.cookie.match(/(?:^|;\s*)cf_continue_v1=([^;]+)/);
            if (cm) {
              var carr = JSON.parse(decodeURIComponent(cm[1]));
              if (Array.isArray(carr)) {
                carr.forEach(function (row) {
                  if (!row || !row.id) return;
                  var k = (row.type === 'tv' ? 'tv' : 'movie') + ':' + row.id;
                  if (!localCw[k] || (Number(row.updated) || 0) > (Number(localCw[k].updated) || 0)) localCw[k] = row;
                });
              }
            }
          } catch (eCookie) {}
          var merge = nsMergeContinueMaps(localCw, data.continueWatching || []);
          try { localStorage.setItem('cf_continue_v1', JSON.stringify(merge.merged)); } catch (e3) {}
          // Keep cookie mirror in sync so home recovery has the FULL list, not one title
          try {
            var mKeys = Object.keys(merge.merged || {}).sort(function (a, b) {
              return (Number(merge.merged[b].updated) || 0) - (Number(merge.merged[a].updated) || 0);
            });
            var compact = mKeys.slice(0, 5).map(function (k) {
              var row = merge.merged[k] || {};
              return {
                id: row.id, type: row.type, title: row.title || '',
                poster: row.poster || '', backdrop: '',
                year: row.year || '', season: row.season, episode: row.episode,
                t: row.t || 0, d: row.d || 0, updated: row.updated || Date.now()
              };
            }).filter(function (r) { return r && r.id; });
            document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
              + ';path=/;max-age=31536000;SameSite=Lax';
          } catch (eCookieMirror) {}
          // Upload only local-newer / local-only rows (max 12 per sync)
          (merge.toPush || []).slice(0, 5).forEach(function (item) {
            nsPushContinueItem(item);
          });
          try {
            if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
              window.ChillflixContinue.render();
            }
          } catch (e4) {}

          var u = data.user;
          try { localStorage.setItem('cf_watch_autoplay', u.autoplayEnabled ? '1' : '0'); } catch (e5) {}
          try { localStorage.setItem('cf_np_autonext', u.autoNextEnabled ? '1' : '0'); } catch (e6) {}
          try { localStorage.setItem('cf_pref_watchlist', u.watchlistEnabled ? '1' : '0'); } catch (e7) {}
          try { localStorage.setItem('cf_pref_continue', u.continueEnabled ? '1' : '0'); } catch (e8) {}
          try { if (u.language) localStorage.setItem('cf_lang', u.language); } catch (e9) {}
          try { if (typeof applyBrowsePrefs === 'function') applyBrowsePrefs(); } catch (e10) {}
          try { if (typeof syncBrowseSettingsUi === 'function') syncBrowseSettingsUi(); } catch (e11) {}
        } catch (err) { console.warn('ns sync failed', err); }
        return data;
      });
  }

  function nsPushPrefs(partial) {
    return authApi('/api/user/prefs', { method: 'POST', body: JSON.stringify(partial || {}) })
      .catch(function () {});
  }

  function nsPushFavorite(item, removing) {
    if (removing) {
      return authApi('/api/user/favorites/remove', {
        method: 'POST', body: JSON.stringify({ type: item.type, id: item.id })
      }).catch(function () {});
    }
    return authApi('/api/user/favorites', {
      method: 'POST', body: JSON.stringify(item)
    }).catch(function () {});
  }

  window.nsSyncLibrary = nsSyncLibrary;
  window.nsPushPrefs = nsPushPrefs;
  window.nsPushFavorite = nsPushFavorite;
  window.nsPushContinue = nsPushContinueItem;
  window.nsRemoveContinue = nsRemoveContinue;



  function siteName() {
    try {
      return (window.APP && APP.siteName) ? String(APP.siteName) : 'Chillflix';
    } catch (e) {
      return 'Chillflix';
    }
  }

  function setBrowseAuthMode(mode) {
    var isLogin = mode !== 'register';
    $('#browse-login-form').prop('hidden', !isLogin);
    $('#browse-register-form').prop('hidden', isLogin);
    $('.browse-auth-tab').removeClass('is-active').attr('aria-selected', 'false');
    $('.browse-auth-tab[data-auth-tab="' + (isLogin ? 'login' : 'register') + '"]')
      .addClass('is-active').attr('aria-selected', 'true');
    var sn = siteName();
    $('#browse-auth-kicker').text(isLogin ? 'Welcome back' : ('Join ' + sn));
    $('#browse-auth-title').text(isLogin ? ('Sign in to ' + sn) : 'Create your account');
    $('#browse-auth-sub').text(
      isLogin
        ? 'Pick up your watchlist, favorites, and profile anywhere.'
        : ('Save favorites and keep your ' + sn + ' profile in sync.')
    );
    $('#browse-login-error, #browse-register-error').prop('hidden', true).text('');
    renderBrowseTurnstile(isLogin ? 'login' : 'register');
  }

  function openBrowseAuth(mode) {
    try { openBrowseSheet(); } catch (eOpen) {}
    closeBrowseSettings();
    closeBrowseRequest();
    closeBrowseContact();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('auth-open');
    setBrowseAuthMode(mode || 'login');
  }

  function closeBrowseAuth() {
    $('#browse-auth-view').removeClass('is-open').attr('hidden', true);
    $('#browse-home-view').removeAttr('hidden');
    $('#browse-sheet').removeClass('auth-open');
    $('#browse-login-error, #browse-register-error').prop('hidden', true).text('');
  }

  function renderBrowseTurnstile( whichtarget ) {
    var key = (window.APP && APP.turnstileSiteKey) || '';
    if (!key || !window.turnstile) return;
    var id = whichtarget === 'register' ? 'browse-turnstile-register' : 'browse-turnstile-login';
    var el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = '';
    try {
      var wid = window.turnstile.render(el, {
        sitekey: key,
        theme: 'dark',
        callback: function (token) {
          if (whichtarget === 'register') browseTurnstile.registerToken = token;
          else browseTurnstile.loginToken = token;
        },
        'expired-callback': function () {
          if (whichtarget === 'register') browseTurnstile.registerToken = '';
          else browseTurnstile.loginToken = '';
        },
        'error-callback': function () {
          if (whichtarget === 'register') browseTurnstile.registerToken = '';
          else browseTurnstile.loginToken = '';
        }
      });
      if (whichtarget === 'register') browseTurnstile.register = wid;
      else browseTurnstile.login = wid;
    } catch (err) {
      console.warn('Turnstile render failed', err);
    }
  }

  function resetBrowseTurnstile(which) {
    if (which === 'register') browseTurnstile.registerToken = '';
    else browseTurnstile.loginToken = '';
    if (!window.turnstile) return;
    var wid = which === 'register' ? browseTurnstile.register : browseTurnstile.login;
    if (wid != null) {
      try { window.turnstile.reset(wid); } catch (e) {}
    } else {
      renderBrowseTurnstile(which);
    }
  }

  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    try { if (window.APP) APP.user = user || null; } catch (eAppU) {}
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
      try { nsSyncLibrary(); } catch (eSync) {}
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e) {}
    try { window.dispatchEvent(new Event('cf:auth')); } catch (eAuth) {}
  }

  function refreshBrowseAuthUser() {
    return authApi('/api/auth/me', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { applyBrowseAuthUser(data && data.user ? data.user : null); })
      .catch(function () { applyBrowseAuthUser(null); });
  }
  window.openBrowseAuth = openBrowseAuth;
  window.getBrowseAuthUser = function () { return browseAuthUser; };
  window.refreshBrowseAuthUser = refreshBrowseAuthUser;

  var _openBrowseSheet = openBrowseSheet;
  openBrowseSheet = function () {
    _openBrowseSheet();
    closeBrowseAuth();
    closeBrowseSettings();
    closeBrowseRequest();
    closeBrowseContact();
    applyBrowsePrefs();
    refreshBrowseAuthUser();
  };

  var _closeBrowseSheet = closeBrowseSheet;
  closeBrowseSheet = function () {
    closeBrowseAuth();
    closeBrowseSettings();
    closeBrowseRequest();
    closeBrowseContact();
    _closeBrowseSheet();
  };

  $(document).on('click', '[data-browse-auth]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openBrowseAuth($(this).data('browse-auth') || 'login');
  });

  $(document).on('click', '#browse-auth-back', function (e) {
    e.preventDefault();
    closeBrowseAuth();
  });

  $(document).on('click', '.browse-auth-tab', function (e) {
    e.preventDefault();
    setBrowseAuthMode($(this).data('auth-tab') || 'login');
  });

  $(document).on('submit', '#browse-login-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var email = String($form.find('[name=email]').val() || '').trim();
    var password = String($form.find('[name=password]').val() || '');
    var $err = $('#browse-login-error');
    var $btn = $('#browse-login-submit');
    $err.prop('hidden', true).text('');
    if ((window.APP && APP.turnstileSiteKey) && !browseTurnstile.loginToken) {
      $err.text('Complete the captcha verification.').prop('hidden', false);
      return;
    }
    $btn.prop('disabled', true).addClass('is-loading');
    authApi('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({
        email: email,
        password: password,
        turnstileToken: browseTurnstile.loginToken || undefined
      })
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      if (!res.ok) {
        $err.text((res.data && res.data.error) || 'Login failed').prop('hidden', false);
        resetBrowseTurnstile('login');
        return;
      }
      applyBrowseAuthUser(res.data.user);
      closeBrowseAuth();
    }).catch(function () {
      $err.text('Network error — try again').prop('hidden', false);
      resetBrowseTurnstile('login');
    }).finally(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('submit', '#browse-register-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var name = String($form.find('[name=name]').val() || '').trim();
    var email = String($form.find('[name=email]').val() || '').trim();
    var password = String($form.find('[name=password]').val() || '');
    var $err = $('#browse-register-error');
    var $btn = $('#browse-register-submit');
    $err.prop('hidden', true).text('');
    if ((window.APP && APP.turnstileSiteKey) && !browseTurnstile.registerToken) {
      $err.text('Complete the captcha verification.').prop('hidden', false);
      return;
    }
    $btn.prop('disabled', true).addClass('is-loading');
    authApi('/api/auth/register', {
      method: 'POST',
      body: JSON.stringify({
        name: name,
        email: email,
        password: password,
        turnstileToken: browseTurnstile.registerToken || undefined
      })
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      if (!res.ok) {
        $err.text((res.data && res.data.error) || 'Registration failed').prop('hidden', false);
        resetBrowseTurnstile('register');
        return;
      }
      applyBrowseAuthUser(res.data.user);
      closeBrowseAuth();
    }).catch(function () {
      $err.text('Network error — try again').prop('hidden', false);
      resetBrowseTurnstile('register');
    }).finally(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '#browse-auth-logout', function (e) {
    e.preventDefault();
    authApi('/api/auth/logout', { method: 'POST', body: '{}' })
      .catch(function () {})
      .then(function () { applyBrowseAuthUser(null); });
  });


// ——— Browse contact (in-sheet) ———
  function closeBrowseContact() {
    var $view = $('#browse-contact-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('contact-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }

  function openBrowseContact() {
    closeBrowseAuth();
    closeBrowseSettings();
    closeBrowseRequest();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-contact-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('contact-open');
    $('#browse-contact-alert').attr('hidden', true);
    var $form = $('#browse-contact-form');
    if ($form.length) {
      try { $form[0].reset(); } catch (e) {}
    }
    setTimeout(function () {
      $('#browse-contact-form input[name=name]').trigger('focus');
    }, 80);
  }

  $(document).on('click', '[data-browse-open="contact"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (!$('#browse-sheet').hasClass('is-open')) {
      try { openBrowseSheet(); } catch (err) {}
    }
    openBrowseContact();
  });

  $(document).on('click', '#browse-contact-back', function (e) {
    e.preventDefault();
    closeBrowseContact();
  });

  $(document).on('submit', '#browse-contact-form', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $form = $(this);
    var $btn = $('#browse-contact-submit');
    var name = String($form.find('[name=name]').val() || '').trim();
    var email = String($form.find('[name=email]').val() || '').trim();
    var message = String($form.find('[name=message]').val() || '').trim();
    if (!name || !email || !message) return;
    $btn.prop('disabled', true);
    var payload = { name: name, email: email, message: message };
    var base = (window.CF_BASE || (window.APP && APP.baseUrl) || '');
    fetch(base + '/contact', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'text/html'
      },
      body: Object.keys(payload).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
      }).join('&'),
      credentials: 'same-origin'
    }).catch(function () {}).finally(function () {
      $btn.prop('disabled', false);
      $('#browse-contact-alert').removeAttr('hidden');
      try { $form[0].reset(); } catch (err) {}
    });
  });

  window.openBrowseContact = openBrowseContact;
  window.closeBrowseContact = closeBrowseContact;

// ——— Browse request (in-sheet) ———
  function closeBrowseRequest() {
    var $view = $('#browse-request-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('request-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }

  function openBrowseRequest() {
    closeBrowseAuth();
    closeBrowseSettings();
    closeBrowseContact();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-request-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('request-open');
    $('#browse-request-alert').attr('hidden', true);
    var $form = $('#browse-request-form');
    if ($form.length) {
      try { $form[0].reset(); } catch (e) {}
      $form.find('input[name=type][value=movie]').prop('checked', true);
    }
    setTimeout(function () {
      $('#browse-request-form input[name=title]').trigger('focus');
    }, 80);
  }

  $(document).on('click', '[data-browse-open="request"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (!$('#browse-sheet').hasClass('is-open')) {
      try { openBrowseSheet(); } catch (err) {}
    }
    openBrowseRequest();
  });

  $(document).on('click', '#browse-request-back', function (e) {
    e.preventDefault();
    closeBrowseRequest();
  });

  $(document).on('submit', '#browse-request-form', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $form = $(this);
    var $btn = $('#browse-request-submit');
    var title = String($form.find('[name=title]').val() || '').trim();
    if (!title) return;
    $btn.prop('disabled', true);
    var payload = {
      type: String($form.find('[name=type]:checked').val() || 'movie'),
      title: title,
      year: String($form.find('[name=year]').val() || '').trim(),
      notes: String($form.find('[name=notes]').val() || '').trim()
    };
    var base = (window.CF_BASE || (window.APP && APP.baseUrl) || '');
    fetch(base + '/request', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'text/html'
      },
      body: Object.keys(payload).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
      }).join('&'),
      credentials: 'same-origin'
    }).catch(function () { /* UI-only fallback */ }).finally(function () {
      $btn.prop('disabled', false);
      $('#browse-request-alert').removeAttr('hidden');
      try { $form[0].reset(); } catch (err) {}
      $form.find('input[name=type][value=movie]').prop('checked', true);
    });
  });

  window.openBrowseRequest = openBrowseRequest;
  window.closeBrowseRequest = closeBrowseRequest;

  // ——— Browse settings ———
  function closeBrowseSettings() {
    var $view = $('#browse-settings-view');
    var wasOpen = $view.length && !$view.attr('hidden');
    $view.removeClass('is-open').attr('hidden', true);
    $('#browse-sheet').removeClass('settings-open');
    if (wasOpen) $('#browse-home-view').removeAttr('hidden');
  }

  function prefEnabled(key, defaultOn) {
    try {
      var v = localStorage.getItem(key);
      if (v == null || v === '') return !!defaultOn;
      return v !== '0';
    } catch (e) {
      return !!defaultOn;
    }
  }

  function setPrefEnabled(key, on) {
    on = !!on;
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
    document.cookie = key + '=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
  }

  function applyBrowsePrefs() {
    var watchlistOn = prefEnabled('cf_pref_watchlist', true);
    var continueOn = prefEnabled('cf_pref_continue', true);
    document.body.classList.toggle('cf-watchlist-off', !watchlistOn);
    document.body.classList.toggle('cf-continue-off', !continueOn);
    $('[data-browse-pref="watchlist"]').toggleClass('is-pref-off', !watchlistOn);
    $('[data-browse-pref="continue"]').toggleClass('is-pref-off', !continueOn);
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.render === 'function') {
        window.ChillflixContinue.render();
      }
    } catch (e) {}
    try { if (typeof refreshBrowseStats === 'function') refreshBrowseStats(); } catch (e2) {}
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
  }

  function openBrowseSettings() {
    closeBrowseAuth();
    closeBrowseRequest();
    closeBrowseContact();
    $('#browse-home-view').attr('hidden', true);
    $('#browse-settings-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('settings-open');
    syncBrowseSettingsUi();
  }

  function setBrowseLanguage(lang) {
    lang = String(lang || 'en').toLowerCase();
    try { localStorage.setItem('cf_lang', lang); } catch (e) {}
    document.cookie = 'cf_lang=' + encodeURIComponent(lang) + ';path=/;max-age=31536000;SameSite=Lax';
    $('#language-menu li').removeClass('active');
    $('#language-menu a[data-lang]').removeClass('active');
    var $link = $('#language-menu a[data-lang="' + lang + '"]');
    $link.addClass('active').closest('li').addClass('active');
    var label = ($link.text() || lang).trim();
    var code = lang.toUpperCase();
    $('#language-toggler .lang-code').text(code);
    $('#language-toggler').attr('title', 'Language: ' + label);
    syncBrowseSettingsUi();
    try { nsPushPrefs({ language: lang }); } catch (eLang) {}
  }

  function setBrowseAutoplay(on) {
    on = !!on;
    try { localStorage.setItem('cf_watch_autoplay', on ? '1' : '0'); } catch (e) {}
    document.cookie = 'cf_watch_autoplay=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
    if (typeof syncWatchAutoplayUi === 'function') syncWatchAutoplayUi();
    if (typeof window.__cfSyncWatchAutoplay === 'function') window.__cfSyncWatchAutoplay();
    syncBrowseSettingsUi();
    try { nsPushPrefs({ autoplayEnabled: on }); } catch (e) {}
  }

  function setBrowseAutoNext(on) {
    on = !!on;
    try { localStorage.setItem('cf_np_autonext', on ? '1' : '0'); } catch (e) {}
    syncBrowseSettingsUi();
    try { nsPushPrefs({ autoNextEnabled: on }); } catch (e2) {}
  }

  $(document).on('click', '[data-browse-open="settings"]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openBrowseSettings();
  });

  $(document).on('click', '#browse-settings-back', function (e) {
    e.preventDefault();
    closeBrowseSettings();
  });

  $(document).on('click', '#browse-settings-langs .browse-settings-lang', function (e) {
    e.preventDefault();
    setBrowseLanguage($(this).data('lang') || 'en');
  });

  $(document).on('click', '#browse-settings-autoplay', function (e) {
    e.preventDefault();
    setBrowseAutoplay($(this).attr('aria-checked') !== 'true');
  });

  $(document).on('click', '#browse-settings-autonext', function (e) {
    e.preventDefault();
    setBrowseAutoNext($(this).attr('aria-checked') !== 'true');
  });

  $(document).on('click', '#browse-settings-watchlist', function (e) {
    e.preventDefault();
    var wOn = $(this).attr('aria-checked') !== 'true';
    setPrefEnabled('cf_pref_watchlist', wOn);
    applyBrowsePrefs();
    syncBrowseSettingsUi();
    try { nsPushPrefs({ watchlistEnabled: wOn }); } catch (e) {}
  });

  $(document).on('click', '#browse-settings-continue', function (e) {
    e.preventDefault();
    var cOn = $(this).attr('aria-checked') !== 'true';
    setPrefEnabled('cf_pref_continue', cOn);
    applyBrowsePrefs();
    syncBrowseSettingsUi();
    try { nsPushPrefs({ continueEnabled: cOn }); } catch (e) {}
  });

  $(document).on('click', '[data-browse-mock]', function (e) {
    var mock = $(this).data('browse-mock');
    if (mock === 'watch-party' || mock === 'history') return; // handled by continue-party.js
    e.preventDefault();
    var label = $.trim($(this).find('.browse-tile-label, .browse-card-copy strong, span').first().text()) || 'This';
    var $note = $('#browse-mock-toast');
    if (!$note.length) {
      $note = $('<div id="browse-mock-toast" class="browse-mock-toast" role="status"></div>').appendTo('body');
    }
    $note.text(label + ' is mock data for now').addClass('is-visible');
    clearTimeout(window.__browseMockToastTimer);
    window.__browseMockToastTimer = setTimeout(function () {
      $note.removeClass('is-visible');
    }, 1600);
  });





  window.addEventListener('popstate', function () {
    softNavigate(window.location.href, false);
  });

  $(function () {
    applyBrowsePrefs();
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    renderFavoritesPage();
    syncAllRails();
    initCinemaReveal();
    document.addEventListener('lazybeforeunveil', function (e) {
      var bg = e.target.getAttribute('data-bgset');
      if (bg) e.target.style.backgroundImage = 'url(' + bg + ')';
    });
    // Desktop tip widgets after first paint / idle
    var bootTips = function () {
      initMediaTips();
    };
    if ('requestIdleCallback' in window) {
      requestIdleCallback(bootTips, { timeout: 1800 });
    } else {
      setTimeout(bootTips, 600);
    }
    try {
      history.replaceState({ softnav: 1 }, '', window.location.href);
    } catch (e) {}
    // Only warm primary nav routes when idle — not every media card HTML
    if ('requestIdleCallback' in window) {
      requestIdleCallback(function () {
        prefetchBottomNavTargets();
      }, { timeout: 2000 });
    } else {
      setTimeout(prefetchBottomNavTargets, 900);
    }
  });
})(jQuery);


/* cf-browse-dropdown-reposition */
(function () {
  function onBrowseReposition() {
    if (document.body.classList.contains('browse-open')) {
      try { positionBrowseDropdown(); } catch (e) {}
    }
  }
  window.addEventListener('resize', onBrowseReposition, { passive: true });
  window.addEventListener('scroll', onBrowseReposition, { passive: true });
})();


/* browse-watch-stats */
(function () {
  function readCwItems() {
    try {
      if (window.ChillflixContinue && typeof window.ChillflixContinue.list === "function") {
        return window.ChillflixContinue.list() || [];
      }
    } catch (e) {}
    try {
      var raw = localStorage.getItem("cf_continue_v1");
      var map = raw ? JSON.parse(raw) : {};
      if (!map || typeof map !== "object") return [];
      return Object.keys(map).map(function (k) { return map[k]; }).filter(Boolean);
    } catch (e2) {
      return [];
    }
  }

  function readFavRaw() {
    try {
      var ls = localStorage.getItem("user_bookmarks");
      if (ls) return ls;
    } catch (e) {}
    try {
      var ss = sessionStorage.getItem("user_bookmarks");
      if (ss) return ss;
    } catch (e2) {}
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_favs_v1=([^;]*)/);
      if (m) return decodeURIComponent(m[1]);
    } catch (e3) {}
    return null;
  }

  function readFavCount() {
    try {
      var raw = readFavRaw();
      if (!raw) return 0;
      var map = JSON.parse(raw) || {};
      return Object.keys(map).length;
    } catch (e) {
      return 0;
    }
  }

  function formatWatchTime(seconds) {
    var s = Math.max(0, Math.floor(Number(seconds) || 0));
    if (s < 60) return "Less than 1m watched";
    var mins = Math.round(s / 60);
    if (mins < 60) return "~" + mins + "m watched";
    var h = Math.floor(mins / 60);
    var m = mins % 60;
    if (h < 24) return "~" + h + "h" + (m ? " " + m + "m" : "") + " watched";
    var d = Math.floor(h / 24);
    var rh = h % 24;
    return "~" + d + "d" + (rh ? " " + rh + "h" : "") + " watched";
  }

  function computeBrowseStats() {
    var items = readCwItems();
    var movies = {};
    var shows = {};
    var watchedSecs = 0;
    items.forEach(function (row) {
      if (!row || !row.id) return;
      var id = String(row.id);
      var t = Number(row.t) || 0;
      if (t > 0) watchedSecs += t;
      if ((row.type || row.mediaType) === "tv") shows[id] = true;
      else movies[id] = true;
    });
    return {
      movies: Object.keys(movies).length,
      tv: Object.keys(shows).length,
      favs: readFavCount(),
      seconds: watchedSecs,
      totalTitles: Object.keys(movies).length + Object.keys(shows).length
    };
  }

  function refreshBrowseStats() {
    var root = document.getElementById("browse-stats-section");
    if (!root) return;
    var stats = computeBrowseStats();
    var m = document.getElementById("browse-stat-movies");
    var tv = document.getElementById("browse-stat-tv");
    var f = document.getElementById("browse-stat-favs");
    var time = document.getElementById("browse-stats-time");
    var who = document.getElementById("browse-stats-who");
    if (m) m.textContent = String(stats.movies);
    if (tv) tv.textContent = String(stats.tv);
    if (f) f.textContent = String(stats.favs);
    if (time) {
      time.textContent = stats.totalTitles
        ? formatWatchTime(stats.seconds)
        : "No watch history yet";
    }
    if (who) {
      var nameEl = document.getElementById("browse-auth-name");
      var userCard = document.getElementById("browse-auth-user");
      var signedIn = userCard && !userCard.hasAttribute("hidden");
      if (signedIn && nameEl && nameEl.textContent && nameEl.textContent !== "Signed in") {
        who.textContent = nameEl.textContent.trim();
      } else if (signedIn) {
        who.textContent = "Member";
      } else {
        who.textContent = "Guest";
      }
    }
  }

  window.refreshBrowseStats = refreshBrowseStats;

  var _open = window.openBrowseSheet;
  if (typeof _open === "function") {
    window.openBrowseSheet = function () {
      var r = _open.apply(this, arguments);
      try { refreshBrowseStats(); } catch (e) {}
      return r;
    };
  }

  // Also hook jQuery-bound open if exposed later
  document.addEventListener("click", function (e) {
    if (e.target && e.target.closest && e.target.closest(".bottom-nav-browse")) {
      setTimeout(function () {
        try { refreshBrowseStats(); } catch (err) {}
      }, 0);
    }
  }, true);

  window.addEventListener("cf:softnav", function () {
    try { refreshBrowseStats(); } catch (e) {}
  });
  window.addEventListener("storage", function (e) {
    if (!e.key || e.key === "cf_continue_v1" || e.key === "user_bookmarks") {
      try { refreshBrowseStats(); } catch (err) {}
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refreshBrowseStats);
  } else {
    refreshBrowseStats();
  }
})();



/* ns-boot-sync-ui102
 * Previous boot called authApi()/nsSyncLibrary() OUTSIDE their IIFE scope, so
 * authApi threw ReferenceError and logged-in library sync never ran on home.
 * That left Continue Watching stuck on whatever was in the local cookie (often 1 item)
 * even when MySQL had the full history.
 */
(function () {
  function base() {
    return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\/$/, '');
  }
  function bootSync() {
    if (typeof window.nsSyncLibrary !== 'function') return;
    fetch(base() + '/api/auth/me', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.user) {
          try { window.nsSyncLibrary(); } catch (e) {}
        }
      })
      .catch(function () {});
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootSync);
  } else {
    bootSync();
  }
})();


/* newsite-admin-menu-ui96 */
(function () {
  function base() {
    return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\/$/, '');
  }
  function menuRoot() { return document.getElementById('header-admin-menu'); }
  function dropdown() { return document.getElementById('header-admin-dropdown'); }
  function toggleBtn() { return document.getElementById('header-admin-link'); }

  function placeDropdown() {
    var btn = toggleBtn();
    var dd = dropdown();
    if (!btn || !dd || dd.hidden) return;
    var r = btn.getBoundingClientRect();
    var pad = 8;
    var width = Math.min(296, window.innerWidth - pad * 2);
    var left = Math.min(Math.max(pad, r.left), window.innerWidth - width - pad);
    // Prefer below; if not enough room, flip above
    var below = r.bottom + 8;
    var estHeight = Math.min(dd.scrollHeight || 320, window.innerHeight * 0.7);
    var top = below;
    if (below + estHeight > window.innerHeight - pad && r.top > estHeight + pad) {
      top = Math.max(pad, r.top - estHeight - 8);
    }
    dd.style.position = 'fixed';
    dd.style.top = Math.round(top) + 'px';
    dd.style.left = Math.round(left) + 'px';
    dd.style.right = 'auto';
    dd.style.width = width + 'px';
    dd.style.zIndex = '10050';
  }

  function closeMenu() {
    var root = menuRoot();
    var dd = dropdown();
    var btn = toggleBtn();
    if (!root || !dd || !btn) return;
    dd.hidden = true;
    root.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('admin-menu-open');
  }
  function openMenu() {
    var root = menuRoot();
    var dd = dropdown();
    var btn = toggleBtn();
    if (!root || !dd || !btn || root.hidden) return;
    dd.hidden = false;
    root.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('admin-menu-open');
    placeDropdown();
    // second pass after layout for accurate height
    requestAnimationFrame(placeDropdown);
  }
  function ensureAdminChrome(user) {
    var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
    var root = menuRoot();
    if (root) {
      if (staff) root.removeAttribute('hidden');
      else { root.setAttribute('hidden', 'hidden'); closeMenu(); }
    }
    // Admin stays in the header menu only — never inject into browse sheet
    var existing = document.getElementById('browse-admin-link');
    if (existing) existing.remove();
  }
  function refreshAdminChrome() {
    fetch(base() + '/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
      .catch(function () { ensureAdminChrome(null); });
  }
  document.addEventListener('click', function (e) {
    var root = menuRoot();
    var btn = toggleBtn();
    var dd = dropdown();
    if (!root || !btn || root.hidden) return;
    var t = e.target;
    if (btn === t || btn.contains(t)) {
      e.preventDefault();
      if (root.classList.contains('is-open')) closeMenu();
      else openMenu();
      return;
    }
    if (dd && !dd.hidden && dd.contains(t)) return;
    if (!root.contains(t)) closeMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });
  window.addEventListener('resize', function () {
    if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
  });
  window.addEventListener('scroll', function () {
    if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
  }, true);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshAdminChrome);
  } else {
    refreshAdminChrome();
  }
  if (window.jQuery) {
    jQuery(document).on('click', '.bottom-nav-browse', function () {
      setTimeout(refreshAdminChrome, 180);
    });
  }
  window.__cfEnsureAdminChrome = ensureAdminChrome;
})();



