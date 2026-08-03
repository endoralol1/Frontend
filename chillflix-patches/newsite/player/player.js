(function () {
  "use strict";

  const ICO = {
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8 5.14v13.72L19 12 8 5.14z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 5h3.5v14H7V5zm6.5 0H17v14h-3.5V5z"/></svg>',
    back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5 8 12l7 7"/></svg>',
    vol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 9.5v5h3.2L12 18.2V5.8L7.7 9.5H4.5z"/><path d="M15.2 9.2a3.2 3.2 0 0 1 0 5.6"/><path d="M17.4 7a6 6 0 0 1 0 10"/></svg>',
    mute: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 9.5v5h3.2L12 18.2V5.8L7.7 9.5H4.5z"/><path d="m16 9 5 5M21 9l-5 5"/></svg>',
    fs: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 4H4v4M16 4h4v4M8 20H4v-4M16 20h4v-4"/></svg>',
  };

  function esc(s) {
    return String(s ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /* -------- Continue Watching (local, throttled) -------- */
  const CW_KEY = "cf_continue_v1";
  const CW_MAX = 5;

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
      // finished — drop from continue list
      const map = cwReadAll();
      delete map[key];
      cwWriteAll(map);
      cwRemoveContinue(key);
      return true;
    }
    const map = cwReadAll();
    const prev = map[key] || {};
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
    <video id="np-video" class="np-video" playsinline webkit-playsinline></video>
    <div class="np-poster" id="np-poster" style="background-image:url('${esc(cfg.backdrop || "")}')"></div>
    <div class="np-subs" id="np-subs" aria-live="polite"></div>
    <div class="np-top">
      <button type="button" class="np-back" id="np-back" aria-label="Back">${ICO.back}</button>
      <div class="np-titleblock">
        <p class="np-now">Now Playing</p>
        <h1 class="np-title">${esc(cfg.title || "Player")}</h1>
        ${meta ? `<p class="np-meta">${esc(meta)}</p>` : ""}
      </div>
      <div class="np-top-actions">
        <button type="button" class="np-chip" id="np-settings-btn" aria-haspopup="dialog">Settings</button>
      </div>
    </div>
    <div class="np-center" id="np-center">
      <button type="button" class="np-playbig" id="np-playbig" aria-label="Play">${ICO.play}</button>
      <p class="np-status" id="np-status">Loading sources…</p>
    </div>
    <div class="np-bottom" id="np-controls">
      <div class="np-progress-wrap">
        <input type="range" id="np-progress" class="np-progress" min="0" max="1000" value="0" step="1" aria-label="Seek">
      </div>
      <div class="np-bar">
        <div class="np-bar-left">
          <button type="button" class="np-btn" id="np-play" aria-label="Play/Pause">${ICO.play}</button>
          <button type="button" class="np-btn np-mute-btn" id="np-mute" aria-label="Mute">${ICO.vol}</button>
          <span class="np-time" id="np-time">0:00 / 0:00</span>
        </div>
        <div class="np-bar-right">
          ${isTv && nextUrl ? `<a class="np-btn" id="np-next" href="${esc(nextUrl)}" title="Next episode">Next</a>` : ""}
          <button type="button" class="np-btn" id="np-fs" aria-label="Fullscreen">${ICO.fs}</button>
        </div>
      </div>
    </div>
  </div>
  <div class="np-scrim" id="np-scrim" hidden></div>
  <aside class="np-panel" id="np-panel" hidden>
    <div class="np-panel-head">
      <div class="np-panel-titles">
        <p class="np-panel-kicker">Controls</p>
        <h2>Settings</h2>
      </div>
      <button type="button" class="np-btn np-panel-x" id="np-panel-close" aria-label="Close">✕</button>
    </div>
    <div class="np-seg" id="np-panel-tabs" role="tablist">
      <button type="button" data-tab="source" class="is-active" role="tab">Source</button>
      <button type="button" data-tab="quality" role="tab">Quality</button>
      <button type="button" data-tab="audio" role="tab">Audio</button>
      <button type="button" data-tab="subs" role="tab">Subs</button>
      <button type="button" data-tab="style" role="tab">Style</button>
    </div>
    <div class="np-panel-body">
      <section data-panel="source" class="is-active">
        <p class="np-panel-hint">Stream source</p>
        <div class="np-list" id="np-source-list"></div>
      </section>
      <section data-panel="quality">
        <p class="np-panel-hint">Video quality</p>
        <div class="np-list np-list--grid" id="np-quality-list"></div>
      </section>
      <section data-panel="audio">
        <p class="np-panel-hint">Audio track</p>
        <div class="np-list" id="np-audio-list"></div>
      </section>
      <section data-panel="subs">
        <p class="np-panel-hint">Subtitle language</p>
        <div class="np-list" id="np-sub-list"></div>
      </section>
      <section data-panel="style">
        <p class="np-panel-hint">Caption timing & look</p>
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
        ${isTv ? `<div class="np-toggle-row"><div class="np-toggle-copy"><span>Auto Next</span><small>Jump to the next episode when this one ends</small></div><button type="button" class="np-switch" id="np-autonext-switch" aria-checked="true" role="switch" aria-label="Auto next"></button></div>` : ""}
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
      video: $("#np-video", root),
      poster: $("#np-poster", root),
      subs: $("#np-subs", root),
      status: $("#np-status", root),
      playBig: $("#np-playbig", root),
      play: $("#np-play", root),
      mute: $("#np-mute", root),
      fs: $("#np-fs", root),
      back: $("#np-back", root),
      progress: $("#np-progress", root),
      time: $("#np-time", root),
      settingsBtn: $("#np-settings-btn", root),
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
      hls: null,
      levels: [],
      qualityOptions: [],
      levelIndex: -1,
      audioTracks: [],
      audioIndex: -1,
      textTracks: [],
      textIndex: -1,
      delaySec: Number(localStorage.getItem("cf_np_sub_delay") || 0),
      fontScale: Number(localStorage.getItem("cf_np_sub_size") || 1),
      subBg: Number(localStorage.getItem("cf_np_sub_bg") || 0.7),
      autoNext: localStorage.getItem("cf_np_autonext") !== "0",
      autoplay: cfg.autoplay !== false,
      hideTimer: null,
      cueBases: new WeakMap(),
      payloadSubtitles: [],
      subsLoading: false,
      subsLoaded: false,
      bound: false,
      resumeApplied: false,
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
          <span class="np-option-check" aria-hidden="true"></span>`;
        b.addEventListener("click", () => onPick(idx));
        container.appendChild(b);
      });
    }

    function refreshMenus() {
      renderList(
        els.sourceList,
        state.sources,
        state.sourceIndex,
        (i) => loadSource(i),
        (s, i) => ({
          title: s.providerName || s.provider || `Source ${i + 1}`,
          sub:
            s.provider === "huhu"
              ? s.language
                ? String(s.language).toUpperCase() + " audio"
                : s.quality && s.quality !== "Auto"
                  ? String(s.quality)
                  : "DE / EN"
              : s.quality
                ? String(s.quality)
                : s.type
                  ? String(s.type).toUpperCase()
                  : "Stream",
        })
      );

      const qualities = [{ key: "auto", label: "Auto", levelIndex: -1 }, ...state.qualityOptions];
      let qActive = 0;
      if (state.levelIndex >= 0) {
        const hit = state.qualityOptions.findIndex((q) => q.levelIndex === state.levelIndex);
        qActive = hit >= 0 ? hit + 1 : 0;
      }
      renderList(
        els.qualityList,
        qualities,
        qActive,
        (i) => {
          const q = qualities[i];
          setQuality(q.key === "auto" ? -1 : q.levelIndex);
        },
        (l) => ({
          title: l.label || "Auto",
          sub: l.key === "auto" ? "Adaptive" : "Closest match",
        })
      );

      const audios = state.audioTracks.length
        ? state.audioTracks
        : [{ id: "default", name: "Default", lang: "", switchable: false }];
      const audioActive = state.audioIndex >= 0 ? state.audioIndex : 0;
      renderList(
        els.audioList,
        audios,
        audioActive,
        (i) => setAudio(i),
        (a, i) => ({
          title: a.name || a.label || a.lang || `Audio ${i + 1}`,
          sub: a.lang
            ? String(a.lang).toUpperCase()
            : a.switchable === false
              ? "Embedded"
              : "Track",
        })
      );

      const subs = [{ name: "Off", lang: "", external: false }, ...state.textTracks];
      const sActive = state.textIndex < 0 ? 0 : state.textIndex + 1;
      renderList(
        els.subList,
        subs,
        sActive,
        (i) => setSubtitle(i - 1),
        (t) => ({
          title: t.name || t.label || t.lang || "Track",
          sub: t.name === "Off" ? "Hidden" : t.source ? String(t.source) : t.lang ? String(t.lang).toUpperCase() : "Caption",
        })
      );
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
    }

    function setQuality(idx) {
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
      // Huhu: German/English are separate resolves — reload stream with lang=
      if (track.switchUrl || (track.lang && String(state.sources[state.sourceIndex]?.provider || "").toLowerCase() === "huhu")) {
        const src = state.sources[state.sourceIndex];
        let nextUrl = track.switchUrl || "";
        if (!nextUrl && src?.url) {
          try {
            const u = new URL(absoluteUrl(src.url));
            u.searchParams.set("lang", String(track.lang).toLowerCase().slice(0, 2));
            nextUrl = u.toString();
          } catch (_) {
            nextUrl = "";
          }
        }
        if (nextUrl) {
          // Keep source entry in sync so quality/source lists stay correct
          if (src) {
            src.url = nextUrl;
            src.language = track.lang || src.language;
            src.label = `Huhu · ${track.name || track.label || String(track.lang || "").toUpperCase()}`;
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

    function fallbackAudioTracks(source) {
      const srcAudio = Array.isArray(source?.audioTracks)
        ? source.audioTracks.map((a, i) => ({
            id: a.id || `src-${i}`,
            name: a.label || a.language || a.name || `Audio ${i + 1}`,
            lang: a.language || a.lang || "",
            switchUrl: a.switchUrl || "",
            switchable: Boolean(a.switchUrl) || false,
            from: "source",
          }))
        : [];
      if (srcAudio.length) return srcAudio;
      const provider = String(source?.provider || "").toLowerCase();
      if (provider === "huhu") {
        return [
          { id: "huhu-de", name: "German", lang: "de", switchable: true, from: "source" },
          { id: "huhu-en", name: "English", lang: "en", switchable: true, from: "source" },
        ];
      }
      return [{ id: "default", name: "Default", lang: "", switchable: false, from: "source" }];
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
          return;
        }
        const captionBase =
          cfg.captionApi ||
          ((window.APP && window.APP.baseUrl) || "") + "/api/player/caption";
        state.payloadSubtitles = data.subtitles.map((sub) => {
          const raw = sub.src || sub.url || sub.file || "";
          return {
            id: sub.id,
            label: sub.label || sub.language || "Subtitle",
            language: sub.language || sub.lang || "",
            lang: sub.language || sub.lang || "",
            source: sub.source || "",
            // Always proxy — remote VTT hosts often block browser track loads (CORS)
            src: raw ? `${captionBase}?url=${encodeURIComponent(raw)}` : "",
          };
        });
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
        els.subs.innerHTML = "";
        return;
      }
      const span = document.createElement("span");
      span.textContent = text;
      span.style.fontSize = `${1.05 * state.fontScale}rem`;
      span.style.background = `rgba(0,0,0,${state.subBg})`;
      els.subs.innerHTML = "";
      els.subs.appendChild(span);
    }

    function activeTextTrack() {
      const tracks = Array.from(els.video?.textTracks || []);
      if (state.textIndex < 0) return null;
      return tracks[state.textIndex] || null;
    }

    function onCueChange() {
      const tt = activeTextTrack();
      if (!tt) {
        paintCue("");
        return;
      }
      const cues = tt.activeCues;
      if (!cues || !cues.length) {
        paintCue("");
        return;
      }
      const parts = [];
      for (let i = 0; i < cues.length; i++) parts.push(cues[i].text || "");
      paintCue(parts.join("\n"));
    }

    function applySubtitleDelay() {
      const tracks = Array.from(els.video?.textTracks || []);
      const delay = state.delaySec;
      tracks.forEach((tt) => {
        try {
          if (!tt.cues) return;
          for (let i = 0; i < tt.cues.length; i++) {
            const cue = tt.cues[i];
            if (!cue) continue;
            if (!state.cueBases.has(cue)) {
              state.cueBases.set(cue, { start: cue.startTime, end: cue.endTime });
            }
            const base = state.cueBases.get(cue);
            cue.startTime = Math.max(0, base.start + delay);
            cue.endTime = Math.max(cue.startTime + 0.05, base.end + delay);
          }
        } catch (_) {}
      });
    }

    function setSubtitle(idx) {
      state.textIndex = idx;
      const tracks = Array.from(els.video?.textTracks || []);
      tracks.forEach((tt, i) => {
        try {
          tt.mode = i === idx ? "hidden" : "disabled";
          tt.oncuechange = i === idx ? onCueChange : null;
        } catch (_) {}
      });
      if (state.hls && typeof state.hls.subtitleTrack !== "undefined") {
        try {
          state.hls.subtitleTrack = idx;
        } catch (_) {}
      }
      if (idx < 0) paintCue("");
      setTimeout(applySubtitleDelay, 80);
      refreshMenus();
    }

    function attachNativeTracks() {
      const tracks = Array.from(els.video?.textTracks || []);
      state.textTracks = tracks.map((t, i) => ({
        id: i,
        name: t.label || t.language || `Sub ${i + 1}`,
        lang: t.language || "",
      }));
      if (state.textIndex >= 0) setSubtitle(state.textIndex);
      refreshMenus();
    }

    function loadExternalSubtitles(payloadSubs, source) {
      const v = els.video;
      if (!v) return;
      Array.from(v.querySelectorAll("track[data-cf]")).forEach((n) => n.remove());
      const fromSource = Array.isArray(source?.subtitles) ? source.subtitles : [];
      const globalSubs = Array.isArray(payloadSubs) ? payloadSubs : [];
      const subs = fromSource.length ? fromSource : globalSubs;
      subs.forEach((sub, i) => {
        const url = absoluteUrl(sub.src || sub.url || sub.file);
        if (!url) return;
        const tr = document.createElement("track");
        tr.kind = "subtitles";
        tr.label = sub.label || sub.language || sub.lang || `Sub ${i + 1}`;
        tr.srclang = String(sub.language || sub.lang || "en").slice(0, 8);
        tr.src = url;
        tr.setAttribute("data-cf", "1");
        v.appendChild(tr);
      });
      setTimeout(attachNativeTracks, 250);
    }

    function playUrl(url, source) {
      const v = els.video;
      if (!v || !url) return;
      destroyHls();
      paintCue("");
      setStatus("Loading…");
      showControls(false);
      const abs = absoluteUrl(url);
      const isHls =
        /\.m3u8(\?|$)/i.test(abs) ||
        /hls|m3u8/i.test(String(source?.type || source?.format || "hls"));

      const onReady = () => {
        setStatus("");
        if (!state.resumeApplied) {
          const resumeAt = cwResumeTime(cfg);
          if (resumeAt > 0 && Number.isFinite(v.duration) && resumeAt < v.duration - 5) {
            try {
              v.currentTime = resumeAt;
            } catch (_) {}
          }
          state.resumeApplied = true;
        }
        ensureParty();
        if (!state.autoplay) return;
        const tryPlay = () =>
          v.play().catch(() => {
            // Browsers often block unmuted autoplay — retry muted, user can unmute
            v.muted = true;
            return v.play()
              .then(() => {
                syncUi();
                setStatus("Muted autoplay — tap volume to unmute");
              })
              .catch(() => setStatus("Tap play to start"));
          });
        tryPlay();
      };

      if (isHls && window.Hls && window.Hls.isSupported()) {
        const hls = new window.Hls({
          enableWorker: true,
          lowLatencyMode: false,
          backBufferLength: 30,
          maxBufferLength: 30,
          maxMaxBufferLength: 60,
        });
        state.hls = hls;
        hls.loadSource(abs);
        hls.attachMedia(v);
        hls.on(window.Hls.Events.MANIFEST_PARSED, (_e, data) => {
          state.levels = data.levels || [];
          state.qualityOptions = normalizeHlsLevels(state.levels);
          state.levelIndex = -1;
          hls.currentLevel = -1;

          const hlsAudio = (hls.audioTracks || []).map((a, i) => ({
            id: i,
            name: a.name || a.lang || `Audio ${i + 1}`,
            lang: a.lang || "",
            switchable: true,
            from: "hls",
          }));
          state.audioTracks = hlsAudio.length ? hlsAudio : fallbackAudioTracks(source);
          if (hlsAudio.length && hls.audioTrack >= 0) {
            state.audioIndex = hls.audioTrack;
          } else {
            const want = String(source?.language || "").toLowerCase();
            const hit = want
              ? state.audioTracks.findIndex((a) => String(a.lang || "").toLowerCase().startsWith(want))
              : -1;
            state.audioIndex = hit >= 0 ? hit : state.audioTracks.length ? 0 : 0;
          }

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
        hls.on(window.Hls.Events.ERROR, (_e, data) => {
          if (!data?.fatal) return;
          if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
            setStatus("Network error — retrying…");
            hls.startLoad();
          } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
            setStatus("Media error — recovering…");
            hls.recoverMediaError();
          } else {
            setStatus("Playback failed — next source…");
            tryNextSource();
          }
        });
      } else if (v.canPlayType("application/vnd.apple.mpegurl") && isHls) {
        v.src = abs;
        state.audioTracks = fallbackAudioTracks(source);
        state.audioIndex = 0;
        state.qualityOptions = [];
        refreshMenus();
        loadExternalSubtitles(state.payloadSubtitles, source);
        fetchExternalSubtitleCatalog();
        v.addEventListener("loadedmetadata", onReady, { once: true });
      } else {
        v.src = abs;
        state.audioTracks = fallbackAudioTracks(source);
        state.audioIndex = 0;
        state.qualityOptions = [];
        refreshMenus();
        loadExternalSubtitles(state.payloadSubtitles, source);
        fetchExternalSubtitleCatalog();
        v.addEventListener("loadedmetadata", onReady, { once: true });
      }
    }

    function loadSource(index) {
      if (!state.sources.length) return;
      state.sourceIndex = Math.max(0, Math.min(index, state.sources.length - 1));
      const src = state.sources[state.sourceIndex];
      if (!src?.url) {
        setStatus("Source missing URL");
        return;
      }
      refreshMenus();
      playUrl(src.url, src);
    }

    function tryNextSource() {
      if (state.sourceIndex + 1 < state.sources.length) loadSource(state.sourceIndex + 1);
      else setStatus("No playable sources");
    }

    async function fetchSources() {
      setStatus("Fetching sources…");
      const api = cfg.sourcesApi || ((window.APP && window.APP.baseUrl) || "") + "/api/player/sources";
      const q = new URLSearchParams({
        type: cfg.type || "movie",
        tmdbId: String(cfg.id || ""),
      });
      if (cfg.type === "tv") {
        q.set("season", String(cfg.season || 1));
        q.set("episode", String(cfg.episode || 1));
      }
      try {
        const res = await fetch(`${api}?${q.toString()}`, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || "Sources failed");
        state.sources = Array.isArray(data.sources) ? data.sources : [];
        state.payloadSubtitles = Array.isArray(data.subtitles) ? data.subtitles : [];
        if (!state.sources.length) {
          setStatus("No sources found");
          return;
        }
        loadSource(0);
      } catch (err) {
        setStatus(err.message || "Failed to load sources");
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
      if (v.paused) v.play().catch(() => {});
      else v.pause();
      showControls();
    }

    function openPanel(tab) {
      if (els.panel) els.panel.hidden = false;
      if (els.scrim) els.scrim.hidden = false;
      els.shell?.classList.add("np-settings-open");
      if (tab) switchTab(tab);
      showControls(false);
    }

    function closePanel() {
      if (els.panel) els.panel.hidden = true;
      if (els.scrim) els.scrim.hidden = true;
      els.shell?.classList.remove("np-settings-open");
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

    function bind() {
      if (state.bound || !els.video) return;
      state.bound = true;

      els.play?.addEventListener("click", togglePlay);
      els.playBig?.addEventListener("click", togglePlay);
      els.mute?.addEventListener("click", () => {
        els.video.muted = !els.video.muted;
        syncUi();
      });
      els.fs?.addEventListener("click", () => {
        const target = els.shell;
        if (!document.fullscreenElement) target?.requestFullscreen?.();
        else document.exitFullscreen?.();
      });
      els.progress?.addEventListener("input", () => {
        const v = els.video;
        if (!v?.duration) return;
        v.currentTime = (Number(els.progress.value) / 1000) * v.duration;
        syncUi();
      });
      els.settingsBtn?.addEventListener("click", () => openPanel());
      els.panelClose?.addEventListener("click", closePanel);
      els.scrim?.addEventListener("click", closePanel);

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
            host.innerHTML = '<div class="message d-none"><i class="uil uil-exclamation-triangle"></i><div>Unable to load trailer. Please try again.</div></div><button type="button" class="btn-play" id="btn-play" aria-label="Watch now" title="Watch now"><i></i></button><div id="player-frame" class="d-none"></div>';
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
          onCueChange();
        });
      }
      if (els.bg) {
        els.bg.value = String(state.subBg);
        if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;
        els.bg.addEventListener("input", () => {
          state.subBg = Number(els.bg.value) || 0;
          localStorage.setItem("cf_np_sub_bg", String(state.subBg));
          if (els.bgVal) els.bgVal.textContent = `${Math.round(state.subBg * 100)}%`;
          onCueChange();
        });
      }

      const v = els.video;
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
        maybeSaveProgress(false);
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
      v.addEventListener("click", togglePlay);
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
          setStatus("Watch Party host · " + state.party.code);
          state.partyTimer = setInterval(() => partyReport(false), 2000);
          partyReport(true);
          bindPartyUnload();
        } else {
          const joinRes = await fetch(`${base}/${encodeURIComponent(state.party.code)}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ peerId: peer }),
          });
          const joinData = await joinRes.json().catch(() => null);
          if (!joinData?.ok) {
            stopPartyLocal((joinData && joinData.error) || "Watch Party ended");
            return;
          }
          setStatus("Watch Party · synced to host · " + state.party.code);
          state.partyTimer = setInterval(() => partyPoll(), 2000);
          partyPoll();
        }
        paintPartyChip();
      } catch (_) {}
    }

    function paintPartyChip() {
      if (!state.party || !els.shell) return;
      let chip = els.shell.querySelector("#np-party-chip");
      if (!chip) {
        chip = document.createElement("div");
        chip.id = "np-party-chip";
        chip.className = "np-party-chip";
        chip.innerHTML =
          '<span class="np-party-chip-label"></span>' +
          '<button type="button" class="np-party-leave" id="np-party-leave" aria-label="Leave party">Leave</button>';
        els.shell.querySelector(".np-top-actions")?.appendChild(chip);
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

    function stopPartyLocal(message) {
      if (state.partyTimer) {
        clearInterval(state.partyTimer);
        state.partyTimer = null;
      }
      if (state.partyUnloadBound) {
        window.removeEventListener("pagehide", state.partyUnloadBound);
        window.removeEventListener("beforeunload", state.partyUnloadBound);
        state.partyUnloadBound = null;
      }
      const code = state.party && state.party.code;
      if (code) {
        try {
          sessionStorage.removeItem("cf_party_host_" + code);
        } catch (_) {}
      }
      state.party = null;
      state.partyHostId = null;
      els.shell?.querySelector("#np-party-chip")?.remove();
      clearPartyFromUrl();
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

    function partyCloseBeacon() {
      if (!state.party || state.party.role !== "host") return;
      const base = partyApiBase();
      const body = JSON.stringify(partyLeavePayload());
      const url = `${base}/${encodeURIComponent(state.party.code)}/close`;
      try {
        if (navigator.sendBeacon) {
          const blob = new Blob([body], { type: "application/json" });
          navigator.sendBeacon(url, blob);
          return;
        }
      } catch (_) {}
      try {
        fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body,
          keepalive: true,
        });
      } catch (_) {}
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
      stopPartyLocal(role === "host" ? "Watch Party ended" : "Left Watch Party");
    }

    function bindPartyUnload() {
      if (!state.party || state.party.role !== "host" || state.partyUnloadBound) return;
      state.partyUnloadBound = () => partyCloseBeacon();
      window.addEventListener("pagehide", state.partyUnloadBound);
      window.addEventListener("beforeunload", state.partyUnloadBound);
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
          stopPartyLocal(data.error || "Watch Party ended");
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
    fetchSources();

    return {
      destroy() {
        if (state.party && state.party.role === "host") partyCloseBeacon();
        if (state.partyTimer) clearInterval(state.partyTimer);
        if (state.partyUnloadBound) {
          window.removeEventListener("pagehide", state.partyUnloadBound);
          window.removeEventListener("beforeunload", state.partyUnloadBound);
          state.partyUnloadBound = null;
        }
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

  window.ChillflixPlayer = {
    mount,
    startOnWatch(cfg) {
      const moviePlayer = document.getElementById("movie-player");
      const host = document.getElementById("player");
      if (!moviePlayer || !host) return;
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
