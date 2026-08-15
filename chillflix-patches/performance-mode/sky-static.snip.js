  // Realistic canvas starfield + moon always above hero
  (function initSky() {
    function isSkyReduced() {
      // Always static sky (no RAF twinkle) — keeps FPS healthy for everyone
      return true;
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
----
                                <strong>Performance mode</strong>
                                <em>Turns off film grain, shiny titles, large-screen zoom, frosted-glass panels, and hover trailers.</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-performance" role="switch" aria-checked="false" aria-label="Performance mode"></button>
                        </div>
                    </section>

