          return false;
        }
      };

      const onReady = () => {
        // Manifest/metadata is NOT enough — Bingr often "ready" at 0:00/0:00.
        // Only disarm when media is actually playable; otherwise keep the watchdog.
        if (mediaLooksPlayable(els.video)) {
          clearLoadWatchdog();
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
        if (mediaLooksPlayable(els.video)) clearLoadWatchdog();
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
        const viaLangProxy = /\/api\/player\/lang-proxy\b/i.test(String(abs || source?.url || ""));
        const hls = new window.Hls({
          enableWorker: true,
          lowLatencyMode: false,
          backBufferLength: viaLangProxy ? 60 : 30,
          maxBufferLength: viaLangProxy ? 60 : 30,
          maxMaxBufferLength: viaLangProxy ? 120 : 60,
      const slot = state.sources[state.sourceIndex];
      const s2 = Number(slot?.autoWaitSec);
      if (Number.isFinite(s2) && s2 > 0) return Math.max(3000, Math.min(120000, s2 * 1000));
      return 15000;
    }

    function mediaLooksPlayable(v) {
      return !!(
        v &&
        !v.error &&
        v.readyState >= 2 &&
        Number.isFinite(v.duration) &&
        v.duration > 0
      );
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

    function markSourceDead(reason) {
      const slot = state.sources[state.sourceIndex];
      if (slot) {
        slot.status = "error";
        slot.error = reason || "Stream not loading";
        // Drop fake "ready" subtitle (English · 1080p) in the Source menu.
        try { refreshMenus(); } catch (_) {}
      }
    }

    /** If a "ready" source never becomes playable (hung proxy / dead CDN), cascade. */
    function armLoadWatchdog(source) {
      clearLoadWatchdog();
      // Keep this snappy — metadata "ready" is not the same as a working stream.
      const waitMs = Math.max(6000, Math.min(12000, sourceAutoWaitMs(source)));
      const token = (state.loadWatchdogToken = (state.loadWatchdogToken || 0) + 1);
      const idxAtArm = state.sourceIndex;
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
