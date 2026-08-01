/**
 * Continue Watching rail + Watch Party browse UI (lean client).
 */
(function () {
  "use strict";

  var CW_KEY = "cf_continue_v1";
  var base = function () {
    return (window.APP && APP.baseUrl) || "";
  };

  function esc(s) {
    return $("<div>").text(s == null ? "" : String(s)).html();
  }

  function readCookieMirror() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)cf_continue_v1=([^;]+)/);
      if (!m) return [];
      var arr = JSON.parse(decodeURIComponent(m[1]));
      return Array.isArray(arr) ? arr.filter(Boolean) : [];
    } catch (e) {
      return [];
    }
  }

  function readContinue() {
    try {
      var raw = localStorage.getItem(CW_KEY);
      var map = raw ? JSON.parse(raw) : {};
      if (!map || typeof map !== "object") map = {};
      var items = Object.keys(map)
        .map(function (k) {
          var row = map[k];
          if (!row) return null;
          row._key = k;
          return row;
        })
        .filter(Boolean);
      // Recover from cookie mirror if LS empty (private mode quirks / wiped LS)
      if (!items.length) {
        var cookieItems = readCookieMirror();
        if (cookieItems.length) {
          cookieItems.forEach(function (row) {
            if (!row || !row.id) return;
            var k =
              (row.type === "tv" ? "tv" : "movie") +
              ":" +
              row.id +
              (row.type === "tv"
                ? ":s" + (row.season || 1) + "e" + (row.episode || 1)
                : "");
            map[k] = row;
            row._key = k;
            items.push(row);
          });
          try {
            localStorage.setItem(CW_KEY, JSON.stringify(map));
          } catch (e2) {}
        }
      }
      return items.sort(function (a, b) {
        return (b.updated || 0) - (a.updated || 0);
      });
    } catch (e) {
      return readCookieMirror();
    }
  }

  function watchHref(row) {
    var url = row.url || "";
    if (!url && row.id) {
      var slug = String(row.title || "title")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/(^-|-$)/g, "");
      url =
        base() +
        "/" +
        (row.type === "tv" ? "tv" : "movie") +
        "/" +
        (slug || "title") +
        "/" +
        row.id;
      if (row.type === "tv") {
        url += "?s=" + (row.season || 1) + "&e=" + (row.episode || 1);
      }
    }
    var sep = url.indexOf("?") >= 0 ? "&" : "?";
    var t = Math.floor(Number(row.t) || 0);
    if (t > 20) url += sep + "t=" + t + "&play=1";
    else if (url.indexOf("play=") < 0) url += sep + "play=1";
    return url;
  }

  function pct(row) {
    var t = Number(row.t) || 0;
    var d = Number(row.d) || 0;
    if (d <= 0) return t > 0 ? 1 : 0;
    return Math.max(1, Math.min(99, Math.round((t / d) * 100)));
  }

  function remainLabel(row) {
    var t = Number(row.t) || 0;
    var d = Number(row.d) || 0;
    if (d > 60 && t >= 0 && t < d) {
      var left = Math.max(1, Math.round((d - t) / 60));
      if (left >= 60) {
        var h = Math.floor(left / 60);
        var m = left % 60;
        return h + "h" + (m ? " " + m + "m" : "") + " left";
      }
      return left + "m left";
    }
    return "Resume watching";
  }

  function artUrl(row) {
    var src = row.backdrop || row.poster || "";
    if (src.indexOf("/t/p/") >= 0) {
      src = src
        .replace("/w1280/", "/w780/")
        .replace("/w600_and_h900_bestv2/", "/w780/")
        .replace("/w500/", "/w780/")
        .replace("/original/", "/w780/");
    }
    return (
      src ||
      "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='320' height='180'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E"
    );
  }

  function renderContinueRail() {
    var $rail = $("#continue-watching");
    if (!$rail.length) return;
    var items = readContinue().slice(0, 24);
    var $track = $rail.find(".media-rail-items");
    if (!items.length) {
      $rail.attr("hidden", true).addClass("d-none").css("display", "none");
      $track.empty().removeClass("cw-track");
      return;
    }
    $rail.removeAttr("hidden").removeClass("d-none");
    $rail.css("display", "block");
    $track.addClass("cw-track");
    var html = items
      .map(function (row) {
        var href = watchHref(row);
        var progress = pct(row);
        var badge =
          row.type === "tv"
            ? "S" + (row.season || 1) + " · E" + (row.episode || 1)
            : row.year
              ? String(row.year)
              : "Movie";
        var sub = remainLabel(row);
        var img = artUrl(row);
        return (
          '<a class="cw-ep episode-card" href="' +
          esc(href) +
          '" data-id="' +
          esc(row.id) +
          '" data-type="' +
          esc(row.type) +
          '" aria-label="Resume ' +
          esc(row.title) +
          '">' +
          '<div class="episode-card-media">' +
          '<img src="' +
          esc(img) +
          '" alt="' +
          esc(row.title) +
          '" width="320" height="180" loading="lazy" decoding="async">' +
          '<span class="episode-card-badge">' +
          esc(badge) +
          "</span>" +
          '<span class="cw-ep-pct">' +
          progress +
          "%</span>" +
          '<div class="episode-card-meta">' +
          '<div class="episode-card-show">' +
          esc(row.title) +
          "</div>" +
          '<div class="episode-card-ep">' +
          esc(sub) +
          "</div>" +
          "</div></div>" +
          '<span class="cw-ep-line" aria-hidden="true"><i style="width:' +
          progress +
          '%"></i></span>' +
          "</a>"
        );
      })
      .join("");
    $track.html(html);
  }

  /* -------- Watch Party panel -------- */
  function peerId() {
    try {
      var id = sessionStorage.getItem("cf_party_peer");
      if (!id) {
        id = Math.random().toString(36).slice(2) + Date.now().toString(36);
        sessionStorage.setItem("cf_party_peer", id);
      }
      return id;
    } catch (e) {
      return "anon";
    }
  }

  function ensurePartyPanel() {
    if ($("#cf-party-panel").length) return $("#cf-party-panel");
    var $panel = $(
      '<div id="cf-party-panel" class="cf-party-panel" hidden>' +
        '<div class="cf-party-scrim" data-party-close></div>' +
        '<div class="cf-party-sheet" role="dialog" aria-label="Watch Party">' +
        '<header class="cf-party-head">' +
        '<div class="cf-party-copy">' +
        '<p class="cf-party-kicker"><span class="cf-party-dot" aria-hidden="true"></span>Watch together</p>' +
        "<h3>Watch Party</h3>" +
        "</div>" +
        '<button type="button" class="cf-party-x" data-party-close aria-label="Close">&times;</button>' +
        "</header>" +
        '<div class="cf-party-tabs" role="tablist" aria-label="Watch Party mode">' +
        '<button type="button" class="is-active" data-party-tab="create" role="tab" aria-selected="true">Create</button>' +
        '<button type="button" data-party-tab="join" role="tab" aria-selected="false">Join</button>' +
        "</div>" +
        '<section class="cf-party-pane is-active" data-party-pane="create">' +
        '<p class="cf-party-hint">Pick any movie or show, share the code, and watch in sync.</p>' +
        '<label class="cf-party-label" for="cf-party-q">Search title</label>' +
        '<input type="search" id="cf-party-q" class="cf-party-input" placeholder="Search movies & TV…" autocomplete="off">' +
        '<div id="cf-party-results" class="cf-party-results"></div>' +
        '<div id="cf-party-created" class="cf-party-created" hidden></div>' +
        "</section>" +
        '<section class="cf-party-pane" data-party-pane="join">' +
        '<p class="cf-party-hint">Enter a 4-digit party code from your friend.</p>' +
        '<label class="cf-party-label" for="cf-party-code">Party code</label>' +
        '<input type="text" id="cf-party-code" class="cf-party-input" maxlength="6" inputmode="numeric" placeholder="1234">' +
        '<button type="button" class="cf-party-btn is-primary" id="cf-party-join">Join party</button>' +
        '<p id="cf-party-join-err" class="cf-party-err" hidden></p>' +
        "</section>" +
        "</div></div>"
    );
    $("body").append($panel);
    return $panel;
  }

  function openPartyPanel(tab) {
    var $p = ensurePartyPanel();
    $p.removeAttr("hidden");
    $("body").addClass("cf-party-open");
    if (tab) switchPartyTab(tab);
  }

  function closePartyPanel() {
    $("#cf-party-panel").attr("hidden", true);
    $("body").removeClass("cf-party-open");
  }

  function switchPartyTab(tab) {
    var $p = $("#cf-party-panel");
    $p.find("[data-party-tab]").removeClass("is-active").attr("aria-selected", "false");
    $p.find('[data-party-tab="' + tab + '"]').addClass("is-active").attr("aria-selected", "true");
    $p.find("[data-party-pane]").removeClass("is-active");
    $p.find('[data-party-pane="' + tab + '"]').addClass("is-active");
  }

  var searchTimer = null;
  function searchTitles(q) {
    var $box = $("#cf-party-results");
    if (!q || q.length < 2) {
      $box.empty();
      return;
    }
    $box.html('<div class="cf-party-empty">Searching…</div>');
    $.getJSON(base() + "/api/search", { q: q })
      .done(function (data) {
        var rows = (data.results || []).slice(0, 12);
        if (!rows.length) {
          $box.html('<div class="cf-party-empty">No titles found</div>');
          return;
        }
        $box.html(
          rows
            .map(function (r) {
              var type = r.type || r.media_type || "movie";
              if (type !== "movie" && type !== "tv") return "";
              var title = r.title || r.name || "Untitled";
              var year = r.year || "";
              var poster = r.poster || "";
              return (
                '<button type="button" class="cf-party-result" data-party-pick' +
                ' data-id="' +
                esc(r.id) +
                '" data-type="' +
                esc(type) +
                '" data-title="' +
                esc(title) +
                '" data-year="' +
                esc(year) +
                '" data-poster="' +
                esc(poster) +
                '">' +
                (poster
                  ? '<img src="' + esc(poster) + '" alt="">'
                  : '<span class="cf-party-ph"></span>') +
                "<span><strong>" +
                esc(title) +
                "</strong><em>" +
                esc(type.toUpperCase() + (year ? " · " + year : "")) +
                "</em></span></button>"
              );
            })
            .join("")
        );
      })
      .fail(function () {
        $box.html('<div class="cf-party-empty">Search failed</div>');
      });
  }

  function createPartyFor(item) {
    var hostId = peerId();
    var slug = String(item.title || "title")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/(^-|-$)/g, "");
    var url =
      base() +
      "/" +
      (item.type === "tv" ? "tv" : "movie") +
      "/" +
      (slug || "title") +
      "/" +
      item.id;
    return $.ajax({
      url: base() + "/api/party",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({
        hostId: hostId,
        content: {
          type: item.type,
          id: item.id,
          title: item.title,
          poster: item.poster,
          year: item.year,
          url: url,
        },
      }),
    }).then(function (data) {
      if (!data || !data.ok) throw new Error((data && data.error) || "Create failed");
      try {
        sessionStorage.setItem("cf_party_host_" + data.room.code, hostId);
      } catch (e) {}
      var watch =
        url +
        (url.indexOf("?") >= 0 ? "&" : "?") +
        "play=1&party=" +
        encodeURIComponent(data.room.code) +
        "&host=1";
      var share = watch.replace("&host=1", "");
      $("#cf-party-created")
        .prop("hidden", false)
        .html(
          '<div class="cf-party-code-wrap"><span>Code</span><strong id="cf-party-code-val">' +
            esc(data.room.code) +
            "</strong></div>" +
            '<p class="cf-party-hint">Share this code or link. You are the host.</p>' +
            '<div class="cf-party-actions">' +
            '<button type="button" class="cf-party-btn" id="cf-party-copy" data-link="' +
            esc(share) +
            '">Copy link</button>' +
            '<a class="cf-party-btn is-primary" href="' +
            esc(watch) +
            '">Start watching</a>' +
            "</div>"
        );
      return data;
    });
  }

  function joinParty(code) {
    code = String(code || "")
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "");
    var $err = $("#cf-party-join-err").prop("hidden", true);
    if (code.length < 4) {
      $err.text("Enter a valid code").prop("hidden", false);
      return;
    }
    $.ajax({
      url: base() + "/api/party/" + encodeURIComponent(code) + "/join",
      method: "POST",
      contentType: "application/json",
      dataType: "json",
      data: JSON.stringify({ peerId: peerId() }),
    })
      .done(function (data) {
        if (!data || !data.ok) {
          $err.text((data && data.error) || "Could not join").prop("hidden", false);
          return;
        }
        var c = data.room.content || {};
        var url = c.url;
        if (!url && c.id) {
          var slug = String(c.title || "title")
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/(^-|-$)/g, "");
          url =
            base() +
            "/" +
            (c.type === "tv" ? "tv" : "movie") +
            "/" +
            (slug || "title") +
            "/" +
            c.id;
        }
        if (!url) {
          $err.text("Host has not picked a title yet").prop("hidden", false);
          return;
        }
        var dest =
          url + (url.indexOf("?") >= 0 ? "&" : "?") + "play=1&party=" + encodeURIComponent(code);
        location.href = dest;
      })
      .fail(function () {
        $err.text("Could not join party").prop("hidden", false);
      });
  }

  // Wire events
  $(document).on("click", '[data-browse-open="watch-party"], [data-browse-mock="watch-party"]', function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    closeBrowseSafe();
    openPartyPanel("create");
  });

  $(document).on("click", '[data-browse-mock="history"], [data-browse-open="history"]', function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    closeBrowseSafe();
    // jump home continue rail if present, else soft toast list
    if ($("#continue-watching").length) {
      location.href = base() + "/home#continue-watching";
      setTimeout(renderContinueRail, 50);
    } else {
      openPartyPanel("create");
    }
  });

  function closeBrowseSafe() {
    try {
      if (typeof window.closeBrowseSheet === "function") window.closeBrowseSheet();
      else {
        $("#browse-sheet").removeClass("is-open");
        $("body").removeClass("browse-open");
      }
    } catch (e) {}
  }

  $(document).on("click", "[data-party-close]", function () {
    closePartyPanel();
  });
  $(document).on("click", "[data-party-tab]", function () {
    switchPartyTab($(this).data("party-tab"));
  });
  $(document).on("input", "#cf-party-q", function () {
    var q = $.trim(this.value);
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      searchTitles(q);
    }, 220);
  });
  $(document).on("click", "[data-party-pick]", function () {
    var $b = $(this);
    $("#cf-party-results").html('<div class="cf-party-empty">Creating party…</div>');
    createPartyFor({
      id: $b.data("id"),
      type: $b.data("type"),
      title: $b.data("title"),
      year: $b.data("year"),
      poster: $b.data("poster"),
    }).fail(function (xhr) {
      $("#cf-party-results").html(
        '<div class="cf-party-empty">' +
          esc((xhr.responseJSON && xhr.responseJSON.error) || "Could not create party") +
          "</div>"
      );
    });
  });
  $(document).on("click", "#cf-party-join", function () {
    joinParty($("#cf-party-code").val());
  });
  $(document).on("click", "#cf-party-copy", function () {
    var link = $(this).attr("data-link") || "";
    if (navigator.clipboard) navigator.clipboard.writeText(link);
    else prompt("Copy party link", link);
    $(this).text("Copied!");
  });

  // Also expose host create from watch page settings later if needed
  window.ChillflixContinue = { render: renderContinueRail, list: readContinue };
  window.ChillflixParty = { open: openPartyPanel, close: closePartyPanel };

  function bootContinue() {
    renderContinueRail();
    if (location.hash === "#continue-watching" && !$("#continue-watching").is("[hidden]")) {
      try {
        document.getElementById("continue-watching").scrollIntoView({ behavior: "smooth", block: "start" });
      } catch (e) {}
    }
  }

  $(function () {
    bootContinue();
    // Re-check shortly after load in case watch tab wrote storage just before nav
    setTimeout(bootContinue, 250);
    setTimeout(bootContinue, 1200);
  });
  // Soft-nav / bfcache / tab focus — re-render when homepage content comes back
  window.addEventListener("pageshow", bootContinue);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") bootContinue();
  });
  window.addEventListener("focus", bootContinue);
  window.addEventListener("storage", function (e) {
    if (!e.key || e.key === "cf_continue_v1") bootContinue();
  });
  // Custom hook used by app.js afterSoftNav
  window.addEventListener("cf:softnav", bootContinue);
})();
