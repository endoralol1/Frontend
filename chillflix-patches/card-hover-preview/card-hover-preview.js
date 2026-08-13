(function () {
  "use strict";

  var MQ = "(min-width: 992px)";
  var SELECTOR = ".section-rail .media-rail-items .movie-item.media-card, .section-top10 .top10-items .movie-item.media-card";
  var cache = Object.create(null);
  var hoverTimer = null;
  var activeCard = null;
  var bound = false;

  function canPreview() {
    try { return window.matchMedia(MQ).matches; } catch (e) { return false; }
  }

  function baseUrl() {
    return (window.APP && APP.baseUrl) ? APP.baseUrl : (window.CF_BASE || "");
  }

  function pageZoom() {
    try {
      var z = window.getComputedStyle(document.documentElement).zoom;
      if (!z || z === "normal") return 1;
      var n = parseFloat(z);
      if (!isFinite(n) || n <= 0) return 1;
      if (n > 10) n = n / 100;
      return n;
    } catch (e) {
      return 1;
    }
  }

  function cardMeta(card) {
    var id = card.getAttribute("data-id") || "";
    var type = card.getAttribute("data-type") || "movie";
    var poster = card.querySelector(".item-poster");
    if (poster) {
      if (poster.getAttribute("data-tip")) id = poster.getAttribute("data-tip") || id;
      if (poster.getAttribute("data-media-type")) type = poster.getAttribute("data-media-type") || type;
    }
    return { id: String(id), type: type === "tv" ? "tv" : "movie", href: poster ? poster.getAttribute("href") : "#" };
  }

  function ensureLayers(card) {
    var media = card.querySelector(".poster-media");
    var host = card.querySelector(".item-poster") || media;
    var inner = card.querySelector(".inner") || host;
    if (!media || !host || !inner) return null;

    var video = media.querySelector("video.cf-card-video");
    if (!video) {
      video = document.createElement("video");
      video.className = "cf-card-video";
      video.muted = true;
      video.defaultMuted = true;
      video.playsInline = true;
      video.loop = true;
      video.preload = "none";
      video.setAttribute("playsinline", "");
      video.setAttribute("muted", "");
      video.setAttribute("loop", "");
      video.setAttribute("disablepictureinpicture", "");
      video.setAttribute("controlslist", "nodownload nofullscreen noremoteplayback");
      video.controls = false;
      media.appendChild(video);
    }

    media.querySelectorAll("iframe.cf-card-yt, .cf-card-yt-wrap").forEach(function (n) {
      try { n.remove(); } catch (e) {}
    });

    // Strip leftovers from older mounts (item-poster / poster-media)
    host.querySelectorAll(":scope > .cf-card-preview-ui, :scope > .cf-card-video-shield").forEach(function (n) {
      try { n.remove(); } catch (e0) {}
    });
    media.querySelectorAll(":scope > .cf-card-preview-ui, :scope > .cf-card-video-shield").forEach(function (n) {
      try { n.remove(); } catch (e1) {}
    });

    // Mount on .inner — locked hover height, overflow hidden, always the visible frame
    if (!inner.style.position) inner.style.position = "relative";

    var shield = inner.querySelector(":scope > .cf-card-video-shield");
    if (!shield) {
      shield = document.createElement("div");
      shield.className = "cf-card-video-shield";
    }
    inner.appendChild(shield);

    var ui = inner.querySelector(":scope > .cf-card-preview-ui");
    if (!ui) {
      ui = document.createElement("div");
      ui.className = "cf-card-preview-ui";
    }
    if (ui.getAttribute("data-cf-ui") !== "v22") {
      ui.setAttribute("data-cf-ui", "v22");
      ui.innerHTML =
        '<div class="cf-card-preview-top">' +
          '<button type="button" class="cf-card-mute" aria-label="Toggle sound">' +
            '<i class="uil uil-volume-mute" aria-hidden="true"></i>' +
          "</button>" +
          '<button type="button" class="cf-card-watchlist favo user-bookmark-toggle" aria-label="Add to watchlist">' +
            '<i class="uil uil-plus-circle" aria-hidden="true"></i>' +
          "</button>" +
        "</div>" +
        '<a class="cf-card-play" href="#" aria-label="Play">' +
          '<i class="uil uil-play" aria-hidden="true"></i>' +
        "</a>" +
        '<div class="cf-card-preview-foot">' +
          '<div class="cf-card-preview-chips"></div>' +
          '<div class="cf-card-preview-title"></div>' +
        "</div>";
    }
    inner.appendChild(ui);

    return { media: media, host: host, inner: inner, video: video, ui: ui, shield: shield };
  }

  var collapseTimers = typeof WeakMap !== "undefined" ? new WeakMap() : null;

  function clearCollapseTimer(card) {
    if (!collapseTimers || !card) return;
    var t = collapseTimers.get(card);
    if (t) {
      clearTimeout(t);
      collapseTimers.delete(card);
    }
  }

  function finishCollapse(card) {
    if (!card) return;
    clearCollapseTimer(card);
    card.classList.remove("is-hover-collapsing");
    try { card.style.removeProperty("--cf-preview-h"); } catch (e) {}
  }

  function stopCard(card) {
    if (!card) return;
    clearCollapseTimer(card);

    // Keep height locked while width animates back — otherwise aspect-ratio
    // (2/3) applies at the still-wide width and the card briefly grows downward.
    var keepH = false;
    try {
      keepH = !!(card.style.getPropertyValue("--cf-preview-h") || card.classList.contains("is-hover-preview"));
    } catch (eK) {}

    card.classList.remove("is-hover-preview", "has-preview-media");
    if (keepH) card.classList.add("is-hover-collapsing");

    var video = card.querySelector("video.cf-card-video");
    if (video) {
      try {
        video.pause();
        video.removeAttribute("src");
        video.load();
      } catch (e2) {}
      video.classList.remove("is-ready");
    }

    if (keepH) {
      var done = function () { finishCollapse(card); };
      if (collapseTimers) collapseTimers.set(card, setTimeout(done, 380));
      else setTimeout(done, 380);
      try {
        var onEnd = function (ev) {
          if (!ev || (ev.propertyName !== "width" && ev.propertyName !== "flex-basis" && ev.propertyName !== "max-width")) return;
          card.removeEventListener("transitionend", onEnd);
          done();
        };
        card.addEventListener("transitionend", onEnd);
      } catch (eT) {}
    } else {
      try { card.style.removeProperty("--cf-preview-h"); } catch (e3) {}
    }
  }

  function hideTip() {
    try {
      var tip = document.querySelector(".cf-media-tip");
      if (tip) {
        tip.setAttribute("hidden", "true");
        tip.style.display = "none";
      }
    } catch (e) {}
  }

  function fetchPreview(type, id) {
    var key = type + ":" + id;
    if (cache[key]) return Promise.resolve(cache[key]);
    return fetch(baseUrl() + "/api/preview/" + encodeURIComponent(type) + "/" + encodeURIComponent(id), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        if (!r.ok) throw new Error("preview failed");
        return r.json();
      })
      .then(function (data) {
        cache[key] = data;
        return data;
      });
  }

  function playPreview(card) {
    if (!canPreview()) return;
    var meta = cardMeta(card);
    if (!meta.id) return;
    var layers = ensureLayers(card);
    if (!layers) return;

    if (activeCard && activeCard !== card) stopCard(activeCard);
    activeCard = card;
    clearCollapseTimer(card);
    card.classList.remove("is-hover-collapsing");

    try {
      var poster = card.querySelector(".item-poster");
      var h = poster ? poster.getBoundingClientRect().height : 0;
      if (!h) {
        var inner = card.querySelector(".inner");
        h = inner ? inner.getBoundingClientRect().height : 0;
      }
      if (h > 0) {
        card.style.setProperty("--cf-preview-h", Math.round(h / pageZoom()) + "px");
      }
    } catch (eH) {}

    card.classList.add("is-hover-preview");
    hideTip();

    var playBtn = layers.ui.querySelector(".cf-card-play");
    if (playBtn) playBtn.setAttribute("href", meta.href || "#");

    var titleEl = layers.ui.querySelector(".cf-card-preview-title");
    var chipsEl = layers.ui.querySelector(".cf-card-preview-chips");
    var captionTitle = card.querySelector(".card-title");
    var captionKicker = card.querySelector(".card-kicker");
    var localTitle = captionTitle ? captionTitle.textContent.trim() : "";
    if (titleEl) titleEl.textContent = localTitle;

    function renderChips(typeLabel, year, rating) {
      if (!chipsEl) return;
      var html = "";
      if (typeLabel) html += '<span class="cf-card-preview-chip is-type">' + typeLabel + "</span>";
      if (year) html += '<span class="cf-card-preview-chip">' + year + "</span>";
      if (rating != null && rating !== "") html += '<span class="cf-card-preview-chip is-rating">★ ' + rating + "</span>";
      chipsEl.innerHTML = html;
    }

    var typeLabel = meta.type === "tv" ? "TV" : "MOVIE";
    var yearBit = "";
    var ratingBit = "";
    if (captionKicker) {
      var spans = captionKicker.querySelectorAll(":scope > span");
      if (spans[0]) typeLabel = (spans[0].textContent || typeLabel).trim().toUpperCase();
      if (spans[1]) yearBit = (spans[1].textContent || "").trim();
      if (spans[2]) ratingBit = (spans[2].textContent || "").replace("★", "").trim();
    }
    renderChips(typeLabel, yearBit, ratingBit);

    var posterImg = card.querySelector(".poster-media img");
    var posterUrl = "";
    if (posterImg) posterUrl = posterImg.getAttribute("data-src") || posterImg.getAttribute("src") || "";

    var wl = layers.ui.querySelector(".cf-card-watchlist");
    if (wl) {
      wl.setAttribute("data-id", meta.id);
      wl.setAttribute("data-media-type", meta.type);
      wl.setAttribute("data-title", localTitle);
      wl.setAttribute("data-poster", posterUrl.indexOf("data:") === 0 ? "" : posterUrl);
      wl.setAttribute("data-year", yearBit || "");
      wl.setAttribute("aria-label", "Add to watchlist");
      var wli = wl.querySelector("i");
      if (wli) wli.className = "uil uil-plus-circle";
    }

    var icon = layers.ui.querySelector(".cf-card-mute i");
    if (icon) icon.className = "uil uil-volume-mute";
    card.dataset.cfMuted = "1";

    fetchPreview(meta.type, meta.id)
      .then(function (data) {
        if (activeCard !== card) return;
        if (playBtn && data.url) playBtn.setAttribute("href", data.url);
        if (titleEl && data.title) titleEl.textContent = data.title;
        var tLab = data.type === "tv" ? "TV" : "MOVIE";
        renderChips(tLab, data.year ? String(data.year) : yearBit, data.rating != null ? String(data.rating) : ratingBit);
        if (wl) {
          if (data.title) wl.setAttribute("data-title", data.title);
          if (data.poster) wl.setAttribute("data-poster", data.poster);
          if (data.year) wl.setAttribute("data-year", String(data.year));
          wl.setAttribute("data-id", String(data.id || meta.id));
          wl.setAttribute("data-media-type", data.type || meta.type);
        }

        // Only HTML5 video — never YouTube iframe (avoids pause/prev/next chrome)
        if (!data.stream) return;

        card.classList.add("has-preview-media");
        var video = layers.video;
        video.controls = false;
        video.removeAttribute("controls");
        video.muted = true;
        video.classList.remove("is-ready");
        // Bust CDN/browser cache on each hover so expired googlevideo proxies refresh
        var src = String(data.stream);
        src += (src.indexOf("?") >= 0 ? "&" : "?") + "_t=" + Date.now();
        video.onerror = function () {
          try {
            video.classList.remove("is-ready");
            card.classList.remove("has-preview-media");
          } catch (eE) {}
        };
        video.onloadeddata = function () {
          if (activeCard !== card) return;
          video.classList.add("is-ready");
        };
        video.src = src;
        var p = video.play();
        if (p && p.then) {
          p.then(function () {
            if (activeCard === card) video.classList.add("is-ready");
          }).catch(function () {});
        }
      })
      .catch(function () {});
  }

  function onEnter(card) {
    if (!canPreview()) return;
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { playPreview(card); }, 260);
  }

  function onLeave(card, related) {
    clearTimeout(hoverTimer);
    if (related && card.contains(related)) return;
    if (activeCard === card) {
      stopCard(card);
      activeCard = null;
    }
  }

  function bind() {
    if (bound) return;
    bound = true;
    document.addEventListener("mouseover", function (e) {
      var card = e.target && e.target.closest ? e.target.closest(SELECTOR) : null;
      if (!card) return;
      var from = e.relatedTarget;
      if (from && card.contains(from)) return;
      onEnter(card);
    });
    document.addEventListener("mouseout", function (e) {
      var card = e.target && e.target.closest ? e.target.closest(SELECTOR) : null;
      if (!card) return;
      var to = e.relatedTarget;
      if (to && card.contains(to)) return;
      onLeave(card, to);
    });
    document.addEventListener("click", function (e) {
      var shield = e.target && e.target.closest ? e.target.closest(".cf-card-video-shield") : null;
      if (shield) {
        var card = shield.closest(".movie-item");
        var link = card && card.querySelector("a.item-poster");
        if (link && link.href) {
          e.preventDefault();
          e.stopPropagation();
          window.location.href = link.href;
          return;
        }
      }
      var mute = e.target && e.target.closest ? e.target.closest(".cf-card-mute") : null;
      if (!mute) return;
      e.preventDefault();
      e.stopPropagation();
      var card2 = mute.closest(".movie-item");
      if (!card2) return;
      var video = card2.querySelector("video.cf-card-video");
      if (!video || !video.classList.contains("is-ready")) return;
      video.muted = !video.muted;
      card2.dataset.cfMuted = video.muted ? "1" : "0";
      var ic = mute.querySelector("i");
      if (ic) ic.className = video.muted ? "uil uil-volume-mute" : "uil uil-volume";
    });
    document.addEventListener("visibilitychange", function () {
      if (document.hidden && activeCard) {
        stopCard(activeCard);
        activeCard = null;
      }
    });
    window.addEventListener("cf:softnav", function () {
      if (activeCard) {
        stopCard(activeCard);
        activeCard = null;
      }
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bind);
  else bind();
})();
