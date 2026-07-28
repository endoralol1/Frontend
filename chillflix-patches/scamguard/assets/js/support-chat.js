(function () {
  'use strict';

  var root = document.getElementById('sg-support-chat');
  if (!root) return;

  var apiUrl = root.getAttribute('data-api') || '';
  var basePath = root.getAttribute('data-base') || '';
  var csrf = '';
  var conversation = null;
  var lastId = 0;
  var pollTimer = null;
  var open = false;
  var busy = false;

  var launcher = el('button', {
    className: 'sgchat-launcher',
    type: 'button',
    'aria-label': 'Open support chat'
  });
  launcher.innerHTML = '<span class="sgchat-launcher-icon" aria-hidden="true">💬</span><span class="sgchat-badge" hidden>0</span>';

  var panel = el('div', { className: 'sgchat-panel', hidden: true, role: 'dialog', 'aria-label': 'Support chat' });
  panel.innerHTML =
    '<div class="sgchat-head">' +
      '<div class="sgchat-head-text">' +
        '<strong>ScamGuard Support</strong>' +
        '<span class="sgchat-status" id="sgchat-status">Bot helper</span>' +
      '</div>' +
      '<div class="sgchat-head-actions">' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-human" title="Talk to admin">👤</button>' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-close-chat" title="End chat">✕</button>' +
        '<button type="button" class="sgchat-iconbtn" id="sgchat-minimize" title="Minimize">–</button>' +
      '</div>' +
    '</div>' +
    '<div class="sgchat-messages" id="sgchat-messages"></div>' +
    '<div class="sgchat-suggestions" id="sgchat-suggestions"></div>' +
    '<form class="sgchat-compose" id="sgchat-form">' +
      '<textarea id="sgchat-input" rows="1" maxlength="2000" placeholder="Ask a question…" required></textarea>' +
      '<button type="submit" class="sgchat-send" aria-label="Send">➤</button>' +
    '</form>' +
    '<div class="sgchat-prechat" id="sgchat-prechat" hidden>' +
      '<p>Start a chat with our helper bot. An admin can join if needed.</p>' +
      '<label>Name <input type="text" id="sgchat-name" maxlength="80" placeholder="Optional"></label>' +
      '<label>Email <input type="email" id="sgchat-email" maxlength="190" placeholder="Optional"></label>' +
      '<button type="button" class="btn btn-primary sgchat-start" id="sgchat-start">Start chat</button>' +
    '</div>';

  root.appendChild(launcher);
  root.appendChild(panel);

  var messagesEl = panel.querySelector('#sgchat-messages');
  var suggestionsEl = panel.querySelector('#sgchat-suggestions');
  var statusEl = panel.querySelector('#sgchat-status');
  var formEl = panel.querySelector('#sgchat-form');
  var inputEl = panel.querySelector('#sgchat-input');
  var prechatEl = panel.querySelector('#sgchat-prechat');
  var badgeEl = launcher.querySelector('.sgchat-badge');

  launcher.addEventListener('click', function () {
    open = !open;
    panel.hidden = !open;
    launcher.classList.toggle('is-open', open);
    if (open) {
      clearBadge();
      if (!conversation) showPrechat(true);
      else { showPrechat(false); scrollBottom(); }
    }
  });

  panel.querySelector('#sgchat-minimize').addEventListener('click', function () {
    open = false;
    panel.hidden = true;
    launcher.classList.remove('is-open');
  });

  panel.querySelector('#sgchat-human').addEventListener('click', function () {
    if (!conversation) return;
    post('request_human', { token: conversation.token }).then(function (data) {
      if (data.conversation) applyConversation(data.conversation, true);
    });
  });

  panel.querySelector('#sgchat-close-chat').addEventListener('click', function () {
    if (!conversation) {
      open = false;
      panel.hidden = true;
      return;
    }
    if (!confirm('End this chat?')) return;
    post('close', { token: conversation.token }).then(function (data) {
      if (data.conversation) applyConversation(data.conversation, true);
      maybeAskRating();
    });
  });

  panel.querySelector('#sgchat-start').addEventListener('click', startChat);

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

  function bootstrap() {
    get('bootstrap').then(function (data) {
      if (!data || !data.ok || data.enabled === false) {
        root.hidden = true;
        return;
      }
      csrf = data.csrf || '';
      if (data.username) {
        var nameInput = panel.querySelector('#sgchat-name');
        if (nameInput && !nameInput.value) nameInput.value = data.username;
      }
      if (data.conversation) {
        applyConversation(data.conversation, true);
        showPrechat(false);
        if ((data.conversation.unread_visitor || 0) > 0) setBadge(data.conversation.unread_visitor);
      } else {
        renderSuggestions(data.quick_actions || []);
      }
      startPolling();
    }).catch(function () {
      root.hidden = true;
    });
  }

  function startChat() {
    if (busy) return;
    busy = true;
    var name = panel.querySelector('#sgchat-name').value || '';
    var email = panel.querySelector('#sgchat-email').value || '';
    post('start', {
      name: name,
      email: email,
      page_url: location.href
    }).then(function (data) {
      busy = false;
      if (!data.ok) { alert(data.error || 'Could not start chat'); return; }
      applyConversation(data.conversation, true);
      showPrechat(false);
      inputEl.focus();
    }).catch(function () { busy = false; });
  }

  function sendMessage(text, quickId) {
    text = (text || '').trim();
    if (!text && !quickId) return;
    if (!conversation) {
      // Auto-start then send
      post('start', {
        name: (panel.querySelector('#sgchat-name').value || ''),
        email: (panel.querySelector('#sgchat-email').value || ''),
        page_url: location.href
      }).then(function (data) {
        if (!data.ok) { alert(data.error || 'Could not start chat'); return; }
        applyConversation(data.conversation, true);
        showPrechat(false);
        sendMessage(text, quickId);
      });
      return;
    }
    if (busy) return;
    busy = true;
    var payload = { token: conversation.token, after_id: String(lastId) };
    if (quickId) payload.quick = quickId;
    else payload.message = text;
    inputEl.value = '';
    post('send', payload).then(function (data) {
      busy = false;
      if (!data.ok) { alert(data.error || 'Could not send'); return; }
      applyConversation(data.conversation, false);
    }).catch(function () { busy = false; });
  }

  function applyConversation(conv, replace) {
    conversation = conv;
    updateStatus(conv.status, conv.assigned_admin_name);
    if (replace) {
      messagesEl.innerHTML = '';
      lastId = 0;
    }
    (conv.messages || []).forEach(appendMessage);
    renderSuggestions(conv.status === 'bot' ? (conv.quick_actions || []) : []);
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
      '<div class="sgchat-msg-body">' + escapeHtml(m.body || '').replace(/\n/g, '<br>') + '</div>';
    messagesEl.appendChild(row);
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
          if (!conversation) {
            sendMessage('Talk to an admin', 'human');
          } else {
            post('request_human', { token: conversation.token }).then(function (data) {
              if (data.conversation) applyConversation(data.conversation, true);
            });
          }
          return;
        }
        sendMessage(a.label || a.id, a.id);
      });
      suggestionsEl.appendChild(btn);
    });
  }

  function updateStatus(status, adminName) {
    var map = {
      bot: 'Bot helper',
      waiting: 'Waiting for admin',
      active: adminName ? ('Admin · ' + adminName) : 'Admin connected',
      closed: 'Chat closed'
    };
    statusEl.textContent = map[status] || status || 'Support';
    statusEl.dataset.status = status || '';
  }

  function showPrechat(show) {
    prechatEl.hidden = !show;
    formEl.hidden = show;
    messagesEl.hidden = show;
    suggestionsEl.hidden = show;
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
