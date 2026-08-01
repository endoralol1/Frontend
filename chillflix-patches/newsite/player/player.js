(function () {
  "use strict";

  const cfg = window.PLAYER || {};
  const $ = (sel, root) => (root || document).querySelector(sel);

  const els = {
    shell: $("#np-shell"),
    video: $("#np-video"),
    poster: $("#np-poster"),
    subs: $("#np-subs"),
    status: $("#np-status"),
    center: $("#np-center"),
    playBig: $("#np-playbig"),
    play: $("#np-play"),
    mute: $("#np-mute"),
    fs: $("#np-fs"),
    progress: $("#np-progress"),
    time: $("#np-time"),
    settingsBtn: $("#np-settings-btn"),
    sourceBtn: $("#np-source-btn"),
    panel: $("#np-panel"),
    panelClose: $("#np-panel-close"),
    autonext: $("#np-autonext"),
    sourceList: $("#np-source-list"),
    qualityList: $("#np-quality-list"),
    audioList: $("#np-audio-list"),
    subList: $("#np-sub-list"),
    delay: $("#np-delay"),
    delayVal: $("#np-delay-val"),
    size: $("#np-size"),
    sizeVal: $("#np-size-val"),
    bg: $("#np-bg"),
    bgVal: $("#np-bg-val"),
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
    autoplay: localStorage.getItem("cf_np_autoplay") !== "0",
    hideTimer: null,
    cueBases: new WeakMap(),
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
      // Prefer main origin for sealed proxy paths from cinepro/huhu
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
      empty.textContent = "None available";
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
      (s) => s.label || `${s.providerName || s.provider || "source"}${s.quality ? " · " + s.quality : ""}`
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
    if (els.play) els.play.textContent = playing ? "❚❚" : "▶";
    if (els.playBig) els.playBig.textContent = playing ? "❚❚" : "▶";
    if (els.mute) els.mute.textContent = v.muted || v.volume === 0 ? "🔇" : "🔊";
    if (els.time) els.time.textContent = `${fmt(v.currentTime)} / ${fmt(v.duration)}`;
    if (els.progress && Number.isFinite(v.duration) && v.duration > 0) {
      els.progress.value = String(Math.round((v.currentTime / v.duration) * 1000));
    }
    if (playing) els.shell?.classList.add("is-playing");
    else els.shell?.classList.remove("is-playing");
  }

  function setQuality(idx) {
    state.levelIndex = idx;
    if (state.hls) state.hls.currentLevel = idx;
    refreshMenus();
    setStatus(idx < 0 ? "Quality: Auto" : `Quality: ${state.levels[idx]?.height || ""}p`);
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
    span.style.fontSize = `${1.15 * state.fontScale}rem`;
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
    for (let i = 0; i < cues.length; i++) {
      parts.push(cues[i].text || "");
    }
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
        // hidden keeps cuechange firing for custom overlay
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
    if (state.textTracks.length && state.textIndex < 0) {
      // default off; user can enable
      state.textIndex = -1;
    }
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

  function playUrl(url, source, payloadSubs) {
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
      if (state.autoplay) {
        v.play().catch(() => setStatus("Tap play to start"));
      }
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
        loadExternalSubtitles(payloadSubs, source);
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
      loadExternalSubtitles(payloadSubs, source);
      v.addEventListener("loadedmetadata", onReady, { once: true });
    } else {
      v.src = abs;
      loadExternalSubtitles(payloadSubs, source);
      v.addEventListener("loadedmetadata", onReady, { once: true });
    }
  }

  let payloadSubtitles = [];

  function loadSource(index) {
    if (!state.sources.length) return;
    state.sourceIndex = Math.max(0, Math.min(index, state.sources.length - 1));
    const src = state.sources[state.sourceIndex];
    if (!src?.url) {
      setStatus("Source missing URL");
      return;
    }
    refreshMenus();
    playUrl(src.url, src, payloadSubtitles);
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
      payloadSubtitles = Array.isArray(data.subtitles) ? data.subtitles : [];
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
    const slug = encodeURIComponent(String(cfg.title || "show").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "show");
    location.href = `${(window.APP && window.APP.baseUrl) || "/newsite"}/player/tv/${slug}/${cfg.id}?s=${cfg.season || 1}&e=${next}`;
  }

  function togglePlay() {
    const v = els.video;
    if (!v) return;
    if (v.paused) v.play().catch(() => {});
    else v.pause();
    showControls();
  }

  function openPanel() {
    if (els.panel) els.panel.hidden = false;
    showControls(false);
  }

  function closePanel() {
    if (els.panel) els.panel.hidden = true;
  }

  function bind() {
    els.play?.addEventListener("click", togglePlay);
    els.playBig?.addEventListener("click", togglePlay);
    els.mute?.addEventListener("click", () => {
      els.video.muted = !els.video.muted;
      syncUi();
    });
    els.fs?.addEventListener("click", () => {
      if (!document.fullscreenElement) els.shell?.requestFullscreen?.();
      else document.exitFullscreen?.();
    });
    els.progress?.addEventListener("input", () => {
      const v = els.video;
      if (!v?.duration) return;
      v.currentTime = (Number(els.progress.value) / 1000) * v.duration;
    });
    els.settingsBtn?.addEventListener("click", openPanel);
    els.sourceBtn?.addEventListener("click", openPanel);
    els.panelClose?.addEventListener("click", closePanel);

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
  fetchSources();
})();
