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
            return ensureProviderLoaded(next, { play: true, isAuto: true });
          }
          setStatus(t('player.no_sources', 'No playable sources'));
          return false;
        slot.error = reason || "Stream not loading";
        // Drop fake "ready" subtitle (English · 1080p) in the Source menu.
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
      const waitMs = coldResolve
        ? Math.max(45000, Math.min(90000, Math.max(base, scrapeMs)))
        : Math.max(6000, Math.min(12000, base));
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
          return;
        }
        if (v && v.error) {
          fail("Stream error");
        }
      }, 500);
    }
