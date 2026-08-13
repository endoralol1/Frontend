(function () {
  "use strict";

  var MQ = "(min-width: 992px)";
  var SELECTOR = ".section-rail .media-rail-items .movie-item.media-card, .section-top10 .top10-items .movie-item.media-card";
  var LONG_PRESS_MS = 420;
  var cache = Object.create(null);
  var hoverTimer = null;
  var activeCard = null;
  var bound = false;
  var longPressTimer = null;
  var longPressCard = null;
  var longPressMoved = false;
  var longPressX = 0;
  var longPressY = 0;
  var suppressClick = false;
  var touchPreview = false;

  function isDesktop() {
    try { return window.matchMedia(MQ).matches; } catch (e) { return false; }
  }

  function canPreview() {
    try {
      if (document.documentElement.classList.contains("cf-perf-mode")) return false;
    } catch (e0) {}
    return true;
  }

  function canHoverPreview() {
    return canPreview() && isDesktop();
  }

  function canTouchPreview() {
    return canPreview() && !isDesktop();
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
    if (ui.getAttribute("data-cf-ui") !== "v25") {
      ui.setAttribute("data-cf-ui", "v25");
      ui.innerHTML =
        '<button type="button" class="cf-card-mute" aria-label="Toggle sound">' +
          '<i class="uil uil-volume-mute" aria-hidden="true"></i>' +
          '<span class="cf-card-mute-label"></span>' +
        "</button>" +
        '<div class="cf-card-preview-foot">' +
          '<div class="cf-card-preview-brand">' +
            '<img class="cf-card-preview-logo" alt="" hidden decoding="async">' +
            '<div class="cf-card-preview-title"></div>' +
          "</div>" +
          '<div class="cf-card-preview-actions">' +
            '<a class="cf-card-play" href="#">' +
              '<i class="uil uil-play" aria-hidden="true"></i>' +
              '<span class="cf-card-play-label">Play</span>' +
            "</a>" +
            '<button type="button" class="cf-card-watchlist favo user-bookmark-toggle" aria-label="Add to watchlist">' +
              '<i class="uil uil-star" aria-hidden="true"></i>' +
            "</button>" +
          "</div>" +
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
    try { if (hoverIO) hoverIO.unobserve(card); } catch (eUn) {}
    clearCollapseTimer(card);

    // Keep height locked while width animates back — otherwise aspect-ratio
    // (2/3) applies at the still-wide width and the card briefly grows downward.
    var keepH = false;
    try {
      keepH = !!(card.style.getPropertyValue("--cf-preview-h") || card.classList.contains("is-hover-preview"));
    } catch (eK) {}

    card.classList.remove("is-hover-preview", "has-preview-media", "is-touch-preview");
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

  var inflight = Object.create(null);

  function fetchPreview(type, id) {
    var key = type + ":" + id;
    if (cache[key]) return Promise.resolve(cache[key]);
    if (inflight[key]) return inflight[key];
    inflight[key] = fetch(baseUrl() + "/api/preview/" + encodeURIComponent(type) + "/" + encodeURIComponent(id), {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        if (!r.ok) throw new Error("preview failed");
        return r.json();
      })
      .then(function (data) {
        cache[key] = data;
        delete inflight[key];
        return data;
      })
      .catch(function (err) {
        delete inflight[key];
        throw err;
      });
    return inflight[key];
  }

  function prefetchCard(card) {
    if (!canPreview() || !card) return;
    var meta = cardMeta(card);
    if (!meta.id) return;
    fetchPreview(meta.type, meta.id).catch(function () {});
  }

  function playPreview(card) {
    if (!canPreview()) return;
    var meta = cardMeta(card);
    if (!meta.id) return;
    var layers = ensureLayers(card);
    if (!layers) return;

    if (activeCard && activeCard !== card) stopCard(activeCard);
    activeCard = card;
    try {
      if (hoverIOSeen && card) hoverIOSeen.delete(card);
      if (hoverIO) hoverIO.observe(card);
    } catch (eObs) {}
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
    if (touchPreview) card.classList.add("is-touch-preview");
    else card.classList.remove("is-touch-preview");
    hideTip();

    // Phone: keep the right-expanded card in view inside the horizontal rail
    if (touchPreview) {
      try {
        setTimeout(function () {
          if (activeCard !== card) return;
          card.scrollIntoView({ behavior: "smooth", inline: "nearest", block: "nearest" });
        }, 40);
      } catch (eScroll) {}
    }

    var playBtn = layers.ui.querySelector(".cf-card-play");
    if (playBtn) playBtn.setAttribute("href", meta.href || "#");

    var titleEl = layers.ui.querySelector(".cf-card-preview-title");
    var logoEl = layers.ui.querySelector(".cf-card-preview-logo");
    var muteLabel = layers.ui.querySelector(".cf-card-mute-label");
    var captionTitle = card.querySelector(".card-title");
    var captionKicker = card.querySelector(".card-kicker");
    var localTitle = captionTitle ? captionTitle.textContent.trim() : "";
    var cached = cache[meta.type + ":" + meta.id] || null;

    // Avoid text→logo flash: hide both until we know; if cache has logo, show it immediately.
    if (titleEl) {
      titleEl.textContent = localTitle;
      titleEl.hidden = true;
    }
    if (logoEl) {
      logoEl.hidden = true;
      logoEl.removeAttribute("src");
      logoEl.alt = localTitle || "";
    }

    function applyLogo(url, title) {
      if (!logoEl) {
        if (titleEl) titleEl.hidden = false;
        return;
      }
      if (url) {
        // If already showing this logo, keep it
        if (logoEl.getAttribute("src") === url && !logoEl.hidden) {
          if (titleEl) titleEl.hidden = true;
          return;
        }
        var done = false;
        function showLogo() {
          if (done) return;
          done = true;
          logoEl.hidden = false;
          if (titleEl) titleEl.hidden = true;
        }
        function showText() {
          if (done) return;
          done = true;
          logoEl.hidden = true;
          if (titleEl) titleEl.hidden = false;
        }
        logoEl.onload = showLogo;
        logoEl.onerror = showText;
        logoEl.alt = title || localTitle || "";
        // Decode ASAP; if cached by browser, onload may be sync
        logoEl.src = url;
        if (logoEl.complete && logoEl.naturalWidth > 0) showLogo();
      } else {
        logoEl.hidden = true;
        logoEl.removeAttribute("src");
        if (titleEl) {
          titleEl.textContent = title || localTitle || "";
          titleEl.hidden = false;
        }
      }
    }

    if (cached) {
      if (titleEl && cached.title) titleEl.textContent = cached.title;
      applyLogo(cached.logo || "", cached.title || localTitle);
    }

    var yearBit = "";
    var ratingBit = "";
    if (captionKicker) {
      var spans = captionKicker.querySelectorAll(":scope > span");
      if (spans[1]) yearBit = (spans[1].textContent || "").trim();
      if (spans[2]) ratingBit = (spans[2].textContent || "").replace("★", "").trim();
      else if (spans[1] && /\d\.\d/.test(spans[1].textContent || "")) {
        ratingBit = (spans[1].textContent || "").replace("★", "").trim();
      }
    }
    var ratingEl = card.querySelector(".card-rating");
    if (!ratingBit && ratingEl) ratingBit = (ratingEl.textContent || "").replace("★", "").trim();
    if (muteLabel) muteLabel.textContent = ratingBit || "";

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
      if (wli) wli.className = "uil uil-star";
    }

    var icon = layers.ui.querySelector(".cf-card-mute i");
    if (icon) icon.className = "uil uil-volume-mute";
    card.dataset.cfMuted = "1";

    function startStream(streamUrl) {
      if (!streamUrl || activeCard !== card) return;
      card.classList.add("has-preview-media");
      var video = layers.video;
      video.controls = false;
      video.removeAttribute("controls");
      video.muted = true;
      video.playsInline = true;
      video.preload = "auto";
      var src = String(streamUrl);
      if (video.getAttribute("src") === src && !video.error) {
        var p0 = video.play();
        if (p0 && p0.then) p0.then(function () { if (activeCard === card) video.classList.add("is-ready"); }).catch(function () {});
        return;
      }
      video.classList.remove("is-ready");
      try { delete video.dataset.cfRetried; } catch (e0) {}
      video.onerror = function () {
        try {
          if (!video.dataset.cfRetried) {
            video.dataset.cfRetried = "1";
            video.src = src + (src.indexOf("?") >= 0 ? "&" : "?") + "_t=" + Date.now();
            video.play().catch(function () {});
            return;
          }
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
    }

    // Warm start from memory cache (logo already applied above)
    if (cached && cached.stream) startStream(cached.stream);

    fetchPreview(meta.type, meta.id)
      .then(function (data) {
        if (activeCard !== card) return;
        if (playBtn && data.url) playBtn.setAttribute("href", data.url);
        if (titleEl && data.title) {
          titleEl.textContent = data.title;
          if (logoEl) logoEl.alt = data.title;
        }
        applyLogo(data.logo || "", data.title || localTitle);
        if (muteLabel && data.rating != null) muteLabel.textContent = String(data.rating);
        if (wl) {
          if (data.title) wl.setAttribute("data-title", data.title);
          if (data.poster) wl.setAttribute("data-poster", data.poster);
          if (data.year) wl.setAttribute("data-year", String(data.year));
          wl.setAttribute("data-id", String(data.id || meta.id));
          wl.setAttribute("data-media-type", data.type || meta.type);
        }

        // Only HTML5 video — never YouTube iframe (avoids pause/prev/next chrome)
        if (!data.stream) return;
        startStream(data.stream);
      })
      .catch(function () {});
  }

  function onEnter(card) {
    if (!canHoverPreview()) return;
    prefetchCard(card);
    clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { playPreview(card); }, 120);
  }

  function onLeave(card, related) {
    clearTimeout(hoverTimer);
    if (related && card.contains(related)) return;
    if (activeCard === card) {
      stopCard(card);
      activeCard = null;
    }
  }


  // Stop hover trailer if the active card scrolls off-screen
  // Ignore the first "not intersecting" callback (Safari/content-visibility false positives).
  var hoverIO = null;
  var hoverIOSeen = typeof WeakSet !== "undefined" ? new WeakSet() : null;
  if ("IntersectionObserver" in window) {
    hoverIO = new IntersectionObserver(function (entries) {
      var i;
      for (i = 0; i < entries.length; i++) {
        var en = entries[i];
        var el = en.target;
        if (en.isIntersecting) {
          try { if (hoverIOSeen) hoverIOSeen.add(el); } catch (eS) {}
          continue;
        }
        // Require at least one positive intersection before treating leave-as-offscreen
        if (hoverIOSeen && !hoverIOSeen.has(el)) continue;
        if (activeCard && el === activeCard) {
          stopCard(activeCard);
          activeCard = null;
        }
      }
    }, { root: null, rootMargin: "80px", threshold: 0 });
  }

  function clearLongPress() {
    if (longPressTimer) {
      clearTimeout(longPressTimer);
      longPressTimer = null;
    }
    longPressCard = null;
    longPressMoved = false;
  }

  function dismissTouchPreview() {
    if (!activeCard) return;
    stopCard(activeCard);
    activeCard = null;
    touchPreview = false;
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

    /* Phone: long-press a media card to play the trailer preview */
    document.addEventListener("touchstart", function (e) {
      if (!canTouchPreview()) return;
      if (!e.touches || e.touches.length !== 1) return;
      var t = e.touches[0];
      var target = e.target;
      if (target && target.closest && target.closest(".cf-card-mute, .cf-card-play, .cf-card-watchlist, .cf-card-preview-actions")) {
        clearLongPress();
        return;
      }
      var card = target && target.closest ? target.closest(SELECTOR) : null;
      clearLongPress();
      if (!card) return;
      longPressCard = card;
      longPressMoved = false;
      longPressX = t.clientX;
      longPressY = t.clientY;
      longPressTimer = setTimeout(function () {
        longPressTimer = null;
        if (!longPressCard || longPressMoved) return;
        if (!canTouchPreview()) return;
        suppressClick = true;
        touchPreview = true;
        prefetchCard(longPressCard);
        playPreview(longPressCard);
        try {
          if (navigator.vibrate) navigator.vibrate(10);
        } catch (eV) {}
      }, LONG_PRESS_MS);
    }, { passive: true });

    document.addEventListener("touchmove", function (e) {
      if (!longPressCard || !e.touches || !e.touches[0]) return;
      var t = e.touches[0];
      if (Math.abs(t.clientX - longPressX) > 12 || Math.abs(t.clientY - longPressY) > 12) {
        longPressMoved = true;
        clearLongPress();
      }
    }, { passive: true });

    document.addEventListener("touchend", function () {
      clearLongPress();
      if (suppressClick) {
        setTimeout(function () { suppressClick = false; }, 550);
      }
    }, { passive: true });

    document.addEventListener("touchcancel", function () {
      clearLongPress();
    }, { passive: true });

    document.addEventListener("contextmenu", function (e) {
      if (!canTouchPreview()) return;
      var card = e.target && e.target.closest ? e.target.closest(SELECTOR) : null;
      if (card) {
        e.preventDefault();
      }
    });

    document.addEventListener("click", function (e) {
      if (suppressClick) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      /* Tap outside an active touch preview dismisses it */
      if (touchPreview && activeCard) {
        var inUi = e.target && e.target.closest
          ? e.target.closest(".cf-card-mute, .cf-card-play, .cf-card-watchlist, .cf-card-preview-actions")
          : null;
        if (!inUi && !activeCard.contains(e.target)) {
          dismissTouchPreview();
          return;
        }
      }

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
        touchPreview = false;
      }
    });

    window.addEventListener("cf:softnav", function () {
      if (activeCard) {
        stopCard(activeCard);
        activeCard = null;
        touchPreview = false;
      }
    });

    // Prefetch preview JSON for nearby cards so logo+stream are warm
    if ("IntersectionObserver" in window) {
      var warmIO = new IntersectionObserver(function (entries) {
        var i;
        for (i = 0; i < entries.length; i++) {
          if (!entries[i].isIntersecting) continue;
          prefetchCard(entries[i].target);
        }
      }, { root: null, rootMargin: "80px", threshold: 0.15 });
      function warmScan() {
        document.querySelectorAll(SELECTOR).forEach(function (card) {
          try { warmIO.observe(card); } catch (eW) {}
        });
      }
      warmScan();
      window.addEventListener("cf:softnav", function () { setTimeout(warmScan, 80); });
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bind);
  else bind();
})();
