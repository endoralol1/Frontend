?>
<main class="watch-p">
    <script>
        window.PLAYER = <?= json_encode($playerConfig ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script>
    /* newsite-autoplay-inline */
    (function () {
      function readPref() {
        try { if (localStorage.getItem('cf_watch_autoplay') === '1') return true; } catch (e) {}
        return /(?:^|;\s*)cf_watch_autoplay=1(?:;|$)/.test(document.cookie || '');
      }
      function writePref(on) {
        try { localStorage.setItem('cf_watch_autoplay', on ? '1' : '0'); } catch (e) {}
        document.cookie = 'cf_watch_autoplay=' + (on ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
      }
      function sync() {
        var on = readPref();
        var btn = document.getElementById('btn-autoplay');
        if (!btn) return on;
        btn.classList.toggle('is-on', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        var icon = btn.querySelector('i');
        if (icon) icon.className = on ? 'uil uil-check-circle' : 'uil uil-circle';
        return on;
      }
      function startIfNeeded() {
        if (!window.PLAYER) return;
        window.PLAYER.autoplay = true;
        if (document.getElementById('np-shell')) return;
        if (window.ChillflixPlayer && typeof window.ChillflixPlayer.startOnWatch === 'function') {
          window.ChillflixPlayer.startOnWatch(window.PLAYER);
          return;
        }
        // player.js may still be loading (defer)
        var tries = 0;
        var t = setInterval(function () {
          tries += 1;
          if (window.ChillflixPlayer && typeof window.ChillflixPlayer.startOnWatch === 'function') {
            clearInterval(t);
            window.ChillflixPlayer.startOnWatch(window.PLAYER);
          } else if (tries > 40) {
            clearInterval(t);
          }
        }, 100);
      }
      function toggle(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var on = !readPref();
        writePref(on);
        sync();
        if (on) startIfNeeded();
      }
      function bind() {
        var btn = document.getElementById('btn-autoplay');
        if (!btn || btn.dataset.cfBound === '1') return;
        btn.dataset.cfBound = '1';
        btn.addEventListener('click', toggle);
        sync();
        // If preference already on, auto-start after assets load
        var forced = false;
        var wrap = document.querySelector('.watch-wrap');
        if (wrap) forced = wrap.getAttribute('data-autoplay') === '1';
        if (readPref() || forced) {
          setTimeout(startIfNeeded, 150);
        }
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
      else bind();
      window.__cfSyncWatchAutoplay = sync;
      window.__cfStartWatchAutoplay = startIfNeeded;
    })();
    </script>
    <div class="container watch-wrap"
         data-id="<?= (int) $item['id'] ?>"
         data-type="<?= e($type) ?>"
