#!/usr/bin/env python3
"""Browse sheet: drop Top IMDB/Filters/Genres hub; add in-sheet Login/Register."""
from __future__ import annotations

from pathlib import Path

CONFIG = Path("/var/www/chillflix-newsite/app/config.php")
LAYOUT = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")
BOTTOM = Path("/var/www/chillflix-newsite/app/Views/partials/bottom-nav.php")
APP_JS = Path("/var/www/chillflix-newsite/public/assets/js/app.js")
APP_CSS = Path("/var/www/chillflix-newsite/public/assets/css/app.css")

TURNSTILE_SITE_KEY = "0x4AAAAAADkt609V4bDdbUKh"

QUICK_LINKS_OLD = """            <section class="browse-section">
                <h3 class="browse-section-title">Quick links</h3>
                <div class="browse-list">
                    <a class="browse-list-item" href="<?= e(url('/top-imdb')) ?>">
                        <i class="uil uil-trophy"></i>
                        <span>Top IMDB</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <a class="browse-list-item" href="<?= e(url('/filters')) ?>">
                        <i class="uil uil-filter"></i>
                        <span>Filters</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <a class="browse-list-item" href="<?= e(url('/request')) ?>">
                        <i class="uil uil-plus-circle"></i>
                        <span>Request</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                    <button type="button" class="browse-list-item" data-browse-mock="genres">
                        <i class="uil uil-layers"></i>
                        <span>Genres hub</span>
                        <em class="browse-mock-pill">Mock</em>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
            </section>
        </div>
    </div>
</div>"""

BROWSE_TAIL_NEW = """            <section class="browse-section" id="browse-account-section">
                <h3 class="browse-section-title">Account</h3>
                <div class="browse-auth-cta" id="browse-auth-guest">
                    <button type="button" class="browse-auth-cta-card" data-browse-auth="login">
                        <span class="browse-auth-cta-icon"><i class="uil uil-signin"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong>Login</strong>
                            <em>Sync favorites across devices</em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                    <button type="button" class="browse-auth-cta-card is-accent" data-browse-auth="register">
                        <span class="browse-auth-cta-icon"><i class="uil uil-user-plus"></i></span>
                        <span class="browse-auth-cta-copy">
                            <strong>Create account</strong>
                            <em>Join Chillflix in seconds</em>
                        </span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
                <div class="browse-auth-user" id="browse-auth-user" hidden>
                    <div class="browse-auth-user-card">
                        <span class="browse-auth-user-avatar" id="browse-auth-avatar">?</span>
                        <span class="browse-auth-cta-copy">
                            <strong id="browse-auth-name">Signed in</strong>
                            <em id="browse-auth-email"></em>
                        </span>
                    </div>
                    <button type="button" class="browse-list-item" id="browse-auth-logout">
                        <i class="uil uil-signout"></i>
                        <span>Log out</span>
                        <i class="uil uil-angle-right"></i>
                    </button>
                </div>
                <div class="browse-list" style="margin-top:0.55rem;">
                    <a class="browse-list-item" href="<?= e(url('/request')) ?>">
                        <i class="uil uil-plus-circle"></i>
                        <span>Request</span>
                        <i class="uil uil-angle-right"></i>
                    </a>
                </div>
            </section>
            </div>

            <div class="browse-auth-view" id="browse-auth-view" hidden>
                <div class="browse-auth-panel">
                    <div class="browse-auth-top">
                        <button type="button" class="browse-auth-back" id="browse-auth-back" aria-label="Back to browse">
                            <i class="uil uil-arrow-left"></i>
                        </button>
                        <div class="browse-auth-tabs" role="tablist">
                            <button type="button" class="browse-auth-tab is-active" data-auth-tab="login" role="tab" aria-selected="true">Login</button>
                            <button type="button" class="browse-auth-tab" data-auth-tab="register" role="tab" aria-selected="false">Register</button>
                        </div>
                    </div>

                    <div class="browse-auth-hero">
                        <div class="browse-auth-orb" aria-hidden="true"></div>
                        <p class="browse-auth-kicker" id="browse-auth-kicker">Welcome back</p>
                        <h3 class="browse-auth-title" id="browse-auth-title">Sign in to Chillflix</h3>
                        <p class="browse-auth-sub" id="browse-auth-sub">Pick up your watchlist, favorites, and profile anywhere.</p>
                    </div>

                    <form class="browse-auth-form" id="browse-login-form" autocomplete="on">
                        <label class="browse-auth-field">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@email.com">
                        </label>
                        <label class="browse-auth-field">
                            <span>Password</span>
                            <input type="password" name="password" required autocomplete="current-password" placeholder="Your password" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-login"></div>
                        <p class="browse-auth-error" id="browse-login-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-login-submit">
                            <span>Sign in</span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>

                    <form class="browse-auth-form" id="browse-register-form" autocomplete="on" hidden>
                        <label class="browse-auth-field">
                            <span>Name</span>
                            <input type="text" name="name" required autocomplete="name" placeholder="Display name">
                        </label>
                        <label class="browse-auth-field">
                            <span>Email</span>
                            <input type="email" name="email" required autocomplete="email" inputmode="email" placeholder="you@email.com">
                        </label>
                        <label class="browse-auth-field">
                            <span>Password</span>
                            <input type="password" name="password" required autocomplete="new-password" placeholder="At least 6 characters" minlength="6">
                        </label>
                        <div class="browse-turnstile" id="browse-turnstile-register"></div>
                        <p class="browse-auth-error" id="browse-register-error" hidden></p>
                        <button type="submit" class="browse-auth-submit" id="browse-register-submit">
                            <span>Create account</span>
                            <i class="uil uil-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>"""


def patch_config() -> None:
    text = CONFIG.read_text()
    if "turnstile_site_key" in text:
        print("config already has turnstile")
        return
    needle = "    'lang'         => 'en',\n"
    insert = (
        "    'lang'         => 'en',\n\n"
        "    // Main-site auth (same domain cookies) + Cloudflare Turnstile\n"
        "    'main_origin' => 'https://www.chillflix.lol',\n"
        f"    'turnstile_site_key' => '{TURNSTILE_SITE_KEY}',\n"
    )
    if needle not in text:
        raise SystemExit("config lang needle missing")
    CONFIG.write_text(text.replace(needle, insert, 1))
    print("patched config.php")


def patch_layout() -> None:
    text = LAYOUT.read_text()
    old = """        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode($site, JSON_UNESCAPED_UNICODE) ?>,"""
    new = """        window.APP = {
            baseUrl: <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>,
            siteName: <?= json_encode($site, JSON_UNESCAPED_UNICODE) ?>,
            mainOrigin: <?= json_encode((string) config('main_origin', 'https://www.chillflix.lol'), JSON_UNESCAPED_SLASHES) ?>,
            turnstileSiteKey: <?= json_encode((string) config('turnstile_site_key', ''), JSON_UNESCAPED_SLASHES) ?>,"""
    if "turnstileSiteKey" not in text:
        if old not in text:
            raise SystemExit("APP block not found")
        text = text.replace(old, new, 1)
        print("patched APP config")
    if "challenges.cloudflare.com/turnstile" not in text:
        text = text.replace(
            '<script src="<?= e(asset(\'vendor/jquery.min.js\')) ?>"></script>',
            '<script src="<?= e(asset(\'vendor/jquery.min.js\')) ?>"></script>\n'
            '    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>',
            1,
        )
        print("added turnstile script")
    text = text.replace("?v=20260731-herobadges2", "?v=20260731-browseauth1")
    text = text.replace("?v=20260731-herobadges1", "?v=20260731-browseauth1")
    LAYOUT.write_text(text)
    print("patched layout")


def patch_bottom_nav() -> None:
    text = BOTTOM.read_text()
    if "browse-auth-view" in text and "Top IMDB" not in text:
        print("bottom-nav already patched")
        return
    if QUICK_LINKS_OLD not in text:
        raise SystemExit("quick links block not found")

    # Wrap existing body content (before quick links) in browse-home-view
    body_open = '<div class="browse-sheet-body">\n'
    if body_open not in text:
        raise SystemExit("browse-sheet-body not found")
    text = text.replace(
        body_open,
        body_open + '            <div class="browse-home-view" id="browse-home-view">\n',
        1,
    )
    text = text.replace(QUICK_LINKS_OLD, BROWSE_TAIL_NEW, 1)
    BOTTOM.write_text(text)
    print("patched bottom-nav.php")


AUTH_JS = r'''
  // ——— Browse auth (main-site /api/auth) ———
  var browseAuthUser = null;
  var browseTurnstile = { login: null, register: null, loginToken: '', registerToken: '' };

  function authApi(path, options) {
    var origin = (window.APP && APP.mainOrigin) || (location.origin || '');
    return fetch(origin + path, Object.assign({
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }
    }, options || {}));
  }

  function setBrowseAuthMode(mode) {
    var isLogin = mode !== 'register';
    $('#browse-login-form').prop('hidden', !isLogin);
    $('#browse-register-form').prop('hidden', isLogin);
    $('.browse-auth-tab').removeClass('is-active').attr('aria-selected', 'false');
    $('.browse-auth-tab[data-auth-tab="' + (isLogin ? 'login' : 'register') + '"]')
      .addClass('is-active').attr('aria-selected', 'true');
    $('#browse-auth-kicker').text(isLogin ? 'Welcome back' : 'Join Chillflix');
    $('#browse-auth-title').text(isLogin ? 'Sign in to Chillflix' : 'Create your account');
    $('#browse-auth-sub').text(
      isLogin
        ? 'Pick up your watchlist, favorites, and profile anywhere.'
        : 'Save favorites and keep your Chillflix profile in sync.'
    );
    $('#browse-login-error, #browse-register-error').prop('hidden', true).text('');
    renderBrowseTurnstile(isLogin ? 'login' : 'register');
  }

  function openBrowseAuth(mode) {
    $('#browse-home-view').attr('hidden', true);
    $('#browse-auth-view').removeAttr('hidden').addClass('is-open');
    $('#browse-sheet').addClass('auth-open');
    setBrowseAuthMode(mode || 'login');
  }

  function closeBrowseAuth() {
    $('#browse-auth-view').removeClass('is-open').attr('hidden', true);
    $('#browse-home-view').removeAttr('hidden');
    $('#browse-sheet').removeClass('auth-open');
    $('#browse-login-error, #browse-register-error').prop('hidden', true).text('');
  }

  function renderBrowseTurnstile( whichtarget ) {
    var key = (window.APP && APP.turnstileSiteKey) || '';
    if (!key || !window.turnstile) return;
    var id = whichtarget === 'register' ? 'browse-turnstile-register' : 'browse-turnstile-login';
    var el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = '';
    try {
      var wid = window.turnstile.render(el, {
        sitekey: key,
        theme: 'dark',
        callback: function (token) {
          if (whichtarget === 'register') browseTurnstile.registerToken = token;
          else browseTurnstile.loginToken = token;
        },
        'expired-callback': function () {
          if (whichtarget === 'register') browseTurnstile.registerToken = '';
          else browseTurnstile.loginToken = '';
        },
        'error-callback': function () {
          if (whichtarget === 'register') browseTurnstile.registerToken = '';
          else browseTurnstile.loginToken = '';
        }
      });
      if (whichtarget === 'register') browseTurnstile.register = wid;
      else browseTurnstile.login = wid;
    } catch (err) {
      console.warn('Turnstile render failed', err);
    }
  }

  function resetBrowseTurnstile(which) {
    if (which === 'register') browseTurnstile.registerToken = '';
    else browseTurnstile.loginToken = '';
    if (!window.turnstile) return;
    var wid = which === 'register' ? browseTurnstile.register : browseTurnstile.login;
    if (wid != null) {
      try { window.turnstile.reset(wid); } catch (e) {}
    } else {
      renderBrowseTurnstile(which);
    }
  }

  function applyBrowseAuthUser(user) {
    browseAuthUser = user || null;
    if (user) {
      $('#browse-auth-guest').attr('hidden', true);
      $('#browse-auth-user').removeAttr('hidden');
      var name = user.name || user.email || 'Member';
      $('#browse-auth-name').text(name);
      $('#browse-auth-email').text(user.email || '');
      $('#browse-auth-avatar').text(String(name).charAt(0).toUpperCase());
    } else {
      $('#browse-auth-user').attr('hidden', true);
      $('#browse-auth-guest').removeAttr('hidden');
    }
  }

  function refreshBrowseAuthUser() {
    return authApi('/api/auth/me', { method: 'GET', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) { applyBrowseAuthUser(data && data.user ? data.user : null); })
      .catch(function () { applyBrowseAuthUser(null); });
  }

  var _openBrowseSheet = openBrowseSheet;
  openBrowseSheet = function () {
    _openBrowseSheet();
    closeBrowseAuth();
    refreshBrowseAuthUser();
  };

  var _closeBrowseSheet = closeBrowseSheet;
  closeBrowseSheet = function () {
    closeBrowseAuth();
    _closeBrowseSheet();
  };

  $(document).on('click', '[data-browse-auth]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    openBrowseAuth($(this).data('browse-auth') || 'login');
  });

  $(document).on('click', '#browse-auth-back', function (e) {
    e.preventDefault();
    closeBrowseAuth();
  });

  $(document).on('click', '.browse-auth-tab', function (e) {
    e.preventDefault();
    setBrowseAuthMode($(this).data('auth-tab') || 'login');
  });

  $(document).on('submit', '#browse-login-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var email = String($form.find('[name=email]').val() || '').trim();
    var password = String($form.find('[name=password]').val() || '');
    var $err = $('#browse-login-error');
    var $btn = $('#browse-login-submit');
    $err.prop('hidden', true).text('');
    if ((window.APP && APP.turnstileSiteKey) && !browseTurnstile.loginToken) {
      $err.text('Complete the captcha verification.').prop('hidden', false);
      return;
    }
    $btn.prop('disabled', true).addClass('is-loading');
    authApi('/api/auth/login', {
      method: 'POST',
      body: JSON.stringify({
        email: email,
        password: password,
        turnstileToken: browseTurnstile.loginToken || undefined
      })
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      if (!res.ok) {
        $err.text((res.data && res.data.error) || 'Login failed').prop('hidden', false);
        resetBrowseTurnstile('login');
        return;
      }
      applyBrowseAuthUser(res.data.user);
      closeBrowseAuth();
    }).catch(function () {
      $err.text('Network error — try again').prop('hidden', false);
      resetBrowseTurnstile('login');
    }).finally(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('submit', '#browse-register-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var name = String($form.find('[name=name]').val() || '').trim();
    var email = String($form.find('[name=email]').val() || '').trim();
    var password = String($form.find('[name=password]').val() || '');
    var $err = $('#browse-register-error');
    var $btn = $('#browse-register-submit');
    $err.prop('hidden', true).text('');
    if ((window.APP && APP.turnstileSiteKey) && !browseTurnstile.registerToken) {
      $err.text('Complete the captcha verification.').prop('hidden', false);
      return;
    }
    $btn.prop('disabled', true).addClass('is-loading');
    authApi('/api/auth/register', {
      method: 'POST',
      body: JSON.stringify({
        name: name,
        email: email,
        password: password,
        turnstileToken: browseTurnstile.registerToken || undefined
      })
    }).then(function (r) {
      return r.json().then(function (data) { return { ok: r.ok, data: data }; });
    }).then(function (res) {
      if (!res.ok) {
        $err.text((res.data && res.data.error) || 'Registration failed').prop('hidden', false);
        resetBrowseTurnstile('register');
        return;
      }
      applyBrowseAuthUser(res.data.user);
      closeBrowseAuth();
    }).catch(function () {
      $err.text('Network error — try again').prop('hidden', false);
      resetBrowseTurnstile('register');
    }).finally(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });

  $(document).on('click', '#browse-auth-logout', function (e) {
    e.preventDefault();
    authApi('/api/auth/logout', { method: 'POST', body: '{}' })
      .catch(function () {})
      .then(function () { applyBrowseAuthUser(null); });
  });
'''


def patch_js() -> None:
    text = APP_JS.read_text()
    if "Browse auth (main-site" in text:
        print("app.js auth already present")
        return
    marker = "  $(document).on('click', '[data-browse-mock]', function (e) {"
    if marker not in text:
        raise SystemExit("browse-mock marker missing")
    text = text.replace(marker, AUTH_JS + "\n" + marker, 1)
    APP_JS.write_text(text)
    print("patched app.js")


AUTH_CSS = """

/* ——— Browse auth panel ——— */
.browse-home-view[hidden],
.browse-auth-view[hidden] {
  display: none !important;
}

.browse-auth-cta {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.browse-auth-cta-card,
.browse-auth-user-card {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  width: 100%;
  min-height: 3.6rem;
  padding: 0.7rem 0.8rem;
  border-radius: 1rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(255, 255, 255, 0.03);
  color: #fff;
  text-align: left;
  cursor: pointer;
  font: inherit;
}

.browse-auth-cta-card.is-accent {
  background: linear-gradient(135deg, rgba(220, 53, 69, 0.22), rgba(219, 105, 55, 0.12));
  border-color: rgba(220, 53, 69, 0.35);
}

.browse-auth-cta-icon,
.browse-auth-user-avatar {
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.07);
  color: #ffb089;
  flex-shrink: 0;
  font-weight: 800;
}

.browse-auth-cta-copy {
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  min-width: 0;
  flex: 1;
}

.browse-auth-cta-copy strong {
  font-size: 0.9rem;
  font-weight: 700;
}

.browse-auth-cta-copy em {
  font-style: normal;
  font-size: 0.68rem;
  color: rgba(255, 255, 255, 0.5);
}

.browse-auth-cta-card > i:last-child {
  color: rgba(255, 255, 255, 0.35);
}

.browse-auth-view {
  padding: 0.15rem 0 0.35rem;
  animation: browseAuthIn 0.22s ease;
}

@keyframes browseAuthIn {
  from { opacity: 0; transform: translateX(12px); }
  to { opacity: 1; transform: translateX(0); }
}

.browse-auth-top {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0.85rem;
}

.browse-auth-back {
  width: 2.1rem;
  height: 2.1rem;
  border: 0;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.browse-auth-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.25rem;
  flex: 1;
  padding: 0.22rem;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.08);
}

.browse-auth-tab {
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: rgba(255, 255, 255, 0.55);
  font: inherit;
  font-size: 0.82rem;
  font-weight: 700;
  padding: 0.45rem 0.5rem;
  cursor: pointer;
}

.browse-auth-tab.is-active {
  background: linear-gradient(180deg, #e4573d, #dc3545);
  color: #fff;
  box-shadow: 0 6px 16px rgba(220, 53, 69, 0.28);
}

.browse-auth-hero {
  position: relative;
  overflow: hidden;
  border-radius: 1.15rem;
  padding: 1rem 1rem 0.95rem;
  margin-bottom: 0.9rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  background:
    radial-gradient(120% 90% at 10% 0%, rgba(220, 53, 69, 0.28), transparent 55%),
    radial-gradient(90% 80% at 100% 20%, rgba(219, 105, 55, 0.18), transparent 50%),
    rgba(255, 255, 255, 0.03);
}

.browse-auth-orb {
  position: absolute;
  right: -1.2rem;
  top: -1.4rem;
  width: 6rem;
  height: 6rem;
  border-radius: 999px;
  background: radial-gradient(circle, rgba(255, 176, 137, 0.35), transparent 70%);
  pointer-events: none;
}

.browse-auth-kicker {
  margin: 0 0 0.2rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #ffb089;
}

.browse-auth-title {
  margin: 0 0 0.3rem;
  color: #fff;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.browse-auth-sub {
  margin: 0;
  color: rgba(255, 255, 255, 0.58);
  font-size: 0.78rem;
  line-height: 1.4;
}

.browse-auth-form {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.browse-auth-form[hidden] {
  display: none !important;
}

.browse-auth-field {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.browse-auth-field > span {
  font-size: 0.72rem;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.55);
  letter-spacing: 0.02em;
}

.browse-auth-field input {
  width: 100%;
  border: 1px solid rgba(255, 255, 255, 0.12);
  background: rgba(0, 0, 0, 0.28);
  color: #fff;
  border-radius: 0.85rem;
  padding: 0.78rem 0.9rem;
  font: inherit;
  font-size: 0.92rem;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.browse-auth-field input::placeholder {
  color: rgba(255, 255, 255, 0.32);
}

.browse-auth-field input:focus {
  border-color: rgba(220, 53, 69, 0.65);
  box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.18);
}

.browse-turnstile {
  min-height: 0.25rem;
  display: flex;
  justify-content: center;
}

.browse-auth-error {
  margin: 0;
  padding: 0.55rem 0.7rem;
  border-radius: 0.75rem;
  border: 1px solid rgba(220, 53, 69, 0.35);
  background: rgba(220, 53, 69, 0.12);
  color: #ffb4b4;
  font-size: 0.78rem;
  font-weight: 600;
}

.browse-auth-error[hidden] {
  display: none !important;
}

.browse-auth-submit {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  width: 100%;
  min-height: 2.85rem;
  margin-top: 0.15rem;
  border: 0;
  border-radius: 0.95rem;
  background: linear-gradient(180deg, #e4573d 0%, #dc3545 100%);
  color: #fff;
  font: inherit;
  font-size: 0.95rem;
  font-weight: 800;
  cursor: pointer;
  box-shadow: 0 10px 24px rgba(220, 53, 69, 0.28);
  transition: transform 0.15s ease, filter 0.15s ease;
}

.browse-auth-submit:active {
  transform: scale(0.98);
}

.browse-auth-submit.is-loading,
.browse-auth-submit:disabled {
  opacity: 0.7;
  cursor: wait;
}

.browse-auth-user {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.browse-sheet.auth-open .browse-sheet-panel {
  max-height: min(86vh, 46rem);
}
"""


def patch_css() -> None:
    css = APP_CSS.read_text()
    if "Browse auth panel" in css:
        print("css already has browse auth")
        return
    APP_CSS.write_text(css + AUTH_CSS)
    print("patched app.css")


if __name__ == "__main__":
    patch_config()
    patch_layout()
    patch_bottom_nav()
    patch_js()
    patch_css()
    print("done")
