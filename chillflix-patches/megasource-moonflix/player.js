(function () {
  "use strict";

  const ICO = {
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8 5.2v13.6L19.2 12 8 5.2z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 5h3.2v14H7V5zm6.8 0H17v14h-3.2V5z"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5 8 12l7 7"/></svg>',
    vol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9.5v5h3.1L12 18V6L8.1 9.5H5z"/><path d="M15.2 9.1a3.1 3.1 0 0 1 0 5.8"/><path d="M17.3 7a5.8 5.8 0 0 1 0 10"/></svg>',
    mute: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 9.5v5h3.1L12 18V6L8.1 9.5H5z"/><path d="m16 9 5 5M21 9l-5 5"/></svg>',
    /* Thin corner brackets — fullscreen */
    fs: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 4H4v4M16 4h4v4M8 20H4v-4M16 20h4v-4"/></svg>',
    /* 6-tooth outline cog — matches standard player settings glyph */
    gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.8 6.8 0 0 1 0 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.6 6.6 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.5 6.5 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.9 6.9 0 0 1 0-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/></svg>',
    /* CC box with large readable letters */
    cc: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.6" y="5.4" width="18.8" height="13.2" rx="2.4"/><text x="12" y="15.15" text-anchor="middle" fill="currentColor" stroke="none" font-size="9.6" font-weight="750" font-family="Outfit,Poppins,Arial,sans-serif" letter-spacing="0.04em">CC</text></svg>',
    /* Stacked servers / sources */
    source: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4.5" width="14" height="5.2" rx="1.4"/><rect x="5" y="14.3" width="14" height="5.2" rx="1.4"/><path d="M8 7h.01M8 16.8h.01"/></svg>',
    /* Picture-in-picture */
    pip: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3.2" y="5.2" width="17.6" height="13.6" rx="2"/><rect x="12.2" y="11.2" width="7.2" height="5.6" rx="1.1"/></svg>',
    /* Clear ±10 seek — arc + large 10 (easy to read) */
    seekBack: '<svg viewBox="0 0 28 24" fill="none" aria-hidden="true"><path d="M14 4.2a8.3 8.3 0 1 0 7.6 11.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M14 4.2 11.1 1.6M14 4.2l-2.4 3.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><text x="14" y="15.4" text-anchor="middle" fill="currentColor" font-size="8.8" font-weight="750" font-family="Outfit,Poppins,Arial,sans-serif">10</text></svg>',
    seekFwd: '<svg viewBox="0 0 28 24" fill="none" aria-hidden="true"><path d="M14 4.2a8.3 8.3 0 1 1-7.6 11.6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M14 4.2 16.9 1.6M14 4.2l2.4 3.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><text x="14" y="15.4" text-anchor="middle" fill="currentColor" font-size="8.8" font-weight="750" font-family="Outfit,Poppins,Arial,sans-serif">10</text></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.2 16.6 4.8 12.2l1.4-1.4 3 3 8-8 1.4 1.4z"/></svg>',
  };


  /* -------- Autoplay sound (match Chillflix: prefer unmuted) -------- */
  const PLAYBACK_GESTURE_MAX_AGE_MS = 45000;
  const AUTO_UNMUTE_DELAYS_MS = [100, 300, 600, 1000, 2000, 4000];
  let lastPlaybackGestureAt = 0;
  let videoAutoplayUnlocked = false;

  function notePlaybackUserGesture() {
    lastPlaybackGestureAt = Date.now();
    try { window.__cfPlaybackGestureAt = lastPlaybackGestureAt; } catch (_) {}
    // Keep prior unlock from the Watch-click primer; don't clear it
  }

  function hasRecentPlaybackUserGesture(maxAgeMs) {
    const age = maxAgeMs == null ? PLAYBACK_GESTURE_MAX_AGE_MS : maxAgeMs;
    let at = lastPlaybackGestureAt;
    try {
      const w = Number(window.__cfPlaybackGestureAt) || 0;
      if (w > at) at = w;
    } catch (_) {}
    if (Date.now() - at <= age) return true;
    try {
      if (navigator.userActivation && navigator.userActivation.isActive) return true;
    } catch (_) {}
    return false;
  }

  function canAttemptAutoplayWithSound() {
    try {
      if (window.__cfVideoAutoplayUnlocked) videoAutoplayUnlocked = true;
    } catch (_) {}
    return videoAutoplayUnlocked || hasRecentPlaybackUserGesture();
  }

  function markVideoAutoplayUnlocked() {
    videoAutoplayUnlocked = true;
    try { window.__cfVideoAutoplayUnlocked = true; } catch (_) {}
  }

  async function unlockVideoElementForAutoplay(video) {
    if (!video || videoAutoplayUnlocked) return !!videoAutoplayUnlocked;
    if (!hasRecentPlaybackUserGesture(8000)) return false;
    try {
      video.muted = true;
      await video.play();
      video.pause();
      // Do not rewind a real stream — only reset empty priming videos
      if (!video.currentSrc && !video.src) {
        try { video.currentTime = 0; } catch (_) {}
      }
      video.muted = false;
      if (!(video.volume > 0)) video.volume = 1;
      markVideoAutoplayUnlocked();
      return true;
    } catch (_) {
      try { video.muted = false; } catch (_) {}
      return false;
    }
  }

  /** Sync unlock during the Watch click (before HLS finishes loading). */
  function primeAutoplaySoundUnlock() {
    notePlaybackUserGesture();
    try {
      let primer = document.getElementById("np-sound-unlock");
      if (!primer) {
        primer = document.createElement("video");
        primer.id = "np-sound-unlock";
        primer.setAttribute("playsinline", "");
        primer.setAttribute("webkit-playsinline", "");
        primer.muted = true;
        primer.playsInline = true;
        primer.style.cssText = "position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px;top:-9999px;";
        document.body.appendChild(primer);
      }
      const p = primer.play();
      if (p && typeof p.then === "function") {
        p.then(() => {
          try { primer.pause(); } catch (_) {}
          markVideoAutoplayUnlocked();
        }).catch(() => {});
      } else {
        markVideoAutoplayUnlocked();
      }
    } catch (_) {
      markVideoAutoplayUnlocked();
    }
  }

  // Expose for watch-page click handlers (must run in the same user gesture)
  window.__cfPrimeAutoplaySound = primeAutoplaySoundUnlock;
  window.__cfNotePlaybackGesture = notePlaybackUserGesture;

  async function attemptAutoplayUnmute(video, options) {
    if (!video) return false;
    if (!video.muted) return true;
    const may =
      (options && options.allowWithoutGesture) ||
      !video.paused ||
      canAttemptAutoplayWithSound();
    if (!may) return false;
    const targetVolume =
      options && typeof options.volume === "number" && options.volume > 0
        ? options.volume
        : video.volume > 0
          ? video.volume
          : 1;
    const wasPlaying = !video.paused;
    try {
      video.muted = false;
      video.volume = targetVolume;
      if (video.paused) await video.play();
      // Chrome/Edge often unmute "successfully" then immediately pause without a gesture.
      await new Promise((r) => setTimeout(r, 60));
      if (video.paused && wasPlaying) {
        video.muted = true;
        video.volume = 0;
        try { video.setAttribute("muted", ""); } catch (_) {}
        try { await video.play(); } catch (_) {}
        return false;
      }
      if (video.paused && video.muted === false) {
        // Unmute blocked playback start — remute and keep quiet play if we can.
        video.muted = true;
        video.volume = 0;
        try { video.setAttribute("muted", ""); } catch (_) {}
        try { await video.play(); } catch (_) {}
        return false;
      }
      return !video.muted && !video.paused;
    } catch (_) {
      try {
        video.muted = true;
        video.volume = 0;
        video.setAttribute("muted", "");
        if (wasPlaying || video.paused) await video.play();
      } catch (_2) {}
      return false;
    }
  }

  function scheduleAutoplayUnmuteAttempts(video, onUnmuted, delaysMs, volume) {
    const delays = delaysMs || AUTO_UNMUTE_DELAYS_MS;
    const vol = volume == null ? 1 : volume;
    const timers = [];
    let cancelled = false;
    const run = () => {
      if (cancelled || !video || !video.muted) return;
      attemptAutoplayUnmute(video, { allowWithoutGesture: true, volume: vol }).then((ok) => {
        if (ok && typeof onUnmuted === "function") onUnmuted();
      });
    };
    for (let i = 0; i < delays.length; i++) {
      timers.push(window.setTimeout(run, delays[i]));
    }
    return () => {
      cancelled = true;
      for (let i = 0; i < timers.length; i++) window.clearTimeout(timers[i]);
    };
  }

  function esc(s) {
    return String(s ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* -------- Continue Watching (local, throttled) -------- */
  const CW_KEY = "cf_continue_v1";
  const CW_MAX = 40;
  const WATCH_STATS_KEY = "cf_watch_stats_v1";

  function readWatchStats() {
    try {
      const raw = localStorage.getItem(WATCH_STATS_KEY);
      const data = raw ? JSON.parse(raw) : null;
      if (data && typeof data === "object") {
        return {
          totalSec: Math.max(0, Number(data.totalSec) || 0),
          movies: data.movies && typeof data.movies === "object" ? data.movies : {},
          tv: data.tv && typeof data.tv === "object" ? data.tv : {},
          updated: Number(data.updated) || 0,
        };
      }
    } catch (_) {}
    return { totalSec: 0, movies: {}, tv: {}, updated: 0 };
  }

  function writeWatchStats(stats) {
    try {
      localStorage.setItem(
        WATCH_STATS_KEY,
        JSON.stringify({
          totalSec: Math.max(0, Math.floor(Number(stats.totalSec) || 0)),
          movies: stats.movies || {},
          tv: stats.tv || {},
          updated: Date.now(),
        })
      );
    } catch (_) {}
    try {
      if (typeof window.refreshBrowseStats === "function") window.refreshBrowseStats();
    } catch (_) {}
  }

  /** Accumulate lifetime watch time + title counts (CW list is capped/pruned). */
  function recordWatchStats(cfg, watchedSec, prevSec) {
    const id = Number(cfg && cfg.id) || 0;
    if (!id) return;
    const type = cfg.type === "tv" ? "tv" : "movie";
    const t = Math.max(0, Number(watchedSec) || 0);
    const prev = Math.max(0, Number(prevSec) || 0);
    const delta = t > prev ? t - prev : 0;
    const stats = readWatchStats();
    if (delta > 0) stats.totalSec += delta;
    if (type === "tv") stats.tv[String(id)] = true;
    else stats.movies[String(id)] = true;
    writeWatchStats(stats);
  }

  function cwProgressKey(cfg) {
    const type = cfg.type === "tv" ? "tv" : "movie";
    // One slot per movie/show (not per episode) — last 5 titles only
    if (type === "tv") return `tv:${cfg.id}`;
    return `movie:${cfg.id}`;
  }

  function cwRowKey(row) {
    if (!row || !row.id) return "";
    // One slot per movie/show (legacy episode keys collapse via cwCollapseToTitles)
    return (row.type === "tv" ? "tv:" : "movie:") + row.id;
  }

  function cwTitleKey(row) {
    if (!row || !row.id) return "";
    return (row.type === "tv" ? "tv:" : "movie:") + row.id;
  }

  function cwCollapseToTitles(map) {
    const out = Object.create(null);
    Object.keys(map || {}).forEach((k) => {
      const row = map[k];
      if (!row || !row.id) return;
      const tk = cwTitleKey(row);
      if (!tk) return;
      const prev = out[tk];
      if (!prev || (Number(row.updated) || 0) >= (Number(prev.updated) || 0)) {
        out[tk] = Object.assign({}, row);
      }
    });
    return out;
  }

  function cwNormalizeMap(input) {
    const out = Object.create(null);
    if (!input) return out;
    if (Array.isArray(input)) {
      input.forEach((row) => {
        if (!row || !row.id) return;
        const k = cwRowKey(row);
        if (!k) return;
        const prev = out[k];
        if (!prev || (Number(row.updated) || 0) >= (Number(prev.updated) || 0)) out[k] = row;
      });
      return out;
    }
    if (typeof input !== "object") return out;
    // Already a keyed map — or a single flat row mistakenly stored as the root.
    if (input.id && (input.type === "tv" || input.type === "movie" || input.t != null)) {
      const k = cwRowKey(input);
      if (k) out[k] = input;
      return out;
    }
    Object.keys(input).forEach((k) => {
      const row = input[k];
      if (!row || typeof row !== "object" || !row.id) return;
      const nk = row._key || k || cwRowKey(row);
      if (!nk || nk === "id" || nk === "title" || nk === "type") {
        const rk = cwRowKey(row);
        if (rk) out[rk] = row;
        return;
      }
      out[nk] = row;
    });
    return out;
  }

  function cwReadCookieMirror() {
    try {
      const m = document.cookie.match(/(?:^|;\s*)cf_continue_v1=([^;]+)/);
      if (!m) return [];
      const arr = JSON.parse(decodeURIComponent(m[1]));
      return Array.isArray(arr) ? arr.filter(Boolean) : [];
    } catch (_) {
      return [];
    }
  }

  function cwReadAll() {
    let map = Object.create(null);
    try {
      const raw = localStorage.getItem(CW_KEY);
      map = cwNormalizeMap(raw ? JSON.parse(raw) : {});
    } catch (_) {
      map = Object.create(null);
    }
    // CRITICAL: if LS is empty/flaky, merge cookie mirror so saving a new title
    // does not wipe every previously watched item from the cookie-only store.
    const cookieItems = cwReadCookieMirror();
    if (cookieItems.length) {
      const fromCookie = cwNormalizeMap(cookieItems);
      Object.keys(fromCookie).forEach((k) => {
        const C = fromCookie[k];
        const L = map[k];
        if (!L || (Number(C.updated) || 0) > (Number(L.updated) || 0)) map[k] = C;
      });
    }
    map = cwCollapseToTitles(map);
    const ordered = Object.keys(map).sort((a, b) => (map[b].updated || 0) - (map[a].updated || 0));
    ordered.slice(CW_MAX).forEach((k) => delete map[k]);
    return map;
  }

  function cwWriteAll(map) {
    map = cwNormalizeMap(map);
    try {
      localStorage.setItem(CW_KEY, JSON.stringify(map));
    } catch (_) {}
    // cookie mirror for home recovery (keep newest 12; omit huge art URLs)
    try {
      const keys = Object.keys(map || {}).sort(
        (a, b) => (map[b].updated || 0) - (map[a].updated || 0)
      );
      const compact = keys.slice(0, 5).map((k) => {
        const row = map[k] || {};
        return {
          id: row.id,
          type: row.type,
          title: row.title,
          poster: row.poster || "",
          backdrop: row.backdrop || "",
          year: row.year || "",
          season: row.season,
          episode: row.episode,
          t: row.t || 0,
          d: row.d || 0,
          updated: row.updated || Date.now(),
        };
      }).filter((r) => r && r.id);
      document.cookie =
        "cf_continue_v1=" +
        encodeURIComponent(JSON.stringify(compact)) +
        ";path=/;max-age=31536000;SameSite=Lax";
    } catch (_) {}
  }

  const CW_PUSH_MIN_MS = 12000;
  const cwPushAt = Object.create(null);
  let cwAuthed = null; // null unknown, true/false after first API response

  function cwApiBase() {
    return (window.APP && APP.baseUrl) || "";
  }

  function cwPushContinue(item, force) {
    try {
      if (!item || !item.key) return;
      if (cwAuthed === false) return;
      const now = Date.now();
      const last = cwPushAt[item.key] || 0;
      if (!force && now - last < CW_PUSH_MIN_MS) return;
      cwPushAt[item.key] = now;
      const payload = {
        key: item.key,
        type: item.type === "tv" ? "tv" : "movie",
        id: item.id,
        season: item.season,
        episode: item.episode,
        title: item.title || "",
        poster: item.poster || "",
        backdrop: item.backdrop || "",
        year: item.year || "",
        t: item.t || 0,
        d: item.d || 0,
      };
      if (typeof window.nsPushContinue === "function") {
        window.nsPushContinue(payload);
        return;
      }
      fetch(cwApiBase() + "/api/user/continue", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
        keepalive: !!force,
      })
        .then((r) => {
          if (r.status === 401) cwAuthed = false;
          else if (r.ok) cwAuthed = true;
        })
        .catch(() => {});
    } catch (_) {}
  }

  function cwRemoveContinue(key) {
    try {
      if (!key || cwAuthed === false) return;
      if (typeof window.nsRemoveContinue === "function") {
        window.nsRemoveContinue(key);
        return;
      }
      fetch(cwApiBase() + "/api/user/continue/remove", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ key: key }),
        keepalive: true,
      })
        .then((r) => {
          if (r.status === 401) cwAuthed = false;
          else if (r.ok) cwAuthed = true;
        })
        .catch(() => {});
    } catch (_) {}
  }

  function cwSave(cfg, watched, duration, opts) {
    try {
      if (localStorage.getItem("cf_pref_continue") === "0") return false;
    } catch (_) {}
    const id = Number(cfg.id) || 0;
    if (!id) return false;
    const seed = !!(opts && opts.seed);
    const forcePush = !!(opts && opts.forcePush);
    let t = Number(watched) || 0;
    let d = Number(duration) || 0;
    if (!Number.isFinite(d) || d === Infinity) d = 0;
    // Seed on Watch Now / first play; otherwise require ~1s so rail fills quickly
    if (seed) t = Math.max(t, 1);
    else if (t < 1) return false;
    const key = cwProgressKey(cfg);
    if (!seed && d > 0 && (d - t < 90 || t / d > 0.96)) {
      // finished — drop from continue list (keep lifetime stats)
      const map = cwReadAll();
      const prevFin = map[key] || {};
      try {
        recordWatchStats(cfg, d || t, Number(prevFin.t) || 0);
      } catch (_) {}
      delete map[key];
      cwWriteAll(map);
      cwRemoveContinue(key);
      return true;
    }
    const map = cwReadAll();
    const prev = map[key] || {};
    try {
      recordWatchStats(cfg, seed ? Math.max(t, Number(prev.t) || 0) : t, Number(prev.t) || 0);
    } catch (_) {}
    // One row per title; season/episode on the row track the latest episode.
    // Use current position (not max) so resume matches where the user left off.
    const entry = {
      id,
      type: cfg.type === "tv" ? "tv" : "movie",
      title: cfg.title || prev.title || "Untitled",
      poster: cfg.poster || prev.poster || "",
      backdrop: cfg.backdrop || prev.backdrop || "",
      year: cfg.year || prev.year || "",
      season: cfg.type === "tv" ? Number(cfg.season) || 1 : null,
      episode: cfg.type === "tv" ? Number(cfg.episode) || 1 : null,
      t: seed ? Math.max(t, Number(prev.t) || 0) : t,
      d: d || Number(prev.d) || 0,
      updated: Date.now(),
      url: cfg.watchUrl || cfg.backUrl || prev.url || location.pathname + location.search,
    };
    map[key] = entry;
    // prune oldest
    const keys = Object.keys(map).sort((a, b) => (map[b].updated || 0) - (map[a].updated || 0));
    keys.slice(CW_MAX).forEach((k) => delete map[k]);
    cwWriteAll(map);
    cwPushContinue(Object.assign({ key }, entry), forcePush || seed);
    return true;
  }

  function cwSeed(cfg) {
    return cwSave(cfg, 1, 0, { seed: true });
  }

  function cwResumeTime(cfg) {
    // URL ?t= wins
    const params = new URLSearchParams(location.search);
    const qt = Number(params.get("t") || 0);
    if (qt > 0) return qt;
    const row = cwReadAll()[cwProgressKey(cfg)];
    if (!row) return 0;
    const t = Number(row.t) || 0;
    const d = Number(row.d) || 0;
    if (t < 5) return 0; // only auto-resume after a meaningful watch
    if (d > 0 && Number.isFinite(d) && d !== Infinity && (d - t < 90 || t / d > 0.96)) return 0;
    return t;
  }

  /* -------- Watch Party (host report / guest poll) -------- */
  function partyPeerId() {
    try {
      let id = sessionStorage.getItem("cf_party_peer");
      if (!id) {
        id = Math.random().toString(36).slice(2) + Date.now().toString(36);
        sessionStorage.setItem("cf_party_peer", id);
      }
      return id;
    } catch (_) {
      return "anon";
    }
  }

  function partyFromUrl() {
    const p = new URLSearchParams(location.search);
    const code = (p.get("party") || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
    if (!code) return null;
    const role = p.get("host") === "1" ? "host" : "guest";
    return { code, role };
  }


  const QUALITY_LADDER = [
    { key: "360", label: "360p", height: 360 },
    { key: "480", label: "480p", height: 480 },
    { key: "720", label: "720p", height: 720 },
    { key: "1080", label: "1080p", height: 1080 },
    { key: "4k", label: "4K", height: 2160 },
  ];

  function heightToQuality(height) {
    const h = Number(height) || 0;
    if (!h) return null;
    const exact = { 360: "360", 480: "480", 720: "720", 1080: "1080", 1440: "1080", 2160: "4k" };
    if (exact[h]) {
      return QUALITY_LADDER.find((q) => q.key === exact[h]) || null;
    }
    // Pick the closest standard rung (266→360, 534→480, 800→720, 872→720, etc.)
    let best = QUALITY_LADDER[0];
    let bestDist = Math.abs(h - best.height);
    for (const q of QUALITY_LADDER) {
      const dist = Math.abs(h - q.height);
      if (dist < bestDist) {
        best = q;
        bestDist = dist;
      }
    }
    return best;
  }

  function normalizeHlsLevels(levels) {
    // Map to standard rungs and keep the best matching level index per rung
    const best = new Map();
    (levels || []).forEach((l, idx) => {
      const q = heightToQuality(l.height);
      if (!q) return;
      const prev = best.get(q.key);
      const score = Number(l.height) || 0;
      if (!prev || score > prev.score) {
        best.set(q.key, { key: q.key, label: q.label, levelIndex: idx, height: l.height, score });
      }
    });
    const order = ["360", "480", "720", "1080", "4k"];
    return order
      .filter((k) => best.has(k))
      .map((k) => best.get(k));
  }

  function buildMarkup(cfg) {
    const isTv = cfg.type === "tv";
    const meta = isTv
      ? `S${cfg.season || 1} · E${cfg.episode || 1}`
      : cfg.year || "";
    const nextUrl = (cfg.next && cfg.next.url) || "";
    return `
<div class="np-shell ${cfg.embed ? "np-embed" : ""}" id="np-shell">
  <div class="np-stage">
    <div class="np-video-transform" id="np-video-transform">
      <video id="np-video" class="np-video" playsinline webkit-playsinline></video>
    </div>
    <iframe id="np-iframe" class="np-iframe" title="Cineplay player" allow="autoplay; fullscreen; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="origin" hidden></iframe>
    <div class="np-poster" id="np-poster" style="background-image:url('${esc(cfg.backdrop || "")}')"></div>
    <div class="np-subs" id="np-subs" aria-live="polite"></div>
    <div class="np-top">
      <button type="button" class="np-back" id="np-back" aria-label="' + t('common.back', 'Back') + '">${ICO.back}</button>
      <div class="np-titleblock">
        <p class="np-now">Now Playing</p>
        <h1 class="np-title">${esc(cfg.title || "Player")}</h1>
        ${meta ? `<p class="np-meta">${esc(meta)}</p>` : ""}
      </div>
      <div class="np-top-actions" aria-hidden="true"></div>
    </div>
    <div class="np-center" id="np-center">
      <button type="button" class="np-playbig" id="np-playbig" aria-label="Play">${ICO.play}</button>
      <p class="np-status" id="np-status">Loading sources…</p>
    </div>
    <button type="button" class="np-skip" id="np-skip" hidden></button>
    <button type="button" class="np-source-hint" id="np-source-hint" hidden>
      <span class="np-source-hint-title">Not working?</span>
      <span class="np-source-hint-text">Change source</span>
    </button>
    <div class="np-bottom" id="np-controls">
      <div class="np-progress-wrap">
        <input type="range" id="np-progress" class="np-progress" min="0" max="1000" value="0" step="1" aria-label="Seek">
      </div>
      <div class="np-bar">
        <div class="np-bar-left">
          <button type="button" class="np-btn" id="np-play" aria-label="${t('player.play_pause', 'Play/Pause')}">${ICO.play}</button>
          <button type="button" class="np-btn np-seek-btn" id="np-seek-back" aria-label="${t('player.seek_back', 'Back 10 seconds')}" title="Back 10 seconds">${ICO.seekBack}</button>
          <button type="button" class="np-btn np-seek-btn" id="np-seek-fwd" aria-label="${t('player.seek_forward', 'Forward 10 seconds')}" title="Forward 10 seconds">${ICO.seekFwd}</button>
          <button type="button" class="np-btn np-mute-btn" id="np-mute" aria-label="Mute">${ICO.vol}</button>
          <span class="np-time" id="np-time">0:00 / 0:00</span>
        </div>
        <div class="np-bar-right">
          ${isTv && nextUrl ? `<a class="np-btn" id="np-next" href="${esc(nextUrl)}" title="${t('a11y.next_episode', 'Next episode')}">${t('common.next', 'Next')}</a>` : ""}
          <button type="button" class="np-btn np-source-btn" id="np-source-btn" aria-label="${t('player.source', 'Source')}" title="${t('player.source', 'Source')}">${ICO.source}</button>
          <button type="button" class="np-btn np-cc-btn" id="np-cc" aria-label="${t('player.subtitles', 'Subtitles')}" aria-haspopup="dialog" title="${t('player.subtitles', 'Subtitles')}" aria-pressed="false"><span class="np-cc-ico" aria-hidden="true">${ICO.cc}</span><span class="np-cc-lang" hidden>EN</span></button>
          <button type="button" class="np-btn np-settings-btn" id="np-settings-btn" aria-label="${t('player.settings', 'Settings')}" aria-haspopup="dialog" title="${t('player.settings', 'Settings')}">${ICO.gear}</button>
          <button type="button" class="np-btn np-pip-btn" id="np-pip" aria-label="${t('player.pip', 'Picture in picture')}" title="${t('player.pip', 'Picture in picture')}">${ICO.pip}</button>
          <button type="button" class="np-btn" id="np-fs" aria-label="${t('player.fullscreen', 'Fullscreen')}">${ICO.fs}</button>
        </div>
      </div>
    </div>
  </div>
  <div class="np-scrim" id="np-scrim" hidden></div>
  <aside class="np-subs-drawer" id="np-subs-drawer" hidden>
    <div class="np-subs-drawer-head">
      <h2>${t('player.subtitles', 'Subtitles')}</h2>
      <button type="button" class="np-btn np-panel-x" id="np-subs-drawer-close" aria-label="Close">✕</button>
    </div>
    <div class="np-list" id="np-sub-list-fs"></div>
  </aside>
  <aside class="np-panel" id="np-panel" hidden>
    <div class="np-panel-head">
      <h2>${t('player.settings', 'Settings')}</h2>
      <button type="button" class="np-btn np-panel-x" id="np-panel-close" aria-label="Close">✕</button>
    </div>
    <div class="np-seg" id="np-panel-tabs" role="tablist">
      <button type="button" data-tab="source" class="is-active" role="tab">Source</button>
      <button type="button" data-tab="quality" role="tab">Quality</button>
      <button type="button" data-tab="audio" role="tab">Audio</button>
      <button type="button" data-tab="subs" role="tab">Subs</button>
      <button type="button" data-tab="style" role="tab">Captions</button>
    </div>
    <div class="np-panel-body">
      <section data-panel="source" class="is-active">
        <div class="np-list" id="np-source-list"></div>
      </section>
      <section data-panel="quality">
        <div class="np-list" id="np-quality-list"></div>
      </section>
      <section data-panel="audio">
        <div class="np-list" id="np-audio-list"></div>
      </section>
      <section data-panel="subs">
        <div class="np-list" id="np-sub-list"></div>
      </section>
      <section data-panel="style">
        <div class="np-style-cards">
          <label class="np-style-card">
            <span class="np-style-top"><span>Delay</span><span class="np-slider-val" id="np-delay-val">0.0s</span></span>
            <input type="range" id="np-delay" min="-10" max="10" step="0.1" value="0">
          </label>
          <label class="np-style-card">
            <span class="np-style-top"><span>Size</span><span class="np-slider-val" id="np-size-val">100%</span></span>
            <input type="range" id="np-size" min="0.75" max="1.75" step="0.05" value="1">
          </label>
          <label class="np-style-card">
            <span class="np-style-top"><span>Background</span><span class="np-slider-val" id="np-bg-val">70%</span></span>
            <input type="range" id="np-bg" min="0" max="0.9" step="0.05" value="0.7">
          </label>
        </div>
        ${isTv ? `<div class="np-toggle-row"><div class="np-toggle-copy"><span>${t('detail.auto_next', 'Auto Next')}</span><small>${t('browse.autonext_help', 'Play the next episode when this one ends')}</small></div><button type="button" class="np-switch" id="np-autonext-switch" aria-checked="true" role="switch" aria-label="Auto next"></button></div>` : ""}
      </section>
    </div>
  </aside>
</div>`;
  }

  function createPlayer(cfg) {
    const $ = (sel, root) => (root || document).querySelector(sel);
    const root = cfg.root || document;
    const els = {
      shell: $("#np-shell", root),
      stage: $(".np-stage", root),
      videoTransform: $("#np-video-transform", root),
      video: $("#np-video", root),
      iframe: $("#np-iframe", root),
      poster: $("#np-poster", root),
      subs: $("#np-subs", root),
      status: $("#np-status", root),
      center: $("#np-center", root),
      controls: $("#np-controls", root),
      skip: $("#np-skip", root),
      playBig: $("#np-playbig", root),
      play: $("#np-play", root),
      mute: $("#np-mute", root),
      seekBack: $("#np-seek-back", root),
      seekFwd: $("#np-seek-fwd", root),
      fs: $("#np-fs", root),
      cc: $("#np-cc", root),
      subsDrawer: $("#np-subs-drawer", root),
      subsDrawerClose: $("#np-subs-drawer-close", root),
      subListFs: $("#np-sub-list-fs", root),
      back: $("#np-back", root),
      progress: $("#np-progress", root),
      time: $("#np-time", root),
      settingsBtn: $("#np-settings-btn", root),
      sourceBtn: $("#np-source-btn", root),
      pip: $("#np-pip", root),
      sourceHint: $("#np-source-hint", root),
      scrim: $("#np-scrim", root),
      panel: $("#np-panel", root),
      panelClose: $("#np-panel-close", root),
      panelTabs: $("#np-panel-tabs", root),
      autonext: $("#np-autonext", root),
      autonextSwitch: $("#np-autonext-switch", root),
      sourceList: $("#np-source-list", root),
      qualityList: $("#np-quality-list", root),
      audioList: $("#np-audio-list", root),
      subList: $("#np-sub-list", root),
      delay: $("#np-delay", root),
      delayVal: $("#np-delay-val", root),
      size: $("#np-size", root),
      sizeVal: $("#np-size-val", root),
      bg: $("#np-bg", root),
      bgVal: $("#np-bg-val", root),
    };

    const state = {
      sources: [],
      sourceIndex: 0,
      sourceHintTimer: null,
      sourceHintDismissed: false,
      skipSegments: [],
      skipDismissed: Object.create(null),
      skipFetchKey: "",
      skipActiveId: "",
      hls: null,
      levels: [],
      qualityOptions: [],
      levelIndex: -1,
      audioTracks: [],
      audioIndex: -1,
      textTracks: [],
      textIndex: -1,
      subsUserOff: false,
      subsDefaultTried: false,
      delaySec: Number(localStorage.getItem("cf_np_sub_delay") || 0),
      fontScale: Number(localStorage.getItem("cf_np_sub_size") || 1),
      subBg: Number(localStorage.getItem("cf_np_sub_bg") || 0.7),
      autoNext: localStorage.getItem("cf_np_autonext") !== "0",
      autoplay: cfg.autoplay !== false,
      hideTimer: null,
      cueBases: new WeakMap(),
      payloadSubtitles: [],
      externalSubs: [],
      activeSubCues: [],
      subCueCache: Object.create(null),
      subLoadToken: 0,
      lastPaintedCueKey: "",
      subsLoading: false,
      subsLoaded: false,
      subsOpenLang: "",
      bound: false,
      resumeApplied: false,
      videoScale: 1,
      videoTranslateX: 0,
      videoTranslateY: 0,
      zoomGestureActive: false,
      suppressVideoTap: false,
      zoomGesture: {
        mode: "idle",
        startDistance: 0,
        startScale: 1,
        startTranslateX: 0,
        startTranslateY: 0,
        lastPanX: 0,
        lastPanY: 0,
        moved: false,
      },
      lastZoomTap: { time: 0, x: 0, y: 0 },
      // Progressive sources: scrape race pack in parallel, then cascade.
      providerLoads: Object.create(null),
      loadWatchdog: null,
      loadWatchdogToken: 0,
      loadWatchdogPoll: null,
      autoWaitTimer: null,
      autoPlayStarted: false,
      raceWinnerIndex: -1,
      // After a full cascade miss: keep "No playable sources" visible and
      // quietly re-walk the same list in background rounds.
      cascadeExhausted: false,
      bgSearchRound: 0,
      bgSearchTimer: null,
      bgSearchActive: false,
      manualSourcePick: false,
      lastCwSave: 0,
      party: partyFromUrl(),
      partyTimer: null,
      partyApplying: false,
      partyHostId: null,
      partyUnloadBound: null,
    };

    function fmt(t) {
      if (!Number.isFinite(t) || t < 0) return "0:00";
      const h = Math.floor(t / 3600);
      const m = Math.floor((t % 3600) / 60);
      const s = Math.floor(t % 60);
      if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
      return `${m}:${String(s).padStart(2, "0")}`;
    }

    function setStatus(msg) {
      if (els.status) els.status.textContent = msg || "";
    }

    function absoluteUrl(url) {
      if (!url) return "";
      if (/^https?:\/\//i.test(url)) return url;
      if (url.startsWith("//")) return location.protocol + url;
      if (url.startsWith("/")) {
        const main = (window.APP && window.APP.mainOrigin) || location.origin;
        if (url.startsWith("/api/cinepro/") || url.startsWith("/api/huhu/")) {
          return main.replace(/\/$/, "") + url;
        }
        return location.origin + url;
      }
      return url;
    }

    function destroyHls() {
      if (state.hls) {
        try {
          state.hls.destroy();
        } catch (_) {}
        state.hls = null;
      }
    }

    function showControls(temp) {
      els.shell?.classList.add("show-controls");
      clearTimeout(state.hideTimer);
      if (temp !== false) {
        state.hideTimer = setTimeout(() => {
          if (els.shell?.classList.contains("np-settings-open")) return;
          if (els.shell?.classList.contains("np-subs-open")) return;
          if (!els.video?.paused) els.shell?.classList.remove("show-controls");
        }, 2800);
      }
    }

    function renderList(container, items, activeIndex, onPick, metaFn) {
      if (!container) return;
      container.innerHTML = "";
      if (!items.length) {
        const empty = document.createElement("div");
        empty.className = "np-empty";
        empty.textContent = "Nothing available yet";
        container.appendChild(empty);
        return;
      }
      items.forEach((item, idx) => {
        const meta = metaFn(item, idx) || {};
        const b = document.createElement("button");
        b.type = "button";
        b.className = "np-option" + (idx === activeIndex ? " is-active" : "");
        b.innerHTML = `
          <span class="np-option-main">
            <span class="np-option-title">${esc(meta.title || "Option")}</span>
            ${meta.sub ? `<span class="np-option-sub">${esc(meta.sub)}</span>` : ""}
          </span>
          <span class="np-option-check" aria-hidden="true">${ICO.check}</span>`;
        b.addEventListener("click", () => onPick(idx));
        container.appendChild(b);
      });
    }

    function streamQualitiesFromSource(source) {
      const list = Array.isArray(source?.qualities) ? source.qualities : [];
      return list
        .filter((q) => q && q.url)
        .map((q, i) => ({
          key: String(q.quality || q.label || i).toLowerCase(),
          label: String(q.quality || q.label || `Q${i + 1}`),
          levelIndex: i,
          url: String(q.url),
          type: q.type || source?.type || "hls",
        }));
    }

    function defaultStreamQualityIndex(variants, source) {
      if (!variants.length) return 0;
      const pid = String(source?.provider || "").toLowerCase();
      const abs = absoluteUrl(source?.url || variants[0]?.url || "");
      // HDGHAR/Moonflix 1080p segments are often 5–10MB via a-relay — phones time out and
      // Auto cascades to MegaSource. Prefer 720p (≈1–2MB) for a-relay family.
      const prefer720 =
        pid === "moonflix" ||
        pid === "hdghar" ||
        pid === "bingr" ||
        /\/api\/player\/(a-relay|lang-proxy)\b/i.test(abs) ||
        variants.some((v) => /\/api\/player\/(a-relay|lang-proxy)\b/i.test(String(v.url || "")));
      if (prefer720) {
        const hit720 = variants.findIndex((v) => /720/.test(String(v.label || "")));
        if (hit720 >= 0) return hit720;
        const hit480 = variants.findIndex((v) => /480/.test(String(v.label || "")));
        if (hit480 >= 0) return hit480;
      }
      const hit1080 = variants.findIndex((v) => /1080/.test(String(v.label || "")));
      if (hit1080 >= 0) return hit1080;
      const hit720 = variants.findIndex((v) => /720/.test(String(v.label || "")));
      if (hit720 >= 0) return hit720;
      return 0;
    }

    function applySourceQualities(source) {
      const variants = streamQualitiesFromSource(source);
      if (!variants.length) return false;
      state.qualityOptions = variants;
      let idx = state.levelIndex;
      if (idx < 0 || idx >= variants.length) {
        idx = defaultStreamQualityIndex(variants, source);
      }
      // Keep selected variant URL on the source entry
      const pick = variants[idx];
      if (pick?.url && source) {
        source.url = pick.url;
        source.quality = pick.label;
        if (pick.type) source.type = pick.type;
      }
      state.levelIndex = idx;
      return true;
    }

    function refreshMenus() {
      renderList(
        els.sourceList,
        state.sources,
        state.sourceIndex,
        (i) => loadSource(i),
        (s, i) => {
          const hasVariants = streamQualitiesFromSource(s).length > 0;
          const isCineplay = String(s.provider || "").toLowerCase() === "cineplay";
          const status = String(s.status || (s.url ? "ready" : "pending"));
          const candN = Array.isArray(s.candidates) ? s.candidates.length : 0;
          let sub = "Stream";
          if (status === "pending") sub = "Tap to load";
          else if (status === "loading") sub = t('common.loading', 'Loading…');
          else if (status === "empty") sub = t('player.no_source', 'No source');
          else if (status === "error") sub = t('player.failed_retry', 'Failed — tap retry');
          else if (s.provider === "huhu") {
            sub = s.language
              ? String(s.language).toUpperCase() + " audio"
              : s.quality && s.quality !== "Auto"
                ? String(s.quality)
                : "DE / EN";
          } else if (hasVariants || isCineplay || candN > 1) {
            sub = candN > 1 ? `Best of ${candN}` : "Best Default";
          } else if (s.language) {
            const lang = String(s.language);
            sub = (/^en/i.test(lang) ? "English" : lang.charAt(0).toUpperCase() + lang.slice(1)) + (s.quality && s.quality !== "Auto" ? (" · " + s.quality) : "");
          } else if (s.quality) sub = String(s.quality);
          else if (s.type) sub = String(s.type).toUpperCase();
          return {
            title: s.providerName || s.provider || `Source ${i + 1}`,
            sub,
          };
        }
      );

      const hasUrlQualities = streamQualitiesFromSource(state.sources[state.sourceIndex]).length > 0;
      const qualities = hasUrlQualities
        ? state.qualityOptions
        : [{ key: "auto", label: "Auto", levelIndex: -1 }, ...state.qualityOptions];
      let qActive = 0;
      if (hasUrlQualities) {
        qActive = state.levelIndex >= 0 ? state.levelIndex : defaultStreamQualityIndex(state.qualityOptions);
      } else if (state.levelIndex >= 0) {
        const hit = state.qualityOptions.findIndex((q) => q.levelIndex === state.levelIndex);
        qActive = hit >= 0 ? hit + 1 : 0;
      }
      renderList(
        els.qualityList,
        qualities,
        qActive,
        (i) => {
          const q = qualities[i];
          if (hasUrlQualities) setQuality(q.levelIndex);
          else setQuality(q.key === "auto" ? -1 : q.levelIndex);
        },
        (l) => ({
          title: l.label || "Auto",
          sub: hasUrlQualities ? "Stream" : l.key === "auto" ? "Adaptive" : "Closest match",
        })
      );

      const audios = state.audioTracks.length
        ? state.audioTracks
        : [{ id: "main", name: "English", lang: "en", switchable: false }];
      const audioActive = state.audioIndex >= 0 ? state.audioIndex : 0;
      renderList(
        els.audioList,
        audios,
        audioActive,
        (i) => setAudio(i),
        (a, i) => ({
          title: friendlyAudioName(a) || a.name || a.label || `Audio ${i + 1}`,
          sub: a.switchUrl ? "Language pack" : a.switchable === false ? "Selected" : "Track",
        })
      );

      renderSubtitleGroups(els.subList);
      renderSubtitleGroups(els.subListFs);
      // Keep CC button state in sync (+ EN/lang under icon)
      try {
        const on = state.textIndex >= 0;
        els.cc?.classList.toggle("is-on", on);
        els.cc?.setAttribute("aria-pressed", on ? "true" : "false");
        const langEl = els.cc?.querySelector(".np-cc-lang");
        if (langEl) {
          if (on) {
            const track = state.externalSubs[state.textIndex] || {};
            let code = String(track.lang || track.language || "").trim();
            if (!code) {
              const label = String(track.label || track.name || "ON");
              const mLang = label.match(/\b([A-Za-z]{2,3})\b/);
              code = mLang ? mLang[1] : "ON";
            }
            langEl.textContent = code.slice(0, 3).toUpperCase();
            langEl.hidden = false;
          } else {
            langEl.textContent = "";
            langEl.hidden = true;
          }
        }
      } catch (_) {}
    }

    function skipLabel(type) {
      switch (type) {
        case "recap":
          return t("player.skipTime.recap", "Skip Recap");
        case "preview":
          return t("player.skipTime.preview", "Skip Preview");
        case "credits":
          return t("player.skipTime.credits", "Skip Credits");
        case "intro":
        default:
          return t("player.skipTime.intro", "Skip Intro");
      }
    }

    function skipSegmentId(seg) {
      return `${seg.type}:${seg.start_ms ?? "0"}:${seg.end_ms ?? "end"}`;
    }

    function activeSkipSegment(currentSec) {
      const ms = currentSec * 1000;
      const order = { intro: 0, recap: 1, preview: 2, credits: 3 };
      const hits = (state.skipSegments || []).filter((seg) => {
        const start = seg.start_ms == null ? 0 : Number(seg.start_ms);
        const end = seg.end_ms == null ? Infinity : Number(seg.end_ms);
        if (!(ms >= start && ms < end)) return false;
        if (state.skipDismissed[skipSegmentId(seg)]) return false;
        return true;
      });
      hits.sort((a, b) => (order[a.type] ?? 9) - (order[b.type] ?? 9));
      return hits[0] || null;
    }

    function updateSkipButton() {
      const btn = els.skip;
      const v = els.video;
      if (!btn || !v) return;
      const playing = !v.paused && !v.ended;
      if (!playing || !state.skipSegments.length) {
        btn.hidden = true;
        state.skipActiveId = "";
        return;
      }
      const seg = activeSkipSegment(v.currentTime || 0);
      if (!seg) {
        btn.hidden = true;
        state.skipActiveId = "";
        return;
      }
      const id = skipSegmentId(seg);
      const start = (seg.start_ms == null ? 0 : Number(seg.start_ms)) / 1000;
      const inFirst10 = (v.currentTime || 0) - start <= 10;
      const controlsUp = !!els.shell?.classList.contains("show-controls");
      // Netflix-like: always show early in the segment; later only while controls are visible.
      if (!inFirst10 && !controlsUp) {
        btn.hidden = true;
        return;
      }
      const isCreditsToEnd = seg.type === "credits" && seg.end_ms == null;
      const nextUrl = cfg.type === "tv" && cfg.next && cfg.next.url;
      if (isCreditsToEnd && nextUrl) {
        btn.textContent = t("player.next_episode", "Next Episode");
        btn.dataset.mode = "next";
      } else {
        btn.textContent = skipLabel(seg.type);
        btn.dataset.mode = "seek";
      }
      btn.dataset.segId = id;
      btn.hidden = false;
      state.skipActiveId = id;
      // Keep skip visible even when chrome auto-hides during early segment window.
      if (inFirst10) btn.classList.add("is-pinned");
      else btn.classList.remove("is-pinned");
    }

    async function fetchSkipSegments() {
      if (!cfg.id) return;
      const key =
        cfg.type === "tv"
          ? `tv:${cfg.id}:s${cfg.season || 1}:e${cfg.episode || 1}`
          : `movie:${cfg.id}`;
      if (state.skipFetchKey === key && state.skipSegments.length) return;
      state.skipFetchKey = key;
      state.skipDismissed = Object.create(null);
      try {
        const q = new URLSearchParams({
          type: cfg.type === "tv" ? "tv" : "movie",
          tmdbId: String(cfg.id || ""),
        });
        if (cfg.type === "tv") {
          q.set("season", String(cfg.season || 1));
          q.set("episode", String(cfg.episode || 1));
        }
        const dur = els.video && Number.isFinite(els.video.duration) ? els.video.duration : 0;
        if (dur > 0) q.set("durationMs", String(Math.round(dur * 1000)));
        const api =
          cfg.skipSegmentsApi ||
          ((window.APP && window.APP.baseUrl) || "") + "/api/player/skip-segments";
        const res = await fetch(`${api}?${q.toString()}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const data = await res.json();
        if (state.skipFetchKey !== key) return;
        state.skipSegments = Array.isArray(data?.segments) ? data.segments : [];
        updateSkipButton();
      } catch (_) {
        if (state.skipFetchKey === key) state.skipSegments = [];
      }
    }

    function performSkip() {
      const v = els.video;
      const btn = els.skip;
      if (!v || !btn || btn.hidden) return;
      const seg = activeSkipSegment(v.currentTime || 0);
      if (!seg) return;
      const id = skipSegmentId(seg);
      state.skipDismissed[id] = true;
      if (btn.dataset.mode === "next" && cfg.next && cfg.next.url) {
        location.href = cfg.next.url;
        return;
      }
      let target;
      if (seg.end_ms == null) {
        target = Number.isFinite(v.duration) ? Math.max(0, v.duration - 0.25) : v.currentTime + 30;
      } else {
        target = Number(seg.end_ms) / 1000;
      }
      try {
        v.currentTime = Math.min(Math.max(0, target), Number.isFinite(v.duration) ? v.duration : target);
      } catch (_) {}
      updateSkipButton();
      showControls();
    }

    function syncUi() {
      const v = els.video;
      if (!v) return;
      const playing = !v.paused && !v.ended;
      if (els.play) els.play.innerHTML = playing ? ICO.pause : ICO.play;
      if (els.playBig) els.playBig.innerHTML = playing ? ICO.pause : ICO.play;
      if (els.mute) els.mute.innerHTML = v.muted || v.volume === 0 ? ICO.mute : ICO.vol;
      if (els.time) els.time.textContent = `${fmt(v.currentTime)} / ${fmt(v.duration)}`;
      if (els.progress && Number.isFinite(v.duration) && v.duration > 0) {
        const pct = (v.currentTime / v.duration) * 100;
        els.progress.value = String(Math.round((v.currentTime / v.duration) * 1000));
        els.progress.style.setProperty("--np-progress", `${pct}%`);
      }
      if (playing) els.shell?.classList.add("is-playing");
      else els.shell?.classList.remove("is-playing");
      updateSkipButton();
    }

    function setQuality(idx) {
      const src = state.sources[state.sourceIndex];
      const variants = streamQualitiesFromSource(src);
      if (variants.length) {
        if (idx < 0 || idx >= variants.length) {
          idx = defaultStreamQualityIndex(variants);
        }
        const pick = variants[idx];
        if (!pick?.url) {
          refreshMenus();
          return;
        }
        // Same URL already playing — just update selection
        if (state.levelIndex === idx && absoluteUrl(src.url) === absoluteUrl(pick.url) && state.hls) {
          state.levelIndex = idx;
          refreshMenus();
          return;
        }
        state.levelIndex = idx;
        src.url = pick.url;
        src.quality = pick.label;
        if (pick.type) src.type = pick.type;
        refreshMenus();
        playUrl(pick.url, src);
        return;
      }
      state.levelIndex = idx;
      if (state.hls) state.hls.currentLevel = idx;
      refreshMenus();
    }

    function setAudio(idx) {
      state.audioIndex = idx;
      const track = state.audioTracks[idx];
      if (!track) {
        refreshMenus();
        return;
      }
      const src = state.sources[state.sourceIndex];
      const provider = String(src?.provider || "").toLowerCase();
      // Language packs with separate URLs (AwsInd / HollyBox / Huhu): reload stream
      if (track.switchUrl || (track.lang && provider === "huhu")) {
        let nextUrl = track.switchUrl || "";
        if (!nextUrl && src?.url && provider === "huhu") {
          try {
            const u = new URL(absoluteUrl(src.url));
            u.searchParams.set("lang", String(track.lang).toLowerCase().slice(0, 2));
            nextUrl = u.toString();
          } catch (_) {
            nextUrl = "";
          }
        }
        if (nextUrl) {
          if (src) {
            src.url = nextUrl;
            src.language = track.lang || src.language;
            const nice = friendlyAudioName(track);
            src.label = `${src.providerName || src.provider || "Source"} · ${nice}`;
          }
          refreshMenus();
          playUrl(nextUrl, src);
          return;
        }
      }
      if (track.switchable !== false && state.hls && state.hls.audioTracks) {
        const hlsIdx = Number.isInteger(track.id) ? track.id : idx;
        state.hls.audioTrack = hlsIdx;
      }
      refreshMenus();
    }


    function normalizeLangToken(v) {
      return String(v || "")
        .toLowerCase()
        .replace(/_/g, "-")
        .trim();
    }

    function isEnglishToken(v) {
      const s = normalizeLangToken(v);
      return (
        s === "en" ||
        s === "eng" ||
        s === "english" ||
        s.startsWith("en-") ||
        /\benglish\b/.test(s)
      );
    }

    function pickDefaultEnglishSubtitleIndex(subs) {
      const list = Array.isArray(subs) ? subs : [];
      let best = -1;
      let bestScore = -1;
      list.forEach((s, i) => {
        const blob = [s?.lang, s?.language, s?.label, s?.name, s?.source]
          .filter(Boolean)
          .join(" ");
        let score = 0;
        if (isEnglishToken(blob)) score = 100;
        else if (/\ben(g|glish)?\b/i.test(blob)) score = 80;
        if (score > bestScore) {
          bestScore = score;
          best = i;
        }
      });
      // Prefer English; if none, leave Off (don't force random language)
      return bestScore > 0 ? best : -1;
    }


    const SUB_LANG_NAMES = {
      en: "English", eng: "English",
      de: "German", ger: "German", deu: "German",
      es: "Spanish", spa: "Spanish",
      fr: "French", fre: "French", fra: "French",
      it: "Italian", ita: "Italian",
      pt: "Portuguese", por: "Portuguese",
      br: "Portuguese", pb: "Portuguese",
      nl: "Dutch", dut: "Dutch", nld: "Dutch",
      hr: "Croatian", cro: "Croatian",
      sr: "Serbian", bs: "Bosnian",
      ru: "Russian", rus: "Russian",
      uk: "Ukrainian",
      pl: "Polish", pol: "Polish",
      cs: "Czech", cz: "Czech",
      sk: "Slovak",
      hu: "Hungarian",
      ro: "Romanian",
      bg: "Bulgarian",
      el: "Greek", gre: "Greek",
      tr: "Turkish", tur: "Turkish",
      ar: "Arabic", ara: "Arabic",
      he: "Hebrew", iw: "Hebrew",
      hi: "Hindi", hin: "Hindi",
      ja: "Japanese", jpn: "Japanese",
      ko: "Korean", kor: "Korean",
      zh: "Chinese", chi: "Chinese", zho: "Chinese", cn: "Chinese",
      th: "Thai",
      vi: "Vietnamese",
      id: "Indonesian", ind: "Indonesian",
      ms: "Malay",
      sv: "Swedish",
      no: "Norwegian", nb: "Norwegian", nn: "Norwegian",
      da: "Danish",
      fi: "Finnish",
      is: "Icelandic",
      et: "Estonian",
      lv: "Latvian",
      lt: "Lithuanian",
      sl: "Slovenian",
      mk: "Macedonian",
      sq: "Albanian",
      fa: "Persian",
      ur: "Urdu",
      bn: "Bengali",
      ta: "Tamil",
      te: "Telugu",
      ml: "Malayalam",
      kn: "Kannada",
      ca: "Catalan",
      eu: "Basque",
      gl: "Galician",
      und: "Unknown",
    };

    const SUB_LANG_FLAGS = {
      en: "🇺🇸", eng: "🇺🇸",
      de: "🇩🇪", ger: "🇩🇪", deu: "🇩🇪",
      es: "🇪🇸", spa: "🇪🇸",
      fr: "🇫🇷", fre: "🇫🇷", fra: "🇫🇷",
      it: "🇮🇹", ita: "🇮🇹",
      pt: "🇧🇷", por: "🇧🇷", br: "🇧🇷", pb: "🇧🇷",
      nl: "🇳🇱", dut: "🇳🇱", nld: "🇳🇱",
      hr: "🇭🇷", cro: "🇭🇷",
      sr: "🇷🇸", bs: "🇧🇦",
      ru: "🇷🇺", rus: "🇷🇺",
      uk: "🇺🇦",
      pl: "🇵🇱", pol: "🇵🇱",
      cs: "🇨🇿", cz: "🇨🇿",
      sk: "🇸🇰",
      hu: "🇭🇺",
      ro: "🇷🇴",
      bg: "🇧🇬",
      el: "🇬🇷", gre: "🇬🇷",
      tr: "🇹🇷", tur: "🇹🇷",
      ar: "🇸🇦", ara: "🇸🇦",
      he: "🇮🇱", iw: "🇮🇱",
      hi: "🇮🇳", hin: "🇮🇳",
      ja: "🇯🇵", jpn: "🇯🇵",
      ko: "🇰🇷", kor: "🇰🇷",
      zh: "🇨🇳", chi: "🇨🇳", zho: "🇨🇳", cn: "🇨🇳",
      th: "🇹🇭",
      vi: "🇻🇳",
      id: "🇮🇩", ind: "🇮🇩",
      ms: "🇲🇾",
      sv: "🇸🇪",
      no: "🇳🇴", nb: "🇳🇴", nn: "🇳🇴",
      da: "🇩🇰",
      fi: "🇫🇮",
      is: "🇮🇸",
      et: "🇪🇪",
      lv: "🇱🇻",
      lt: "🇱🇹",
      sl: "🇸🇮",
      mk: "🇲🇰",
      sq: "🇦🇱",
      fa: "🇮🇷",
      ur: "🇵🇰",
      bn: "🇧🇩",
      ta: "🇮🇳",
      te: "🇮🇳",
      ml: "🇮🇳",
      kn: "🇮🇳",
      ca: "🇪🇸",
      eu: "🇪🇸",
      gl: "🇪🇸",
      und: "🏳️",
    };

    const SUB_SOURCE_NAMES = {
      opensubs: "OpenSubtitles",
      granite: "VDRK",
      wyzie: "Wyzie",
      vdrk: "VDRK",
    };

    function canonicalizeSubLang(raw) {
      let s = normalizeLangToken(raw);
      if (!s) return "und";
      // strip region: en-us -> en
      if (s.includes("-")) s = s.split("-")[0];
      if (s === "eng" || s === "en") return "en";
      if (s === "ger" || s === "deu") return "de";
      if (s === "fre" || s === "fra") return "fr";
      if (s === "spa") return "es";
      if (s === "ita") return "it";
      if (s === "por" || s === "pb" || s === "ptbr" || s === "brazillian" || s === "brazilian") return "pt";
      if (s === "dut" || s === "nld") return "nl";
      if (s === "cro") return "hr";
      if (s === "rus") return "ru";
      if (s === "jpn") return "ja";
      if (s === "kor") return "ko";
      if (s === "chi" || s === "zho" || s === "cn" || s === "zh") return "zh";
      if (s === "gre") return "el";
      if (s === "tur") return "tr";
      if (s === "ara") return "ar";
      if (s === "iw") return "he";
      if (s === "hin") return "hi";
      if (s === "ind") return "id";
      if (s === "pol") return "pl";
      if (s === "cz") return "cs";
      // full names
      for (const [code, name] of Object.entries(SUB_LANG_NAMES)) {
        if (s === normalizeLangToken(name) || s.includes(normalizeLangToken(name))) {
          return canonicalizeSubLang(code);
        }
      }
      if (/brazilian|portugu/.test(s)) return "pt";
      if (/english/.test(s)) return "en";
      if (/german|deutsch/.test(s)) return "de";
      if (/spanish|espanol|español/.test(s)) return "es";
      if (/french|francais|français/.test(s)) return "fr";
      if (/italian/.test(s)) return "it";
      if (/croatian|hrvatski/.test(s)) return "hr";
      if (/dutch|nederlands/.test(s)) return "nl";
      if (/russian/.test(s)) return "ru";
      if (/chinese|mandarin/.test(s)) return "zh";
      if (/japanese/.test(s)) return "ja";
      if (/korean/.test(s)) return "ko";
      if (/arabic/.test(s)) return "ar";
      if (/turkish/.test(s)) return "tr";
      if (/hindi/.test(s)) return "hi";
      if (s.length > 3) return "und";
      return s.slice(0, 3) || "und";
    }

    function subtitleLangKey(sub) {
      const fromCode = canonicalizeSubLang(sub?.lang || sub?.language || "");
      if (fromCode && fromCode !== "und") return fromCode;
      return canonicalizeSubLang(sub?.label || sub?.name || "") || "und";
    }

    function subtitleLangName(code, fallback) {
      const c = canonicalizeSubLang(code);
      if (SUB_LANG_NAMES[c]) return SUB_LANG_NAMES[c];
      const fb = String(fallback || "").trim();
      if (fb) {
        // strip trailing digits: Croatian2 -> Croatian
        const cleaned = fb.replace(/\d+$/, "").trim();
        if (cleaned) return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
      }
      return c !== "und" ? c.toUpperCase() : "Unknown";
    }

    function subtitleLangFlag(code) {
      const c = canonicalizeSubLang(code);
      return SUB_LANG_FLAGS[c] || "🏳️";
    }

    function subtitleSourceLabel(source) {
      const s = String(source || "").toLowerCase();
      return SUB_SOURCE_NAMES[s] || (s ? s : "Caption");
    }

    function subtitleTrackTitle(sub, indexInGroup) {
      const langName = subtitleLangName(subtitleLangKey(sub), sub?.label);
      let label = String(sub?.label || sub?.name || "").trim();
      // Drop pure language labels so group children stay distinct
      if (
        !label ||
        canonicalizeSubLang(label) === subtitleLangKey(sub) ||
        normalizeLangToken(label) === normalizeLangToken(langName)
      ) {
        return subtitleSourceLabel(sub?.source) + (indexInGroup > 0 ? ` ${indexInGroup + 1}` : "");
      }
      // Keep useful variants like "Croatian2" / "English (SDH)"
      return label;
    }

    function renderSubtitleGroups(container) {
      if (!container) return;
      container.innerHTML = "";
      container.classList.add("np-sub-groups");

      const groups = new Map();
      (state.externalSubs || []).forEach((sub, index) => {
        const key = subtitleLangKey(sub);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push({ sub, index });
      });

      const prio = { en: 0, de: 1, hr: 2, es: 3, fr: 4, it: 5, pt: 6, nl: 7 };
      const keys = [...groups.keys()].sort((a, b) => {
        const pa = prio[a] ?? 50;
        const pb = prio[b] ?? 50;
        if (pa !== pb) return pa - pb;
        return subtitleLangName(a).localeCompare(subtitleLangName(b));
      });

      // Off
      const off = document.createElement("button");
      off.type = "button";
      off.className = "np-option np-sub-off" + (state.textIndex < 0 ? " is-active" : "");
      off.innerHTML = `
        <span class="np-option-main">
          <span class="np-option-title">Off</span>
          <span class="np-option-sub">Hidden</span>
        </span>
        <span class="np-option-check" aria-hidden="true">${ICO.check}</span>`;
      off.addEventListener("click", () => {
        state.subsOpenLang = "";
        setSubtitle(-1);
      });
      container.appendChild(off);

      if (!keys.length) {
        if (state.subsLoading) {
          const loading = document.createElement("div");
          loading.className = "np-empty";
          loading.textContent = "Loading subtitles…";
          container.appendChild(loading);
        } else if (!state.subsLoaded) {
          const pending = document.createElement("div");
          pending.className = "np-empty";
          pending.textContent = "Subtitles loading…";
          container.appendChild(pending);
        } else {
          const empty = document.createElement("div");
          empty.className = "np-empty";
          empty.textContent = "No subtitles found";
          container.appendChild(empty);
        }
        return;
      }

      keys.forEach((key) => {
        const tracks = groups.get(key) || [];
        const isOpen = state.subsOpenLang === key;
        const hasActive =
          state.textIndex >= 0 && tracks.some((t) => t.index === state.textIndex);
        const name = subtitleLangName(key, tracks[0]?.sub?.label);
        const flag = subtitleLangFlag(key);

        const wrap = document.createElement("div");
        wrap.className =
          "np-sub-group" + (isOpen ? " is-open" : "") + (hasActive ? " has-active" : "");

        const head = document.createElement("button");
        head.type = "button";
        head.className = "np-option np-sub-group-head";
        head.setAttribute("aria-expanded", isOpen ? "true" : "false");
        const countLabel =
          tracks.length === 1 ? "1 track" : tracks.length + " tracks";
        const subLabel = hasActive ? "Selected · " + countLabel : countLabel;
        head.innerHTML = `
          <span class="np-sub-group-flag" aria-hidden="true">${flag}</span>
          <span class="np-option-main">
            <span class="np-option-title">${esc(name)}</span>
            <span class="np-option-sub${hasActive ? " is-selected" : ""}">${esc(subLabel)}</span>
          </span>
          <span class="np-sub-group-chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="${isOpen ? "6 9 12 15 18 9" : "9 6 15 12 9 18"}"/></svg>
          </span>`;
        head.addEventListener("click", () => {
          // Toggle open; single-track languages still expand/collapse (no auto-pick open)
          if (tracks.length === 1 && !isOpen) {
            // Pick the only track, then leave groups collapsed
            state.subsOpenLang = "";
            setSubtitle(tracks[0].index);
            return;
          }
          state.subsOpenLang = isOpen ? "" : key;
          renderSubtitleGroups(els.subList);
          renderSubtitleGroups(els.subListFs);
        });
        wrap.appendChild(head);

        if (isOpen) {
          const list = document.createElement("div");
          list.className = "np-sub-group-tracks";
          tracks.forEach((t, i) => {
            const active = state.textIndex === t.index;
            const b = document.createElement("button");
            b.type = "button";
            b.className = "np-option np-sub-track" + (active ? " is-active" : "");
            const title = subtitleTrackTitle(t.sub, i);
            const src = subtitleSourceLabel(t.sub?.source);
            const subLine =
              normalizeLangToken(title) === normalizeLangToken(src)
                ? String(t.sub?.type || "Caption").toUpperCase()
                : src;
            b.innerHTML = `
              <span class="np-option-main">
                <span class="np-option-title">${esc(title)}</span>
                <span class="np-option-sub">${esc(subLine)}</span>
              </span>
              <span class="np-option-check" aria-hidden="true">${ICO.check}</span>`;
            b.addEventListener("click", (e) => {
              e.stopPropagation();
              // Select track, then collapse so the language list stays scannable
              state.subsOpenLang = "";
              setSubtitle(t.index);
            });
            list.appendChild(b);
          });
          wrap.appendChild(list);
        }

        container.appendChild(wrap);
      });
    }

    function friendlyAudioName(track) {
      const raw = String(track?.name || track?.label || track?.lang || "").trim();
      const lang = normalizeLangToken(track?.lang || track?.language || raw);
      if (isEnglishToken(raw) || isEnglishToken(lang)) return "English";
      if (/^hi\b|hindi/.test(lang) || /hindi/i.test(raw)) return "Hindi";
      if (/tamil|^ta\b/.test(lang) || /tamil/i.test(raw)) return "Tamil";
      if (/telugu|^te\b/.test(lang) || /telugu/i.test(raw)) return "Telugu";
      if (/spanish|^es\b/.test(lang) || /spanish/i.test(raw)) return "Spanish";
      if (/portuguese|^pt\b/.test(lang) || /portuguese/i.test(raw)) return "Portuguese";
      if (/german|^de\b/.test(lang) || /german/i.test(raw)) return "German";
      if (/french|^fr\b/.test(lang) || /french/i.test(raw)) return "French";
      if (/original/.test(lang) || /original/i.test(raw)) return "Original";
      if (/ip\s*cloud|ipcloud/.test(lang) || /ip\s*cloud/i.test(raw)) return "IP Cloud";
      if (!raw || /^default$/i.test(raw) || /embedded/i.test(raw)) {
        return lang ? lang.toUpperCase() : "Audio";
      }
      return raw;
    }

    function preferEnglishAudioIndex(tracks, preferred) {
      const list = Array.isArray(tracks) ? tracks : [];
      if (!list.length) return 0;
      const want = normalizeLangToken(preferred || "en");
      let best = 0;
      let bestScore = -1;
      list.forEach((a, i) => {
        const blob = normalizeLangToken(
          [a?.name, a?.label, a?.lang, a?.language].filter(Boolean).join(" ")
        );
        let score = 0;
        if (isEnglishToken(blob) || (want && (blob === want || blob.startsWith(want)))) score = 100;
        else if (/original/.test(blob)) score = 50;
        else if (want && blob.includes(want)) score = 80;
        if (score > bestScore) {
          bestScore = score;
          best = i;
        }
      });
      return bestScore > 0 ? best : 0;
    }

    function fallbackAudioTracks(source) {
      const srcAudio = Array.isArray(source?.audioTracks)
        ? source.audioTracks.map((a, i) => {
            const track = {
              id: a.id || `src-${i}`,
              name: a.label || a.name || a.language || a.lang || `Audio ${i + 1}`,
              lang: a.language || a.lang || "",
              language: a.language || a.lang || "",
              switchUrl: a.switchUrl || a.url || "",
              switchable: Boolean(a.switchUrl || a.url) || false,
              from: "source",
            };
            track.name = friendlyAudioName(track);
            return track;
          })
        : [];
      if (srcAudio.length) {
        // English first in the Audio tab
        return srcAudio.slice().sort((a, b) => {
          const ae = isEnglishToken(a.name) || isEnglishToken(a.lang) ? 1 : 0;
          const be = isEnglishToken(b.name) || isEnglishToken(b.lang) ? 1 : 0;
          return be - ae;
        });
      }
      const provider = String(source?.provider || "").toLowerCase();
      if (provider === "huhu") {
        return [
          { id: "huhu-en", name: "English", lang: "en", switchable: true, from: "source" },
          { id: "huhu-de", name: "German", lang: "de", switchable: true, from: "source" },
        ];
      }
      // Prefer a named English placeholder over "Default" / "Embedded"
      const lang = String(source?.language || source?.preferAudio || "en");
      const name = isEnglishToken(lang) ? "English" : friendlyAudioName({ name: lang, lang });
      return [{ id: "main", name: name || "English", lang: isEnglishToken(lang) ? "en" : lang, switchable: false, from: "source" }];
    }

    function captionProxyUrl(raw) {
      const src = String(raw || "").trim();
      if (!src) return "";
      if (src.includes("/api/player/caption")) return absoluteUrl(src);
      const captionBase =
        cfg.captionApi ||
        ((window.APP && window.APP.baseUrl) || "") + "/api/player/caption";
      return `${captionBase}?url=${encodeURIComponent(src)}`;
    }

    function normalizeSubtitleEntries(list) {
      if (!Array.isArray(list)) return [];
      return list
        .map((sub, i) => {
          const raw = sub?.src || sub?.url || sub?.file || "";
          const src = captionProxyUrl(raw);
          if (!src) return null;
          return {
            id: sub.id || `sub-${i}`,
            label: sub.label || sub.language || sub.lang || `Sub ${i + 1}`,
            language: sub.language || sub.lang || "",
            lang: sub.language || sub.lang || "",
            source: sub.source || "",
            src,
          };
        })
        .filter(Boolean);
    }

    function vttTs(value) {
      const m = String(value || "")
        .trim()
        .match(/(?:(\d+):)?(\d{1,2}):(\d{2})(?:\.|,)(\d{1,3})/);
      if (!m) return 0;
      const h = Number(m[1] || 0);
      const min = Number(m[2] || 0);
      const sec = Number(m[3] || 0);
      const ms = Number(String(m[4] || "0").padEnd(3, "0").slice(0, 3));
      return h * 3600 + min * 60 + sec + ms / 1000;
    }

    function parseVttCues(text) {
      const body = String(text || "")
        .replace(/^\uFEFF/, "")
        .replace(/\r/g, "");
      const blocks = body.split(/\n\n+/);
      const cues = [];
      for (const block of blocks) {
        const lines = block.split("\n").filter((l) => l.trim() !== "");
        if (!lines.length) continue;
        let i = 0;
        if (lines[0].startsWith("WEBVTT") || lines[0].startsWith("NOTE") || lines[0].startsWith("STYLE") || lines[0].startsWith("REGION")) {
          continue;
        }
        if (!lines[0].includes("-->") && lines.length > 1 && lines[1].includes("-->")) i = 1;
        const timing = lines[i];
        if (!timing || !timing.includes("-->")) continue;
        const parts = timing.split("-->");
        if (parts.length < 2) continue;
        const start = vttTs(parts[0]);
        const end = vttTs(parts[1].trim().split(/\s+/)[0]);
        const cueText = lines
          .slice(i + 1)
          .join("\n")
          .replace(/<[^>]+>/g, "")
          .trim();
        if (!cueText || !(end > start)) continue;
        cues.push({ start, end, text: cueText });
      }
      return cues;
    }

    async function fetchExternalSubtitleCatalog() {
      if (state.subsLoading || state.subsLoaded) return;
      if (!cfg.id) return;
      state.subsLoading = true;
      try {
        const api =
          cfg.subsApi ||
          ((window.APP && window.APP.baseUrl) || "") + "/api/player/subtitles";
        const q = new URLSearchParams({
          type: cfg.type || "movie",
          tmdbId: String(cfg.id || ""),
        });
        if (cfg.imdbId) q.set("imdbId", String(cfg.imdbId));
        if (cfg.type === "tv") {
          q.set("season", String(cfg.season || 1));
          q.set("episode", String(cfg.episode || 1));
        }
        const res = await fetch(`${api}?${q.toString()}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const data = await res.json();
        if (!data?.ok || !Array.isArray(data.subtitles) || !data.subtitles.length) {
          state.subsLoaded = true;
          refreshMenus();
          return;
        }
        state.payloadSubtitles = normalizeSubtitleEntries(data.subtitles);
        loadExternalSubtitles(state.payloadSubtitles, state.sources[state.sourceIndex]);
        state.subsLoaded = true;
      } catch (_) {
        state.subsLoaded = true;
      } finally {
        state.subsLoading = false;
      }
    }

    function paintCue(text) {
      if (!els.subs) return;
      if (!text) {
        if (state.lastPaintedCueKey !== "") {
          els.subs.innerHTML = "";
          state.lastPaintedCueKey = "";
        }
        return;
      }
      if (state.lastPaintedCueKey === text) return;
      const span = document.createElement("span");
      span.textContent = text;
      span.style.fontSize = `${1.05 * state.fontScale}rem`;
      span.style.background = `rgba(0,0,0,${state.subBg})`;
      els.subs.innerHTML = "";
      els.subs.appendChild(span);
      state.lastPaintedCueKey = text;
    }

    function syncSubtitleOverlay() {
      if (state.textIndex < 0 || !state.activeSubCues.length || !els.video) {
        paintCue("");
        return;
      }
      const t = (els.video.currentTime || 0) + (Number(state.delaySec) || 0);
      const hits = [];
      for (let i = 0; i < state.activeSubCues.length; i++) {
        const cue = state.activeSubCues[i];
        if (t >= cue.start && t < cue.end) hits.push(cue.text);
      }
      paintCue(hits.length ? hits.join("\n") : "");
    }

    function applySubtitleDelay() {
      // Delay is applied live in syncSubtitleOverlay via currentTime offset.
      syncSubtitleOverlay();
    }

    async function setSubtitle(idx) {
      state.textIndex = idx;
      if (idx < 0) state.subsUserOff = true;
      else state.subsUserOff = false;
      // Keep language groups collapsed after pick so other languages stay easy to find.
      // Never drive hls.js subtitleTrack from external catalogue indexes — that
      // silently broke cue rendering when streams had zero in-manifest subs.
      if (state.hls && typeof state.hls.subtitleTrack !== "undefined") {
        try {
          state.hls.subtitleTrack = -1;
        } catch (_) {}
      }
      // Keep native tracks disabled; we paint our own overlay from parsed VTT.
      try {
        Array.from(els.video?.textTracks || []).forEach((tt) => {
          tt.mode = "disabled";
          tt.oncuechange = null;
        });
      } catch (_) {}

      if (idx < 0) {
        state.activeSubCues = [];
        state.subLoadToken += 1;
        paintCue("");
        refreshMenus();
        return;
      }

      const sub = state.externalSubs[idx];
      if (!sub?.src) {
        state.activeSubCues = [];
        paintCue("");
        refreshMenus();
        return;
      }

      refreshMenus();
      const token = ++state.subLoadToken;
      const cacheKey = sub.src;
      if (Array.isArray(state.subCueCache[cacheKey])) {
        state.activeSubCues = state.subCueCache[cacheKey];
        syncSubtitleOverlay();
        return;
      }

      try {
        setStatus(t("player.subtitles", "Subtitles") + "…");
        const res = await fetch(sub.src, {
          credentials: "same-origin",
          headers: { Accept: "text/vtt, text/plain, */*" },
        });
        if (!res.ok) throw new Error("caption " + res.status);
        const text = await res.text();
        if (token !== state.subLoadToken) return;
        const cues = parseVttCues(text);
        state.subCueCache[cacheKey] = cues;
        state.activeSubCues = cues;
        syncSubtitleOverlay();
        if (!cues.length) setStatus("Subtitle file empty");
      } catch (_) {
        if (token !== state.subLoadToken) return;
        state.activeSubCues = [];
        paintCue("");
        setStatus("Subtitle load failed");
      }
    }

    function loadExternalSubtitles(payloadSubs, source) {
      const fromSource = normalizeSubtitleEntries(
        Array.isArray(source?.subtitles) ? source.subtitles : []
      );
      const globalSubs = normalizeSubtitleEntries(
        Array.isArray(payloadSubs) ? payloadSubs : state.payloadSubtitles
      );
      // Prefer per-source captions when present; else global VDRK/OpenSubs catalogue.
      state.externalSubs = fromSource.length ? fromSource : globalSubs;
      // Drop native <track> tags — HLS.js + lazy track loading was unreliable.
      try {
        Array.from(els.video?.querySelectorAll("track[data-cf]") || []).forEach((n) => n.remove());
      } catch (_) {}
      if (state.textIndex >= state.externalSubs.length) {
        state.textIndex = -1;
        state.activeSubCues = [];
        paintCue("");
      } else if (state.textIndex >= 0) {
        void setSubtitle(state.textIndex);
      } else if (!state.subsUserOff && state.externalSubs.length) {
        // Default: English CC on when available
        const idx = pickDefaultEnglishSubtitleIndex(state.externalSubs);
        if (idx >= 0) {
          state.subsDefaultTried = true;
          void setSubtitle(idx);
        }
      }
      state.subsDefaultTried = true;
      refreshMenus();
    }

    function ensureIframe() {
      if (els.iframe && els.iframe.isConnected) return els.iframe;
      const stage = els.shell?.querySelector(".np-stage") || els.shell;
      if (!stage) return null;
      let iframe = stage.querySelector("#np-iframe");
      if (!iframe) {
        iframe = document.createElement("iframe");
        iframe.id = "np-iframe";
        iframe.className = "np-iframe";
        iframe.title = "Cineplay player";
        iframe.allow =
          "autoplay; fullscreen; encrypted-media; picture-in-picture";
        iframe.setAttribute("allowfullscreen", "");
        iframe.setAttribute("referrerpolicy", "origin");
        const video = stage.querySelector("#np-video");
        if (video && video.parentNode === stage) {
          stage.insertBefore(iframe, video.nextSibling);
        } else {
          stage.appendChild(iframe);
        }
      }
      els.iframe = iframe;
      return iframe;
    }

    function clearIframe() {
      const iframe = els.iframe || els.shell?.querySelector("#np-iframe");
      if (iframe) {
        iframe.hidden = true;
        iframe.removeAttribute("src");
        els.iframe = iframe;
      }
      els.shell?.classList.remove("np-iframe-mode");
      if (els.video) els.video.hidden = false;
      if (els.controls) els.controls.hidden = false;
      if (els.center) els.center.hidden = false;
      if (els.subs) els.subs.hidden = false;
    }

    function isIframeSource(url, source) {
      const type = String(source?.type || source?.format || "").toLowerCase();
      if (type === "iframe" || type === "embed") return true;
      const u = String(url || "");
      if (/vidking\.net\/embed\//i.test(u)) return true;
      if (/cineplay\.to\/(?:movie|tv)\//i.test(u) && /[?&]play=true\b/i.test(u)) {
        return true;
      }
      return false;
    }

    function isCineplayYoruSource(url, source) {
      const type = String(source?.type || source?.format || "").toLowerCase();
      if (type === "cineplay-yoru" || type === "cineplay") return true;
      return /^cineplay-yoru:\/\//i.test(String(url || ""));
    }

    function pickBestYoruSource(sources) {
      const list = Array.isArray(sources) ? sources.filter((s) => s && s.url) : [];
      const rank = (q) => {
        const s = String(q || "").toLowerCase();
        if (s.includes("2160") || s === "4k") return 50;
        if (s.includes("1080")) return 40;
        if (s.includes("720")) return 30;
        if (s.includes("480")) return 20;
        return 5;
      };
      list.sort((a, b) => rank(b.quality) - rank(a.quality));
      return list[0] || null;
    }

    async function resolveCineplayYoru(source) {
      // Native-only: ask our server (relay-backed). Never open Vidking/Cineplay embed.
      const meta = source?.meta || {};
      const mediaType = meta.mediaType || cfg.type || "movie";
      const tmdbId = meta.tmdbId || cfg.id;
      const season = meta.season || cfg.season || 1;
      const episode = meta.episode || cfg.episode || 1;
      setStatus(t('player.loading_cineplay', 'Loading Cineplay Yoru (4K)…'));
      const base = (window.APP && window.APP.baseUrl) || "";
      const qs = new URLSearchParams({
        type: mediaType === "tv" ? "tv" : "movie",
        tmdbId: String(tmdbId || ""),
        season: String(season || 1),
        episode: String(episode || 1),
      });
      const res = await fetch(`${base}/api/cineplay/yoru?${qs}`, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const json = await res.json();
      if (!json?.ok || !Array.isArray(json.sources) || !json.sources.length) {
        throw new Error(json?.error || "Yoru returned no streams");
      }
      const best = pickBestYoruSource(json.sources);
      if (!best?.url) throw new Error("Yoru returned no streams");
      // Server already mints media-proxy URLs for HLS.
      return {
        url: best.url,
        type: best.type || "hls",
        quality: best.quality || "4K",
        provider: "cineplay",
        providerName: source.providerName || best.providerName || "Cineplay",
        label: best.label || `Cineplay · Yoru · ${best.quality || "4K"}`,
        language: "en",
        meta: { ...(source.meta || {}), server: "Yoru", scraped: true },
      };
    }

    function candidateUrls(source) {
      // Only explicit same-quality mirrors (e.g. VAPlayer). Do NOT include Quality-tab variants.
      const out = [];
      const seen = new Set();
      const push = (u) => {
        const s = String(u || "").trim();
        if (!s || seen.has(s)) return;
        seen.add(s);
        out.push(s);
      };
      if (Array.isArray(source?.candidates)) {
        for (const c of source.candidates) {
          push(typeof c === "string" ? c : c?.url);
        }
      }
      if (!out.length) push(source?.url);
      return out;
    }

    /** Race VAPlayer (etc.) candidate playlists; keep the first that returns a body. */
    async function pickFastestCandidate(source) {
      const urls = candidateUrls(source);
      if (urls.length <= 1) return urls[0] || source?.url || "";
      setStatus("Picking fastest stream…");
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 4500);
      try {
        const winner = await Promise.any(
          urls.map(async (url) => {
            const abs = absoluteUrl(url);
            const res = await fetch(abs, {
              method: "GET",
              signal: ctrl.signal,
              credentials: "omit",
              mode: "cors",
              cache: "no-store",
              headers: { Accept: "application/vnd.apple.mpegurl,*/*;q=0.8" },
            });
            if (!res.ok) throw new Error("HTTP " + res.status);
            // m3u8 bodies are tiny — reading confirms the path works.
            const text = await res.text();
            if (!text || text.length < 8) throw new Error("empty");
            return url;
          })
        );
        try {
          ctrl.abort();
        } catch (_) {}
        return winner || urls[0];
      } catch (_) {
        return urls[0];
      } finally {
        clearTimeout(timer);
      }
    }

    async function playUrl(url, source) {
      const v = els.video;
      if (!url && !isCineplayYoruSource(url, source) && !candidateUrls(source).length) return;

      // Multi-CDN providers (VAPlayer): one Source entry, race fastest URL.
      if (!isCineplayYoruSource(url, source) && candidateUrls(source).length > 1) {
        const fastest = await pickFastestCandidate(source);
        if (fastest && fastest !== url) {
          url = fastest;
          if (source) source.url = fastest;
        }
      }

      // Cineplay → native HLS only (server relay + media-proxy). No third-party embed.
      if (isCineplayYoruSource(url, source)) {
        try {
          const scraped = await resolveCineplayYoru(source || {});
          if (state.sources[state.sourceIndex]) {
            state.sources[state.sourceIndex] = {
              ...state.sources[state.sourceIndex],
              ...scraped,
            };
            refreshMenus();
          }
          return playUrl(scraped.url, scraped);
        } catch (err) {
          setStatus(err?.message || "Cineplay Yoru failed");
          tryNextSource();
          return;
        }
      }

      // Never load Vidking/Cineplay embeds in our player shell.
      if (isIframeSource(url, source)) {
        setStatus("Embed sources disabled — trying next…");
        tryNextSource();
        return;
      }
      clearIframe();
      if (!v) return;
      destroyHls();
      paintCue("");
      setStatus(t('common.loading', 'Loading…'));
      showControls(false);
      const abs = absoluteUrl(url);
      const isHls =
        /\.m3u8(\?|$)/i.test(abs) ||
        /hls|m3u8/i.test(String(source?.type || source?.format || "hls"));

      const applyResumeIfNeeded = () => {
        if (state.resumeApplied) return true;
        const resumeAt = cwResumeTime(cfg);
        if (!(resumeAt > 0)) {
          state.resumeApplied = true;
          return true;
        }
        const d = Number(v.duration);
        // HLS often reports NaN/Infinity at MANIFEST_PARSED — wait for a real duration.
        if (!Number.isFinite(d) || d <= 0) return false;
        if (resumeAt >= d - 5) {
          state.resumeApplied = true;
          return true;
        }
        try {
          v.currentTime = resumeAt;
          state.resumeApplied = true;
          return true;
        } catch (_) {
          return false;
        }
      };

      const onReady = () => {
        // Manifest/metadata is NOT enough — Bingr often "ready" at 0:00/0:00.
        // Only disarm when media is actually playable; otherwise keep the watchdog.
        if (mediaLooksPlayable(els.video)) {
          clearLoadWatchdog();
          clearBackgroundSearchTimers(true);
        }
        setStatus("");
        if (!applyResumeIfNeeded()) {
          let poll = null;
          const stop = () => {
            if (poll) {
              clearInterval(poll);
              poll = null;
            }
            try { v.removeEventListener("durationchange", retry); } catch (_) {}
            try { v.removeEventListener("loadedmetadata", retry); } catch (_) {}
            try { v.removeEventListener("canplay", retry); } catch (_) {}
          };
          const retry = () => {
            if (applyResumeIfNeeded()) stop();
          };
          v.addEventListener("durationchange", retry);
          v.addEventListener("loadedmetadata", retry);
          v.addEventListener("canplay", retry);
          poll = setInterval(retry, 250);
          setTimeout(() => {
            stop();
            if (!state.resumeApplied) state.resumeApplied = true;
          }, 8000);
        }
        ensureParty();
        if (!state.autoplay) return;

        if (state._autoUnmuteCleanup) {
          try { state._autoUnmuteCleanup(); } catch (_) {}
          state._autoUnmuteCleanup = null;
        }

        const forceSound = () => {
          try {
            v.muted = false;
            if (!(v.volume > 0)) v.volume = 1;
          } catch (_) {}
          syncUi();
        };

        const tryPlay = async () => {
          forceSound();
          try {
            await unlockVideoElementForAutoplay(v);
          } catch (_) {}
          forceSound();

          try {
            await v.play();
            forceSound();
            setStatus("");
            // If a browser silently re-mutes, keep fighting it for a few seconds
            state._autoUnmuteCleanup = scheduleAutoplayUnmuteAttempts(
              v,
              () => {
                forceSound();
                state._autoUnmuteCleanup = null;
              },
              [50, 150, 350, 700, 1200, 2000, 3500],
              1
            );
            return;
          } catch (_) {
            // Browser blocked unmuted autoplay (common when Auto Play pref is already
            // on at page load — no fresh user gesture). Still start playback muted so
            // Auto Play feels like it works; first tap unmutes.
            try {
              v.muted = true;
              try { v.setAttribute("muted", ""); } catch (_) {}
              await v.play();
              setStatus(t("player.tap_for_sound", "Playing muted — tap for sound"));
              els.shell?.classList.add("is-playing");
              showControls(true);
              syncUi();
              // Do NOT schedule auto-unmute: browsers pause muted→unmuted without a gesture.
              // User tap (pointerdown unmuteIfNeeded / togglePlay) unlocks sound.
              return;
            } catch (_2) {
              forceSound();
              setStatus(t("player.tap_play", "Tap play to start"));
              els.shell?.classList.remove("is-playing");
              showControls(false);
            }
          }
        };
        tryPlay();
      };

      const clearWatchdogIfPlayable = () => {
        if (mediaLooksPlayable(els.video)) {
          clearLoadWatchdog();
          clearBackgroundSearchTimers(true);
        }
      };
      try {
        v.addEventListener("canplay", clearWatchdogIfPlayable);
        v.addEventListener("loadeddata", clearWatchdogIfPlayable);
        v.addEventListener("playing", clearWatchdogIfPlayable);
        v.addEventListener(
          "error",
          () => {
            if (!mediaLooksPlayable(v)) {
              markSourceDead("Stream error");
              setStatus("No stream — next source…");
              tryNextSource({ immediate: true });
            }
          },
          { once: true }
        );
      } catch (_) {}

      if (isHls && window.Hls && window.Hls.isSupported()) {
        const viaLangProxy = /\/api\/player\/(a-relay|lang-proxy)\b/i.test(String(abs || source?.url || ""));
        const viaHdgharCdn = /streamraiwind\.stream/i.test(String(abs || source?.url || ""));
        const fatHls = viaLangProxy || viaHdgharCdn;
        const hls = new window.Hls({
          // Worker XHR often ignores overrideMimeType — needed for HDGHAR .jpg→TS.
          enableWorker: !viaHdgharCdn,
          lowLatencyMode: false,
          backBufferLength: fatHls ? 60 : 30,
          maxBufferLength: fatHls ? 60 : 30,
          maxMaxBufferLength: fatHls ? 120 : 60,
          maxBufferSize: fatHls ? 120 * 1000 * 1000 : 60 * 1000 * 1000,
          maxBufferHole: fatHls ? 1.0 : 0.5,
          nudgeMaxRetry: fatHls ? 10 : 3,
          // Same-origin a-relay/v-relay need vf_ps. CDN is cross-origin.
          // streamraiwind serves MPEG-TS as image/jpeg — force TS MIME or demux fails.
          xhrSetup: (xhr, url) => {
            try {
              const u = String(url || "");
              if (/streamraiwind\.stream/i.test(u) && /\.(jpe?g|png|bin|ts|m4s)(\?|$)/i.test(u)) {
                try {
                  xhr.overrideMimeType("video/mp2t");
                } catch (_) {}
              }
              xhr.withCredentials = /\/api\/player\/(a-relay|v-relay|lang-proxy|media-proxy)\b/i.test(u);
            } catch (_) {}
          },
        });
        state.hls = hls;
        onSourceWillChange(); hls.loadSource(abs);
        hls.attachMedia(v);
        hls.on(window.Hls.Events.MANIFEST_PARSED, (_e, data) => {
          state.levels = data.levels || [];
          // Prefer explicit per-URL qualities (Cineplay) over in-manifest HLS ladders
          if (!applySourceQualities(source)) {
            state.qualityOptions = normalizeHlsLevels(state.levels);
            state.levelIndex = -1;
            hls.currentLevel = -1;
          }

          const hlsAudio = (hls.audioTracks || []).map((a, i) => {
            const track = {
              id: i,
              name: a.name || a.lang || `Audio ${i + 1}`,
              lang: a.lang || "",
              language: a.lang || "",
              switchable: true,
              from: "hls",
            };
            track.name = friendlyAudioName(track);
            return track;
          });
          // Prefer real HLS audio groups; keep source language pack URLs only when HLS has none
          state.audioTracks = hlsAudio.length ? hlsAudio : fallbackAudioTracks(source);
          const prefer = source?.preferAudio || source?.language || "en";
          const idx = preferEnglishAudioIndex(state.audioTracks, prefer);
          state.audioIndex = idx;
          if (hlsAudio.length) {
            try {
              hls.audioTrack = idx;
            } catch (_) {}
          }
          // Some CDNs populate/reset tracks after MANIFEST_PARSED — lock English again.
          try {
            hls.off(window.Hls.Events.AUDIO_TRACKS_UPDATED);
          } catch (_) {}
          hls.on(window.Hls.Events.AUDIO_TRACKS_UPDATED, () => {
            const tracks = (hls.audioTracks || []).map((a, i) => {
              const track = {
                id: i,
                name: a.name || a.lang || `Audio ${i + 1}`,
                lang: a.lang || "",
                language: a.lang || "",
                switchable: true,
                from: "hls",
              };
              track.name = friendlyAudioName(track);
              return track;
            });
            if (!tracks.length) return;
            state.audioTracks = tracks;
            const prefer2 = source?.preferAudio || source?.language || "en";
            const idx2 = preferEnglishAudioIndex(tracks, prefer2);
            state.audioIndex = idx2;
            try {
              hls.audioTrack = idx2;
            } catch (_) {}
            refreshMenus();
          });

          if (hls.subtitleTracks && hls.subtitleTracks.length) {
            const native = hls.subtitleTracks.map((t, i) => ({
              id: `hls-${i}`,
              name: t.name || t.lang || `Sub ${i + 1}`,
              lang: t.lang || "",
              from: "hls",
            }));
            // keep external list; prefer merging later
            state.hlsTextTracks = native;
          }
          refreshMenus();
          loadExternalSubtitles(state.payloadSubtitles, source);
          fetchExternalSubtitleCatalog();
          onReady();
        });
        // Give slow CDNs / media-proxy a longer runway before abandoning the source.
        // Manual re-click often worked because the second attempt was already warm.
        state.hlsFatalCount = 0;
        state.hlsLoadStartedAt = Date.now();
        armLoadWatchdog(source);
        hls.on(window.Hls.Events.ERROR, (_e, data) => {
          if (!data?.fatal) return;
          const ageMs = Date.now() - (state.hlsLoadStartedAt || Date.now());
          const graceMs = sourceAutoWaitMs(source);
          state.hlsFatalCount = (state.hlsFatalCount || 0) + 1;
          const count = state.hlsFatalCount;
          const details = String(data.details || "");
          const httpCode = Number(data.response?.code || data.response?.status || 0);
          const respText = String(data.response?.text || data.reason || "");
          // a-relay / v-relay binding 403 is transient (session race) — remint once, don't hard-fail.
          const bindingFail =
            httpCode === 403 && /proxy binding|invalid lang-proxy|forbidden/i.test(respText);
          if (bindingFail && !source._bindingReminted) {
            source._bindingReminted = true;
            clearLoadWatchdog();
            const slot = state.sources[state.sourceIndex];
            const pid = String(slot?.provider || "").toLowerCase();
            setStatus("Session refresh — retrying…");
            if (pid && slot) {
              slot.status = "pending";
              slot.url = "";
              delete state.providerLoads[pid];
              ensureProviderLoaded(state.sourceIndex, { play: true, isAuto: false, background: false })
                .catch(() => tryNextSource({ immediate: true }));
              return;
            }
          }
          // Hard-miss only for definitive CDN misses (404/410). Bare manifestLoadError is often
          // a transient proxy/session blip — do not stamp the Source row as Failed.
          const hardMiss =
            !bindingFail &&
            (httpCode === 404 ||
              httpCode === 410 ||
              ((httpCode === 403 || /not found|404/i.test(respText)) &&
                /manifestLoadError|levelLoadError|keyLoadError/i.test(details)));

          if (hardMiss) {
            clearLoadWatchdog();
            const slot = state.sources[state.sourceIndex];
            if (slot) {
              // Keep URL so the menu doesn't show "Failed — tap retry" for a play blip.
              if (slot.url || streamQualitiesFromSource(slot).length || candidateUrls(slot).length) {
                slot.status = "ready";
                slot.error = "Not found";
              } else {
                slot.status = "error";
                slot.error = "Not found";
              }
              try { refreshMenus(); } catch (_) {}
            }
            setStatus("Not found — next source…");
            tryNextSource({ immediate: true });
            return;
          }

          if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
            setStatus(t('player.network_retry', 'Network error — retrying…'));
            if (count <= 3 || ageMs < graceMs) {
              try {
                hls.startLoad();
              } catch (_) {}
              return;
            }
          } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
            setStatus("Media error — recovering…");
            if (count <= 3 || ageMs < graceMs) {
              try {
                hls.recoverMediaError();
              } catch (_) {}
              return;
            }
          } else if (count <= 2 || ageMs < graceMs) {
            setStatus(t('player.network_retry', 'Network error — retrying…'));
            try {
              hls.startLoad();
            } catch (_) {}
            return;
          }

          setStatus("Playback failed — next source…");
          tryNextSource();
        });
      } else if (v.canPlayType("application/vnd.apple.mpegurl") && isHls) {
        onSourceWillChange();
        v.src = abs;
        state.audioTracks = fallbackAudioTracks(source);
        state.audioIndex = preferEnglishAudioIndex(state.audioTracks, source?.preferAudio || source?.language || "en");
        state.qualityOptions = [];
        refreshMenus();
        loadExternalSubtitles(state.payloadSubtitles, source);
        fetchExternalSubtitleCatalog();
        armLoadWatchdog(source);
        v.addEventListener("loadedmetadata", onReady, { once: true });
      } else {
        onSourceWillChange();
        v.src = abs;
        state.audioTracks = fallbackAudioTracks(source);
        state.audioIndex = preferEnglishAudioIndex(state.audioTracks, source?.preferAudio || source?.language || "en");
        state.qualityOptions = [];
        refreshMenus();
        loadExternalSubtitles(state.payloadSubtitles, source);
        fetchExternalSubtitleCatalog();
        // MP4 / progressive: wait Auto wait seconds before abandoning
        const stallMs = sourceAutoWaitMs(source);
        armLoadWatchdog(source);
        const stallTimer = setTimeout(() => {
          if (!v || v.readyState >= 2) return;
          clearLoadWatchdog();
          markSourceDead("Stream stalled");
          setStatus("Stream stalled — next source…");
          tryNextSource({ immediate: true });
        }, stallMs);
        v.addEventListener(
          "loadedmetadata",
          () => {
            clearTimeout(stallTimer);
            onReady();
          },
          { once: true }
        );
        v.addEventListener(
          "error",
          () => {
            clearTimeout(stallTimer);
            setStatus("Playback failed — next source…");
            tryNextSource({ immediate: true });
          },
          { once: true }
        );
      }
    }

    function sourcesApiBase() {
      return cfg.sourcesApi || ((window.APP && window.APP.baseUrl) || "") + "/api/player/sources";
    }

    function providersApiBase() {
      return (
        cfg.providersApi ||
        String(sourcesApiBase()).replace(/\/sources\/?$/, "/providers") ||
        ((window.APP && window.APP.baseUrl) || "") + "/api/player/providers"
      );
    }

    function mediaQueryParams() {
      const q = new URLSearchParams({
        type: cfg.type || "movie",
        tmdbId: String(cfg.id || ""),
      });
      if (cfg.type === "tv") {
        q.set("season", String(cfg.season || 1));
        q.set("episode", String(cfg.episode || 1));
      }
      return q;
    }

    function placeholderSources(providers) {
      return (Array.isArray(providers) ? providers : []).map((p, i) => ({
        id: "slot-" + (p.id || i),
        provider: String(p.id || "").toLowerCase(),
        providerName: p.providerName || p.name || p.publicLabel || p.id,
        publicLabel: p.publicLabel || p.name || p.id,
        label: p.providerName || p.name || p.id,
        status: "pending",
        url: "",
        type: "hls",
        quality: "",
        candidates: [],
        qualities: [],
        audioTracks: [],
        autoLoad: p.autoLoad !== false,
        autoloadNonEnglish: !!p.autoloadNonEnglish,
        scrapeTimeoutSec: Math.max(15, Math.min(180, Number(p.scrapeTimeoutSec) || 45)),
        autoWaitSec: Math.max(3, Math.min(120, Number(p.autoWaitSec) || 15)),
        hasEnglish: null,
      }));
    }

    async function fetchProviderSourcesOnce(pid, slot) {
      const q = mediaQueryParams();
      q.set("provider", pid);
      // Honor Admin → Sources scrape timeout. VSEmbed empty probes usually finish in
      // ~3–7s on their own; do not artificially shorten below the admin value.
      let timeoutSec = Math.max(15, Math.min(180, Number(slot?.scrapeTimeoutSec) || 45));
      if (pid === "vsembed") timeoutSec = Math.max(18, Math.min(timeoutSec, 45));
      const timeoutMs = timeoutSec * 1000;
      const ctrl = new AbortController();
      const timer = setTimeout(() => {
        try { ctrl.abort(); } catch (_) {}
      }, timeoutMs);
      let res;
      try {
        res = await fetch(`${sourcesApiBase()}?${q.toString()}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          signal: ctrl.signal,
        });
      } catch (err) {
        clearTimeout(timer);
        if (err?.name === "AbortError") {
          throw new Error(`${slot.providerName || pid} timed out after ${Math.round(timeoutMs/1000)}s — raise Timeout in Admin → Sources`);
        }
        throw err;
      }
      clearTimeout(timer);
      let data = null;
      try {
        data = await res.json();
      } catch (_) {
        throw new Error(`Bad response from ${slot.providerName || pid} (${res.status || 0})`);
      }
      if (!data?.ok || !Array.isArray(data.sources) || !data.sources.length) {
        const diags = Array.isArray(data?.diagnostics) ? data.diagnostics : [];
        const emptyDiag = diags.find((d) => /_EMPTY\b|NO_STREAMS|UPSTREAM_DEAD|not found|stream_urls missing/i.test(String(d?.code || "") + " " + String(d?.message || "")));
        const softDiag = diags.find((d) => {
          const blob = String(d?.code || "") + " " + String(d?.message || "");
          // Rate limits / timeouts / upstream 5xx — retry this or a later round.
          if (/HTTP\s*429|HTTP\s*5\d\d|rate.?limit|timed?\s*out|upstream.?timeout|ECONN|abort|temporar(?:ily)? unavailable/i.test(blob)) {
            return true;
          }
          // PROVIDER_ERROR only counts as soft when the message looks transient.
          if (/PROVIDER_ERROR/i.test(blob) && /429|5\d\d|rate.?limit|timeout|ECONN|temporar|unavailable|fetch failed|network|worker/i.test(blob)) {
            return true;
          }
          return false;
        });
        const err = new Error(
          (emptyDiag && emptyDiag.message) || data?.error || `No streams from ${slot.providerName || pid}`
        );
        err.code = String((emptyDiag && emptyDiag.code) || (diags[0] && diags[0].code) || "PROVIDER_EMPTY");
        err.diagnostics = diags;
        // Hard empty (title not on provider) → skip this round (background may re-check later).
        // Soft failures stay retryable now + in background rounds.
        err.definitiveEmpty = !!(emptyDiag && !softDiag);
        err.retryable = !err.definitiveEmpty;
        throw err;
      }
      const matched = data.sources.filter((s) => String(s?.provider || "").toLowerCase() === pid);
      const pool = matched.length ? matched : data.sources;
      const found = pool[0];
      const keepName = slot.providerName;
      const keepPublic = slot.publicLabel;
      // Merge qualities + language packs from every returned stream into one Source card
      const qualities = [];
      const audioTracks = [];
      const seenQ = new Set();
      const seenA = new Set();
      pool.forEach((s) => {
        (Array.isArray(s.qualities) ? s.qualities : []).forEach((q) => {
          if (!q?.url) return;
          const k = String(q.quality || q.url);
          if (seenQ.has(k)) return;
          seenQ.add(k);
          qualities.push(q);
        });
        (Array.isArray(s.audioTracks) ? s.audioTracks : []).forEach((a) => {
          const label = friendlyAudioName(a);
          const k = String(a.switchUrl || a.url || label);
          if (seenA.has(k)) return;
          seenA.add(k);
          audioTracks.push({ ...a, name: label, label });
        });
        if ((!s.audioTracks || !s.audioTracks.length) && s.url && s.language) {
          const label = friendlyAudioName({ name: s.language, lang: s.language });
          const k = String(s.url);
          if (!seenA.has(k)) {
            seenA.add(k);
            audioTracks.push({
              id: "lang-" + label,
              name: label,
              label,
              language: s.language,
              lang: s.language,
              switchUrl: s.url,
              url: s.url,
            });
          }
        }
        if ((!s.qualities || !s.qualities.length) && s.url && pool.length > 1 && s.quality) {
          const k = String(s.quality);
          if (!seenQ.has(k)) {
            seenQ.add(k);
            qualities.push({ quality: s.quality, url: s.url, type: s.type || "hls" });
          }
        }
      });
      // Prefer English stream as primary URL
      let primary = found;
      const eng = pool.find((s) => isEnglishToken(s.language) || isEnglishToken(s.label) || s.hasEnglish === true);
      if (eng) primary = eng;
      // EN required should only skip when we KNOW there is no English.
      // Empty/unknown language (VSEmbed, OnlyFlix, etc.) must NOT count as non-English
      // or auto will skip the source in <1s without ever trying playback — Auto wait never runs.
      const langRaw = String(primary.language || "").trim();
      const detectedEn =
        primary.hasEnglish === true ||
        isEnglishToken(langRaw) ||
        audioTracks.some((a) => isEnglishToken(a.name) || isEnglishToken(a.lang) || isEnglishToken(a.language)) ||
        pool.some((s) => s.hasEnglish === true || isEnglishToken(s.language));
      const detectedNonEnOnly =
        primary.hasEnglish === false ||
        (langRaw !== "" && !isEnglishToken(langRaw) && !detectedEn);
      const hasEnglish = detectedEn || !detectedNonEnOnly;
      Object.assign(slot, primary, {
        provider: pid,
        providerName: primary.providerName || keepName,
        publicLabel: primary.publicLabel || keepPublic,
        status: "ready",
        qualities: qualities.length ? qualities : primary.qualities || [],
        audioTracks: audioTracks.length ? audioTracks : primary.audioTracks || [],
        language: hasEnglish ? (isEnglishToken(langRaw) || !langRaw ? "en" : langRaw) : (langRaw || ""),
        hasEnglish,
        preferAudio: "en",
        autoLoad: slot.autoLoad,
        autoloadNonEnglish: slot.autoloadNonEnglish,
        scrapeTimeoutSec: slot.scrapeTimeoutSec,
        autoWaitSec: slot.autoWaitSec,
      });
      if (Array.isArray(data.subtitles) && data.subtitles.length) {
        state.payloadSubtitles = normalizeSubtitleEntries(data.subtitles);
        if (!state.externalSubs.length) {
          loadExternalSubtitles(state.payloadSubtitles, state.sources[state.sourceIndex]);
        }
      }
      refreshMenus();
      return true;
    }

    /** Admin-ordered auto_load indices (manual-only skipped). */
    function autoLoadSourceIndices() {
      const out = [];
      for (let i = 0; i < state.sources.length; i++) {
        if (state.sources[i]?.autoLoad === false) continue;
        out.push(i);
      }
      return out;
    }

    /**
     * Cinemove-style race: scrape the first N auto sources in parallel and play
     * whichever returns a usable stream first. Remaining sources stay sequential.
     */
    async function raceAutoSources({ play = true, background = false, packSize = 3 } = {}) {
      const indices = autoLoadSourceIndices();
      if (!indices.length) {
        announceNoPlayableSources();
        return false;
      }
      const n = Math.max(1, Math.min(6, Number(packSize) || 3));
      const pack = indices.slice(0, n);
      state.raceWinnerIndex = -1;

      if (!background || !state.cascadeExhausted) {
        const names = pack
          .map((i) => state.sources[i]?.providerName || state.sources[i]?.provider || "?")
          .join(", ");
        setStatus(`Racing ${names}…`);
      }

      await Promise.all(
        pack.map(async (idx) => {
          let ok = false;
          try {
            ok = await ensureProviderLoaded(idx, {
              play: false,
              isAuto: false,
              background,
              claimIndex: false,
            });
          } catch (_) {
            ok = false;
          }
          if (!ok) return;
          const slot = state.sources[idx];
          if (!slot) return;
          if (slot.hasEnglish === false && !slot.autoloadNonEnglish) return;
          if (!(slot.url || streamQualitiesFromSource(slot).length || candidateUrls(slot).length)) return;
          // First usable hit wins (JS is single-threaded between awaits).
          if (state.raceWinnerIndex >= 0) return;
          state.raceWinnerIndex = idx;
          if (background && state.cascadeExhausted) {
            state.cascadeExhausted = false;
            clearBackgroundSearchTimers(false);
          }
          if (play) startPlaybackForIndex(idx);
        })
      );

      if (state.raceWinnerIndex >= 0) return true;

      // Pack missed — continue sequential cascade after the raced block.
      const after = Math.max(...pack) + 1;
      for (let i = after; i < state.sources.length; i++) {
        if (state.sources[i]?.autoLoad === false) continue;
        return ensureProviderLoaded(i, { play: true, isAuto: true, background });
      }
      announceNoPlayableSources();
      return false;
    }

    async function ensureProviderLoaded(index, { play = false, isAuto = false, background = false, claimIndex = true } = {}) {
      if (!state.sources.length) return false;
      const idx = Math.max(0, Math.min(index, state.sources.length - 1));
      const slot = state.sources[idx];
      if (!slot) return false;
      if (claimIndex !== false) state.sourceIndex = idx;

      // Auto-play cascade: skip providers marked manual-only
      if (isAuto && slot.autoLoad === false) {
        const next = idx + 1;
        if (next < state.sources.length) {
          return ensureProviderLoaded(next, { play: true, isAuto: true, background });
        }
        announceNoPlayableSources();
        return false;
      }

      if (slot.status === "ready" && (slot.url || streamQualitiesFromSource(slot).length || candidateUrls(slot).length)) {
        if (isAuto && slot.hasEnglish === false && !slot.autoloadNonEnglish) {
          const next = idx + 1;
          if (next < state.sources.length) {
            setStatus(`${slot.providerName || slot.provider} has no English — skipping…`);
            return ensureProviderLoaded(next, { play: true, isAuto: true, background });
          }
          announceNoPlayableSources();
          return false;
        }
        if (play) startPlaybackForIndex(idx);
        return true;
      }

      const pid = String(slot.provider || "").toLowerCase();
      if (!pid) return false;

      // Deduplicate in-flight scrapes for the same provider.
      if (state.providerLoads[pid]) {
        try {
          await state.providerLoads[pid];
        } catch (_) {}
        if (play && state.sources[idx]?.status === "ready") startPlaybackForIndex(idx);
        return state.sources[idx]?.status === "ready";
      }

      slot.status = "loading";
      refreshMenus();
      if (!background || !state.cascadeExhausted) {
        setStatus(`Fetching ${slot.providerName || pid}…`);
      }

      const slowProviders = new Set([
        "flixhqz",
        "cineplay",
        "vidmoly",
        "hdghar",
        "moonflix",
        "megasource",
        "opstream",
        "bingr",
        "castle",
        "awsind",
        "huhu",
        "vsembed",
        "moviebox",
        "onlyflix",
        "filesun",
      ]);
      // Retry flaky network/timeouts. Hard empty still stops early (below).
      const maxAttempts = (slowProviders.has(pid) || (Number(slot.scrapeTimeoutSec) || 0) >= 60) ? 2 : 1;

      const isDefinitiveEmpty = (err) => {
        if (err && err.retryable) return false;
        if (err && err.definitiveEmpty === true) return true;
        if (err && err.definitiveEmpty === false) return false;
        const msg = String(err?.message || "");
        const code = String(err?.code || "");
        const diags = Array.isArray(err?.diagnostics) ? err.diagnostics : [];
        const diagBlob = diags.map((d) => String(d?.code || "") + " " + String(d?.message || "")).join(" ");
        const blob = `${msg} ${code} ${diagBlob}`;
        // Transient only — do not treat every PROVIDER_ERROR as soft (VSEmbed stream_urls missing is hard).
        if (/HTTP\s*429|HTTP\s*5\d\d|rate.?limit|timed?\s*out|upstream.?timeout|ECONN|abort|Bad response/i.test(blob)) {
          return false;
        }
        if (/PROVIDER_ERROR/i.test(blob) && /429|5\d\d|rate.?limit|timeout|ECONN|temporar|unavailable|fetch failed|network|worker/i.test(blob) && !/_EMPTY\b|stream_urls missing|not found/i.test(blob)) {
          return false;
        }
        return /_EMPTY\b|UPSTREAM_DEAD|no source|not available|empty|no playable|no streams|not found|No playable sources|HDGHAR_EMPTY|no hdghar|no holly|stream_urls missing/i.test(blob);
      };

      const run = (async () => {
        let lastErr = null;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
          try {
            if (attempt > 1) {
              if (!background || !state.cascadeExhausted) {
                setStatus(`Retrying ${slot.providerName || pid}…`);
              }
              await new Promise((r) => setTimeout(r, 1600));
            }
            return await fetchProviderSourcesOnce(pid, slot);
          } catch (err) {
            lastErr = err;
            // Not found / empty from provider = final answer for this round.
            if (isDefinitiveEmpty(err)) break;
          }
        }
        throw lastErr || new Error(`No streams from ${slot.providerName || pid}`);
      })();

      state.providerLoads[pid] = run;
      try {
        await run;
        // Huhu stream endpoint is cold on first hit — prefetch EN URL while UI mounts.
        try {
          if (String(slot.provider || "").toLowerCase() === "huhu") {
            let warmUrl = "";
            const tracks = Array.isArray(slot.audioTracks) ? slot.audioTracks : [];
            const en = tracks.find((a) => /^(en)\b/i.test(String(a?.lang || a?.language || a?.name || "")));
            warmUrl = (en && (en.switchUrl || en.url)) || slot.url || "";
            if (warmUrl) {
              const absWarm = absoluteUrl(warmUrl);
              fetch(absWarm, {
                method: "GET",
                credentials: "omit",
                mode: "cors",
                cache: "no-store",
                headers: { Accept: "application/vnd.apple.mpegurl,*/*;q=0.8" },
              }).catch(() => {});
            }
          }
        } catch (_) {}
        if (isAuto && slot.hasEnglish === false && !slot.autoloadNonEnglish) {
          slot.status = "ready";
          const next = idx + 1;
          if (next < state.sources.length) {
            setStatus(`${slot.providerName || pid} has no English — skipping…`);
            return ensureProviderLoaded(next, { play: true, isAuto: true, background });
          }
          announceNoPlayableSources();
          return false;
        }
        // Found a candidate during background search — take over UI.
        if (background && state.cascadeExhausted) {
          state.cascadeExhausted = false;
          clearBackgroundSearchTimers(false);
        }
        if (play) startPlaybackForIndex(idx);
        return true;
      } catch (err) {
        const emptyish = !!(err && err.definitiveEmpty) || isDefinitiveEmpty(err);
        // Soft blips (timeout / binding / network) must NOT show "Failed — tap retry"
        // for Moonflix/HDGHAR — that label made it look like the scrape was dead.
        const softPid = pid === "moonflix" || pid === "hdghar" || pid === "bingr";
        if (!emptyish && softPid) {
          slot.status = "pending";
          slot.error = err?.message || "";
          slot.retryable = true;
        } else {
          slot.status = emptyish ? "empty" : "error";
          slot.error = err?.message || t('common.failed', 'Failed');
          slot.retryable = !emptyish || !!(err && err.retryable);
        }
        refreshMenus();
        if (isAuto || play) {
          // Auto / failure path: try next admin-ordered source.
          const next = idx + 1;
          if (next < state.sources.length) {
            if (!background || !state.cascadeExhausted) {
              setStatus(`${slot.providerName || pid} ${emptyish ? "not found" : "failed"} — next…`);
            }
            return ensureProviderLoaded(next, { play: true, isAuto: true, background });
          }
          announceNoPlayableSources(emptyish ? t('player.no_sources', 'No playable sources') : (err?.message || t('player.no_sources', 'No playable sources')));
        } else {
          setStatus(emptyish ? (err?.message || "Not found for this title") : (err?.message || "Source failed"));
        }
        return false;
      } finally {
        delete state.providerLoads[pid];
      }
    }

    function startPlaybackForIndex(index) {
      if (!state.sources.length) return;
      state.sourceIndex = Math.max(0, Math.min(index, state.sources.length - 1));
      state.sourcePlayStartedAt = Date.now();
      const src = state.sources[state.sourceIndex];
      if (!src?.url && !streamQualitiesFromSource(src).length && !candidateUrls(src).length) {
        setStatus("Source missing URL");
        return;
      }
      // Language packs: pick English switchUrl as primary before play
      const pack = Array.isArray(src.audioTracks) ? src.audioTracks : [];
      if (pack.length) {
        const ei = preferEnglishAudioIndex(pack, src.preferAudio || src.language || "en");
        const pick = pack[ei];
        if (pick?.switchUrl || pick?.url) {
          src.url = pick.switchUrl || pick.url;
          src.language = isEnglishToken(pick.lang || pick.language || pick.name) ? "en" : (pick.lang || src.language);
        }
        state.audioTracks = fallbackAudioTracks(src);
        state.audioIndex = preferEnglishAudioIndex(state.audioTracks, "en");
      }
      if (applySourceQualities(src)) {
        // levelIndex already set to default (1080p)
      } else {
        state.levelIndex = -1;
        state.qualityOptions = [];
      }
      const url = src.url || candidateUrls(src)[0] || "";
      if (!url && !isCineplayYoruSource(url, src)) {
        setStatus("Source missing URL");
        return;
      }
      refreshMenus();
      playUrl(url, src);
    }

    function loadSource(index) {
      onSourceWillChange();
      state.manualSourcePick = true;
      clearBackgroundSearchTimers(true);
      // Selecting a source scrapes that provider on demand (not all providers).
      ensureProviderLoaded(index, { play: true, isAuto: false });
    }

    function sourceAutoWaitMs(source) {
      const sec = Number(source?.autoWaitSec);
      if (Number.isFinite(sec) && sec > 0) return Math.max(3000, Math.min(120000, sec * 1000));
      // Fallback from current slot
      const slot = state.sources[state.sourceIndex];
      const s2 = Number(slot?.autoWaitSec);
      if (Number.isFinite(s2) && s2 > 0) return Math.max(3000, Math.min(120000, s2 * 1000));
      return 15000;
    }

    function mediaLooksPlayable(v) {
      if (!v || v.error) return false;
      // HAVE_FUTURE_DATA / HAVE_ENOUGH_DATA — clearly playable
      if (v.readyState >= 3) return true;
      if (v.readyState < 2) return false;
      // HAVE_CURRENT_DATA: accept live/HLS (Infinity duration), advancing playback, or known VOD length
      if (!v.paused && v.currentTime > 0.05) return true;
      if (v.currentTime > 0.25) return true;
      const d = Number(v.duration);
      if (Number.isFinite(d) && d > 0) return true;
      if (d === Infinity) return true;
      return false;
    }

    function clearLoadWatchdog() {
      if (state.loadWatchdog) {
        try { clearTimeout(state.loadWatchdog); } catch (_) {}
        state.loadWatchdog = null;
      }
      if (state.loadWatchdogPoll) {
        try { clearInterval(state.loadWatchdogPoll); } catch (_) {}
        state.loadWatchdogPoll = null;
      }
    }

    function clearBackgroundSearchTimers(resetRound) {
      if (state.bgSearchTimer) {
        try { clearTimeout(state.bgSearchTimer); } catch (_) {}
        state.bgSearchTimer = null;
      }
      state.bgSearchActive = false;
      if (resetRound) {
        state.bgSearchRound = 0;
        state.cascadeExhausted = false;
      }
    }

    function resetSlotsForBackgroundRescan() {
      state.sources.forEach((slot) => {
        if (!slot) return;
        if (slot.status === "empty" || slot.status === "error" || slot.status === "pending") {
          slot.status = "pending";
          slot.url = "";
          slot.error = "";
          slot.candidates = [];
          slot.qualities = [];
          slot.audioTracks = [];
          slot.hasEnglish = null;
          delete slot.retryable;
        }
      });
      state.providerLoads = Object.create(null);
    }

    /** First full miss: show "No playable sources", then keep re-checking the list in background. */
    function announceNoPlayableSources(msg) {
      const text = msg || t("player.no_sources", "No playable sources");
      if (mediaLooksPlayable(els.video)) return;
      state.cascadeExhausted = true;
      setStatus(text);
      scheduleBackgroundSourceSearch();
    }

    function scheduleBackgroundSourceSearch() {
      if (state.manualSourcePick && mediaLooksPlayable(els.video)) return;
      if (mediaLooksPlayable(els.video)) {
        clearBackgroundSearchTimers(true);
        return;
      }
      if (state.bgSearchActive) return;
      if (state.bgSearchTimer) return;
      const round = Number(state.bgSearchRound) || 0;
      if (round >= 8) return;
      // 6s, 13s, 20s… capped at 45s between rounds
      const delay = Math.min(45000, 6000 + round * 7000);
      state.bgSearchTimer = setTimeout(() => {
        state.bgSearchTimer = null;
        void runBackgroundSourceSearch();
      }, delay);
    }

    async function runBackgroundSourceSearch() {
      if (mediaLooksPlayable(els.video)) {
        clearBackgroundSearchTimers(true);
        return;
      }
      if (state.bgSearchActive) return;
      if (!state.sources.length) return;
      state.bgSearchActive = true;
      state.bgSearchRound = (Number(state.bgSearchRound) || 0) + 1;
      const round = state.bgSearchRound;
      state.cascadeExhausted = true;
      setStatus(
        t("player.no_sources", "No playable sources") +
          ` — searching… (${round})`
      );
      resetSlotsForBackgroundRescan();
      refreshMenus();
      try {
        await raceAutoSources({ play: true, background: true, packSize: 3 });
      } catch (_) {
        /* announce path handles exhaustion */
      } finally {
        state.bgSearchActive = false;
        if (!mediaLooksPlayable(els.video) && state.cascadeExhausted) {
          setStatus(t("player.no_sources", "No playable sources"));
          scheduleBackgroundSourceSearch();
        }
      }
    }

    function markSourceDead(reason) {
      const slot = state.sources[state.sourceIndex];
      if (slot) {
        // Scraped URL still usable — don't show "Failed — tap retry" (that means scrape miss).
        // Keep ready so the user can re-tap; cascade still moves Auto-play along.
        if (slot.url || streamQualitiesFromSource(slot).length || candidateUrls(slot).length) {
          slot.status = "ready";
          slot.error = reason || "Stream not loading";
        } else {
          slot.status = "error";
          slot.error = reason || "Stream not loading";
          slot.retryable = true;
        }
        try { refreshMenus(); } catch (_) {}
      }
    }

    /** If a "ready" source never becomes playable (hung proxy / dead CDN), cascade. */
    function armLoadWatchdog(source) {
      clearLoadWatchdog();
      const pid = String(source?.provider || "").toLowerCase();
      const abs = absoluteUrl(source?.url || "");
      // Huhu /api/huhu/stream is often 20–40s cold, ~3–5s warm. Do NOT use the short
      // Bingr-style watchdog or the first click looks "empty" and the second works.
      const coldResolve =
        pid === "huhu" ||
        /\/api\/huhu\/stream\b/i.test(abs) ||
        /\/api\/huhu\//i.test(abs);
      const base = sourceAutoWaitMs(source);
      const scrapeMs = Math.max(15, Math.min(180, Number(source?.scrapeTimeoutSec) || 45)) * 1000;
      const viaProxy = /\/api\/player\/(v-relay|a-relay|media-proxy|lang-proxy)\b/i.test(abs);
      const fatProxy =
        pid === "moonflix" ||
        pid === "hdghar" ||
        pid === "bingr" ||
        /\/api\/player\/(a-relay|lang-proxy)\b/i.test(abs);
      // Honor Admin auto_wait_sec. Previous 6–12s clamp skipped real HLS (VSEmbed/MovieBox)
      // before the CDN had a chance — second click then "worked" because it was warm.
      let waitMs;
      if (coldResolve) {
        waitMs = Math.max(45000, Math.min(90000, Math.max(base, scrapeMs)));
      } else if (fatProxy) {
        // HDGHAR demuxed 1080p first segment can be multi‑MB through a-relay.
        waitMs = Math.max(35000, Math.min(90000, Math.max(base, 45000)));
      } else if (viaProxy || pid === "vsembed" || pid === "onlyflix" || pid === "moviebox") {
        waitMs = Math.max(12000, Math.min(60000, Math.max(base, 15000)));
      } else {
        waitMs = Math.max(8000, Math.min(45000, base));
      }
      const token = (state.loadWatchdogToken = (state.loadWatchdogToken || 0) + 1);
      const idxAtArm = state.sourceIndex;
      if (coldResolve) {
        setStatus(t("player.resolving_huhu", "Resolving Huhu… first load can take a while"));
      }
      const fail = (why) => {
        if (state.loadWatchdogToken !== token) return;
        if (state.sourceIndex !== idxAtArm) return;
        if (mediaLooksPlayable(els.video)) return;
        clearLoadWatchdog();
        markSourceDead(why || "Stream not loading");
        setStatus("No stream — next source…");
        tryNextSource({ immediate: true });
      };
      state.loadWatchdog = setTimeout(() => fail("Stream not loading"), waitMs);
      // Also poll: if we somehow get duration/readyState, clear; if video.error, fail fast.
      state.loadWatchdogPoll = setInterval(() => {
        if (state.loadWatchdogToken !== token) return;
        if (state.sourceIndex !== idxAtArm) return;
        const v = els.video;
        if (mediaLooksPlayable(v)) {
          clearLoadWatchdog();
          clearBackgroundSearchTimers(true);
          return;
        }
        if (v && v.error) {
          fail("Stream error");
        }
      }, 500);
    }

    function tryNextSource(opts) {
      opts = opts || {};
      const slot = state.sources[state.sourceIndex];
      const waitMs = sourceAutoWaitMs(slot);
      const started = state.sourcePlayStartedAt || 0;
      const elapsed = started ? (Date.now() - started) : waitMs;
      // Hard miss / empty: skip Auto-wait and cascade immediately.
      const skipWait = !!opts.immediate || slot?.status === "empty";
      // If playback was attempted, respect Auto wait before cascading (transient errors only).
      if (!skipWait && started && elapsed < waitMs) {
        const left = Math.max(250, waitMs - elapsed);
        setStatus(`Waiting on ${slot?.providerName || slot?.provider || 'source'}… ${Math.ceil(left/1000)}s`);
        clearTimeout(state.autoWaitTimer);
        state.autoWaitTimer = setTimeout(() => {
          // Re-check: if still on same source and not playing, move on.
          const v = els.video;
          if (mediaLooksPlayable(v)) {
            clearBackgroundSearchTimers(true);
            return;
          }
          if (state.sourceIndex + 1 < state.sources.length) {
            ensureProviderLoaded(state.sourceIndex + 1, { play: true, isAuto: true });
          } else announceNoPlayableSources();
        }, left);
        return;
      }
      if (state.sourceIndex + 1 < state.sources.length) {
        ensureProviderLoaded(state.sourceIndex + 1, { play: true, isAuto: true });
      } else announceNoPlayableSources();
    }

    async function fetchSources() {
      setStatus(t('player.loading_sources', 'Loading sources…'));
      state.autoPlayStarted = false;
      state.providerLoads = Object.create(null);
      state.manualSourcePick = false;
      clearBackgroundSearchTimers(true);

      // Player session cookie required by ProxyGuard before /sources and relays.
      try {
        const sessionApi = String(sourcesApiBase()).replace(/\/sources\/?$/, "/session");
        await fetch(sessionApi, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          cache: "no-store",
        });
      } catch (_) {}

      // Show Source slots immediately from page config (no network wait).
      let providers = Array.isArray(cfg.providers) ? cfg.providers : [];
      if (providers.length) {
        state.sources = placeholderSources(providers);
        state.sourceIndex = 0;
        refreshMenus();
      }

      try {
        // Refresh order/labels from API when available (does not clear the list on failure).
        try {
          const pres = await fetch(providersApiBase(), {
            headers: { Accept: "application/json" },
            credentials: "same-origin",
          });
          if (pres.ok) {
            const pdata = await pres.json();
            if (Array.isArray(pdata?.providers) && pdata.providers.length) {
              providers = pdata.providers;
              // Keep already-loaded slots; rebuild only if still placeholders / empty.
              const anyReady = state.sources.some((s) => s && s.status === "ready" && s.url);
              if (!anyReady) {
                state.sources = placeholderSources(providers);
                state.sourceIndex = 0;
                refreshMenus();
              }
            }
          }
        } catch (_) {
          /* keep seeded placeholders */
        }

        if (!state.sources.length) {
          if (!providers.length) {
            setStatus(t('player.no_sources_enabled', 'No sources enabled'));
            refreshMenus();
            return;
          }
          state.sources = placeholderSources(providers);
          state.sourceIndex = 0;
          refreshMenus();
        }

        // Automatic: race first 3 auto_load providers (Cinemove-style), then cascade.
        state.autoPlayStarted = true;
        await raceAutoSources({ play: true, background: false, packSize: 3 });
      } catch (err) {
        setStatus(err.message || t('player.sources_failed', 'Failed to load sources'));
      } finally {
        refreshMenus();
      }
    }

    function goNextEpisode() {
      if (cfg.type !== "tv") return;
      if (cfg.next && cfg.next.url) {
        location.href = cfg.next.url;
        return;
      }
      const next = (Number(cfg.episode) || 1) + 1;
      const base = cfg.watchUrl || location.pathname;
      location.href = `${base}${base.includes("?") ? "&" : "?"}s=${cfg.season || 1}&e=${next}&play=1`;
    }

    function togglePlay() {
      const v = els.video;
      if (!v) return;
      notePlaybackUserGesture();
      // While autoplay is muted, first tap should unmute — not pause.
      if (!v.paused && (v.muted || v.volume === 0)) {
        v.muted = false;
        try { v.removeAttribute("muted"); } catch (_) {}
        if (!(v.volume > 0)) v.volume = 1;
        v.play()
          .then(() => {
            if (v.paused) {
              v.muted = true;
              v.volume = 0;
              try { v.setAttribute("muted", ""); } catch (_) {}
              v.play().catch(() => {});
              setStatus(t("player.tap_for_sound", "Playing muted — tap for sound"));
            } else {
              setStatus("");
            }
            syncUi();
          })
          .catch(() => {
            v.muted = true;
            v.volume = 0;
            setStatus(t("player.tap_for_sound", "Playing muted — tap for sound"));
            syncUi();
          });
        showControls();
        return;
      }
      if (v.paused) {
        v.muted = false;
        if (!(v.volume > 0)) v.volume = 1;
        v.play().catch(() => {});
      } else {
        v.pause();
      }
      syncUi();
      showControls();
    }

    function isPlayerFullscreen() {
      const fsEl = document.fullscreenElement || document.webkitFullscreenElement || null;
      return !!(fsEl && els.shell && (fsEl === els.shell || els.shell.contains(fsEl)));
    }

    function syncFullscreenClass() {
      const on = isPlayerFullscreen();
      els.shell?.classList.toggle("is-fullscreen", on);
    }

    function openSubsDrawer() {
      hideSourceHint();
      closePanel(true);
      if (els.subsDrawer) els.subsDrawer.hidden = false;
      if (els.scrim) els.scrim.hidden = false;
      els.shell?.classList.add("np-subs-open");
      try { fetchExternalSubtitleCatalog(); } catch (_) {}
      refreshMenus();
      showControls(false);
    }

    function closeSubsDrawer() {
      if (els.subsDrawer) els.subsDrawer.hidden = true;
      els.shell?.classList.remove("np-subs-open");
      if (!els.shell?.classList.contains("np-settings-open") && els.scrim) {
        els.scrim.hidden = true;
      }
    }

    function openPanel(tab) {
      hideSourceHint();
      closeSubsDrawer();
      if (els.panel) els.panel.hidden = false;
      if (els.scrim) els.scrim.hidden = false;
      els.shell?.classList.add("np-settings-open");
      if (tab) switchTab(tab);
      showControls(false);
    }

    function closePanel(keepScrim) {
      if (els.panel) els.panel.hidden = true;
      els.shell?.classList.remove("np-settings-open");
      if (!keepScrim && !els.shell?.classList.contains("np-subs-open") && els.scrim) {
        els.scrim.hidden = true;
      }
    }

    function switchTab(tab) {
      if (!els.panel) return;
      els.panel.querySelectorAll("[data-tab]").forEach((b) => {
        b.classList.toggle("is-active", b.getAttribute("data-tab") === tab);
      });
      els.panel.querySelectorAll("[data-panel]").forEach((p) => {
        p.classList.toggle("is-active", p.getAttribute("data-panel") === tab);
      });
    }

    
    function hideSourceHint() {
      if (state.sourceHintTimer) {
        clearTimeout(state.sourceHintTimer);
        state.sourceHintTimer = null;
      }
      if (els.sourceHint) els.sourceHint.hidden = true;
    }

    function armSourceHint(delayMs) {
      if (state.sourceHintDismissed) return;
      if (els.shell?.classList.contains("np-settings-open")) return;
      if (!els.sourceHint) return;
      if (state.sourceHintTimer) clearTimeout(state.sourceHintTimer);
      state.sourceHintTimer = setTimeout(() => {
        state.sourceHintTimer = null;
        if (state.sourceHintDismissed) return;
        if (els.shell?.classList.contains("np-settings-open")) return;
        // Only show while video is still struggling (not advancing well / waiting)
        const v = els.video;
        if (!v) return;
        if (v.readyState >= 3 && !v.paused && !v.seeking) return;
        els.sourceHint.hidden = false;
      }, delayMs || 8000);
    }


    const VIDEO_ZOOM_MIN = 1;
    const VIDEO_ZOOM_MAX = 5;
    const VIDEO_PAN_THRESHOLD_PX = 8;
    const VIDEO_DOUBLE_TAP_MS = 300;
    const VIDEO_DOUBLE_TAP_DISTANCE_PX = 40;

    function clampVideoScale(scale) {
      return Math.max(VIDEO_ZOOM_MIN, Math.min(VIDEO_ZOOM_MAX, scale));
    }

    function getTouchDistance(a, b) {
      if (!a || !b) return 0;
      const dx = a.clientX - b.clientX;
      const dy = a.clientY - b.clientY;
      return Math.hypot(dx, dy);
    }

    function readSafeAreaInsets() {
      const cs = getComputedStyle(document.documentElement);
      const num = (v) => {
        const n = parseFloat(v || "0");
        return Number.isFinite(n) ? n : 0;
      };
      return {
        top: num(cs.getPropertyValue("--sat") || cs.getPropertyValue("env(safe-area-inset-top)")) || 0,
        right: num(cs.getPropertyValue("--sar") || cs.getPropertyValue("env(safe-area-inset-right)")) || 0,
        bottom: num(cs.getPropertyValue("--sab") || cs.getPropertyValue("env(safe-area-inset-bottom)")) || 0,
        left: num(cs.getPropertyValue("--sal") || cs.getPropertyValue("env(safe-area-inset-left)")) || 0,
      };
    }

    function measureSafeAreaInsets() {
      // Prefer live env() via a probe element (getPropertyValue("env(...)") is unreliable).
      let probe = document.getElementById("np-safe-area-probe");
      if (!probe) {
        probe = document.createElement("div");
        probe.id = "np-safe-area-probe";
        probe.setAttribute("aria-hidden", "true");
        probe.style.cssText =
          "position:absolute;visibility:hidden;pointer-events:none;padding:env(safe-area-inset-top,0px) env(safe-area-inset-right,0px) env(safe-area-inset-bottom,0px) env(safe-area-inset-left,0px);";
        document.body.appendChild(probe);
      }
      const cs = getComputedStyle(probe);
      const num = (v) => {
        const n = parseFloat(v || "0");
        return Number.isFinite(n) ? n : 0;
      };
      return {
        top: num(cs.paddingTop),
        right: num(cs.paddingRight),
        bottom: num(cs.paddingBottom),
        left: num(cs.paddingLeft),
      };
    }

    function clampVideoTranslate(x, y, scale, containerWidth, containerHeight, safe) {
      if (scale <= VIDEO_ZOOM_MIN) return { x: 0, y: 0 };
      const bleedX = (safe?.left || 0) + (safe?.right || 0);
      const bleedY = (safe?.top || 0) + (safe?.bottom || 0);
      const maxX = (containerWidth * (scale - 1)) / 2 + bleedX;
      const maxY = (containerHeight * (scale - 1)) / 2 + bleedY;
      return {
        x: Math.max(-maxX, Math.min(maxX, x)),
        y: Math.max(-maxY, Math.min(maxY, y)),
      };
    }

    function applyVideoTransform(scale, x, y) {
      const el = els.videoTransform || els.video;
      const container = els.stage || els.shell;
      if (!el) return;
      const nextScale = clampVideoScale(scale);
      let nextX = x;
      let nextY = y;
      if (nextScale <= VIDEO_ZOOM_MIN) {
        nextX = 0;
        nextY = 0;
      } else if (container) {
        const bounds = container.getBoundingClientRect();
        const safe = measureSafeAreaInsets();
        const clamped = clampVideoTranslate(
          x,
          y,
          nextScale,
          bounds.width,
          bounds.height,
          safe
        );
        nextX = clamped.x;
        nextY = clamped.y;
      }
      state.videoScale = nextScale;
      state.videoTranslateX = nextX;
      state.videoTranslateY = nextY;
      el.style.transform = `translate(${nextX}px, ${nextY}px) scale(${nextScale})`;
      el.style.touchAction = state.zoomGestureActive ? "none" : "manipulation";
      if (nextScale > VIDEO_ZOOM_MIN) {
        els.shell?.classList.add("np-zoomed");
      } else {
        els.shell?.classList.remove("np-zoomed");
      }
    }

    function resetVideoTransform() {
      state.zoomGesture.mode = "idle";
      state.zoomGesture.moved = false;
      state.zoomGestureActive = false;
      applyVideoTransform(VIDEO_ZOOM_MIN, 0, 0);
    }

    
    function onSourceWillChange() {
      clearLoadWatchdog();
      try { resetVideoTransform(); } catch (_) {}
    }

    function bindPinchZoom() {
      // Older static markup may lack the transform wrapper — create it once.
      if (!els.videoTransform && els.video && els.video.parentElement) {
        const wrap = document.createElement("div");
        wrap.className = "np-video-transform";
        wrap.id = "np-video-transform";
        els.video.parentElement.insertBefore(wrap, els.video);
        wrap.appendChild(els.video);
        els.videoTransform = wrap;
      }
      const el = els.videoTransform || els.video;
      if (!el || el.dataset.npPinchBound === "1") return;
      el.dataset.npPinchBound = "1";

      const finishGesture = () => {
        const gesture = state.zoomGesture;
        if (gesture.mode === "idle") return;
        if (gesture.moved) state.suppressVideoTap = true;
        gesture.mode = "idle";
        gesture.moved = false;
        state.zoomGestureActive = false;
        el.style.touchAction = "manipulation";
      };

      el.addEventListener(
        "touchstart",
        (event) => {
          if (event.touches.length === 2) {
            const distance = getTouchDistance(event.touches[0], event.touches[1]);
            if (distance <= 0) return;
            state.zoomGesture = {
              mode: "pinch",
              startDistance: distance,
              startScale: state.videoScale || VIDEO_ZOOM_MIN,
              startTranslateX: state.videoTranslateX || 0,
              startTranslateY: state.videoTranslateY || 0,
              lastPanX: 0,
              lastPanY: 0,
              moved: false,
            };
            state.zoomGestureActive = true;
            el.style.touchAction = "none";
            return;
          }

          if (event.touches.length === 1 && (state.videoScale || 1) > VIDEO_ZOOM_MIN) {
            const touch = event.touches[0];
            state.zoomGesture = {
              mode: "pan",
              startDistance: 0,
              startScale: state.videoScale || VIDEO_ZOOM_MIN,
              startTranslateX: state.videoTranslateX || 0,
              startTranslateY: state.videoTranslateY || 0,
              lastPanX: touch.clientX,
              lastPanY: touch.clientY,
              moved: false,
            };
            state.zoomGestureActive = true;
            el.style.touchAction = "none";
          }
        },
        { passive: true }
      );

      el.addEventListener(
        "touchmove",
        (event) => {
          const gesture = state.zoomGesture;
          if (!gesture || gesture.mode === "idle") return;
          event.preventDefault();

          if (gesture.mode === "pinch" && event.touches.length >= 2) {
            const distance = getTouchDistance(event.touches[0], event.touches[1]);
            if (gesture.startDistance <= 0) return;
            const ratio = distance / gesture.startDistance;
            const nextScale = clampVideoScale(gesture.startScale * ratio);
            gesture.moved = gesture.moved || Math.abs(ratio - 1) > 0.02;
            applyVideoTransform(
              nextScale,
              gesture.startTranslateX,
              gesture.startTranslateY
            );
            return;
          }

          if (gesture.mode === "pan" && event.touches.length === 1) {
            const touch = event.touches[0];
            const dx = touch.clientX - gesture.lastPanX;
            const dy = touch.clientY - gesture.lastPanY;
            if (
              !gesture.moved &&
              (Math.abs(dx) > VIDEO_PAN_THRESHOLD_PX ||
                Math.abs(dy) > VIDEO_PAN_THRESHOLD_PX)
            ) {
              gesture.moved = true;
            }
            gesture.lastPanX = touch.clientX;
            gesture.lastPanY = touch.clientY;
            applyVideoTransform(
              state.videoScale,
              state.videoTranslateX + dx,
              state.videoTranslateY + dy
            );
          }
        },
        { passive: false }
      );

      el.addEventListener(
        "touchend",
        (event) => {
          const gesture = state.zoomGesture;
          if (!gesture || gesture.mode === "idle") {
            // still track taps for double-tap reset when zoomed
          } else {
            if (gesture.mode === "pinch" && event.touches.length >= 2) return;
            if (gesture.mode === "pan" && event.touches.length >= 1) return;
          }

          const wasMoved = !!(gesture && gesture.moved);
          if (gesture && gesture.mode !== "idle") finishGesture();

          if (event.touches.length > 0 || wasMoved) return;

          const touch = event.changedTouches[0];
          if (!touch) return;
          const now = Date.now();
          const lastTap = state.lastZoomTap || { time: 0, x: 0, y: 0 };

          if ((state.videoScale || 1) > VIDEO_ZOOM_MIN) {
            const dt = now - lastTap.time;
            const distance = Math.hypot(
              touch.clientX - lastTap.x,
              touch.clientY - lastTap.y
            );
            if (dt < VIDEO_DOUBLE_TAP_MS && distance < VIDEO_DOUBLE_TAP_DISTANCE_PX) {
              resetVideoTransform();
              state.suppressVideoTap = true;
              state.lastZoomTap = { time: 0, x: 0, y: 0 };
              return;
            }
          }

          state.lastZoomTap = { time: now, x: touch.clientX, y: touch.clientY };
        },
        { passive: true }
      );

      el.addEventListener("touchcancel", () => finishGesture(), { passive: true });

      window.addEventListener("resize", () => {
        applyVideoTransform(
          state.videoScale,
          state.videoTranslateX,
          state.videoTranslateY
        );
      });
      window.addEventListener("orientationchange", () => {
        applyVideoTransform(
          state.videoScale,
          state.videoTranslateX,
          state.videoTranslateY
        );
      });
    }


    function bind() {
      if (state.bound || !els.video) return;
      state.bound = true;

      bindPinchZoom();

      els.play?.addEventListener("click", togglePlay);
      els.playBig?.addEventListener("click", togglePlay);

      const unmuteIfNeeded = () => {
        const vid = els.video;
        if (!vid) return;
        if (vid.muted || vid.volume === 0) {
          notePlaybackUserGesture();
          vid.muted = false;
          try { vid.removeAttribute("muted"); } catch (_) {}
          if (!(vid.volume > 0)) vid.volume = 1;
          const p = vid.play();
          if (p && typeof p.then === "function") {
            p.then(() => {
              if (vid.paused) {
                vid.muted = true;
                vid.volume = 0;
                try { vid.setAttribute("muted", ""); } catch (_) {}
                vid.play().catch(() => {});
                setStatus(t("player.tap_for_sound", "Playing muted — tap for sound"));
              } else {
                setStatus("");
              }
              syncUi();
            }).catch(() => {
              vid.muted = true;
              vid.volume = 0;
              setStatus(t("player.tap_for_sound", "Playing muted — tap for sound"));
              syncUi();
            });
          } else {
            setStatus("");
            syncUi();
          }
        }
      };
      // First tap/click anywhere on the player unmutes (never leave muted playback)
      els.shell?.addEventListener("pointerdown", unmuteIfNeeded, true);
      els.shell?.addEventListener("keydown", (e) => {
        if (e.key === "m" || e.key === " " || e.key === "k") unmuteIfNeeded();
      }, true);
      // Do not force-unmute on "playing" — that pauses muted autoplay in Chrome/Edge.
      // Sound unlock happens only on a real user gesture (pointerdown / togglePlay / mute btn).
      els.mute?.addEventListener("click", () => {
        els.video.muted = !els.video.muted;
        syncUi();
      });
      const seekBy = (delta) => {
        const v = els.video;
        if (!v) return;
        const dur = Number.isFinite(v.duration) ? v.duration : Infinity;
        try {
          v.currentTime = Math.max(0, Math.min(dur, (v.currentTime || 0) + delta));
        } catch (_) {}
        syncUi();
        showControls();
      };
      els.seekBack?.addEventListener("click", () => seekBy(-10));
      els.seekFwd?.addEventListener("click", () => seekBy(10));
      els.fs?.addEventListener("click", () => {
        const target = els.shell;
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (!fsEl) target?.requestFullscreen?.() || target?.webkitRequestFullscreen?.();
        else document.exitFullscreen?.() || document.webkitExitFullscreen?.();
      });
      document.addEventListener("fullscreenchange", syncFullscreenClass);
      document.addEventListener("webkitfullscreenchange", syncFullscreenClass);
      syncFullscreenClass();
      els.cc?.addEventListener("click", () => {
        // Open subtitles inside the player Settings panel (not a separate drawer)
        closeSubsDrawer();
        if (els.shell?.classList.contains("np-settings-open")) {
          const active = els.panel?.querySelector("[data-tab].is-active");
          if (active && active.getAttribute("data-tab") === "subs") closePanel();
          else openPanel("subs");
        } else {
          openPanel("subs");
        }
      });
      els.subsDrawerClose?.addEventListener("click", closeSubsDrawer);
      els.progress?.addEventListener("input", () => {
        const v = els.video;
        if (!v?.duration) return;
        v.currentTime = (Number(els.progress.value) / 1000) * v.duration;
        syncUi();
      });
      els.skip?.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        performSkip();
      });
      els.settingsBtn?.addEventListener("click", () => openPanel("source"));
      els.sourceBtn?.addEventListener("click", () => openPanel("source"));
      els.pip?.addEventListener("click", async () => {
        const v = els.video;
        if (!v) return;
        try {
          if (document.pictureInPictureElement) {
            await document.exitPictureInPicture();
          } else if (document.pictureInPictureEnabled && v.requestPictureInPicture) {
            await v.requestPictureInPicture();
          }
        } catch (_) {}
        showControls();
      });
      els.sourceHint?.addEventListener("click", () => {
        hideSourceHint();
        openPanel("source");
      });
      els.panelClose?.addEventListener("click", closePanel);
      els.scrim?.addEventListener("click", () => { closePanel(); closeSubsDrawer(); });

      els.back?.addEventListener("click", () => {
        if (cfg.embed) {
          try { active?.destroy?.(); } catch (_) {}
          const mp = document.getElementById("movie-player");
          mp?.classList.remove("np-active", "playing");
          mp?.classList.add("no-player");
          const host = document.getElementById("player");
          if (host) {
            host.innerHTML = "";
            // restore play button shell
            host.innerHTML = '<div class="message d-none"><i class="uil uil-exclamation-triangle"></i><div>' + t('detail.trailer_error', 'Unable to load trailer. Please try again.') + '</div></div><button type="button" class="btn-play" id="btn-play" aria-label="' + t('home.watch_now', 'Watch now') + '" title="' + t('home.watch_now', 'Watch now') + '"><i></i></button><div id="player-frame" class="d-none"></div>';
          }
          return;
        }
        const href = cfg.backUrl || cfg.watchUrl;
        if (href) location.href = href;
        else history.back();
      });

      els.panelTabs?.querySelectorAll("[data-tab]").forEach((btn) => {
        btn.addEventListener("click", () => switchTab(btn.getAttribute("data-tab")));
      });

      setTimeout(() => els.shell?.classList.add("np-title-in"), 1000);

      function syncAutoNextUi() {
        els.autonext?.setAttribute("aria-pressed", state.autoNext ? "true" : "false");
        els.autonextSwitch?.setAttribute("aria-checked", state.autoNext ? "true" : "false");
      }
      function toggleAutoNext() {
        state.autoNext = !state.autoNext;
        localStorage.setItem("cf_np_autonext", state.autoNext ? "1" : "0");
        syncAutoNextUi();
        setStatus(state.autoNext ? "Auto Next on" : "Auto Next off");
      }
      syncAutoNextUi();
      els.autonext?.addEventListener("click", toggleAutoNext);
      els.autonextSwitch?.addEventListener("click", toggleAutoNext);

      if (els.delay) {
        els.delay.value = String(state.delaySec);
        if (els.delayVal) els.delayVal.textContent = `${state.delaySec.toFixed(1)}s`;
        els.delay.addEventListener("input", () => {
          state.delaySec = Number(els.delay.value) || 0;
          localStorage.setItem("cf_np_sub_delay", String(state.delaySec));
          if (els.delayVal) els.delayVal.textContent = `${state.delaySec.toFixed(1)}s`;
          applySubtitleDelay();
        });
      }
      if (els.size) {
        els.size.value = String(state.fontScale);
        if (els.sizeVal) els.sizeVal.textContent = `${Math.round(state.fontScale * 100)}%`;
        els.size.addEventListener("input", () => {
          state.fontScale = Number(els.size.value) || 1;
          localStorage.setItem("cf_np_sub_size", String(state.fontScale));
          if (els.sizeVal) els.sizeVal.textContent = `${Math.round(state.fontScale * 100)}%`;
          syncSubtitleOverlay();
        });
      }
      if (els.bg) {
        els.bg.value = String(state.subBg);
        if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;
        els.bg.addEventListener("input", () => {
          state.subBg = Number(els.bg.value) || 0;
          localStorage.setItem("cf_np_sub_bg", String(state.subBg));
          if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;
          syncSubtitleOverlay();
        });
      }

      const v = els.video;
      try {
        v.muted = false; // cf-prefer-sound
        if (!(v.volume > 0)) v.volume = 1;
      } catch (_) {}
      const maybeSaveProgress = (force, seed) => {
        const now = Date.now();
        const t = v.currentTime || 0;
        // First eligible save should not wait on the throttle
        const firstOk = (seed || t >= 1) && state.lastCwSave === 0;
        if (!force && !seed && !firstOk && now - state.lastCwSave < 4000) return;
        const opts = {};
        if (seed) opts.seed = true;
        if (force || seed) opts.forcePush = true;
        const ok = cwSave(cfg, Math.max(t, seed ? 1 : 0), v.duration, opts);
        if (ok) state.lastCwSave = now;
      };
      // Ensure homepage rail can show even if stream stalls after start
      maybeSaveProgress(true, true);
      v.addEventListener("timeupdate", () => {
        syncUi();
        syncSubtitleOverlay();
        maybeSaveProgress(false);
      });
      v.addEventListener("loadedmetadata", () => {
        fetchSkipSegments();
      });
      // Kick off early (duration optional); refresh again after metadata for duration_ms match.
      fetchSkipSegments();
      v.addEventListener("waiting", () => {
        armSourceHint(8000);
      });
      v.addEventListener("stalled", () => {
        armSourceHint(8000);
      });
      v.addEventListener("playing", () => {
        hideSourceHint();
      });
      v.addEventListener("canplay", () => {
        // Keep hint if still effectively stuck (black / no frames) after a while
        if (v.videoWidth === 0) armSourceHint(10000);
        else hideSourceHint();
      });
      v.addEventListener("play", () => {
        syncUi();
        showControls();
        maybeSaveProgress(true, true);
        partyReport(true);
      });
      v.addEventListener("pause", () => {
        syncUi();
        showControls(false);
        maybeSaveProgress(true);
        partyReport(true);
      });
      v.addEventListener("seeked", () => {
        maybeSaveProgress(true);
        partyReport(true);
      });
      v.addEventListener("volumechange", syncUi);
      v.addEventListener("ended", () => {
        maybeSaveProgress(true);
        if (cfg.type === "tv" && state.autoNext) goNextEpisode();
      });
      v.addEventListener("click", (e) => {
        if (state.suppressVideoTap) {
          state.suppressVideoTap = false;
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        if ((state.videoScale || 1) > VIDEO_ZOOM_MIN && state.zoomGestureActive) {
          return;
        }
        togglePlay();
      });
      window.addEventListener("pagehide", () => maybeSaveProgress(true));

      els.shell?.addEventListener("mousemove", () => showControls());
      els.shell?.addEventListener("touchstart", () => showControls(), { passive: true });

      document.addEventListener("keydown", (e) => {
        if (!els.shell || !document.body.contains(els.shell)) return;
        if (e.target && ["INPUT", "TEXTAREA", "SELECT"].includes(e.target.tagName)) return;
        if (e.key === " " || e.key === "k") {
          e.preventDefault();
          togglePlay();
        } else if (e.key === "ArrowLeft") {
          els.video.currentTime = Math.max(0, els.video.currentTime - 10);
        } else if (e.key === "ArrowRight") {
          els.video.currentTime = Math.min(els.video.duration || 0, els.video.currentTime + 10);
        } else if (e.key === "f") els.fs?.click();
        else if (e.key === "m") els.mute?.click();
        else if (e.key === "n" && cfg.type === "tv") goNextEpisode();
        else if (e.key === "Escape") closePanel();
      });
    }

    function partyApiBase() {
      return (cfg.partyApi || ((window.APP && window.APP.baseUrl) || "") + "/api/party").replace(/\/$/, "");
    }

    async function ensureParty() {
      if (!state.party || state.partyTimer) return;
      const base = partyApiBase();
      const peer = partyPeerId();
      try {
        if (state.party.role === "host") {
          // hostId from create flow, or claim via first update using session peer
          state.partyHostId = sessionStorage.getItem("cf_party_host_" + state.party.code) || peer;
          sessionStorage.setItem("cf_party_host_" + state.party.code, state.partyHostId);
          setStatus(t('party.host', 'Watch Party host') + ' · ' + state.party.code);
          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
          saveHostingResume();
        } else {
          const joinRes = await fetch(`${base}/${encodeURIComponent(state.party.code)}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ peerId: peer }),
          });
          const joinData = await joinRes.json().catch(() => null);
          if (!joinData?.ok) {
            stopPartyLocal((joinData && joinData.error) || t('party.ended', 'Watch Party ended'));
            return;
          }
          setStatus(t('party.synced', 'Watch Party · synced to host') + ' · ' + state.party.code);
          state.partyTimer = setInterval(() => partyPoll(), 2000);
          partyPoll();
        }
        paintPartyChip();
      } catch (_) {}
    }

    function partyChipHost() {
      let host = document.getElementById("cf-party-chip-bar");
      if (host) return host;
      const end =
        document.querySelector("header .wrapper .end") ||
        document.querySelector("header .wrapper");
      if (!end) return null;
      host = document.createElement("div");
      host.id = "cf-party-chip-bar";
      host.className = "cf-party-chip-bar";
      host.hidden = true;
      end.appendChild(host);
      return host;
    }

    function paintPartyChip() {
      if (!state.party) return;
      const host = partyChipHost();
      if (!host) return;
      // Remove any leftover chip stuck inside the player chrome
      try { els.shell?.querySelector("#np-party-chip")?.remove(); } catch (_) {}
      host.hidden = false;
      let chip = host.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        chip.innerHTML =
          '<span class="np-party-chip-label"></span>' +
          '<button type="button" class="np-party-leave" id="np-party-leave" aria-label="Leave party">Leave</button>';
        host.appendChild(chip);
        chip.querySelector("#np-party-leave")?.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          leaveParty(true);
        });
      }
      const label = chip.querySelector(".np-party-chip-label");
      if (label) {
        label.textContent =
          state.party.role === "host"
            ? `Party ${state.party.code} · Host`
            : `Party ${state.party.code}`;
      }
    }

    function clearPartyFromUrl() {
      try {
        const u = new URL(location.href);
        u.searchParams.delete("party");
        u.searchParams.delete("host");
        history.replaceState(null, "", u.toString());
      } catch (_) {}
    }

    function hostingStoreKey() {
      return "cf_party_hosting_v1";
    }

    function saveHostingResume() {
      if (!state.party || state.party.role !== "host") return;
      try {
        const watch =
          (cfg.watchUrl || location.href).split("#")[0];
        const u = new URL(watch, location.origin);
        u.searchParams.set("party", state.party.code);
        u.searchParams.set("host", "1");
        u.searchParams.set("play", "1");
        sessionStorage.setItem(
          hostingStoreKey(),
          JSON.stringify({
            code: state.party.code,
            hostId: state.partyHostId || partyPeerId(),
            url: u.toString(),
            title: cfg.title || t('party.title', 'Watch Party'),
            updated: Date.now(),
          })
        );
      } catch (_) {}
      try {
        window.ChillflixParty?.paintResume?.();
      } catch (_) {}
    }

    function clearHostingResume() {
      try {
        sessionStorage.removeItem(hostingStoreKey());
      } catch (_) {}
      try {
        window.ChillflixParty?.paintResume?.();
      } catch (_) {}
    }

    function stopPartyLocal(message) {
      if (state.partyTimer) {
        clearInterval(state.partyTimer);
        state.partyTimer = null;
      }
      const code = state.party && state.party.code;
      if (code) {
        try {
          sessionStorage.removeItem("cf_party_host_" + code);
        } catch (_) {}
      }
      state.party = null;
      state.partyHostId = null;
      state.partyUnloadBound = null;
      try { els.shell?.querySelector("#np-party-chip")?.remove(); } catch (_) {}
      try {
        document.getElementById("np-party-chip")?.remove();
        const bar = document.getElementById("cf-party-chip-bar");
        if (bar) bar.hidden = true;
      } catch (_) {}
      clearPartyFromUrl();
      clearHostingResume();
      if (message) setStatus(message);
    }

    function partyLeavePayload() {
      const peer = partyPeerId();
      const hostId = state.partyHostId || sessionStorage.getItem("cf_party_host_" + (state.party?.code || "")) || peer;
      return {
        peerId: peer,
        hostId: state.party?.role === "host" ? hostId : undefined,
      };
    }

    async function leaveParty(confirmHost) {
      if (!state.party) return;
      if (state.party.role === "host" && confirmHost) {
        const ok = window.confirm("End Watch Party for everyone?");
        if (!ok) return;
      }
      const base = partyApiBase();
      const code = state.party.code;
      const role = state.party.role;
      const path = role === "host" ? "close" : "leave";
      try {
        await fetch(`${base}/${encodeURIComponent(code)}/${path}`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify(partyLeavePayload()),
          keepalive: true,
        });
      } catch (_) {}
      stopPartyLocal(role === "host" ? t('party.ended', 'Watch Party ended') : "Left Watch Party");
    }

    async function partyReport(force) {
      if (!state.party || state.party.role !== "host") return;
      const v = els.video;
      if (!v) return;
      const base = partyApiBase();
      try {
        const res = await fetch(`${base}/${encodeURIComponent(state.party.code)}`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            hostId: state.partyHostId || partyPeerId(),
            paused: !!v.paused,
            t: v.currentTime || 0,
            duration: v.duration || 0,
            content: {
              type: cfg.type,
              id: cfg.id,
              title: cfg.title,
              poster: cfg.poster,
              year: cfg.year,
              season: cfg.season,
              episode: cfg.episode,
              url: cfg.watchUrl || location.href,
            },
          }),
          keepalive: !!force,
        });
        const data = await res.json().catch(() => null);
        if (data && data.ok === false) {
          stopPartyLocal(data.error || t('party.ended', 'Watch Party ended'));
        } else if (data && data.ok) {
          saveHostingResume();
        }
      } catch (_) {}
    }

    async function partyPoll() {
      if (!state.party || state.party.role === "host" || state.partyApplying) return;
      const v = els.video;
      if (!v) return;
      const base = partyApiBase();
      try {
        const res = await fetch(`${base}/${encodeURIComponent(state.party.code)}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const data = await res.json();
        if (!data?.ok || !data.room) {
          stopPartyLocal("Host left — party ended");
          return;
        }
        const room = data.room;
        const target = Number(room.t) || 0;
        const drift = Math.abs((v.currentTime || 0) - target);
        state.partyApplying = true;
        if (drift > 2.5 && target >= 0) {
          try {
            v.currentTime = target;
          } catch (_) {}
        }
        if (room.paused && !v.paused) v.pause();
        else if (!room.paused && v.paused) v.play().catch(() => {});
      } catch (_) {
      } finally {
        state.partyApplying = false;
      }
    }

    bind();
    syncUi();
    // Paint Source tab before any network — uses providers embedded in window.PLAYER.
    if (Array.isArray(cfg.providers) && cfg.providers.length) {
      state.sources = placeholderSources(cfg.providers);
      state.sourceIndex = 0;
      refreshMenus();
    }
    fetchSources();

    return {
      destroy() {
        // Keep host party alive so they can return from Home / other pages
        if (state.party && state.party.role === "host") saveHostingResume();
        if (state.partyTimer) clearInterval(state.partyTimer);
        state.partyTimer = null;
        try {
          const v = els.video;
          if (v) cwSave(cfg, v.currentTime, v.duration, { forcePush: true });
        } catch (_) {}
        destroyHls();
        els.shell?.remove();
      },
    };
  }

  function ensureHls(cb) {
    if (window.Hls) {
      cb();
      return;
    }
    const s = document.createElement("script");
    s.src = "https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js";
    s.onload = cb;
    s.onerror = () => cb();
    document.head.appendChild(s);
  }

  let active = null;

  function mount(container, cfg) {
    if (!container) return null;
    if (active) {
      try {
        active.destroy();
      } catch (_) {}
      active = null;
    }
    const conf = Object.assign({ embed: true, autoplay: true }, cfg || {});
    container.innerHTML = buildMarkup(conf);
    const host = container.querySelector("#np-shell") || container;
    active = createPlayer(Object.assign({}, conf, { root: host.parentElement || container }));
    return active;
  }

  function preparePartyLeave() {
    if (!active) return;
    try {
      active.destroy();
    } catch (_) {}
    active = null;
  }
  // Save hosting resume before wrapper swap, and again after
  window.addEventListener("cf:before-softnav", preparePartyLeave);
  window.addEventListener("cf:softnav", preparePartyLeave);

  window.ChillflixWatchStats = { read: readWatchStats, record: recordWatchStats };
  window.ChillflixPlayer = {
    mount,
    prepareLeave: preparePartyLeave,
    startOnWatch(cfg) {
      const moviePlayer = document.getElementById("movie-player");
      const host = document.getElementById("player");
      if (!moviePlayer || !host) return;
      // Must run inside the click gesture — unlock sound before stream resolves
      primeAutoplaySoundUnlock();
      moviePlayer.classList.add("playing", "np-active");
      moviePlayer.classList.remove("no-player");
      const frame = document.getElementById("player-frame");
      if (frame) {
        frame.classList.add("d-none");
        frame.innerHTML = "";
      }
      // Seed Continue Watching immediately on Watch Now (before stream buffers)
      try {
        cwSeed(cfg || {});
      } catch (_) {}
      ensureHls(() => mount(host, cfg));
    },
    continueList() {
      const map = cwReadAll();
      return Object.keys(map)
        .map((k) => map[k])
        .filter(Boolean)
        .sort((a, b) => (b.updated || 0) - (a.updated || 0));
    },
    clearContinue(key) {
      const map = cwReadAll();
      if (key) delete map[key];
      else Object.keys(map).forEach((k) => delete map[k]);
      cwWriteAll(map);
    },
  };

  // Standalone /player page
  if (document.getElementById("np-shell") && window.PLAYER && !window.PLAYER.embed) {
    ensureHls(() => {
      active = createPlayer(Object.assign({}, window.PLAYER, { root: document, embed: false }));
    });
  }
})();
