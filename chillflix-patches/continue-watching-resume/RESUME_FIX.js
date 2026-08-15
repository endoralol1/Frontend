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
