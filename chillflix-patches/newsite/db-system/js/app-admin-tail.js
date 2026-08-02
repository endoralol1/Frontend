  // Also hook jQuery-bound open if exposed later
  document.addEventListener("click", function (e) {
    if (e.target && e.target.closest && e.target.closest(".bottom-nav-browse")) {
      setTimeout(function () {
        try { refreshBrowseStats(); } catch (err) {}
      }, 0);
    }
  }, true);

  window.addEventListener("cf:softnav", function () {
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

/* newsite-admin-link-ui91 */
(function () {
  function base() {
    return (window.CF_BASE || (window.APP && APP.baseUrl) || '').replace(/\/$/, '');
  }
  function meUrl() {
    return base() + '/api/auth/me';
  }
  function ensureAdminChrome(user) {
    var staff = !!(user && (user.role === 'admin' || user.role === 'moderator'));
    var header = document.getElementById('header-admin-link');
    if (header) {
      if (staff) header.removeAttribute('hidden');
      else header.setAttribute('hidden', 'hidden');
    }
    var existing = document.getElementById('browse-admin-link');
    if (!staff) {
      if (existing) existing.remove();
      return;
    }
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
    fetch(meUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) { ensureAdminChrome(d && d.user ? d.user : null); })
      .catch(function () { ensureAdminChrome(null); });
  }
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

