(function () {
  "use strict";

  // Lifecycle softened: do NOT unload posters on scroll-away.
  // Decode thrash from unload/reload was a major scroll FPS cost.
  // Lazysizes already defers offscreen loads; we only ensure data-src is set.

  var IMG_SEL = ".movie-item.media-card .poster-media > img";
  var observed = typeof WeakSet !== "undefined" ? new WeakSet() : null;

  function boot() {
    function scan(root) {
      var scope = root && root.querySelectorAll ? root : document;
      var list = scope.querySelectorAll(IMG_SEL);
      var i;
      for (i = 0; i < list.length; i++) {
        var img = list[i];
        if (observed && observed.has(img)) continue;
        if (observed) observed.add(img);
        var src = img.getAttribute("data-src") || "";
        if (!src) {
          var cur = img.getAttribute("src") || "";
          if (cur && cur.indexOf("data:") !== 0) img.setAttribute("data-src", cur);
        }
      }
    }

    scan(document);
    if ("MutationObserver" in window) {
      var mo = new MutationObserver(function (muts) {
        var m, j, node;
        for (m = 0; m < muts.length; m++) {
          var nodes = muts[m].addedNodes;
          for (j = 0; j < nodes.length; j++) {
            node = nodes[j];
            if (!node || node.nodeType !== 1) continue;
            if (node.querySelectorAll) scan(node);
          }
        }
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
