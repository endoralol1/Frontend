  // Realistic canvas starfield + moon always above hero
  (function initSky() {
    function isSkyReduced() {
      try {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
      } catch (e0) {}
      try {
        if (document.documentElement.classList.contains('cf-perf-mode')) return true;
      } catch (e1) {}
      return false;
    }
    var reduce = isSkyReduced();

    /* ——— Moon: behind content, under hero; fixed — no scroll-down drift ——— */
    (function initMoon() {
      var moon = null;
      var ticking = false;
      function syncUnderHero() {
        if (!moon) return;
        var hero = document.getElementById('featured');
        var mh = moon.offsetHeight || 180;
        var topPx;
        if (hero && hero.offsetHeight > 80) {
          // Mostly below hero bottom so it stays readable (not buried in shadow)
          topPx = Math.max(64, hero.offsetHeight - mh * 0.28);
        } else {
          topPx = Math.max(96, Math.min(window.innerHeight * 0.38, 380));
        }
        // Cap so it never sits absurdly low on tall PC heroes
        var maxTop = Math.min(window.innerHeight * 0.72, hero && hero.offsetHeight ? hero.offsetHeight + mh * 0.15 : 520);
        if (topPx > maxTop) topPx = maxTop;
        moon.style.top = topPx.toFixed(1) + 'px';
        // Stay put while scrolling — ambient is fixed; no downward parallax
        moon.style.transform = 'translate3d(0,0,0)';
      }
      function update() {
        ticking = false;
        syncUnderHero();
      }
      function onResize() {
        if (!ticking) {
          ticking = true;
          window.requestAnimationFrame(update);
        }
      }
      function boot() {
        moon = document.querySelector('.cf-bgfx-moon');
        if (!moon) return;
        syncUnderHero();
        // Resize / orientation only — do NOT drift on scroll
        window.addEventListener('resize', onResize, { passive: true });
        setTimeout(syncUnderHero, 250);
        setTimeout(syncUnderHero, 900);
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();
    })();

    /* ——— Stars: procedural, depth + soft glow (not CSS box-shadow dots) ——— */
    (function initStars() {
      var canvas = document.getElementById('cf-sky-stars');
      if (!canvas || !canvas.getContext) return;
      var ctx = canvas.getContext('2d', { alpha: true });
      var stars = [];
      var twinkle = [];
      var raf = 0;
      var w = 0;
      var h = 0;
      var dpr = 1;

      function mulberry32(a) {
        return function () {
          a |= 0; a = (a + 0x6d2b79f5) | 0;
          var t = Math.imul(a ^ (a >>> 15), 1 | a);
          t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
          return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
      }

      function buildStars() {
        var rnd = mulberry32(0x5f75a2c1);
        var area = (w * h) / (1920 * 1080);
        var count = Math.round((520 + 280 * Math.min(1.4, area)) * (w < 700 ? 0.72 : 1));
        stars = [];
        twinkle = [];
        var i;
        for (i = 0; i < count; i++) {
          // Power-law size: mostly dust, few bright suns
          var u = rnd();
          var size = Math.pow(u, 4.2) * 1.85 + 0.25;
          var bright = 0.18 + Math.pow(u, 2.8) * 0.82;
          // Color temperature: mostly cool white, some blue, rare warm
          var vibe = rnd();
          var r, g, b;
          if (vibe < 0.12) { r = 180; g = 205; b = 255; }      // blue-white
          else if (vibe > 0.93) { r = 255; g = 230; b = 200; }  // warm
          else { r = 235; g = 240; b = 255; }                   // natural white
          var s = {
            x: rnd() * w,
            y: rnd() * h,
            size: size,
            bright: bright,
            r: r, g: g, b: b,
            layer: size > 1.15 ? 2 : (size > 0.6 ? 1 : 0)
          };
          stars.push(s);
          // Only brighter stars gently twinkle
          if (!reduce && bright > 0.55 && rnd() < 0.35) {
            twinkle.push({
              i: stars.length - 1,
              phase: rnd() * Math.PI * 2,
              speed: 0.35 + rnd() * 0.9,
              amp: 0.12 + rnd() * 0.22
            });
          }
        }
      }

      function paintStar(s, mul) {
        var x = s.x;
        var y = s.y;
        var rad = s.size;
        var a = Math.max(0.05, Math.min(1, s.bright * mul));
        // Soft atmospheric halo
        var halo = rad * (2.8 + s.layer * 1.1);
        var g = ctx.createRadialGradient(x, y, 0, x, y, halo);
        g.addColorStop(0, 'rgba(' + s.r + ',' + s.g + ',' + s.b + ',' + (a * 0.95).toFixed(3) + ')');
        g.addColorStop(0.22, 'rgba(' + s.r + ',' + s.g + ',' + s.b + ',' + (a * 0.28).toFixed(3) + ')');
        g.addColorStop(0.55, 'rgba(' + s.r + ',' + s.g + ',' + s.b + ',' + (a * 0.08).toFixed(3) + ')');
        g.addColorStop(1, 'rgba(' + s.r + ',' + s.g + ',' + s.b + ',0)');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(x, y, halo, 0, Math.PI * 2);
        ctx.fill();
        // Hot core
        var core = Math.max(0.35, rad * 0.32);
        ctx.fillStyle = 'rgba(255,255,255,' + Math.min(1, a + 0.15).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(x, y, core, 0, Math.PI * 2);
        ctx.fill();
        // Subtle diffraction only on the brightest (keeps sky natural)
        if (s.layer === 2 && a > 0.75) {
          var spike = rad * 5.5;
          var sa = a * 0.18;
          ctx.strokeStyle = 'rgba(' + s.r + ',' + s.g + ',' + s.b + ',' + sa.toFixed(3) + ')';
          ctx.lineWidth = Math.max(0.4, rad * 0.18);
          ctx.beginPath();
          ctx.moveTo(x - spike, y);
          ctx.lineTo(x + spike, y);
          ctx.moveTo(x, y - spike);
          ctx.lineTo(x, y + spike);
          ctx.stroke();
        }
      }

      function drawFrame(t) {
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        var boost = {};
        var k;
        for (k = 0; k < twinkle.length; k++) {
          var tw = twinkle[k];
          boost[tw.i] = 1 + Math.sin(t * 0.001 * tw.speed + tw.phase) * tw.amp;
        }
        var i;
        for (i = 0; i < stars.length; i++) {
          paintStar(stars[i], boost[i] != null ? boost[i] : 1);
        }
      }

      function renderStatic() {
        drawFrame(0);
      }

      function loop(t) {
        drawFrame(t || 0);
        raf = window.requestAnimationFrame(loop);
      }

      function resize() {
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        w = Math.max(1, window.innerWidth || 1);
        h = Math.max(1, window.innerHeight || 1);
        canvas.width = Math.floor(w * dpr);
        canvas.height = Math.floor(h * dpr);
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        buildStars();
        if (reduce) renderStatic();
        else {
          if (raf) cancelAnimationFrame(raf);
          raf = requestAnimationFrame(loop);
        }
      }

      var resizeTimer = 0;
      function onResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(resize, 120);
      }

      function boot() {
        resize();
        window.addEventListener('resize', onResize, { passive: true });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();

      window.CF_refreshSkyMotion = function () {
        reduce = isSkyReduced();
        try {
          if (raf) cancelAnimationFrame(raf);
          raf = 0;
        } catch (eR) {}
        try { resize(); } catch (eS) {}
      };
    })();
  })();



