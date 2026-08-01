    <script>
        /* cw-seed-on-watch-page: persist Continue Watching without waiting for player.js */
        (function () {
          try {
            var cfg = window.PLAYER;
            if (!cfg || !cfg.id) return;
            var key = (cfg.type === 'tv' ? 'tv' : 'movie') + ':' + cfg.id;
            if (cfg.type === 'tv') key += ':s' + (cfg.season || 1) + 'e' + (cfg.episode || 1);
            var map = {};
            try { map = JSON.parse(localStorage.getItem('cf_continue_v1') || '{}') || {}; } catch (e) { map = {}; }
            var prev = map[key] || {};
            map[key] = {
              id: Number(cfg.id) || 0,
              type: cfg.type === 'tv' ? 'tv' : 'movie',
              title: cfg.title || prev.title || 'Untitled',
              poster: cfg.poster || prev.poster || '',
              backdrop: cfg.backdrop || prev.backdrop || '',
              year: cfg.year || prev.year || '',
              season: cfg.type === 'tv' ? (Number(cfg.season) || 1) : null,
              episode: cfg.type === 'tv' ? (Number(cfg.episode) || 1) : null,
              t: Math.max(1, Number(prev.t) || 0),
              d: Number(prev.d) || 0,
              updated: Date.now(),
              url: cfg.watchUrl || cfg.backUrl || (location.pathname + location.search)
            };
            // keep newest 36
            var keys = Object.keys(map).sort(function (a, b) {
              return (map[b].updated || 0) - (map[a].updated || 0);
            });
            keys.slice(36).forEach(function (k) { delete map[k]; });
            localStorage.setItem('cf_continue_v1', JSON.stringify(map));
            // cookie mirror (compact) so home can recover if LS quirks
            try {
              var compact = keys.slice(0, 8).map(function (k) { return map[k]; });
              document.cookie = 'cf_continue_v1=' + encodeURIComponent(JSON.stringify(compact))
                + ';path=/;max-age=31536000;SameSite=Lax';
            } catch (e2) {}
          } catch (err) {}
        })();
    </script>
