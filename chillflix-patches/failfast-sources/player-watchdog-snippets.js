2205:          return false;
2206:        }
2207:      };
2208:
2209:      const onReady = () => {
2210:        clearLoadWatchdog();
2211:        setStatus("");
2212:        if (!applyResumeIfNeeded()) {
2213:          let poll = null;
2214:          const stop = () => {
2215:            if (poll) {

2385:          fetchExternalSubtitleCatalog();
2386:          onReady();
2387:        });
2388:        // Give slow CDNs / media-proxy a longer runway before abandoning the source.
2389:        // Manual re-click often worked because the second attempt was already warm.
2390:        state.hlsFatalCount = 0;
2391:        state.hlsLoadStartedAt = Date.now();
2392:        armLoadWatchdog(source);
2393:        hls.on(window.Hls.Events.ERROR, (_e, data) => {
2394:          if (!data?.fatal) return;
2395:          const ageMs = Date.now() - (state.hlsLoadStartedAt || Date.now());
2396:          const graceMs = sourceAutoWaitMs(source);
2397:          state.hlsFatalCount = (state.hlsFatalCount || 0) + 1;
2398:          const count = state.hlsFatalCount;
2399:          const details = String(data.details || "");
2400:          const httpCode = Number(data.response?.code || data.response?.status || 0);
2401:          // Manifest/key hard-miss: title not on this CDN — don't burn Auto-wait retries.
2402:          const hardMiss =
2403:            httpCode === 404 ||
2404:            httpCode === 410 ||
2405:            details === "manifestLoadError" ||
2406:            details === "manifestParsingError" ||
2407:            ((httpCode === 403 || /not found|404/i.test(String(data.response?.text || data.reason || ""))) &&
2408:              /manifestLoadError|levelLoadError|keyLoadError/i.test(details));
2409:
2410:          if (hardMiss) {
2411:            clearLoadWatchdog();
2412:            const slot = state.sources[state.sourceIndex];
2413:            if (slot) {
2414:              slot.status = "error";
2415:              slot.error = "Not found";
2416:              try { refreshMenus(); } catch (_) {}
2417:            }
2418:            setStatus("Not found — next source…");
2419:            tryNextSource({ immediate: true });
2420:            return;
2421:          }
2422:
2423:          if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
2424:            setStatus(t('player.network_retry', 'Network error — retrying…'));
2425:            if (count <= 3 || ageMs < graceMs) {

2455:        state.qualityOptions = [];
2456:        refreshMenus();
2457:        loadExternalSubtitles(state.payloadSubtitles, source);
2458:        fetchExternalSubtitleCatalog();
2459:        armLoadWatchdog(source);
2460:        v.addEventListener("loadedmetadata", onReady, { once: true });
2461:      } else {
2462:        onSourceWillChange();
2463:        v.src = abs;
2464:        state.audioTracks = fallbackAudioTracks(source);
2465:        state.audioIndex = preferEnglishAudioIndex(state.audioTracks, source?.preferAudio || source?.language || "en");
2466:        state.qualityOptions = [];
2467:        refreshMenus();
2468:        loadExternalSubtitles(state.payloadSubtitles, source);
2469:        fetchExternalSubtitleCatalog();
2470:        // MP4 / progressive: wait Auto wait seconds before abandoning
2471:        const stallMs = sourceAutoWaitMs(source);
2472:        armLoadWatchdog(source);
2473:        const stallTimer = setTimeout(() => {
2474:          if (!v || v.readyState >= 2) return;
2475:          clearLoadWatchdog();
2476:          const slot = state.sources[state.sourceIndex];
2477:          if (slot) {
2478:            slot.status = "error";
2479:            slot.error = "Stream stalled";
2480:            try { refreshMenus(); } catch (_) {}
2481:          }
2482:          setStatus("Stream stalled — next source…");
2483:          tryNextSource({ immediate: true });
2484:        }, stallMs);
2485:        v.addEventListener(
2486:          "loadedmetadata",
2487:          () => {
2488:            clearTimeout(stallTimer);
2489:            onReady();
2490:          },

2835:    }
2836:
2837:    function sourceAutoWaitMs(source) {
2838:      const sec = Number(source?.autoWaitSec);
2839:      if (Number.isFinite(sec) && sec > 0) return Math.max(3000, Math.min(120000, sec * 1000));
2840:      // Fallback from current slot
2841:      const slot = state.sources[state.sourceIndex];
2842:      const s2 = Number(slot?.autoWaitSec);
2843:      if (Number.isFinite(s2) && s2 > 0) return Math.max(3000, Math.min(120000, s2 * 1000));
2844:      return 15000;
2845:    }
2846:
2847:    function clearLoadWatchdog() {
2848:      if (state.loadWatchdog) {
2849:        try { clearTimeout(state.loadWatchdog); } catch (_) {}
2850:        state.loadWatchdog = null;
2851:      }
2852:    }
2853:
2854:    /** If a "ready" source never becomes playable (hung proxy / dead CDN), cascade. */
2855:    function armLoadWatchdog(source) {
2856:      clearLoadWatchdog();
2857:      const waitMs = Math.max(8000, Math.min(20000, sourceAutoWaitMs(source)));
2858:      const token = (state.loadWatchdogToken = (state.loadWatchdogToken || 0) + 1);
2859:      const idxAtArm = state.sourceIndex;
2860:      state.loadWatchdog = setTimeout(() => {
2861:        if (state.loadWatchdogToken !== token) return;
2862:        if (state.sourceIndex !== idxAtArm) return;
2863:        const v = els.video;
2864:        const playable =
2865:          v &&
2866:          !v.error &&
2867:          v.readyState >= 2 &&
2868:          Number.isFinite(v.duration) &&
2869:          v.duration > 0;
2870:        if (playable) return;
2871:        const slot = state.sources[state.sourceIndex];
2872:        if (slot) {
2873:          slot.status = "error";
2874:          slot.error = "Stream not loading";
2875:          try { refreshMenus(); } catch (_) {}
2876:        }
2877:        setStatus("No stream — next source…");
2878:        tryNextSource({ immediate: true });
2879:      }, waitMs);
2880:    }
2881:
2882:    function tryNextSource(opts) {
2883:      opts = opts || {};
2884:      const slot = state.sources[state.sourceIndex];
2885:      const waitMs = sourceAutoWaitMs(slot);
2886:      const started = state.sourcePlayStartedAt || 0;
2887:      const elapsed = started ? (Date.now() - started) : waitMs;
2888:      // Hard miss / empty: skip Auto-wait and cascade immediately.
2889:      const skipWait = !!opts.immediate || slot?.status === "empty";
2890:      // If playback was attempted, respect Auto wait before cascading (transient errors only).

3160:    function clampVideoTranslate(x, y, scale, containerWidth, containerHeight, safe) {
3161:      if (scale <= VIDEO_ZOOM_MIN) return { x: 0, y: 0 };
3162:      const bleedX = (safe?.left || 0) + (safe?.right || 0);
3163:      const bleedY = (safe?.top || 0) + (safe?.bottom || 0);
3164:      const maxX = (containerWidth * (scale - 1)) / 2 + bleedX;
3165:      const maxY = (containerHeight * (scale - 1)) / 2 + bleedY;
3166:      return {
3167:        x: Math.max(-maxX, Math.min(maxX, x)),
3168:        y: Math.max(-maxY, Math.min(maxY, y)),
3169:      };
3170:    }