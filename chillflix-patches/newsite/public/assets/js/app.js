(function ($) {
  'use strict';

  var FAV_KEY = 'user_bookmarks';

  function favStore() {
    try {
      return JSON.parse(localStorage.getItem(FAV_KEY) || '{}') || {};
    } catch (e) {
      return {};
    }
  }

  function saveFavs(map) {
    localStorage.setItem(FAV_KEY, JSON.stringify(map));
    updateFavCounter();
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
      var id = String($(this).data('id'));
      $(this).toggleClass('is-fav', !!favStore()[id]);
    });
  }

  function toggleFav($btn) {
    var id = String($btn.data('id'));
    var type = $btn.data('media-type') || 'movie';
    var map = favStore();
    if (map[id]) {
      delete map[id];
    } else {
      map[id] = {
        id: id,
        type: type,
        mediaType: type,
        title: $btn.data('title') || '',
        name: $btn.data('title') || '',
        poster: $btn.data('poster') || '',
        poster_path: null,
        year: $btn.data('year') || '',
        saved_at: new Date().toISOString()
      };
    }
    saveFavs(map);
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
    var $search = $('#search');
    var $wrap = $('#search-wrapper');
    // Mobile CSS expects .active on #search-wrapper (not .show).
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

  $(document).on('input', '#search-wrapper input[name=keyword]', function () {
    var q = $.trim(this.value || '');
    var $box = $('#search-wrapper .search-suggest');
    clearTimeout(suggestTimer);
    if (suggestReq && suggestReq.abort) {
      try {
        suggestReq.abort();
      } catch (err) {}
      suggestReq = null;
    }
    if (q.length < 2) {
      $box.removeClass('open').empty();
      return;
    }
    suggestTimer = setTimeout(function () {
      $box.html('<div class="suggest-empty">Searching movies &amp; TV…</div>').addClass('open');
      suggestReq = $.getJSON((window.APP && APP.baseUrl ? APP.baseUrl : '') + '/api/search', {
        q: q,
      })
        .done(function (res) {
          $box.html(renderSearchSuggest(res && res.results)).addClass('open');
        })
        .fail(function (xhr, status) {
          if (status === 'abort') return;
          $box
            .html('<div class="suggest-empty">Search failed — try again</div>')
            .addClass('open');
        });
    }, 180);
  });

  $(document).on('click', function (e) {
    if ($(e.target).closest('#search').length) return;
    $('#search-wrapper .search-suggest').removeClass('open').empty();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') {
      $('#search-wrapper .search-suggest').removeClass('open').empty();
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
    $wrap.find('> .tab-content, .tab-content').hide().filter('[data-name="' + name + '"]').show();
    // recommended section structure
    $tab.closest('.section').find('.tab-content').hide().filter('[data-name="' + name + '"]').show();
  });

  // Top 10 horizontal rail arrows
  function syncTop10Nav($rail) {
    if (!$rail || !$rail.length) return;
    var el = $rail.get(0);
    var $wrap = $rail.closest('.top10-rail-wrap');
    var max = el.scrollWidth - el.clientWidth - 2;
    $wrap.find('.top10-nav-prev').prop('disabled', el.scrollLeft <= 2);
    $wrap.find('.top10-nav-next').prop('disabled', el.scrollLeft >= max);
  }

  $(document).on('click', '.top10-nav', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $wrap = $btn.closest('.top10-rail-wrap');
    var $rail = $wrap.find('.top10-items').first();
    if (!$rail.length) return;
    var step = Math.max($rail.width() * 0.85, 220);
    $rail.get(0).scrollBy({ left: $btn.hasClass('top10-nav-next') ? step : -step, behavior: 'smooth' });
  });

  $(document).on('scroll', '.top10-items', function () {
    syncTop10Nav($(this));
  });

  $('.section-top10 .top10-items').each(function () {
    syncTop10Nav($(this));
  });

  // Favorites buttons
  $(document).on('click', '.user-bookmark-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();
    toggleFav($(this));
  });

  // Featured swiper (Swiper 5 uses .swiper-container)
  function initSwiper() {
    if (!window.Swiper || !$('#featured').length) return;
    if (window.__featuredSwiper) {
      try {
        window.__featuredSwiper.destroy(true, true);
      } catch (e) {}
      window.__featuredSwiper = null;
    }
    window.__featuredSwiper = new Swiper('#featured', {
      loop: true,
      autoplay: { delay: 5500, disableOnInteraction: false },
      pagination: { el: '#featured .swiper-pagination', clickable: true },
      navigation: {
        nextEl: '#featured .swiper-button-next',
        prevEl: '#featured .swiper-button-prev'
      }
    });
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

  // Keep filter dropdown open when clicking inside (noclose)
  $(document).on('click', '#site-filters .noclose', function (e) {
    e.stopPropagation();
  });

  // Filter dropdowns (works with BS4 data-toggle or as fallback)
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
      $btn.text($.trim(checked.first().next('label').text()));
    }
  }

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
      $form.trigger('submit');
    }, delay || 280);
  }

  $(document).on('change', '#site-filters input[type=radio], #site-filters input[type=checkbox]', function () {
    var $input = $(this);
    // Type switches between Movies / TV pages
    if ($input.attr('name') === 'type[]') {
      var href = $input.data('href');
      if (href) {
        var view = $('#view-mode-input').val();
        location.href = href + (view && view !== 'grid' ? ('?view=' + encodeURIComponent(view)) : '');
      }
      return;
    }
    updateFilterLabel($input);
    // Close dropdown after radio pick; keep open for multi genre checkboxes
    if ($input.attr('type') === 'radio') {
      $('#site-filters .dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
      submitFiltersSoon(120);
    } else {
      submitFiltersSoon(650);
    }
  });

  // Discover view switcher — update class + persist via query
  $(document).on('click', '.btn-view-switcher', function () {
    var view = $(this).data('view');
    $('.btn-view-switcher').removeClass('active');
    $(this).addClass('active');
    $('#view-mode-input').val(view);
    $('#discover-results').removeClass('view-grid view-list').addClass('view-' + view);
    var url = new URL(location.href);
    url.searchParams.set('view', view);
    history.replaceState(null, '', url.toString());
  });

  // Watch page player (official YouTube trailers)
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
  }

  $(document).on('click', '#btn-play, #btn-trailer', function (e) {
    e.preventDefault();
    playTrailer();
  });

  $(document).on('click', '#btn-light', function () {
    $('#movie-player').toggleClass('light-off');
  });

  $(document).on('click', '#btn-share', function () {
    var url = location.href;
    if (navigator.clipboard) navigator.clipboard.writeText(url);
    else prompt('Copy link', url);
  });

  $(document).on('change', '#serverGroupSwitch', function () {
    var backup = this.checked;
    $('.server-group-container').hide();
    $('.server-group-container[data-group="' + (backup ? '2' : '1') + '"]').show();
  });

  $(document).on('click', '.movie-cloud', function () {
    $(this).addClass('active').siblings().removeClass('active');
    // Visual server switch — playback uses official trailer
    playTrailer();
  });

  // Watch: season dropdown + comment scroll + episode play
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
  $(document).on('click', '.movie-manager.comment, .movie-manager[data-scroll]', function (e) {
    var sel = $(this).data('scroll') || '#comment';
    var el = document.querySelector(sel);
    if (el) {
      e.preventDefault();
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
  $(document).on('click', '#btn-ep-play, .horizontal-episode-play-btn', function (e) {
    e.preventDefault();
    playTrailer();
  });

  // Favorites page render
  function renderFavoritesPage() {
    var $grid = $('#favorites-grid');
    if (!$grid.length) return;
    var typeFilter = $('[data-tabs][data-id="fav-type"] .tab.active').data('name') || 'all';
    var map = favStore();
    var items = Object.keys(map).map(function (k) { return map[k]; });
    items.sort(function (a, b) {
      return new Date(b.saved_at || 0) - new Date(a.saved_at || 0);
    });
    if (typeFilter !== 'all') {
      items = items.filter(function (i) { return (i.type || i.mediaType) === typeFilter; });
    }
    if (!items.length) {
      $grid.empty();
      $('#favorites-empty').removeAttr('hidden');
      return;
    }
    $('#favorites-empty').attr('hidden', true);
    var html = items.map(function (it) {
      var type = it.type || it.mediaType || 'movie';
      var title = it.title || it.name || 'Untitled';
      var year = it.year || '';
      var poster = it.poster || ((window.APP && APP.imgBase) + '/w600_and_h900_bestv2' + (it.poster_path || ''));
      var slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'item';
      var href = (window.APP && APP.baseUrl ? APP.baseUrl : '') + '/' + (type === 'tv' ? 'tv' : 'movie') + '/' + slug + '/' + it.id;
      return '<div class="movie-item"><div class="inner">' +
        '<a class="item-poster" href="' + href + '"><div><img src="' + poster + '" alt="' + $('<div>').text(title).html() + '"></div></a>' +
        '<div class="meta"><div class="meta-bg"><span class="dot">' + (type === 'tv' ? 'TV' : 'Movie') + '</span>' +
        (year ? '<span class="dot">' + year + '</span>' : '') + '</div>' +
        '<a class="name" href="' + href + '">' + $('<div>').text(title).html() + '</a></div>' +
        '<button type="button" class="btn btn-sm btn-danger remove-fav mt-2" data-id="' + it.id + '">Remove</button>' +
        '</div></div>';
    }).join('');
    $grid.html(html);
  }

  $(document).on('click', '[data-tabs][data-id="fav-type"] .tab', function () {
    setTimeout(renderFavoritesPage, 0);
  });

  $(document).on('click', '#clearAllFavorites', function () {
    if (confirm('Clear all favorites?')) {
      localStorage.removeItem(FAV_KEY);
      updateFavCounter();
      renderFavoritesPage();
    }
  });

  $(document).on('click', '.remove-fav', function () {
    var map = favStore();
    delete map[String($(this).data('id'))];
    saveFavs(map);
    renderFavoritesPage();
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

  function refreshLazyMedia() {
    if (window.lazySizes && lazySizes.loader && typeof lazySizes.loader.checkElems === 'function') {
      lazySizes.loader.checkElems();
    }
    $('[data-bgset]').each(function () {
      if (!this.style.backgroundImage) {
        this.style.backgroundImage = 'url(' + this.getAttribute('data-bgset') + ')';
      }
      $(this).addClass('lazyloaded');
    });
    $('img.lazyload[data-src]').each(function () {
      if (!this.getAttribute('src') || this.getAttribute('src').indexOf('data:image') === 0) {
        this.src = this.getAttribute('data-src');
      }
      $(this).addClass('lazyloaded');
    });
  }

  function afterSoftNav() {
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    if ($.fn.tooltipster) {
      try {
        $('.tooltipstered').tooltipster('destroy');
      } catch (e) {}
    }
    initMediaTips();
    renderFavoritesPage();
    $('.section-top10 .top10-items').each(function () {
      syncTop10Nav($(this));
    });
    refreshLazyMedia();
    prefetchBottomNavTargets();
  }

  function applySoftNavHtml(html, url, push) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var nextWrapper = doc.querySelector('.wrapper');
    var curWrapper = document.querySelector('.wrapper');
    if (!nextWrapper || !curWrapper) {
      window.location.href = url;
      return;
    }
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
    if (push) {
      history.pushState({ softnav: 1 }, '', url);
    }
    window.scrollTo(0, 0);
    afterSoftNav();
  }

  function softNavigate(url, push) {
    url = absoluteUrl(url);
    if (softNavBusy) return;
    softNavBusy = true;

    function done(html) {
      softNavBusy = false;
      if (!html) {
        window.location.href = url;
        return;
      }
      applySoftNavHtml(html, url, push);
    }

    if (pageCache[url]) {
      done(pageCache[url]);
      // Refresh cache in background
      delete pageCache[url];
      prefetchPage(url);
      return;
    }

    prefetchPage(url).then(done);
  }

  $(document).on('pointerdown', '.bottom-nav-item[href]', function () {
    prefetchPage(this.href);
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
    closeBrowseSheet();
    if (samePage(href, window.location.href)) return;

    $('.bottom-nav-item').removeClass('active').removeAttr('aria-current');
    $(this).addClass('active').attr('aria-current', 'page');

    softNavigate(href, true);
  });

  function openBrowseSheet() {
    var $sheet = $('#browse-sheet');
    if (!$sheet.length) return;
    $sheet.removeAttr('hidden').addClass('is-open');
    $('body').addClass('browse-open');
    $('.bottom-nav-browse').addClass('is-open').attr('aria-expanded', 'true');
  }

  function closeBrowseSheet() {
    var $sheet = $('#browse-sheet');
    if (!$sheet.length) return;
    $sheet.removeClass('is-open').attr('hidden', true);
    $('body').removeClass('browse-open');
    $('.bottom-nav-browse').removeClass('is-open').attr('aria-expanded', 'false');
  }

  $(document).on('click', '.bottom-nav-browse', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if ($('#browse-sheet').hasClass('is-open')) closeBrowseSheet();
    else openBrowseSheet();
  });

  $(document).on('click', '.browse-sheet-backdrop, .browse-sheet-close', function (e) {
    e.preventDefault();
    closeBrowseSheet();
  });

  $(document).on('click', '.browse-sheet-panel a', function () {
    closeBrowseSheet();
  });

  $(document).on('click', '[data-browse-mock]', function (e) {
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

  $(document).on('click', '[data-browse-ads]', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var on = !$btn.hasClass('is-on');
    $btn.toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false').text(on ? 'ON' : 'OFF');
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeBrowseSheet();
  });

  window.addEventListener('popstate', function () {
    softNavigate(window.location.href, false);
  });

  $(function () {
    updateFavCounter();
    initSwiper();
    initRecentlyUpdated();
    initMediaTips();
    renderFavoritesPage();
    if (window.lazysizes) {
      document.addEventListener('lazybeforeunveil', function (e) {
        var bg = e.target.getAttribute('data-bgset');
        if (bg) e.target.style.backgroundImage = 'url(' + bg + ')';
      });
    } else {
      $('[data-bgset]').each(function () {
        this.style.backgroundImage = 'url(' + this.getAttribute('data-bgset') + ')';
        $(this).addClass('lazyloaded');
      });
      $('img.lazyload[data-src]').each(function () {
        this.src = this.getAttribute('data-src');
        $(this).addClass('lazyloaded');
      });
    }
    try {
      history.replaceState({ softnav: 1 }, '', window.location.href);
    } catch (e) {}
    // Warm Home / Movies / TV so the first tap is instant
    if ('requestIdleCallback' in window) {
      requestIdleCallback(function () {
        prefetchBottomNavTargets();
      }, { timeout: 800 });
    } else {
      setTimeout(prefetchBottomNavTargets, 120);
    }
  });
})(jQuery);
