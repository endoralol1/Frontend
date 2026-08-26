/**
 * Hero backdrop quality: keep w1280 for LCP / mobile / performance mode,
 * upgrade to w1920 on large / high-DPR displays (2K–4K).
 * Must load before app.docksearch-*.js so Swiper unveils the right URL.
 */
(function () {
  function wantsHiHero() {
    try {
      if (document.documentElement.classList.contains('cf-perf-mode')) return false;
    } catch (e0) {}
    try {
      var c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      if (c && (c.saveData || /2g/.test(String(c.effectiveType || '')))) return false;
    } catch (e1) {}
    var w = window.innerWidth || 0;
    var dpr = window.devicePixelRatio || 1;
    // Desktop wide, or laptop with retina / 2K+ effective pixels
    if (w >= 1600) return true;
    if (w >= 1200 && dpr >= 1.5) return true;
    if (w * dpr >= 2200) return true;
    return false;
  }

  function applyHeroQuality() {
    if (!wantsHiHero()) return;
    var root = document.getElementById('featured');
    if (!root) return;

    root.querySelectorAll('.swiper-slide[data-bg-hi]').forEach(function (slide) {
      var hi = slide.getAttribute('data-bg-hi');
      if (!hi) return;

      // Prefer hi for lazy unveils / slideChange
      if (slide.getAttribute('data-bgset')) {
        slide.setAttribute('data-bgset', hi);
      }

      // First slide already painted with w1280 — swap after decode
      var hasBg = !!(slide.style.backgroundImage && slide.style.backgroundImage !== 'none');
      var lcp = slide.querySelector('img.hero-lcp');
      if (!hasBg && !lcp) return;

      var img = new Image();
      img.decoding = 'async';
      img.onload = function () {
        slide.style.backgroundImage = 'url(' + hi + ')';
        if (lcp) lcp.src = hi;
        slide.classList.add('hero-bg-hi');
      };
      img.src = hi;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyHeroQuality);
  } else {
    applyHeroQuality();
  }
})();
