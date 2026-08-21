2385:        // Give slow CDNs / media-proxy a longer runway before abandoning the source.
2386:        // Manual re-click often worked because the second attempt was already warm.
2387:        state.hlsFatalCount = 0;
2388:        state.hlsLoadStartedAt = Date.now();
2389:        hls.on(window.Hls.Events.ERROR, (_e, data) => {
2390:          if (!data?.fatal) return;
2391:          const ageMs = Date.now() - (state.hlsLoadStartedAt || Date.now());
2392:          const graceMs = sourceAutoWaitMs(source);
2393:          state.hlsFatalCount = (state.hlsFatalCount || 0) + 1;
2394:          const count = state.hlsFatalCount;
2395:          const details = String(data.details || "");
2396:          const httpCode = Number(data.response?.code || data.response?.status || 0);
2397:          // Manifest/key hard-miss: title not on this CDN — don't burn Auto-wait retries.
2398:          const hardMiss =
2399:            httpCode === 404 ||
2400:            httpCode === 410 ||
2401:            details === "manifestLoadError" ||
2402:            details === "manifestParsingError" ||
2403:            ((httpCode === 403 || /not found|404/i.test(String(data.response?.text || data.reason || ""))) &&
2404:              /manifestLoadError|levelLoadError|keyLoadError/i.test(details));
2405:
2406:          if (hardMiss) {
2407:            setStatus("Not found — next source…");
2408:            tryNextSource({ immediate: true });
2409:            return;
2410:          }
2411:
2412:          if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
2413:            setStatus(t('player.network_retry', 'Network error — retrying…'));
2414:            if (count <= 3 || ageMs < graceMs) {
2415:              try {
2416:                hls.startLoad();
2417:              } catch (_) {}
2418:              return;
2419:            }
2420:          } else if (data.type === window.Hls.ErrorTypes.MEDIA_ERROR) {
2421:            setStatus("Media error — recovering…");
2422:            if (count <= 3 || ageMs < graceMs) {
2423:              try {
2424:                hls.recoverMediaError();
2425:              } catch (_) {}
2426:              return;
2427:            }
2428:          } else if (count <= 2 || ageMs < graceMs) {
2429:            setStatus(t('player.network_retry', 'Network error — retrying…'));
2430:            try {

2550:        }
2551:        throw err;
2552:      }
2553:      clearTimeout(timer);
2554:      let data = null;
2555:      try {
2556:        data = await res.json();
2557:      } catch (_) {
2558:        throw new Error(`Bad response from ${slot.providerName || pid} (${res.status || 0})`);
2559:      }
2560:      if (!data?.ok || !Array.isArray(data.sources) || !data.sources.length) {
2561:        const err = new Error(data?.error || `No streams from ${slot.providerName || pid}`);
2562:        err.code = String((Array.isArray(data?.diagnostics) && data.diagnostics[0] && data.diagnostics[0].code) || "PROVIDER_EMPTY");
2563:        err.diagnostics = Array.isArray(data?.diagnostics) ? data.diagnostics : [];
2564:        // API already said empty / not found — do not scrape again.
2565:        err.definitiveEmpty = true;
2566:        throw err;
2567:      }
2568:      const matched = data.sources.filter((s) => String(s?.provider || "").toLowerCase() === pid);
2569:      const pool = matched.length ? matched : data.sources;
2570:      const found = pool[0];
2571:      const keepName = slot.providerName;
2572:      const keepPublic = slot.publicLabel;
2573:      // Merge qualities + language packs from every returned stream into one Source card
2574:      const qualities = [];
2575:      const audioTracks = [];

2690:
2691:      // Deduplicate in-flight scrapes for the same provider.
2692:      if (state.providerLoads[pid]) {
2693:        try {
2694:          await state.providerLoads[pid];
2695:        } catch (_) {}
2696:        if (play && state.sources[idx]?.status === "ready") startPlaybackForIndex(idx);
2697:        return state.sources[idx]?.status === "ready";
2698:      }
2699:
2700:      slot.status = "loading";
2701:      refreshMenus();
2702:      setStatus(`Fetching ${slot.providerName || pid}…`);
2703:
2704:      const slowProviders = new Set(["vsembed", "flixhqz", "cineplay", "vidmoly", "hdghar", "bingr", "castle", "awsind"]);
2705:      // Retry only for flaky network/timeouts — never for definitive "not found"/empty.
2706:      const maxAttempts = (slowProviders.has(pid) || (Number(slot.scrapeTimeoutSec) || 0) >= 60) ? 2 : 1;
2707:
2708:      const isDefinitiveEmpty = (err) => {
2709:        if (err && err.definitiveEmpty) return true;
2710:        const msg = String(err?.message || "");
2711:        const code = String(err?.code || "");
2712:        const diags = Array.isArray(err?.diagnostics) ? err.diagnostics : [];
2713:        const diagBlob = diags.map((d) => String(d?.code || "") + " " + String(d?.message || "")).join(" ");
2714:        const blob = `${msg} ${code} ${diagBlob}`;
2715:        return /_EMPTY\b|UPSTREAM_DEAD|no source|not available|empty|no playable|no streams|not found|No playable sources|HDGHAR_EMPTY|no hdghar|no holly/i.test(blob);
2716:      };
2717:
2718:      const run = (async () => {
2719:        let lastErr = null;
2720:        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
2721:          try {
2722:            if (attempt > 1) {
2723:              setStatus(`Retrying ${slot.providerName || pid}…`);
2724:              await new Promise((r) => setTimeout(r, 1600));
2725:            }
2726:            return await fetchProviderSourcesOnce(pid, slot);
2727:          } catch (err) {
2728:            lastErr = err;
2729:            // Not found / empty from provider = final answer for this source.
2730:            if (isDefinitiveEmpty(err)) break;
2731:          }
2732:        }
2733:        throw lastErr || new Error(`No streams from ${slot.providerName || pid}`);
2734:      })();
2735:
2736:      state.providerLoads[pid] = run;
2737:      try {
2738:        await run;
2739:        if (isAuto && slot.hasEnglish === false && !slot.autoloadNonEnglish) {
2740:          slot.status = "ready";
2741:          const next = idx + 1;
2742:          if (next < state.sources.length) {
2743:            setStatus(`${slot.providerName || pid} has no English — skipping…`);
2744:            return ensureProviderLoaded(next, { play: true, isAuto: true });
2745:          }
2746:          setStatus(t('player.no_sources', 'No playable sources'));
2747:          return false;
2748:        }
2749:        if (play) startPlaybackForIndex(idx);
2750:        return true;
2751:      } catch (err) {
2752:        const emptyish = !!(err && err.definitiveEmpty) || /_EMPTY\b|UPSTREAM_DEAD|no source|not available|empty|no playable|no streams|not found|No playable sources|no hdghar|no holly|HDGHAR_EMPTY/i.test(
2753:          String(err?.message || "") + " " + String(err?.code || "")
2754:        );
2755:        slot.status = emptyish ? "empty" : "error";
2756:        slot.error = err?.message || t('common.failed', 'Failed');
2757:        refreshMenus();
2758:        if (isAuto || play) {
2759:          // Auto / failure path: try next admin-ordered source immediately (no re-scrape of empty).
2760:          const next = idx + 1;
2761:          if (next < state.sources.length) {
2762:            setStatus(`${slot.providerName || pid} ${emptyish ? "not found" : "failed"} — next…`);
2763:            return ensureProviderLoaded(next, { play: true, isAuto: true });
2764:          }
2765:          setStatus(emptyish ? t('player.no_sources', 'No playable sources') : (err?.message || t('player.no_sources', 'No playable sources')));
2766:        } else {
2767:          setStatus(emptyish ? (err?.message || "Not found for this title") : (err?.message || "Source failed"));
2768:        }
2769:        return false;
2770:      } finally {
2771:        delete state.providerLoads[pid];
2772:      }
2773:    }
2774:
2775:    function startPlaybackForIndex(index) {

2790:          src.url = pick.switchUrl || pick.url;
2791:          src.language = isEnglishToken(pick.lang || pick.language || pick.name) ? "en" : (pick.lang || src.language);
2792:        }
2793:        state.audioTracks = fallbackAudioTracks(src);
2794:        state.audioIndex = preferEnglishAudioIndex(state.audioTracks, "en");
2795:      }
2796:      if (applySourceQualities(src)) {
2797:        // levelIndex already set to default (1080p)
2798:      } else {
2799:        state.levelIndex = -1;
2800:        state.qualityOptions = [];
2801:      }
2802:      const url = src.url || candidateUrls(src)[0] || "";
2803:      if (!url && !isCineplayYoruSource(url, src)) {
2804:        setStatus("Source missing URL");
2805:        return;
2806:      }
2807:      refreshMenus();
2808:      playUrl(url, src);
2809:    }
2810:
2811:    function loadSource(index) {
2812:      onSourceWillChange();
2813:      // Selecting a source scrapes that provider on demand (not all providers).
2814:      ensureProviderLoaded(index, { play: true, isAuto: false });
2815:    }
2816:
2817:    function sourceAutoWaitMs(source) {
2818:      const sec = Number(source?.autoWaitSec);
2819:      if (Number.isFinite(sec) && sec > 0) return Math.max(3000, Math.min(120000, sec * 1000));
2820:      // Fallback from current slot
2821:      const slot = state.sources[state.sourceIndex];
2822:      const s2 = Number(slot?.autoWaitSec);
2823:      if (Number.isFinite(s2) && s2 > 0) return Math.max(3000, Math.min(120000, s2 * 1000));
2824:      return 15000;
2825:    }
2826:
2827:    function tryNextSource(opts) {
2828:      opts = opts || {};
2829:      const slot = state.sources[state.sourceIndex];
2830:      const waitMs = sourceAutoWaitMs(slot);
2831:      const started = state.sourcePlayStartedAt || 0;
2832:      const elapsed = started ? (Date.now() - started) : waitMs;
2833:      // Hard miss / empty: skip Auto-wait and cascade immediately.
2834:      const skipWait = !!opts.immediate || slot?.status === "empty";
2835:      // If playback was attempted, respect Auto wait before cascading (transient errors only).
2836:      if (!skipWait && started && elapsed < waitMs) {
2837:        const left = Math.max(250, waitMs - elapsed);
2838:        setStatus(`Waiting on ${slot?.providerName || slot?.provider || 'source'}… ${Math.ceil(left/1000)}s`);
2839:        clearTimeout(state.autoWaitTimer);
2840:        state.autoWaitTimer = setTimeout(() => {
2841:          // Re-check: if still on same source and not playing, move on.
2842:          const v = els.video;
2843:          if (v && v.readyState >= 2 && !v.error) return;
2844:          if (state.sourceIndex + 1 < state.sources.length) {
2845:            ensureProviderLoaded(state.sourceIndex + 1, { play: true, isAuto: true });