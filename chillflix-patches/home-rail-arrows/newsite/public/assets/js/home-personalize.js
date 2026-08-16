/**
 * Homepage: Because you watched + Only on [network]
 * Sources titles from Continue Watching (cf_continue_v1 / ChillflixContinue).
 */
(function () {
  "use strict";

  var BECAUSE_KEY = "vf_because_source_v1";
  var NETWORK_KEY = "vf_only_on_network_v1";

  var NETWORKS = [
    { id: "8", name: "Netflix", mark: "N", color: "#e50914" },
    { id: "337", name: "Disney+", mark: "D+", color: "#113ccf" },
    { id: "1899", name: "Max", mark: "M", color: "#002be7" },
    { id: "10", name: "Prime Video", mark: "Pv", color: "#00a8e1" },
    { id: "15", name: "Hulu", mark: "H", color: "#1ce783" },
    { id: "2", name: "Apple TV+", mark: "tv", color: "#a2aaad" },
    { id: "531", name: "Paramount+", mark: "P+", color: "#0064ff" },
  ];

  function base() {
    return (window.APP && APP.baseUrl) || (window.CF_BASE) || "";
  }

  function imgBase() {
    return (window.APP && APP.imgBase) || "https://image.tmdb.org/t/p";
  }

  function esc(s) {
    return $("<div>")
      .text(s == null ? "" : String(s))
      .html();
  }

  function slugify(title) {
    return String(title || "title")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/(^-|-$)/g, "") || "title";
  }

  function mediaHref(type, id, title) {
    var prefix = type === "tv" ? "tv" : "movie";
    return base() + "/" + prefix + "/" + slugify(title) + "/" + id;
  }

  function posterUrl(path) {
    if (!path) {
      return (
        "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
      );
    }
    if (String(path).indexOf("http") === 0) return path;
    return imgBase() + "/w600_and_h900_bestv2" + path;
  }

  function continueList() {
    try {
      if (window.ChillflixContinue && typeof ChillflixContinue.list === "function") {
        return ChillflixContinue.list() || [];
      }
    } catch (e) {}
    return [];
  }

  function cardHtml(item, type) {
    var id = item.id;
    var title = item.title || item.name || "Untitled";
    var year = "";
    var date = item.release_date || item.first_air_date || "";
    if (date) year = String(date).slice(0, 4);
    var label = type === "tv" ? "TV" : "Movie";
    var rating =
      item.vote_average != null ? Math.round(Number(item.vote_average) * 10) / 10 : null;
    var href = mediaHref(type, id, title);
    var poster = posterUrl(item.poster_path);
    var ratingHtml =
      rating != null && rating > 0
        ? '<span class="card-rating">★ ' + esc(String(rating)) + "</span>"
        : "";
    return (
      '<div class="movie-item media-card" data-id="' +
      esc(id) +
      '" data-type="' +
      esc(type) +
      '">' +
      '<div class="inner">' +
      '<a class="item-poster" href="' +
      esc(href) +
      '" data-tip="' +
      esc(id) +
      '" data-media-type="' +
      esc(type) +
      '" aria-label="' +
      esc(title) +
      '">' +
      '<div class="poster-media">' +
      '<img class="lazyload" data-src="' +
      esc(poster) +
      '" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'300\' height=\'450\'%3E%3Crect width=\'100%25\' height=\'100%25\' fill=\'%231a1c23\'/%3E%3C/svg%3E" alt="' +
      esc(title) +
      '" width="300" height="450" loading="lazy" decoding="async">' +
      "</div>" +
      '<div class="card-caption">' +
      '<span class="card-kicker"><span>' +
      esc(label) +
      "</span>" +
      (year ? "<span>" + esc(year) + "</span>" : "") +
      ratingHtml +
      "</span>" +
      '<span class="card-title">' +
      esc(title) +
      "</span>" +
      "</div></a>" +
      '<div class="meta"><div class="meta-bg"><span class="dot">' +
      esc(label) +
      "</span>" +
      (year ? '<span class="dot">' + esc(year) + "</span>" : "") +
      '</div><a class="name" href="' +
      esc(href) +
      '">' +
      esc(title) +
      "</a></div></div></div>"
    );
  }

  function skeletonCards(n) {
    var html = "";
    for (var i = 0; i < n; i++) {
      html +=
        '<div class="movie-item media-card home-personalize-skel" aria-hidden="true"><div class="inner"><div class="item-poster"><div class="poster-media"></div></div></div></div>';
    }
    return html;
  }

  function closeMenus(except) {
    $(".home-personalize-menu").each(function () {
      if (except && this === except) return;
      $(this).attr("hidden", true);
      $(this).closest(".home-personalize-picker").removeClass("is-open");
      $(this).closest(".section").removeClass("is-picker-open");
    });
    $(".home-personalize-trigger").each(function () {
      var $m = $(this).closest(".home-personalize-picker").find(".home-personalize-menu");
      if (except && $m[0] === except) return;
      $(this).attr("aria-expanded", "false");
    });
  }

  function bindPicker($trigger, $menu, onPick) {
    $trigger.off("click.homePersonalizePicker").on("click.homePersonalizePicker", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var currentlyOpen = $trigger.attr("aria-expanded") === "true";
      closeMenus();
      if (!currentlyOpen) {
        $menu.prop("hidden", false);
        $menu.removeAttr("hidden");
        $trigger.attr("aria-expanded", "true");
        $trigger.closest(".home-personalize-picker").addClass("is-open");
        $trigger.closest(".section").addClass("is-picker-open");
      }
    });
    $menu.off("click.homePersonalizeOpt").on("click.homePersonalizeOpt", "[data-pick]", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var $btn = $(this);
      onPick({
        id: $btn.attr("data-pick"),
        type: $btn.attr("data-type") || "",
        title: $btn.attr("data-title") || $btn.text(),
      });
      closeMenus();
    });
  }

  function renderItems($track, items, type) {
    if (!items || !items.length) {
      $track.html(
        '<div class="home-personalize-empty text-muted">No titles found right now.</div>'
      );
      return;
    }
    $track.html(
      items
        .map(function (item) {
          return cardHtml(item, type);
        })
        .join("")
    );
    try {
      if (window.lazySizes) lazySizes.autoSizer.checkElems();
    } catch (e) {}
    // Rails fill async after first syncAllRails — refresh prev/next arrows.
    function syncRailsSoon() {
      try {
        if (typeof window.cfSyncHomeRails === "function") window.cfSyncHomeRails();
      } catch (eSync) {}
    }
    syncRailsSoon();
    requestAnimationFrame(syncRailsSoon);
    setTimeout(syncRailsSoon, 120);
    setTimeout(syncRailsSoon, 400);
  }

  function fetchJson(url) {
    return $.ajax({
      url: url,
      dataType: "json",
      cache: true,
      timeout: 20000,
    });
  }

  /* -------- Because you watched -------- */
  function initBecause() {
    var $section = $("#because-you-watched");
    if (!$section.length) return;

    var sources = continueList()
      .filter(function (row) {
        return row && row.id && (row.type === "movie" || row.type === "tv");
      })
      .slice(0, 12);

    if (!sources.length) {
      $section.attr("hidden", true).addClass("d-none");
      return;
    }

    $section.removeAttr("hidden").removeClass("d-none");

    var $label = $("#because-title-label");
    var $menu = $("#because-title-menu");
    var $trigger = $("#because-title-trigger");
    var $items = $("#because-you-watched-items");

    var saved = "";
    try {
      saved = localStorage.getItem(BECAUSE_KEY) || "";
    } catch (e) {}

    var selected =
      sources.find(function (s) {
        return String(s.id) === String(saved);
      }) || sources[0];

    function setSelected(src) {
      selected = src;
      try {
        localStorage.setItem(BECAUSE_KEY, String(src.id));
      } catch (e) {}
      $label.text(src.title || "Untitled");
      $menu.html(
        sources
          .map(function (s) {
            var active = String(s.id) === String(selected.id) ? " is-active" : "";
            var kind = s.type === "tv" ? "TV Show" : "Movie";
            return (
              '<button type="button" class="home-personalize-option' +
              active +
              '" role="option" data-pick="' +
              esc(s.id) +
              '" data-type="' +
              esc(s.type) +
              '" data-title="' +
              esc(s.title || "") +
              '"><span class="home-personalize-option-title">' +
              esc(s.title || "Untitled") +
              '</span><span class="home-personalize-option-meta">' +
              esc(kind) +
              "</span></button>"
            );
          })
          .join("")
      );
      loadBecause();
    }

    function loadBecause() {
      $items.html(skeletonCards(8));
      var type = selected.type === "tv" ? "tv" : "movie";
      fetchJson(
        base() +
          "/api/home/because?type=" +
          encodeURIComponent(type) +
          "&id=" +
          encodeURIComponent(selected.id)
      )
        .done(function (data) {
          renderItems($items, (data && data.items) || [], type);
        })
        .fail(function () {
          $items.html(
            '<div class="home-personalize-empty text-muted">Couldn’t load suggestions.</div>'
          );
        });
    }

    bindPicker($trigger, $menu, function (pick) {
      var src = sources.find(function (s) {
        return String(s.id) === String(pick.id);
      });
      if (src) setSelected(src);
    });

    setSelected(selected);
  }

  /* -------- Only on network -------- */
  function initNetwork() {
    var $section = $("#only-on-network");
    if (!$section.length) return;

    var $label = $("#network-label");
    var $mark = $("#network-mark");
    var $menu = $("#network-menu");
    var $trigger = $("#network-trigger");
    var $items = $("#only-on-network-items");

    var saved = "8";
    try {
      saved = localStorage.getItem(NETWORK_KEY) || "8";
    } catch (e) {}

    var selected =
      NETWORKS.find(function (n) {
        return n.id === saved;
      }) || NETWORKS[0];

    function paintNetwork(n, opts) {
      opts = opts || {};
      selected = n;
      try {
        localStorage.setItem(NETWORK_KEY, n.id);
      } catch (e) {}
      $label.text(n.name);
      var $dot = $("#network-dot");
      if ($dot.length) $dot.css("background", n.color || "#e50914");
      var $legacyMark = $("#network-mark");
      if ($legacyMark.length) {
        $legacyMark.text(n.mark || n.name.charAt(0));
        $legacyMark.css("background", n.color || "#333");
      }
      $trigger.css("--net-color", n.color || "#e50914");
      $trigger.attr("aria-label", "Only on " + n.name + ". Change network");
      $menu.html(
        NETWORKS.map(function (row) {
          var active = row.id === selected.id ? " is-active" : "";
          var selectedAttr = row.id === selected.id ? "true" : "false";
          return (
            '<button type="button" class="home-personalize-option' +
            active +
            '" role="option" aria-selected="' +
            selectedAttr +
            '" data-pick="' +
            esc(row.id) +
            '" data-title="' +
            esc(row.name) +
            '" style="--opt-color:' +
            esc(row.color) +
            '">' +
            '<span class="home-personalize-option-swatch" aria-hidden="true"></span>' +
            '<span class="home-personalize-option-title">' +
            esc(row.name) +
            '</span><span class="home-personalize-option-check" aria-hidden="true"><i class="uil uil-check"></i></span></button>'
          );
        }).join("")
      );
      if (!opts.skipLoad) loadNetwork();
    }

    function loadNetwork() {
      $items.addClass("is-swapping");
      $items.html(skeletonCards(8));
      fetchJson(
        base() +
          "/api/home/network?provider=" +
          encodeURIComponent(selected.id) +
          "&type=movie"
      )
        .done(function (data) {
          renderItems($items, (data && data.items) || [], "movie");
          requestAnimationFrame(function () {
            $items.removeClass("is-swapping");
          });
        })
        .fail(function () {
          $items.removeClass("is-swapping");
          $items.html(
            '<div class="home-personalize-empty text-muted">Couldn’t load this network.</div>'
          );
        });
    }

    bindPicker($trigger, $menu, function (pick) {
      var n = NETWORKS.find(function (x) {
        return x.id === pick.id;
      });
      if (n) paintNetwork(n);
    });

    paintNetwork(selected);
  }

  function boot() {
    if (!$("body").hasClass("home")) return;
    initBecause();
    initNetwork();
    $(document).off("click.homePersonalize").on("click.homePersonalize", function (e) {
      if ($(e.target).closest(".home-personalize-picker").length) return;
      closeMenus();
    });
    $(document).off("keydown.homePersonalize").on("keydown.homePersonalize", function (e) {
      if (e.key === "Escape") closeMenus();
    });
  }

  function scheduleBoot(delay) {
    setTimeout(boot, delay == null ? 60 : delay);
  }

  $(function () {
    // Continue Watching paints first; wait a tick so ChillflixContinue.list is ready.
    scheduleBoot(60);
    $(window).on("cf:continue-updated", function () {
      initBecause();
    });
  });

  // Soft-nav swaps .wrapper HTML but does not re-run deferred scripts — re-boot rails.
  window.addEventListener("cf:softnav", function () {
    setTimeout(function () {
      try { if (typeof window.cfInitMediaTips === "function") window.cfInitMediaTips(); } catch (eTip) {}
    }, 80);
    scheduleBoot(40);
    // Second pass after Continue Watching finishes soft-nav paint (Because you watched).
    scheduleBoot(250);
  });
  window.addEventListener("pageshow", function () {
    scheduleBoot(40);
  });
})();

  // Re-bind hover tips when personalized rails inject new posters
  (function observePersonalizeTips() {
    var scheduled = false;
    function kick() {
      if (scheduled) return;
      scheduled = true;
      setTimeout(function () {
        scheduled = false;
        try {
          if (typeof window.cfInitMediaTips === "function") window.cfInitMediaTips();
        } catch (e) {}
        try {
          if (typeof window.cfSyncHomeRails === "function") window.cfSyncHomeRails();
        } catch (eRail) {}
      }, 60);
    }
    function watch(sel) {
      var el = document.querySelector(sel);
      if (!el || typeof MutationObserver === "undefined") return;
      new MutationObserver(kick).observe(el, { childList: true, subtree: true });
    }
    function arm() {
      watch("#only-on-network-items");
      watch("#because-you-watched-items");
      watch("#continue-watching .media-rail-items");
    }
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", arm);
    } else {
      arm();
    }
    window.addEventListener("cf:softnav", function () {
      setTimeout(arm, 50);
    });
  })();

