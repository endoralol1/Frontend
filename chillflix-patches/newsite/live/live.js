/**
 * Chillflix Live TV — catalog filter + HLS playback.
 * Streams via main-site /api/iptv/live/{id}/index.m3u8 proxy.
 */
(function () {
  "use strict";

  var boot = window.LIVE_BOOT || { categories: [], channels: [], initialId: null };
  var state = {
    cat: "",
    q: "",
    activeId: boot.initialId || null,
    hls: null,
  };

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function shell() {
    return $(".live-shell");
  }

  function playUrl(id) {
    var s = shell();
    var base = (s && s.getAttribute("data-main-play")) || "/api/iptv/live/";
    return base.replace(/\/?$/, "/") + encodeURIComponent(id) + "/index.m3u8";
  }

  function filtered() {
    var q = state.q.trim().toLowerCase();
    return (boot.channels || []).filter(function (ch) {
      if (state.cat && ch.cat !== state.cat) return false;
      if (!q) return true;
      return (
        String(ch.name || "").toLowerCase().indexOf(q) >= 0 ||
        String(ch.group || "").toLowerCase().indexOf(q) >= 0 ||
        String(ch.epg || "").toLowerCase().indexOf(q) >= 0
      );
    });
  }

  function renderList() {
    var list = $("#live-list");
    if (!list) return;
    var rows = filtered().slice(0, 200);
    if (!rows.length) {
      list.innerHTML = '<div class="live-empty">No channels match your filters.</div>';
      return;
    }
    var html = rows
      .map(function (ch) {
        var mark = String(ch.name || "?").charAt(0).toUpperCase();
        var meta = ch.group || "";
        if (ch.epg) meta += (meta ? " · " : "") + ch.epg;
        var on = ch.id === state.activeId ? " is-active" : "";
        return (
          '<button type="button" class="live-ch' +
          on +
          '" role="option" data-id="' +
          esc(ch.id) +
          '" data-name="' +
          esc(ch.name) +
          '" data-group="' +
          esc(ch.group) +
          '" data-epg="' +
          esc(ch.epg || "") +
          '">' +
          '<span class="live-ch-mark" aria-hidden="true">' +
          esc(mark) +
          "</span>" +
          '<span class="live-ch-copy"><strong>' +
          esc(ch.name) +
          "</strong><small>" +
          esc(meta) +
          "</small></span>" +
          '<span class="live-ch-go" aria-hidden="true"><i class="uil uil-play"></i></span>' +
          "</button>"
        );
      })
      .join("");
    list.innerHTML = html;
    var count = $("#live-count");
    if (count) count.textContent = String(filtered().length);
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function setStatus(text) {
    var el = $("#live-status");
    if (el) el.textContent = text;
  }

  function setNow(name, meta) {
    var t = $("#live-now-title");
    var m = $("#live-now-meta");
    if (t) t.textContent = name || "No channel selected";
    if (m) m.textContent = meta || "Live";
  }

  function destroyHls() {
    if (state.hls) {
      try {
        state.hls.destroy();
      } catch (e) {}
      state.hls = null;
    }
  }

  function playChannel(id, name, epg, group) {
    var video = $("#live-video");
    var player = $("#live-player");
    if (!video || !id) return;

    state.activeId = id;
    setNow(name, epg || group || "Live");
    setStatus("Connecting…");
    player.classList.remove("is-idle");
    renderList();

    var url = playUrl(id);
    destroyHls();
    video.pause();
    video.removeAttribute("src");
    video.load();

    function onReady() {
      setStatus("Live");
      video.play().catch(function () {
        setStatus("Tap play");
      });
    }

    if (window.Hls && Hls.isSupported()) {
      var hls = new Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 30,
        maxBufferLength: 45,
      });
      state.hls = hls;
      hls.loadSource(url);
      hls.attachMedia(video);
      hls.on(Hls.Events.MANIFEST_PARSED, onReady);
      hls.on(Hls.Events.ERROR, function (_e, data) {
        if (!data || !data.fatal) return;
        setStatus("Reconnecting…");
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad();
        else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
        else {
          setStatus("Unavailable");
          destroyHls();
        }
      });
    } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
      video.src = url;
      video.addEventListener("loadedmetadata", onReady, { once: true });
    } else {
      setStatus("HLS unsupported");
    }
  }

  function bind() {
    var root = shell();
    if (!root || root.dataset.bound === "1") return;
    root.dataset.bound = "1";

    var qTimer = null;
    var q = $("#live-q");
    if (q) {
      q.addEventListener("input", function () {
        clearTimeout(qTimer);
        qTimer = setTimeout(function () {
          state.q = q.value || "";
          renderList();
        }, 120);
      });
    }

    var cats = $("#live-cat-bar");
    if (cats) {
      cats.addEventListener("click", function (e) {
        var btn = e.target.closest(".live-cat");
        if (!btn) return;
        state.cat = btn.getAttribute("data-cat") || "";
        cats.querySelectorAll(".live-cat").forEach(function (el) {
          var on = el === btn;
          el.classList.toggle("is-on", on);
          el.setAttribute("aria-selected", on ? "true" : "false");
        });
        renderList();
      });
    }

    var list = $("#live-list");
    if (list) {
      list.addEventListener("click", function (e) {
        var btn = e.target.closest(".live-ch");
        if (!btn) return;
        playChannel(
          btn.getAttribute("data-id"),
          btn.getAttribute("data-name"),
          btn.getAttribute("data-epg"),
          btn.getAttribute("data-group")
        );
      });
    }

    var video = $("#live-video");
    var player = $("#live-player");
    $("#live-playpause") &&
      $("#live-playpause").addEventListener("click", function () {
        if (!video) return;
        if (video.paused) video.play();
        else video.pause();
      });
    $("#live-mute") &&
      $("#live-mute").addEventListener("click", function () {
        if (!video) return;
        video.muted = !video.muted;
        this.querySelector("i").className = video.muted ? "uil uil-volume-mute" : "uil uil-volume";
      });
    $("#live-fs") &&
      $("#live-fs").addEventListener("click", function () {
        var el = player;
        if (!el) return;
        if (document.fullscreenElement) document.exitFullscreen();
        else if (el.requestFullscreen) el.requestFullscreen();
      });

    if (video && player) {
      var hideTimer = null;
      var showUi = function () {
        player.classList.add("show-ui");
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
          player.classList.remove("show-ui");
        }, 2200);
      };
      player.addEventListener("pointermove", showUi);
      player.addEventListener("click", showUi);
      video.addEventListener("play", function () {
        var i = $("#live-playpause i");
        if (i) i.className = "uil uil-pause";
      });
      video.addEventListener("pause", function () {
        var i = $("#live-playpause i");
        if (i) i.className = "uil uil-play";
      });
    }

    // Hydrate full catalog from static feed (preferred) or API
    var feed = root.getAttribute("data-feed") || root.getAttribute("data-api");
    if (feed) {
      fetch(feed, { credentials: "same-origin" })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.channels || !data.channels.length) return;
          boot.channels = data.channels;
          boot.categories = data.categories || boot.categories;
          var total = document.getElementById("live-count");
          if (total) total.textContent = String(data.totalAll || data.total || data.channels.length);
          var catStat = document.getElementById("live-cats");
          if (catStat) catStat.textContent = String((data.categories || []).length);
          renderCatBar();
          renderList();
        })
        .catch(function () {
          var api = root.getAttribute("data-api");
          if (!api || api === feed) return;
          fetch(api, { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (!data || !data.ok || !data.channels) return;
              boot.channels = data.channels;
              boot.categories = data.categories || boot.categories;
              renderCatBar();
              renderList();
            })
            .catch(function () {});
        });
    }
  }

  function renderCatBar() {
    var cats = $("#live-cat-bar");
    if (!cats || !(boot.categories || []).length) return;
    var active = state.cat || "";
    var html =
      '<button type="button" class="live-cat' +
      (active === "" ? " is-on" : "") +
      '" data-cat="" role="tab" aria-selected="' +
      (active === "" ? "true" : "false") +
      '">All</button>';
    html += boot.categories
      .map(function (cat) {
        var on = cat.id === active;
        return (
          '<button type="button" class="live-cat' +
          (on ? " is-on" : "") +
          '" data-cat="' +
          esc(cat.id) +
          '" role="tab" aria-selected="' +
          (on ? "true" : "false") +
          '">' +
          esc(cat.name) +
          "<em>" +
          esc(String(cat.count || 0)) +
          "</em></button>"
        );
      })
      .join("");
    cats.innerHTML = html;
  }

  function bootPage() {
    if (!$(".live-shell")) return;
    bind();
    renderList();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootPage);
  } else {
    bootPage();
  }
  window.addEventListener("cf:softnav", bootPage);
})();
