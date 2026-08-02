    try { refreshBrowseStats(); } catch (e) {}
  });
  window.addEventListener("storage", function (e) {
    if (!e.key || e.key === "cf_continue_v1" || e.key === "user_bookmarks") {
      try { refreshBrowseStats(); } catch (err) {}
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refreshBrowseStats);
  } else {
    refreshBrowseStats();
  }
})();

/* newsite-admin-menu-ui96 */
(function () {
  function base() {
    return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\/$/, '');
  }
  function menuRoot() { return document.getElementById('header-admin-menu'); }
  function dropdown() { return document.getElementById('header-admin-dropdown'); }
  function toggleBtn() { return document.getElementById('header-admin-link'); }

  function placeDropdown() {
    var btn = toggleBtn();
    var dd = dropdown();
    if (!btn || !dd || dd.hidden) return;
    var r = btn.getBoundingClientRect();
    var pad = 8;
    var width = Math.min(296, window.innerWidth - pad * 2);
    var left = Math.min(Math.max(pad, r.left), window.innerWidth - width - pad);
    // Prefer below; if not enough room, flip above
    var below = r.bottom + 8;
    var estHeight = Math.min(dd.scrollHeight || 320, window.innerHeight * 0.7);
    var top = below;
    if (below + estHeight > window.innerHeight - pad && r.top > estHeight + pad) {
      top = Math.max(pad, r.top - estHeight - 8);
    }
    dd.style.position = 'fixed';
    dd.style.top = Math.round(top) + 'px';
    dd.style.left = Math.round(left) + 'px';
    dd.style.right = 'auto';
    dd.style.width = width + 'px';
    dd.style.zIndex = '10050';
  }

  function closeMenu() {
    var root = menuRoot();
    var dd = dropdown();
    var btn = toggleBtn();
    if (!root || !dd || !btn) return;
    dd.hidden = true;
    root.classList.remove('is-open');
    btn.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('admin-menu-open');
  }
  function openMenu() {
    var root = menuRoot();
    var dd = dropdown();
    var btn = toggleBtn();
    if (!root || !dd || !btn || root.hidden) return;
    dd.hidden = false;
    root.classList.add('is-open');
    btn.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('admin-menu-open');
    placeDropdown();
    // second pass after layout for accurate height
    requestAnimationFrame(placeDropdown);
  }
  function ensureAdminChrome(user) {
    var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
    var root = menuRoot();
    if (root) {
      if (staff) root.removeAttribute('hidden');
      else { root.setAttribute('hidden', 'hidden'); closeMenu(); }
    }
    var existing = document.getElementById('browse-admin-link');
    if (!staff) { if (existing) existing.remove(); return; }
    if (existing) return;
    var a = document.createElement('a');
    a.id = 'browse-admin-link';
    a.className = 'browse-list-item';
    a.href = base() + '/admin';
    a.innerHTML = '<i class="uil uil-shield-check" aria-hidden="true"></i><span>Admin</span><i class="uil uil-angle-right" aria-hidden="true"></i>';
    var userBox = document.getElementById('browse-auth-user');
    if (userBox && !userBox.hidden) {
      var logout = userBox.querySelector('#browse-auth-logout');
      if (logout) userBox.insertBefore(a, logout);
      else userBox.appendChild(a);
      return;
    }
    var list = document.querySelector('#browse-account-section .browse-list');
    if (list) list.insertBefore(a, list.firstChild);
  }
  function refreshAdminChrome() {
    fetch(base() + '/api/auth/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
      .catch(function () { ensureAdminChrome(null); });
  }
  document.addEventListener('click', function (e) {
    var root = menuRoot();
    var btn = toggleBtn();
    var dd = dropdown();
    if (!root || !btn || root.hidden) return;
    var t = e.target;
    if (btn === t || btn.contains(t)) {
      e.preventDefault();
      if (root.classList.contains('is-open')) closeMenu();
      else openMenu();
      return;
    }
    if (dd && !dd.hidden && dd.contains(t)) return;
    if (!root.contains(t)) closeMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
  });
  window.addEventListener('resize', function () {
    if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
  });
  window.addEventListener('scroll', function () {
    if (menuRoot() && menuRoot().classList.contains('is-open')) placeDropdown();
  }, true);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refreshAdminChrome);
  } else {
    refreshAdminChrome();
  }
  if (window.jQuery) {
    jQuery(document).on('click', '.bottom-nav-browse', function () {
      setTimeout(refreshAdminChrome, 180);
    });
  }
  window.__cfEnsureAdminChrome = ensureAdminChrome;
})();



