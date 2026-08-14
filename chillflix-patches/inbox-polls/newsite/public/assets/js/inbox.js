/**
 * Header inbox dropdown — polls + notifications.
 * Icon hidden when no active items; badge when unread.
 */
(function () {
  "use strict";

  var API = "/api/inbox";
  var root = null;
  var btn = null;
  var drop = null;
  var badge = null;
  var listEl = null;
  var state = { items: [], unread: 0, totalActive: 0, open: false };

  function qs(sel, el) {
    return (el || document).querySelector(sel);
  }

  function esc(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/"/g, "&quot;");
  }

  function iconFor(it) {
    if (it.type === "poll") return "uil-chart-pie";
    return "uil-bell";
  }

  function ensureDom() {
    root = qs("#cf-inbox");
    if (!root) return false;
    btn = qs("#cf-inbox-btn", root);
    drop = qs("#cf-inbox-dropdown", root);
    badge = qs("#cf-inbox-badge", root);
    listEl = qs("#cf-inbox-list", root);
    return !!(btn && drop && listEl);
  }

  function clearPlace() {
    if (!drop) return;
    drop.style.position = "";
    drop.style.top = "";
    drop.style.left = "";
    drop.style.right = "";
    drop.style.width = "";
    drop.style.maxHeight = "";
    drop.style.zIndex = "";
  }

  /** Keep dropdown in viewport (esp. phones) — same approach as admin header menu. */
  function placeDropdown() {
    if (!btn || !drop || drop.hasAttribute("hidden")) return;
    var r = btn.getBoundingClientRect();
    var pad = 10;
    var safeL = 0;
    var safeR = 0;
    var safeB = 0;
    try {
      var cs = getComputedStyle(document.documentElement);
      // env() isn't readable via computed style reliably; use visualViewport when present.
    } catch (e) {}
    var vw = window.visualViewport ? window.visualViewport.width : window.innerWidth;
    var vh = window.visualViewport ? window.visualViewport.height : window.innerHeight;
    var vOffY = window.visualViewport ? window.visualViewport.offsetTop : 0;
    var narrow = vw < 720;
    var width = narrow
      ? Math.max(0, vw - pad * 2)
      : Math.min(352, vw - pad * 2);
    // Align to button when possible; on phones pin to screen edges.
    var left = narrow
      ? pad
      : Math.min(Math.max(pad, r.right - width), vw - width - pad);
    var below = r.bottom + 8;
    var maxH = Math.min(vh * 0.72, narrow ? vh - below - pad - 8 : 512);
    var top = below + vOffY;
    if (below + Math.min(drop.scrollHeight || 280, maxH) > vh - pad && r.top > 120) {
      var est = Math.min(drop.scrollHeight || 280, maxH);
      top = Math.max(pad + vOffY, r.top - est - 8 + vOffY);
    }
    drop.style.position = "fixed";
    drop.style.top = Math.round(top) + "px";
    drop.style.left = Math.round(left) + "px";
    drop.style.right = "auto";
    drop.style.width = Math.round(width) + "px";
    drop.style.maxHeight = Math.round(maxH) + "px";
    drop.style.zIndex = "10050";
  }

  function setOpen(on) {
    state.open = !!on;
    root.classList.toggle("is-open", state.open);
    document.documentElement.classList.toggle("cf-inbox-open", state.open);
    btn.setAttribute("aria-expanded", state.open ? "true" : "false");
    if (state.open) {
      drop.removeAttribute("hidden");
      placeDropdown();
      requestAnimationFrame(placeDropdown);
    } else {
      drop.setAttribute("hidden", "");
      clearPlace();
    }
  }

  function render() {
    if (!ensureDom()) return;
    var active = (state.items || []).filter(function (it) {
      return it.status === "active" || it.status === "closed";
    });
    // Icon only when there is something to show (active preferred; closed with recent ok).
    var showIcon = (state.totalActive || 0) > 0 || active.some(function (it) {
      return it.status === "active";
    });
    if (!showIcon) {
      root.setAttribute("hidden", "");
      setOpen(false);
      return;
    }
    root.removeAttribute("hidden");

    var unread = state.unread || 0;
    if (unread > 0) {
      badge.hidden = false;
      badge.textContent = unread > 9 ? "9+" : String(unread);
    } else {
      badge.hidden = true;
      badge.textContent = "";
    }

    if (!active.length) {
      listEl.innerHTML =
        '<div class="cf-inbox-empty">No polls or notifications right now.</div>';
      if (state.open) requestAnimationFrame(placeDropdown);
      return;
    }

    listEl.innerHTML = active
      .map(function (it) {
        var unreadCls = it.unread ? " is-unread" : "";
        var body = it.body
          ? '<p class="cf-inbox-body">' + esc(it.body) + "</p>"
          : "";
        var opts = "";
        if (it.type === "poll" && (it.options || []).length) {
          opts =
            '<div class="cf-inbox-options" data-poll="' +
            esc(it.id) +
            '">' +
            it.options
              .map(function (o) {
                var selected = (it.myVotes || []).indexOf(o.id) >= 0;
                var pct =
                  o.percent != null
                    ? '<span class="cf-inbox-pct">' +
                      (o.voteCount != null ? o.voteCount + " · " : "") +
                      o.percent +
                      "%</span>"
                    : o.voteCount != null
                      ? '<span class="cf-inbox-pct">' + o.voteCount + "</span>"
                      : "";
                var bar =
                  o.percent != null
                    ? '<span class="cf-inbox-bar"><i style="width:' +
                      o.percent +
                      '%"></i></span>'
                    : "";
                return (
                  '<button type="button" class="cf-inbox-opt' +
                  (selected ? " is-selected" : "") +
                  '" data-opt="' +
                  esc(o.id) +
                  '"' +
                  (it.canVote ? "" : " disabled") +
                  ">" +
                  '<span class="cf-inbox-opt-label">' +
                  esc(o.label) +
                  "</span>" +
                  pct +
                  bar +
                  "</button>"
                );
              })
              .join("") +
            (it.canVote
              ? '<button type="button" class="cf-inbox-vote-btn" data-vote="' +
                esc(it.id) +
                '">' +
                (it.hasVoted ? "Update vote" : "Vote") +
                "</button>"
              : it.hasVoted
                ? '<div class="cf-inbox-voted">You voted</div>'
                : "") +
            "</div>";
        }

        var react = "";
        if (it.settings && it.settings.allowReactions) {
          react =
            '<div class="cf-inbox-react" data-react="' +
            esc(it.id) +
            '">' +
            '<button type="button" class="cf-inbox-react-btn' +
            (it.myReaction === "like" ? " is-on" : "") +
            '" data-reaction="like"' +
            (it.canReact ? "" : " disabled") +
            ' aria-label="Like"><i class="uil uil-thumbs-up" aria-hidden="true"></i> <span>' +
            (it.likeCount || 0) +
            "</span></button>" +
            '<button type="button" class="cf-inbox-react-btn' +
            (it.myReaction === "dislike" ? " is-on" : "") +
            '" data-reaction="dislike"' +
            (it.canReact ? "" : " disabled") +
            ' aria-label="Dislike"><i class="uil uil-thumbs-down" aria-hidden="true"></i> <span>' +
            (it.dislikeCount || 0) +
            "</span></button>" +
            "</div>";
        }

        return (
          '<article class="cf-inbox-item' +
          unreadCls +
          '" data-id="' +
          esc(it.id) +
          '">' +
          '<div class="cf-inbox-item-top">' +
          '<span class="cf-inbox-item-ico"><i class="uil ' +
          iconFor(it) +
          '" aria-hidden="true"></i></span>' +
          '<div class="cf-inbox-item-copy">' +
          '<strong>' +
          esc(it.title) +
          "</strong>" +
          '<em>' +
          esc(it.type === "poll" ? "Poll" : "Notice") +
          (it.status === "closed" ? " · closed" : "") +
          "</em>" +
          "</div></div>" +
          body +
          opts +
          react +
          "</article>"
        );
      })
      .join("");
    if (state.open) requestAnimationFrame(placeDropdown);
  }

  function applyItem(updated) {
    if (!updated || !updated.id) return;
    state.items = (state.items || []).map(function (it) {
      return it.id === updated.id ? Object.assign({}, it, updated, { unread: false }) : it;
    });
    render();
  }

  function load() {
    fetch(API, { credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (d) {
        if (!d || !d.ok) return;
        state.items = d.items || [];
        state.unread = d.unread || 0;
        state.totalActive = d.totalActive || 0;
        render();
      })
      .catch(function () {});
  }

  function markRead(id) {
    fetch(API + "/" + encodeURIComponent(id) + "/read", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    }).catch(function () {});
  }

  function onOpen() {
    setOpen(true);
    // Mark visible unread as read when opening.
    (state.items || []).forEach(function (it) {
      if (it.unread) markRead(it.id);
    });
    state.unread = 0;
    state.items = (state.items || []).map(function (it) {
      return Object.assign({}, it, { unread: false });
    });
    render();
  }

  function bind() {
    if (!ensureDom()) return;
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (state.open) setOpen(false);
      else onOpen();
    });
    document.addEventListener("click", function (e) {
      if (!state.open) return;
      if (root.contains(e.target)) return;
      setOpen(false);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && state.open) setOpen(false);
    });
    window.addEventListener(
      "resize",
      function () {
        if (state.open) placeDropdown();
      },
      { passive: true }
    );
    window.addEventListener(
      "scroll",
      function () {
        if (state.open) placeDropdown();
      },
      { passive: true, capture: true }
    );
    if (window.visualViewport) {
      window.visualViewport.addEventListener("resize", function () {
        if (state.open) placeDropdown();
      });
      window.visualViewport.addEventListener("scroll", function () {
        if (state.open) placeDropdown();
      });
    }

    listEl.addEventListener("click", function (e) {
      var opt = e.target.closest(".cf-inbox-opt");
      if (opt && !opt.disabled) {
        var wrap = opt.closest(".cf-inbox-options");
        var multi = false;
        var item = (state.items || []).find(function (it) {
          return it.id === (wrap && wrap.getAttribute("data-poll"));
        });
        if (item && item.settings && item.settings.allowMultiple) multi = true;
        if (!multi) {
          wrap.querySelectorAll(".cf-inbox-opt").forEach(function (b) {
            b.classList.remove("is-selected");
          });
        }
        opt.classList.toggle("is-selected");
        return;
      }

      var voteBtn = e.target.closest("[data-vote]");
      if (voteBtn) {
        var pid = voteBtn.getAttribute("data-vote");
        var box = listEl.querySelector('.cf-inbox-options[data-poll="' + pid + '"]');
        var ids = [];
        if (box) {
          box.querySelectorAll(".cf-inbox-opt.is-selected").forEach(function (b) {
            ids.push(b.getAttribute("data-opt"));
          });
        }
        if (!ids.length) return;
        voteBtn.disabled = true;
        fetch(API + "/" + encodeURIComponent(pid) + "/vote", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ optionIds: ids }),
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (d) {
            if (d && d.ok && d.item) applyItem(d.item);
            else load();
          })
          .catch(function () {
            voteBtn.disabled = false;
          });
        return;
      }

      var reactBtn = e.target.closest(".cf-inbox-react-btn");
      if (reactBtn && !reactBtn.disabled) {
        var reactWrap = reactBtn.closest("[data-react]");
        var rid = reactWrap.getAttribute("data-react");
        var reaction = reactBtn.getAttribute("data-reaction");
        var item = (state.items || []).find(function (it) {
          return it.id === rid;
        });
        // Toggle off if same reaction clicked again.
        if (item && item.myReaction === reaction) reaction = null;
        fetch(API + "/" + encodeURIComponent(rid) + "/react", {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ reaction: reaction }),
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (d) {
            if (d && d.ok && d.item) applyItem(d.item);
            else load();
          })
          .catch(function () {});
      }
    });
  }

  function boot() {
    if (!ensureDom()) return;
    bind();
    load();
    // Light poll for new items while page is open.
    setInterval(load, 90000);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
