/**
 * Chillflix / Vuflix Bot Shield v1.1 (SG farm → nginx/CF; GA human-gated)
 * Catches dumb scrapers / honeypot hits / headless tools.
 * Real browsers that interact normally are left alone.
 * Cloudflare remains the primary bot filter.
 */
(function () {
  "use strict";

  var HARD_DAYS = 30;
  var STORAGE_HARD = "cf_bot_shield_v1_hard";
  var STORAGE_HUMAN = "cf_bot_shield_v1_human";
  var HONEYPOT_PARAMS = ["bot_trap", "copyright_audit", "dmca_scan", "scanner_check"];
  // Harmless public TMDB decoy (The Beverly Hillbillies)
  var DECOY_PATH = "/tv/1930";
  var DECOY_HASH = "#/tv/1930/1/1";

  var BAD_UA = [
    "markmonitor", "opsec", "leakid", "copytrack", "picrights",
    "brandprotector", "ip-echelon", "cosight", "digimarc", "audiblemagic",
    "anti-piracy", "bytespider", "ahrefs", "semrush", "mj12", "dotbot",
    "scrapy", "python-requests", "python-urllib", "curl/", "wget/",
    "puppeteer", "playwright", "headlesschrome", "phantomjs",
    "axios/", "postman", "go-http-client", "libwww", "zgrab",
    "nmap", "masscan", "censys", "shodan", "netcraft", "nikto",
    "openvas", "sqlmap", "dirbuster", "gobuster", "petalbot",
    "claudebot", "gptbot", "chatgpt-user", "ccbot", "cohere-ai",
    "anthropic-ai", "google-extended", "amazonbot"
  ];

  function now() {
    return Date.now();
  }

  function path() {
    try {
      return String(window.location.pathname || "");
    } catch (e) {
      return "";
    }
  }

  function isExemptPath() {
    var p = path().toLowerCase();
    // Don't break embeds, APIs, admin, or static assets
    if (
      p.indexOf("/embed") === 0 ||
      p.indexOf("/api/") === 0 ||
      p.indexOf("/admin") === 0 ||
      p.indexOf("/_next/") === 0 ||
      p.indexOf("/assets/") === 0
    ) {
      return true;
    }
    return false;
  }

  function markHuman(e) {
    if (!e || !e.isTrusted) return;
    try {
      sessionStorage.setItem(STORAGE_HUMAN, "1");
    } catch (err) {}
  }

  try {
    window.addEventListener("mousemove", markHuman, { passive: true });
    window.addEventListener("pointerdown", markHuman, { passive: true });
    window.addEventListener("touchstart", markHuman, { passive: true });
    window.addEventListener("keydown", markHuman, { passive: true });
    window.addEventListener("scroll", markHuman, { passive: true });
  } catch (eBind) {}

  function isHardBlocked() {
    try {
      var exp = localStorage.getItem(STORAGE_HARD);
      if (exp && now() < parseInt(exp, 10)) return true;
      if (exp) localStorage.removeItem(STORAGE_HARD);
    } catch (e) {}
    return false;
  }

  function setRestrictedFlag() {
    try {
      window.__cfBotShieldRestricted = isHardBlocked();
    } catch (e) {}
  }

  function triggerHardBlock() {
    try {
      localStorage.setItem(STORAGE_HARD, String(now() + HARD_DAYS * 864e5));
    } catch (e) {}
    setRestrictedFlag();
    enforceDecoyRedirect();
  }

  function detectBotSignatures() {
    var ua = (navigator.userAgent || "").toLowerCase();
    var i;
    for (i = 0; i < BAD_UA.length; i++) {
      if (ua.indexOf(BAD_UA[i]) !== -1) return "ua";
    }
    try {
      if (navigator.webdriver) return "webdriver";
    } catch (e1) {}
    try {
      if (document.documentElement.getAttribute("webdriver")) return "webdriver-attr";
    } catch (e2) {}
    if (
      window._phantom ||
      window.callPhantom ||
      window.__nightmare ||
      window.domAutomation ||
      window.domAutomationController
    ) {
      return "automation";
    }
    var search = (window.location.search || "").toLowerCase();
    for (i = 0; i < HONEYPOT_PARAMS.length; i++) {
      if (search.indexOf(HONEYPOT_PARAMS[i]) !== -1) return "honeypot-qs";
    }
    return null;
  }

  function isWatchLikePath(p) {
    return /\/(movie|tv|player|watch)\b/i.test(p || path());
  }

  function enforceDecoyRedirect() {
    if (!isHardBlocked() || isExemptPath()) return;
    var p = path();
    var hash = "";
    try {
      hash = String(window.location.hash || "");
    } catch (e) {}

    // Hash-router SPA (e.g. #/movie/… or #/tv/…)
    if (hash.indexOf("#/movie/") !== -1 || hash.indexOf("#/tv/") !== -1) {
      if (hash.indexOf(DECOY_HASH) !== 0) {
        try {
          window.location.hash = DECOY_HASH;
        } catch (eH) {}
      }
      return;
    }

    // Path-router watch pages
    if (isWatchLikePath(p) && p.indexOf(DECOY_PATH) !== 0) {
      try {
        window.location.replace(DECOY_PATH);
      } catch (eP) {
        try {
          window.location.href = DECOY_PATH;
        } catch (eP2) {}
      }
    }
  }

  function injectHoneypots() {
    if (isExemptPath()) return;

    function trap(param) {
      var a = document.createElement("a");
      a.href = (window.location.pathname || "/") + "?" + param + "=1";
      a.setAttribute("aria-hidden", "true");
      a.setAttribute("tabindex", "-1");
      a.setAttribute("rel", "nofollow");
      a.style.cssText =
        "position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;";
      a.textContent = "System Audit Link";
      a.addEventListener("click", function (e) {
        try {
          e.preventDefault();
        } catch (err) {}
        triggerHardBlock();
      });
      return a;
    }

    function append() {
      if (!document.body) return;
      try {
        document.body.appendChild(trap("bot_trap"));
        document.body.appendChild(trap("copyright_audit"));
        document.body.appendChild(trap("dmca_scan"));
      } catch (e) {}
    }

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", append);
    } else {
      append();
    }
  }

  function ensureRobotsMeta() {
    try {
      if (!document.head) return;
      var meta = document.querySelector('meta[name="robots"]');
      if (!meta) {
        meta = document.createElement("meta");
        meta.name = "robots";
        document.head.appendChild(meta);
      }
      // Don't override a stricter existing policy; reinforce noindex for scrapers that execute JS
      if (!meta.content || meta.content.indexOf("noindex") === -1) {
        meta.content = "noindex, nofollow, noarchive, nosnippet, noimageindex";
      }
    } catch (e) {}
  }

  function handleResetHelper() {
    if ((window.location.search || "").indexOf("reset_shield") === -1) return;
    try {
      localStorage.removeItem(STORAGE_HARD);
      sessionStorage.setItem(STORAGE_HUMAN, "1");
    } catch (e) {}
    try {
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, "", window.location.pathname);
      }
    } catch (e2) {}
  }

  // Softnav / client navigations — keep decoy enforcement
  try {
    window.addEventListener("hashchange", enforceDecoyRedirect);
    window.addEventListener("cf:softnav", function () {
      setRestrictedFlag();
      enforceDecoyRedirect();
    });
    window.addEventListener("popstate", function () {
      setTimeout(enforceDecoyRedirect, 0);
    });
  } catch (eNav) {}

  handleResetHelper();
  ensureRobotsMeta();
  injectHoneypots();

  if (isExemptPath()) {
    setRestrictedFlag();
    return;
  }

  var botReason = detectBotSignatures();
  if (botReason) {
    triggerHardBlock();
    return;
  }

  setRestrictedFlag();
  enforceDecoyRedirect();
})();
