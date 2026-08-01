(function () {
  "use strict";

  const ICO = {
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8 5.14v13.72L19 12 8 5.14z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 5h3.5v14H7V5zm6.5 0H17v14h-3.5V5z"/></svg>',
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
      ${cfg.embed ? "" : `<a class="np-back" href="${esc(cfg.backUrl || "#")}" aria-label="Back"><span>←</span><span>Details</span></a>`}
      <div class="np-titleblock">
        <h1 class="np-title">${esc(cfg.title || "Player")}</h1>
        ${meta ? `<p class="np-meta">${esc(meta)}</p>` : ""}
      </div>
      <div class="np-top-actions">
        ${isTv ? `<button type="button" class="np-chip" id="np-autonext" aria-pressed="true" title="Auto next episode">Auto Next</button>` : ""}
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
          <button type="button" class="np-btn" id="np-source-btn" title="Source">Src</button>
          <button type="button" class="np-btn" id="np-fs" aria-label="Fullscreen">${ICO.fs}</button>
        </div>
      </div>
    </div>
  </div>
  <aside class="np-panel" id="np-panel" hidden>
    <div class="np-panel-head">
      <h2>Settings</h2>
      <button type="button" class="np-btn" id="np-panel-close" aria-label="Close">✕</button>
    </div>
    <div class="np-panel-tabs" id="np-panel-tabs">
      <button type="button" data-tab="source" class="is-active">Source</button>
      <button type="button" data-tab="quality">Quality</button>
      <button type="button" data-tab="audio">Audio</button>
      <button type="button" data-tab="subs">Subs</button>
    </div>
    <div class="np-panel-body">
      <section data-panel="source" class="is-active"><div class="np-list" id="np-source-list"></div></section>
      <section data-panel="quality"><div class="np-list" id="np-quality-list"></div></section>
      <section data-panel="audio"><div class="np-list" id="np-audio-list"></div></section>
      <section data-panel="subs">
        <div class="np-list" id="np-sub-list"></div>
        <div class="np-sliders">
          <label><span>Delay</span><input type="range" id="np-delay" min="-10" max="10" step="0.1" value="0"><span class="np-slider-val" id="np-delay-val">0.0s</span></label>
          <label><span>Size</span><input type="range" id="np-size" min="0.75" max="1.75" step="0.05" value="1"><span class="np-slider-val" id="np-size-val">100%</span></label>
          <label><span>BG</span><input type="range" id="np-bg" min="0" max="0.9" step="0.05" value="0.7"><span class="np-slider-val" id="np-bg-val">70%</span></label>
        </div>
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
      progress: $("#np-progress", root),
      time: $("#np-time", root),
      settingsBtn: $("#np-settings-btn", root),
      sourceBtn: $("#np-source-btn", root),
      panel: $("#np-panel", root),
      panelClose: $("#np-panel-close", root),
      panelTabs: $("#np-panel-tabs", root),
      autonext: $("#np-autonext", root),
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
      bound: false,
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
          if (!els.video?.paused) els.shell?.classList.remove("show-controls");
        }, 2800);
      }
    }

    function renderList(container, items, activeIndex, onPick, labelFn) {
      if (!container) return;
      container.innerHTML = "";
      if (!items.length) {
        const empty = document.createElement("button");
        empty.type = "button";
        empty.disabled = true;
        empty.textContent = "None";
        container.appendChild(empty);
        return;
      }
      items.forEach((item, idx) => {
        const b = document.createElement("button");
        b.type = "button";
        b.className = idx === activeIndex ? "is-active" : "";
        b.textContent = labelFn(item, idx);
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
        (s) => s.label || `${s.providerName || s.provider || "src"}${s.quality ? " · " + s.quality : ""}`
      );
      const qualities = [{ id: -1, height: 0, name: "Auto" }, ...state.levels];
      const qActive = state.levelIndex < 0 ? 0 : state.levelIndex + 1;
      renderList(
        els.qualityList,
        qualities,
        qActive,
        (i) => setQuality(i === 0 ? -1 : i - 1),
        (l) => l.name || (l.height ? `${l.height}p` : "Auto")
      );
      renderList(
        els.audioList,
        state.audioTracks,
        state.audioIndex,
        (i) => setAudio(i),
        (a) => a.name || a.lang || `Audio ${(a.id ?? 0) + 1}`
      );
      const subs = [{ name: "Off" }, ...state.textTracks];
      const sActive = state.textIndex < 0 ? 0 : state.textIndex + 1;
      renderList(
        els.subList,
        subs,
        sActive,
        (i) => setSubtitle(i - 1),
        (t) => t.name || t.lang || "Track"
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
      if (state.hls && state.hls.audioTracks) state.hls.audioTrack = idx;
      refreshMenus();
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
        if (state.autoplay) v.play().catch(() => setStatus("Tap play to start"));
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
          state.levels = (data.levels || []).map((l, i) => ({
            id: i,
            height: l.height,
            name: l.height ? `${l.height}p` : `Level ${i}`,
          }));
          state.levelIndex = -1;
          hls.currentLevel = -1;
          state.audioTracks = (hls.audioTracks || []).map((a, i) => ({
            id: i,
            name: a.name || a.lang || `Audio ${i + 1}`,
            lang: a.lang || "",
          }));
          state.audioIndex = hls.audioTrack >= 0 ? hls.audioTrack : state.audioTracks.length ? 0 : -1;
          if (hls.subtitleTracks && hls.subtitleTracks.length) {
            state.textTracks = hls.subtitleTracks.map((t, i) => ({
              id: i,
              name: t.name || t.lang || `Sub ${i + 1}`,
              lang: t.lang || "",
            }));
          }
          refreshMenus();
          loadExternalSubtitles(state.payloadSubtitles, source);
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
        loadExternalSubtitles(state.payloadSubtitles, source);
        v.addEventListener("loadedmetadata", onReady, { once: true });
      } else {
        v.src = abs;
        loadExternalSubtitles(state.payloadSubtitles, source);
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
      if (tab) switchTab(tab);
      showControls(false);
    }

    function closePanel() {
      if (els.panel) els.panel.hidden = true;
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
      els.sourceBtn?.addEventListener("click", () => openPanel("source"));
      els.panelClose?.addEventListener("click", closePanel);

      els.panelTabs?.querySelectorAll("[data-tab]").forEach((btn) => {
        btn.addEventListener("click", () => switchTab(btn.getAttribute("data-tab")));
      });

      if (els.autonext) {
        els.autonext.setAttribute("aria-pressed", state.autoNext ? "true" : "false");
        els.autonext.addEventListener("click", () => {
          state.autoNext = !state.autoNext;
          localStorage.setItem("cf_np_autonext", state.autoNext ? "1" : "0");
          els.autonext.setAttribute("aria-pressed", state.autoNext ? "true" : "false");
          setStatus(state.autoNext ? "Auto Next on" : "Auto Next off");
        });
      }

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
      v.addEventListener("timeupdate", syncUi);
      v.addEventListener("play", () => {
        syncUi();
        showControls();
      });
      v.addEventListener("pause", () => {
        syncUi();
        showControls(false);
      });
      v.addEventListener("volumechange", syncUi);
      v.addEventListener("ended", () => {
        if (cfg.type === "tv" && state.autoNext) goNextEpisode();
      });
      v.addEventListener("click", togglePlay);

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

    bind();
    syncUi();
    fetchSources();

    return {
      destroy() {
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
      ensureHls(() => mount(host, cfg));
    },
  };

  // Standalone /player page
  if (document.getElementById("np-shell") && window.PLAYER && !window.PLAYER.embed) {
    ensureHls(() => {
      active = createPlayer(Object.assign({}, window.PLAYER, { root: document, embed: false }));
    });
  }
})();
