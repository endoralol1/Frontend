(function () {
  "use strict";

  var PLACEHOLDER =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450'%3E%3Crect width='100%25' height='100%25' fill='%231a1c23'/%3E%3C/svg%3E";
  var IMG_SEL = ".movie-item.media-card .poster-media > img";
  var observed = typeof WeakSet !== "undefined" ? new WeakSet() : null;

  function realUrl(img) {
    var d = img.getAttribute("data-src") || img.getAttribute("data-cf-poster") || "";
    if (d && d.indexOf("data:") !== 0) return d;
    var cur = img.currentSrc || img.getAttribute("src") || "";
    if (cur && cur.indexOf("data:") !== 0) return cur;
    return "";
  }

  function isBusyCard(img) {
    var card = img.closest ? img.closest(".movie-item") : null;
    if (!card) return false;
    return card.classList.contains("is-hover-preview") || card.classList.contains("is-hover-collapsing");
  }

  function unload(img) {
    if (isBusyCard(img)) return;
    var url = realUrl(img);
    if (!url) return;
    var src = img.getAttribute("src") || "";
    if (!src || src.indexOf("data:") === 0) return;

    try {
      img.setAttribute("data-src", url);
      img.setAttribute("data-cf-poster", url);
      img.setAttribute("src", PLACEHOLDER);
      img.classList.add("lazyload");
      img.classList.remove("lazyloaded", "lazyloading");
      // Drop decoded bitmap from memory where supported
      if (typeof img.decode === "function" && img.replaceWith) {
        /* no-op — src swap is enough */
      }
    } catch (e) {}
  }

  function reload(img) {
    var url = realUrl(img);
    if (!url) return;
    img.setAttribute("data-src", url);
    var src = img.getAttribute("src") || "";
    if (src.indexOf("data:") === 0 || !src) {
      img.classList.add("lazyload");
      img.classList.remove("lazyloaded", "lazyloading");
      try {
        if (window.lazySizes && lazySizes.loader && typeof lazySizes.loader.unveil === "function") {
          lazySizes.loader.unveil(img);
        } else if (!img.getAttribute("src") || img.getAttribute("src").indexOf("data:") === 0) {
          img.setAttribute("src", url);
          img.classList.add("lazyloaded");
          img.classList.remove("lazyload");
        }
      } catch (e) {}
    }
  }

  function boot() {
    if (!("IntersectionObserver" in window)) return;

    // Keep images warm slightly outside the viewport; unload only when clearly away.
    var io = new IntersectionObserver(
      function (entries) {
        var i;
        for (i = 0; i < entries.length; i++) {
          var en = entries[i];
          var img = en.target;
          if (en.isIntersecting) reload(img);
          else unload(img);
        }
      },
      { root: null, rootMargin: "220px 160px", threshold: 0.01 }
    );

    function watch(img) {
      if (!img || (observed && observed.has(img))) return;
      if (observed) observed.add(img);
      var url = realUrl(img);
      if (url) {
        img.setAttribute("data-src", url);
        img.setAttribute("data-cf-poster", url);
      }
      io.observe(img);
    }

    function scan(root) {
      var scope = root && root.querySelectorAll ? root : document;
      var list = scope.querySelectorAll(IMG_SEL);
      var i;
      for (i = 0; i < list.length; i++) watch(list[i]);
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
            if (node.matches && node.matches(IMG_SEL)) watch(node);
            else if (node.querySelectorAll) scan(node);
          }
        }
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    window.addEventListener("cf:softnav", function () {
      setTimeout(function () { scan(document); }, 50);
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
