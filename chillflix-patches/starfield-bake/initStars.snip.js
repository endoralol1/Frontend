    /* ——— Stars: bake once to a fixed background image (no resize/RAF redraw) ——— */
    (function initStars() {
      var canvas = document.getElementById('cf-sky-stars');
      if (!canvas || !canvas.getContext) return;
      var host = canvas.parentElement || canvas;

      function mulberry32(a) {
        return function () {
          a |= 0; a = (a + 0x6d2b79f5) | 0;
          var t = Math.imul(a ^ (a >>> 15), 1 | a);
          t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
          return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
      }

      function bake() {
        var ctx = canvas.getContext('2d', { alpha: true, willReadFrequently: false });
        if (!ctx) return;
        // Fixed sky resolution — CSS cover scales it; no redraw on window resize
        var w = 1600;
        var h = 900;
        canvas.width = w;
        canvas.height = h;

        var rnd = mulberry32(0x5f75a2c1);
        var count = 360;
        var i;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, w, h);

        for (i = 0; i < count; i++) {
          var u = rnd();
          var size = Math.pow(u, 4.2) * 1.7 + 0.22;
          var bright = 0.18 + Math.pow(u, 2.8) * 0.82;
          var vibe = rnd();
          var r, g, b;
          if (vibe < 0.12) { r = 180; g = 205; b = 255; }
          else if (vibe > 0.93) { r = 255; g = 230; b = 200; }
          else { r = 235; g = 240; b = 255; }
          var x = rnd() * w;
          var y = rnd() * h;
          var a = Math.max(0.05, Math.min(1, bright));
          var layer = size > 1.15 ? 2 : (size > 0.6 ? 1 : 0);

          if (layer === 0) {
            // Tiny dust: cheap single fill (no gradients)
            ctx.fillStyle = 'rgba(' + r + ',' + g + ',' + b + ',' + (a * 0.85).toFixed(3) + ')';
            ctx.beginPath();
            ctx.arc(x, y, Math.max(0.35, size * 0.45), 0, Math.PI * 2);
            ctx.fill();
            continue;
          }

          var halo = size * (2.6 + layer * 1.0);
          var grad = ctx.createRadialGradient(x, y, 0, x, y, halo);
          grad.addColorStop(0, 'rgba(' + r + ',' + g + ',' + b + ',' + (a * 0.95).toFixed(3) + ')');
          grad.addColorStop(0.25, 'rgba(' + r + ',' + g + ',' + b + ',' + (a * 0.25).toFixed(3) + ')');
          grad.addColorStop(1, 'rgba(' + r + ',' + g + ',' + b + ',0)');
          ctx.fillStyle = grad;
          ctx.beginPath();
          ctx.arc(x, y, halo, 0, Math.PI * 2);
          ctx.fill();

          ctx.fillStyle = 'rgba(255,255,255,' + Math.min(1, a + 0.12).toFixed(3) + ')';
          ctx.beginPath();
          ctx.arc(x, y, Math.max(0.35, size * 0.3), 0, Math.PI * 2);
          ctx.fill();
        }

        function applyUrl(url) {
          try {
            host.style.backgroundImage = 'url("' + url + '")';
            host.style.backgroundSize = 'cover';
            host.style.backgroundPosition = 'center top';
            host.style.backgroundRepeat = 'no-repeat';
            canvas.style.display = 'none';
            canvas.width = 0;
            canvas.height = 0;
          } catch (eA) {
            // Fallback: leave the baked canvas stretched by CSS
            canvas.style.display = 'block';
            canvas.style.width = '100%';
            canvas.style.height = '100%';
          }
        }

        try {
          if (canvas.toBlob) {
            canvas.toBlob(function (blob) {
              if (!blob) {
                applyUrl(canvas.toDataURL('image/png'));
                return;
              }
              applyUrl(URL.createObjectURL(blob));
            }, 'image/webp', 0.82);
          } else {
            applyUrl(canvas.toDataURL('image/png'));
          }
        } catch (eB) {
          canvas.style.width = '100%';
          canvas.style.height = '100%';
        }
      }

      function boot() {
        try { bake(); } catch (e) {}
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();

      // Sky is fixed — nothing to refresh
      window.CF_refreshSkyMotion = function () {};
    })();
  })();

