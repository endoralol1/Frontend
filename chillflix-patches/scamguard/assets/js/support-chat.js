(function () {
  'use strict';

  var root = document.getElementById('sg-support-chat');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || '';
  var csrf = '';
  var conversation = null;
  var lastId = 0;
  var pollTimer = null;
  var open = false;
  var busy = false;
  var loggedIn = false;
  var username = '';
  var aiEnabled = false;

  var launcher = el('button', {
    className: 'sgchat-launcher',
    type: 'button',
    'aria-label': 'Open support chat'
  });
  launcher.innerHTML =
    '<svg class="sgchat-launcher-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M21 12a8.5 8.5 0 0 1-8.5 8.5H7l-4 2.2V12A8.5 8.5 0 1 1 21 12z"/>' +
      '<path d="M8 11h8M8 14h5"/>' +
    '</svg>' +
    '<span class="sgchat-badge" hidden>0</span>';

  var panel = el('div', { className: 'sgchat-panel', role: 'dialog', 'aria-label': 'Support chat', 'aria-hidden': 'true' });
  panel.innerHTML =
    '<div class="sgchat-head">' +
      '<div class="sgchat-head-text">' +
        '<strong>ScamGuard Support</strong>' +
        '<span class="sgchat-status" id="sgchat-status">AI helper</span>' +
      '</div>' +
      '<div class="sgchat-head-actions">' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-human" title="Talk to admin" aria-label="Talk to admin">' +
          '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1"/><circle cx="10" cy="8" r="3"/><path d="M19 8v4M17 10h4"/></svg>' +
        '</button>' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-close-chat" title="End chat" aria-label="End chat">' +
          '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>' +
        '</button>' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-minimize" title="Minimize" aria-label="Minimize">' +
          '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 12h12"/></svg>' +
        '</button>' +
      '</div>' +
    '</div>' +
    '<div class="sgchat-messages" id="sgchat-messages"></div>' +
    '<div class="sgchat-suggestions" id="sgchat-suggestions"></div>' +
    '<div class="sgchat-restart" id="sgchat-restart" hidden>' +
      '<p>This chat ended. Start a new one to ask again.</p>' +
      '<button type="button" class="btn btn-primary" id="sgchat-new">New chat</button>' +
    '</div>' +
    '<form class="sgchat-compose" id="sgchat-form">' +
      '<textarea id="sgchat-input" rows="1" maxlength="2000" placeholder="Ask anything about ScamGuard…" required></textarea>' +
      '<button type="submit" class="sgchat-send" aria-label="Send">' +
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h12"/><path d="M13 6l6 6-6 6"/></svg>' +
      '</button>' +
    '</form>' +
    '<div class="sgchat-prechat" id="sgchat-prechat">' +
      '<p>Chat with our AI helper about ScamGuard. An admin can join if needed.</p>' +
      '<div id="sgchat-guest-fields">' +
        '<label>Name <input type="text" id="sgchat-name" maxlength="80" placeholder="Optional"></label>' +
        '<label>Email <input type="email" id="sgchat-email" maxlength="190" placeholder="Optional"></label>' +
      '</div>' +
      '<button type="button" class="btn btn-primary sgchat-start" id="sgchat-start">Start chat</button>' +
    '</div>';

  root.appendChild(launcher);
  root.appendChild(panel);

  var nudge = el('div', {
    className: 'sgchat-nudge',
    hidden: true,
    role: 'dialog',
    'aria-live': 'polite',
    'aria-label': 'Chat suggestion'
  });
  nudge.innerHTML =
    '<button type="button" class="sgchat-nudge-close" aria-label="Dismiss">&times;</button>' +
    '<p class="sgchat-nudge-text"></p>' +
    '<div class="sgchat-nudge-actions">' +
      '<button type="button" class="sgchat-nudge-btn" data-action="open">Chat with me</button>' +
      '<button type="button" class="sgchat-nudge-btn is-secondary" data-action="scan">Need a scan?</button>' +
    '</div>';
  root.appendChild(nudge);

  var messagesEl = panel.querySelector('#sgchat-messages');
  var suggestionsEl = panel.querySelector('#sgchat-suggestions');
  var statusEl = panel.querySelector('#sgchat-status');
  var formEl = panel.querySelector('#sgchat-form');
  var inputEl = panel.querySelector('#sgchat-input');
  var prechatEl = panel.querySelector('#sgchat-prechat');
  var guestFieldsEl = panel.querySelector('#sgchat-guest-fields');
  var restartEl = panel.querySelector('#sgchat-restart');
  var badgeEl = launcher.querySelector('.sgchat-badge');
  var nudgeTextEl = nudge.querySelector('.sgchat-nudge-text');
  var pendingPrompt = null;
  var nudgeTimer = null;
  var attentionTimer = null;
  var typeTimer = null;
  var typeLines = [];
  var typeIndex = 0;
  var typePos = 0;
  var typeMode = 'idle'; // idle | type | hold | erase

  // Start in chat mode UI hidden until opened; prechat only for guests.
  showChatUi(false);
  showPrechat(false);
  showRestart(false);

  launcher.addEventListener('click', function () {
    setOpen(!open);
  });

  nudge.querySelector('.sgchat-nudge-close').addEventListener('click', function (e) {
    e.stopPropagation();
    hideNudge(true, 12);
  });
  nudge.querySelector('[data-action="open"]').addEventListener('click', function () {
    openFromNudge(null);
  });
  nudge.querySelector('[data-action="scan"]').addEventListener('click', function () {
    openFromNudge('Can you check something for me?');
  });
  nudge.addEventListener('click', function (e) {
    if (e.target.closest('button')) return;
    openFromNudge(null);
  });

  panel.querySelector('#sgchat-minimize').addEventListener('click', function () {
    setOpen(false);
  });

  panel.querySelector('#sgchat-human').addEventListener('click', function () {
    if (!conversation || isClosed()) {
      beginFreshChat(function () { requestHuman(); });
      return;
    }
    requestHuman();
  });

  panel.querySelector('#sgchat-close-chat').addEventListener('click', function () {
    if (!conversation || isClosed()) {
      setOpen(false);
      return;
    }
    if (!confirm('End this chat?')) return;
    post('close', { token: conversation.token }).then(function (data) {
      if (data.conversation) applyConversation(data.conversation, true);
      maybeAskRating();
      showClosedState();
    });
  });

  panel.querySelector('#sgchat-start').addEventListener('click', function () {
    beginFreshChat(function () { flushPendingPrompt(); });
  });

  panel.querySelector('#sgchat-new').addEventListener('click', function () {
    beginFreshChat(function () { flushPendingPrompt(); });
  });

  formEl.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(inputEl.value);
  });

  inputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage(inputEl.value);
    }
  });

  bootstrap();

  function isClosed() {
    return !!(conversation && conversation.status === 'closed');
  }

  function beginFreshChat(done) {
    conversation = null;
    lastId = 0;
    messagesEl.innerHTML = '';
    showRestart(false);
    showPrechat(false);
    showChatUi(true);
    startChat(done);
  }

  function showClosedState() {
    showRestart(true);
    formEl.hidden = true;
    suggestionsEl.hidden = true;
    setTyping(false);
  }

  function setOpen(next) {
    open = !!next;
    panel.classList.toggle('is-open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    launcher.classList.toggle('is-open', open);
    launcher.setAttribute('aria-label', open ? 'Close support chat' : 'Open support chat');
    if (open) {
      hideNudge(true, 24);
      stopAttention();
      clearBadge();
      flushPendingPrompt();
      if (conversation && !isClosed()) {
        showPrechat(false);
        showRestart(false);
        showChatUi(true);
        scrollBottom();
        return;
      }
      if (conversation && isClosed()) {
        showPrechat(false);
        showChatUi(true);
        showClosedState();
        scrollBottom();
        return;
      }
      if (loggedIn) {
        beginFreshChat(function () { flushPendingPrompt(); });
        return;
      }
      showChatUi(false);
      showRestart(false);
      showPrechat(true);
      return;
    }
    // Closed: keep a soft attention pulse if they never chatted this session.
    maybeStartAttention();
  }

  function bootstrap() {
    get('bootstrap').then(function (data) {
      if (!data || !data.ok || data.enabled === false) {
        root.hidden = true;
        return;
      }
      csrf = data.csrf || '';
      loggedIn = !!data.logged_in;
      username = data.username || '';
      aiEnabled = !!data.ai_enabled;
      statusEl.textContent = aiEnabled ? 'AI helper' : 'Bot helper';

      if (loggedIn) {
        guestFieldsEl.hidden = true;
        var nameInput = panel.querySelector('#sgchat-name');
        if (nameInput) nameInput.value = username;
      } else {
        guestFieldsEl.hidden = false;
      }

      if (data.conversation) {
        applyConversation(data.conversation, true);
        showPrechat(false);
        if (data.conversation.status === 'closed') {
          showChatUi(true);
          showClosedState();
        } else {
          showRestart(false);
          showChatUi(true);
        }
        if ((data.conversation.unread_visitor || 0) > 0) setBadge(data.conversation.unread_visitor);
      } else {
        renderSuggestions(data.quick_actions || []);
      }
      startPolling();
      scheduleSmartNudge();
    }).catch(function () {
      root.hidden = true;
    });
  }

  function nudgeStorageKey() {
    return 'sgchat_nudge_hide_until';
  }

  function isNudgeSuppressed() {
    try {
      var until = Number(localStorage.getItem(nudgeStorageKey()) || 0);
      return until > Date.now();
    } catch (e) {
      return false;
    }
  }

  function suppressNudgeHours(hours) {
    try {
      localStorage.setItem(nudgeStorageKey(), String(Date.now() + hours * 3600 * 1000));
    } catch (e) {}
  }

  function buildNudgeLines() {
    var path = (location.pathname || '').toLowerCase();
    var hour = new Date().getHours();
    var hello = hour < 12 ? 'Good morning' : hour < 18 ? 'Hey' : 'Hi there';
    var lines = [];

    if (path.indexOf('/site/') !== -1) {
      lines.push(hello + ' — need help reading this score?');
      lines.push('I can explain the risks, or recheck this site for you.');
      lines.push('Not sure what the signals mean? Ask me.');
    } else if (path.indexOf('browse') !== -1) {
      lines.push(hello + ' — want me to scan something from this list?');
      lines.push('Paste a website, phone, crypto, or IBAN — I’ll check it.');
    } else if (path.indexOf('/phone/') !== -1 || path.indexOf('/crypto/') !== -1 || path.indexOf('/iban/') !== -1) {
      lines.push(hello + ' — questions about this result?');
      lines.push('I can walk you through it, or run a fresh check.');
    }

    var rotating = [
      hello + '! Need a free scam check?',
      'I can scan websites, phones, crypto & IBANs.',
      'Got a sketchy link? Paste it — I’ll check it.',
      'Not sure if a site is safe? Tap “Need a scan?”',
      'Ask me about scores, phishing, or red flags.',
      'Someone asking you to pay? Let’s check them first.',
      'I’m the ScamGuard helper — here if you need me.',
      'Suspicious email? Drop the domain and I’ll look.',
      'Before you click — I can check it in seconds.',
      'Crypto wallet look off? I can check the address.',
      'Phone number calling about money? Let’s verify it.',
      'Free check. No signup. Just ask.'
    ];

    // Shuffle rotating lines so each visit feels different.
    for (var i = rotating.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var tmp = rotating[i];
      rotating[i] = rotating[j];
      rotating[j] = tmp;
    }

    // Prefer page-aware lines first, then a mix of the rest.
    return lines.concat(rotating).slice(0, 10);
  }

  function scheduleSmartNudge() {
    if (open || isNudgeSuppressed() || root.hidden) return;
    maybeStartAttention();
    if (nudgeTimer) clearTimeout(nudgeTimer);
    nudgeTimer = setTimeout(function () {
      if (open || isNudgeSuppressed()) return;
      showNudge();
    }, 3500);
  }

  function showNudge() {
    if (!nudgeTextEl) return;
    nudge.hidden = false;
    nudge.classList.add('is-visible');
    launcher.classList.add('is-attention');
    startTypewriter();
  }

  function hideNudge(persist, hours) {
    stopTypewriter();
    nudge.classList.remove('is-visible');
    nudge.hidden = true;
    if (nudgeTimer) {
      clearTimeout(nudgeTimer);
      nudgeTimer = null;
    }
    if (persist) suppressNudgeHours(hours || 12);
  }

  function stopTypewriter() {
    if (typeTimer) {
      clearTimeout(typeTimer);
      typeTimer = null;
    }
    typeMode = 'idle';
    if (nudgeTextEl) {
      nudgeTextEl.classList.remove('is-typing');
      nudgeTextEl.textContent = '';
    }
  }

  function startTypewriter() {
    stopTypewriter();
    typeLines = buildNudgeLines();
    if (!typeLines.length) return;
    typeIndex = 0;
    typePos = 0;
    typeMode = 'type';
    nudgeTextEl.classList.add('is-typing');
    nudgeTextEl.textContent = '';
    tickTypewriter();
  }

  function tickTypewriter() {
    if (!nudgeTextEl || nudge.hidden || open) {
      stopTypewriter();
      return;
    }
    var line = typeLines[typeIndex] || '';
    var delay = 42;

    if (typeMode === 'type') {
      typePos += 1;
      nudgeTextEl.textContent = line.slice(0, typePos);
      if (typePos >= line.length) {
        typeMode = 'hold';
        delay = 2200 + Math.floor(Math.random() * 900);
      } else {
        // Slightly snappier on spaces / punctuation so it feels natural.
        var ch = line.charAt(typePos - 1);
        delay = /[,.!?]/.test(ch) ? 90 : ch === ' ' ? 28 : 34 + Math.floor(Math.random() * 28);
      }
    } else if (typeMode === 'hold') {
      typeMode = 'erase';
      delay = 180;
    } else if (typeMode === 'erase') {
      typePos = Math.max(0, typePos - 1);
      nudgeTextEl.textContent = line.slice(0, typePos);
      if (typePos <= 0) {
        typeIndex = (typeIndex + 1) % typeLines.length;
        typeMode = 'type';
        delay = 320;
      } else {
        // Faster delete than typing — like holding backspace.
        delay = 14 + Math.floor(Math.random() * 10);
      }
    }

    typeTimer = setTimeout(tickTypewriter, delay);
  }

  function maybeStartAttention() {
    if (open || isNudgeSuppressed() || root.hidden) return;
    launcher.classList.add('is-attention');
    if (attentionTimer) clearTimeout(attentionTimer);
    // After a while, settle to a gentler idle pulse so it isn’t nagging forever.
    attentionTimer = setTimeout(function () {
      launcher.classList.remove('is-attention');
      launcher.classList.add('is-idle-pulse');
    }, 18000);
  }

  function stopAttention() {
    launcher.classList.remove('is-attention');
    launcher.classList.remove('is-idle-pulse');
    if (attentionTimer) {
      clearTimeout(attentionTimer);
      attentionTimer = null;
    }
  }

  function openFromNudge(prompt) {
    hideNudge(true, 24);
    stopAttention();
    pendingPrompt = prompt || null;
    setOpen(true);
    if (loggedIn || (conversation && !isClosed())) {
      flushPendingPrompt();
    }
    // Guests see prechat; after they start, flushPendingPrompt runs via beginFreshChat/setOpen paths.
    if (!loggedIn && !(conversation && !isClosed())) {
      // Keep pendingPrompt for when they hit Start chat / auto-start after typing isn't available yet.
      var startBtn = panel.querySelector('#sgchat-start');
      if (startBtn && pendingPrompt) {
        var once = function () {
          startBtn.removeEventListener('click', once);
          // beginFreshChat already wired on that button; flush after short delay once started
          setTimeout(flushPendingPrompt, 600);
        };
        startBtn.addEventListener('click', once);
      }
    }
  }

  function flushPendingPrompt() {
    if (!pendingPrompt) return;
    if (!conversation || isClosed()) return;
    var text = pendingPrompt;
    pendingPrompt = null;
    setTimeout(function () {
      sendMessage(text);
    }, 250);
  }

  function startChat(done) {
    if (busy) return;
    if (conversation && !isClosed()) {
      showPrechat(false);
      showRestart(false);
      showChatUi(true);
      if (typeof done === 'function') done();
      return;
    }
    busy = true;
    conversation = null;
    var name = loggedIn ? username : (panel.querySelector('#sgchat-name').value || '');
    var email = loggedIn ? '' : (panel.querySelector('#sgchat-email').value || '');
    post('start', {
      name: name,
      email: email,
      page_url: location.href
    }).then(function (data) {
      busy = false;
      if (!data.ok) {
        softError(data.error || 'Could not start chat');
        return;
      }
      applyConversation(data.conversation, true);
      showPrechat(false);
      showRestart(false);
      showChatUi(true);
      inputEl.focus();
      if (typeof done === 'function') done();
    }).catch(function () { busy = false; });
  }

  function requestHuman() {
    if (!conversation || isClosed()) return;
    post('request_human', { token: conversation.token }).then(function (data) {
      if (data.conversation) applyConversation(data.conversation, true);
    });
  }

  function sendMessage(text, quickId) {
    text = (text || '').trim();
    if (!text && !quickId) return;
    if (!conversation || isClosed()) {
      beginFreshChat(function () { sendMessage(text, quickId); });
      return;
    }
    if (busy) return;
    busy = true;
    var payload = { token: conversation.token, after_id: String(lastId) };
    if (quickId) payload.quick = quickId;
    else payload.message = text;
    inputEl.value = '';
    setTyping(true);
    post('send', payload).then(function (data) {
      busy = false;
      setTyping(false);
      if (!data.ok) {
        if ((data.error || '').toLowerCase().indexOf('closed') !== -1) {
          if (conversation) conversation.status = 'closed';
          showClosedState();
          beginFreshChat(function () { sendMessage(text, quickId); });
          return;
        }
        softError(data.error || 'Could not send');
        return;
      }
      applyConversation(data.conversation, false);
    }).catch(function () {
      busy = false;
      setTyping(false);
    });
  }

  function softError(msg) {
    var row = el('div', { className: 'sgchat-msg sgchat-msg-system' });
    row.innerHTML = '<div class="sgchat-msg-body">' + escapeHtml(msg) + '</div>';
    messagesEl.appendChild(row);
    scrollBottom();
  }

  function setTyping(on) {
    var existing = messagesEl.querySelector('.sgchat-typing');
    if (existing) existing.remove();
    if (!on) return;
    var row = el('div', { className: 'sgchat-msg sgchat-msg-bot sgchat-typing' });
    row.innerHTML = '<div class="sgchat-msg-meta">ScamGuard AI</div><div class="sgchat-msg-body">Thinking…</div>';
    messagesEl.appendChild(row);
    scrollBottom();
  }

  function applyConversation(conv, replace) {
    conversation = conv;
    updateStatus(conv.status, conv.assigned_admin_name);
    setTyping(false);
    if (replace) {
      messagesEl.innerHTML = '';
      lastId = 0;
    }
    (conv.messages || []).forEach(appendMessage);
    if (conv.status === 'closed') {
      suggestionsEl.hidden = true;
      showClosedState();
    } else {
      showRestart(false);
      formEl.hidden = false;
      renderSuggestions(conv.status === 'bot' ? (conv.quick_actions || []) : []);
    }
    scrollBottom();
  }

  function appendMessage(m) {
    var id = Number(m.id || 0);
    if (id && id <= lastId) return;
    if (id) lastId = id;
    var row = el('div', { className: 'sgchat-msg sgchat-msg-' + (m.sender_type || 'system') });
    var who = m.sender_name || m.sender_type || '';
    row.innerHTML =
      '<div class="sgchat-msg-meta">' + escapeHtml(who) + '</div>' +
      '<div class="sgchat-msg-body">' + formatBody(m.body || '') + '</div>';
    messagesEl.appendChild(row);
  }

  function formatBody(text) {
    var esc = escapeHtml(text).replace(/\n/g, '<br>');
    // Repair URLs the model may have split with spaces/breaks
    esc = esc.replace(/https?:\s*\/\s*\//gi, 'https://');
    esc = esc.replace(/https:\/\/(?:[^\s<]+|\s(?=[a-z0-9/.?&=_%#-]))+/gi, function (url) {
      return url.replace(/\s+/g, '');
    });
    return esc.replace(/(https:\/\/[^\s<]+)/g, function (url) {
      var clean = url.replace(/[),.;!?]+$/g, '');
      var trail = url.slice(clean.length);
      return '<a href="' + clean + '" target="_blank" rel="noopener noreferrer">' + clean + '</a>' + trail;
    });
  }

  function renderSuggestions(actions) {
    suggestionsEl.innerHTML = '';
    if (!actions || !actions.length) {
      suggestionsEl.hidden = true;
      return;
    }
    suggestionsEl.hidden = false;
    actions.forEach(function (a) {
      var btn = el('button', { type: 'button', className: 'sgchat-chip' });
      btn.textContent = a.label || a.id;
      btn.addEventListener('click', function () {
        if (a.id === 'human') {
          if (!conversation || isClosed()) beginFreshChat(requestHuman);
          else requestHuman();
          return;
        }
        sendMessage(a.label || a.id, a.id);
      });
      suggestionsEl.appendChild(btn);
    });
  }

  function updateStatus(status, adminName) {
    var map = {
      bot: aiEnabled ? 'AI helper' : 'Bot helper',
      waiting: 'Waiting for admin',
      active: adminName ? ('Admin · ' + adminName) : 'Admin connected',
      closed: 'Chat closed'
    };
    statusEl.textContent = map[status] || status || 'Support';
    statusEl.dataset.status = status || '';
  }

  function showPrechat(show) {
    prechatEl.classList.toggle('is-visible', !!show);
    prechatEl.hidden = !show;
  }

  function showChatUi(show) {
    formEl.hidden = !show;
    messagesEl.hidden = !show;
    if (!show) {
      suggestionsEl.hidden = true;
      showRestart(false);
    }
  }

  function showRestart(show) {
    restartEl.hidden = !show;
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      if (!conversation || conversation.status === 'closed') return;
      post('poll', { token: conversation.token, after_id: String(lastId) }).then(function (data) {
        if (!data.ok) return;
        if (data.status) updateStatus(data.status, data.assigned_admin_name);
        var added = 0;
        (data.messages || []).forEach(function (m) {
          var before = lastId;
          appendMessage(m);
          if (lastId > before) added++;
        });
        if (added) {
          scrollBottom();
          if (!open) setBadge((Number(badgeEl.textContent) || 0) + added);
        }
        if (conversation) conversation.status = data.status || conversation.status;
      });
    }, 4000);
  }

  function maybeAskRating() {
    if (!conversation || conversation.rating) return;
    var wrap = el('div', { className: 'sgchat-rating' });
    wrap.innerHTML = '<p>How was this chat?</p><div class="sgchat-stars"></div>';
    var stars = wrap.querySelector('.sgchat-stars');
    for (var i = 1; i <= 5; i++) {
      (function (n) {
        var b = el('button', { type: 'button', className: 'sgchat-star' });
        b.textContent = '★';
        b.addEventListener('click', function () {
          post('rate', { token: conversation.token, rating: String(n) }).then(function () {
            wrap.innerHTML = '<p>Thanks for the feedback.</p>';
          });
        });
        stars.appendChild(b);
      })(i);
    }
    messagesEl.appendChild(wrap);
    scrollBottom();
  }

  function setBadge(n) {
    n = Number(n) || 0;
    if (n <= 0) { clearBadge(); return; }
    badgeEl.hidden = false;
    badgeEl.textContent = n > 9 ? '9+' : String(n);
  }
  function clearBadge() {
    badgeEl.hidden = true;
    badgeEl.textContent = '0';
  }
  function scrollBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function get(action) {
    return fetch(apiUrl + '?action=' + encodeURIComponent(action), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (r) { return r.json(); });
  }

  function post(action, fields) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf', csrf);
    Object.keys(fields || {}).forEach(function (k) {
      if (fields[k] != null) body.set(k, fields[k]);
    });
    return fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function el(tag, attrs) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        if (k === 'className') node.className = attrs[k];
        else if (k === 'hidden') node.hidden = !!attrs[k];
        else node.setAttribute(k, attrs[k]);
      });
    }
    return node;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
})();
