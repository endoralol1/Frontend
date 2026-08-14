<?php
$adminUser = $adminUser ?? Auth::user();
?>
<main class="cf-admin page-pad-top cf-inbox-admin">
  <div class="container cf-admin-wrap">
    <nav class="cf-admin-nav" aria-label="Admin">
      <a href="<?= e(url('/admin')) ?>">Dashboard</a>
      <a href="<?= e(url('/admin/users')) ?>">Users</a>
      <a href="<?= e(url('/admin/sources')) ?>">Sources</a>
      <a class="is-active" href="<?= e(url('/admin/inbox')) ?>">Inbox</a>
      <a href="<?= e(url('/home')) ?>">Site</a>
    </nav>

    <div class="cf-admin-head cf-ia-head">
      <h1>Inbox</h1>
      <p>Polls &amp; notifications · bell shows only while something is active</p>
    </div>

    <div class="cf-admin-msg" id="inbox-msg" hidden></div>

    <details class="cf-ia-create cf-admin-panel">
      <summary>Create new</summary>
      <div class="cf-ia-form">
        <div class="cf-ia-row">
          <select id="in-type" class="cf-ia-sel" aria-label="Type">
            <option value="notification">Notification</option>
            <option value="poll">Poll</option>
          </select>
          <select id="in-status" class="cf-ia-sel" aria-label="Status">
            <option value="active">Active</option>
            <option value="draft">Draft</option>
          </select>
        </div>
        <input id="in-title" class="cf-ia-inp" type="text" maxlength="200" placeholder="Title" required>
        <textarea id="in-body" class="cf-ia-inp" rows="2" placeholder="Body (optional)"></textarea>
        <div id="in-poll-opts" class="cf-ia-poll-opts" hidden>
          <textarea id="in-options" class="cf-ia-inp" rows="3" placeholder="Options, one per line. Seed: Option|12"></textarea>
        </div>
        <div class="cf-ia-row">
          <label class="cf-ia-mini">Likes <input id="in-likes" class="cf-ia-num" type="number" min="0" value="0"></label>
          <label class="cf-ia-mini">Dislikes <input id="in-dislikes" class="cf-ia-num" type="number" min="0" value="0"></label>
          <select id="in-show-results" class="cf-ia-sel" aria-label="Show results">
            <option value="after_vote">Results: after vote</option>
            <option value="always">Results: always</option>
            <option value="after_close">Results: after close</option>
            <option value="never">Results: never</option>
          </select>
        </div>
        <div class="cf-ia-row">
          <select id="in-voting" class="cf-ia-sel" aria-label="Voting">
            <option value="1">Voting on</option>
            <option value="0">Voting off</option>
          </select>
          <select id="in-react" class="cf-ia-sel" aria-label="Reactions">
            <option value="1">Reactions on</option>
            <option value="0">Reactions off</option>
          </select>
          <select id="in-guests" class="cf-ia-sel" aria-label="Guests">
            <option value="1">Guests allowed</option>
            <option value="0">Signed-in only</option>
          </select>
        </div>
        <div class="cf-ia-row">
          <select id="in-pin" class="cf-ia-sel" aria-label="Pin">
            <option value="0">Not pinned</option>
            <option value="1">Pin to top</option>
          </select>
          <select id="in-multi" class="cf-ia-sel" aria-label="Multi" hidden>
            <option value="0">Single choice</option>
            <option value="1">Multi choice</option>
          </select>
          <input id="in-ends" class="cf-ia-inp cf-ia-ends" type="datetime-local" aria-label="Ends at">
        </div>
        <div class="cf-ia-actions">
          <button type="button" class="cf-admin-btn" id="in-save">Create</button>
        </div>
      </div>
    </details>

    <div class="cf-ia-toolbar">
      <strong>Items</strong>
      <button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" id="in-refresh">Refresh</button>
    </div>
    <div id="inbox-list" class="cf-ia-list">Loading…</div>
  </div>
</main>

<style>
.cf-inbox-admin .cf-ia-head { margin-bottom: .85rem; }
.cf-inbox-admin .cf-ia-head h1 { font-size: clamp(1.35rem, 3.5vw, 1.7rem) !important; margin-bottom: .2rem !important; }
.cf-inbox-admin .cf-ia-head p { margin: 0 !important; font-size: .82rem !important; color: rgba(255,255,255,.5) !important; }
.cf-ia-create { margin-bottom: .85rem; padding: 0 !important; overflow: hidden; }
.cf-ia-create > summary {
  list-style: none; cursor: pointer; padding: .7rem .85rem; font-weight: 700; font-size: .86rem;
  display: flex; align-items: center; justify-content: space-between;
}
.cf-ia-create > summary::-webkit-details-marker { display: none; }
.cf-ia-create > summary::after { content: "+"; color: rgba(255,255,255,.45); font-size: 1.1rem; font-weight: 600; }
.cf-ia-create[open] > summary::after { content: "–"; }
.cf-ia-create .cf-ia-form { padding: 0 .85rem .85rem; display: grid; gap: .45rem; }
.cf-ia-row { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
.cf-ia-sel, .cf-ia-inp, .cf-ia-num {
  min-height: 2rem; border-radius: .55rem; border: 1px solid rgba(255,255,255,.12);
  background: rgba(0,0,0,.28); color: #fff; font: inherit; font-size: .82rem; padding: .3rem .5rem;
}
.cf-ia-sel { flex: 1 1 8rem; max-width: 100%; }
.cf-ia-inp { width: 100%; }
.cf-ia-num { width: 3.6rem; text-align: center; padding: .25rem .3rem; }
.cf-ia-mini { display: inline-flex; align-items: center; gap: .3rem; font-size: .75rem; color: rgba(255,255,255,.55); }
.cf-ia-ends { flex: 1 1 10rem; min-width: 0; }
.cf-ia-actions { display: flex; gap: .4rem; }
.cf-ia-toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: .5rem;
  margin: .35rem 0 .55rem; font-size: .78rem; letter-spacing: .06em; text-transform: uppercase;
  color: rgba(255,255,255,.5);
}
.cf-ia-btn-sm { min-height: 1.85rem !important; padding: .3rem .65rem !important; font-size: .75rem !important; }
.cf-ia-list { display: grid; gap: .45rem; }
.cf-ia-card {
  border-radius: .85rem; border: 1px solid rgba(255,255,255,.1);
  background: rgba(255,255,255,.03); overflow: hidden;
}
.cf-ia-sum {
  list-style: none; cursor: pointer; display: flex; align-items: center; gap: .55rem;
  padding: .65rem .75rem; user-select: none;
}
.cf-ia-sum::-webkit-details-marker { display: none; }
.cf-ia-title { min-width: 0; flex: 1; }
.cf-ia-title strong { display: block; color: #fff; font-size: .88rem; font-weight: 700; line-height: 1.25; }
.cf-ia-title em {
  display: block; margin-top: .12rem; font-style: normal; font-size: .66rem;
  color: rgba(255,255,255,.45); letter-spacing: .04em; text-transform: uppercase;
}
.cf-ia-sum-hint {
  flex: 0 0 auto; width: 1.35rem; height: 1.35rem; border-radius: .4rem;
  border: 1px solid rgba(255,255,255,.12); background: rgba(0,0,0,.2);
  display: grid; place-items: center; color: rgba(255,255,255,.45); font-size: .85rem; font-weight: 600;
}
.cf-ia-sum-hint::before { content: "+"; }
.cf-ia-card[open] > .cf-ia-sum { border-bottom: 1px solid rgba(255,255,255,.08); }
.cf-ia-card[open] > .cf-ia-sum .cf-ia-sum-hint::before { content: "–"; }
.cf-ia-body-wrap { display: grid; gap: .45rem; padding: .65rem .75rem .75rem; }
.cf-ia-top-actions { display: flex; flex-wrap: wrap; gap: .3rem; }
.cf-ia-top-actions .cf-ia-sel { flex: 1 1 8rem; max-width: 100%; }
.cf-ia-body { margin: 0; font-size: .8rem; color: rgba(255,255,255,.68); line-height: 1.35; }
.cf-ia-opts { display: grid; gap: .3rem; }
.cf-ia-opt {
  display: grid; grid-template-columns: 1fr auto auto; gap: .35rem .45rem; align-items: center;
  padding: .45rem .5rem; border-radius: .55rem; background: rgba(0,0,0,.2); border: 1px solid rgba(255,255,255,.06);
}
.cf-ia-opt-main { min-width: 0; grid-column: 1 / -1; }
.cf-ia-opt-l { font-size: .8rem; font-weight: 650; color: #fff; }
.cf-ia-opt-m { font-size: .68rem; color: rgba(255,255,255,.45); margin-top: .1rem; }
.cf-ia-opt > .cf-ia-mini { flex-direction: column; align-items: stretch; gap: .15rem; font-size: .62rem; }
.cf-ia-plus-votes { color: #ffd2b8 !important; font-weight: 700; }
.cf-ia-bar { height: .28rem; border-radius: 999px; background: rgba(255,255,255,.08); overflow: hidden; margin-top: .25rem; }
.cf-ia-bar > i { display: block; height: 100%; background: linear-gradient(90deg, var(--cf-orange,#db6937), var(--cf-orange-deep,#c43c2e)); }
.cf-ia-meta { font-size: .72rem; color: rgba(255,255,255,.45); }
.cf-ia-panel {
  display: none; grid-gap: .4rem; padding-top: .45rem; border-top: 1px solid rgba(255,255,255,.08);
}
.cf-ia-card.is-edit .cf-ia-panel-edit,
.cf-ia-card.is-counts .cf-ia-panel-counts,
.cf-ia-card.is-drip .cf-ia-panel-drip { display: grid; }
.cf-ia-panel label.cf-ia-field {
  display: grid; gap: .2rem; font-size: .68rem; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .04em;
}
.cf-ia-ramp-h {
  font-size: .65rem; letter-spacing: .06em; text-transform: uppercase;
  color: rgba(255,255,255,.5); font-weight: 700;
}
.cf-ia-ramp-live {
  display: grid; gap: .2rem; padding: .45rem .55rem; border-radius: .55rem;
  border: 1px solid rgba(var(--cf-orange-rgb, 219,105,55), .35);
  background: rgba(var(--cf-orange-rgb, 219,105,55), .1);
  font-size: .72rem; color: rgba(255,255,255,.78); line-height: 1.35;
}
.cf-ia-ramp-live strong { color: #ffd2b8; font-weight: 700; }
@media (max-width: 640px) {
  .cf-ia-top-actions { width: 100%; }
  .cf-ia-top-actions .cf-ia-sel { flex: 1 1 calc(50% - .3rem); }
  .cf-ia-opt { grid-template-columns: 1fr 1fr; }
  .cf-ia-opt > .cf-ia-mini .cf-ia-num { width: 100%; min-height: 2.2rem; }
}
</style>

<script>
(function () {
  var API = <?= json_encode(url('/api/admin/inbox')) ?>;
  var msg = document.getElementById('inbox-msg');
  var list = document.getElementById('inbox-list');

  function show(t, ok) {
    msg.hidden = false;
    msg.textContent = t;
    msg.className = 'cf-admin-msg' + (ok ? ' ok' : '');
  }
  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }
  function fmtRemain(sec) {
    sec = Math.max(0, sec | 0);
    if (sec < 60) return sec + 's';
    if (sec < 3600) return Math.round(sec / 60) + 'm';
    var h = Math.floor(sec / 3600), m = Math.round((sec % 3600) / 60);
    return h + 'h' + (m ? (' ' + m + 'm') : '');
  }
  function sel(id) { return document.getElementById(id); }
  function syncType() {
    var poll = sel('in-type').value === 'poll';
    sel('in-poll-opts').hidden = !poll;
    sel('in-multi').hidden = !poll;
    sel('in-voting').hidden = !poll;
  }
  sel('in-type').onchange = syncType;
  syncType();

  function createSettings() {
    return {
      allowGuests: sel('in-guests').value === '1',
      allowReactions: sel('in-react').value === '1',
      votingEnabled: sel('in-voting').value === '1',
      pin: sel('in-pin').value === '1',
      allowMultiple: sel('in-multi').value === '1',
      requireAuthToVote: false,
      requireAuthToReact: false,
      showResults: sel('in-show-results').value
    };
  }

  function parseOptionLines(text) {
    return String(text || '').split(/\n+/).map(function (line) {
      line = line.trim();
      if (!line) return null;
      var m = line.match(/^(.*)\|(\d+)\s*$/);
      if (m) return { label: m[1].trim(), voteCount: parseInt(m[2], 10) || 0 };
      return { label: line, voteCount: 0 };
    }).filter(Boolean);
  }

  function optSelected(val, cur) {
    return String(val) === String(cur) ? ' selected' : '';
  }

  function render(items) {
    if (!items || !items.length) {
      list.innerHTML = '<p class="cf-ia-meta">No inbox items yet.</p>';
      return;
    }
    var openIds = {};
    var panelState = {};
    list.querySelectorAll('.cf-ia-card[open]').forEach(function (el) {
      var oid = el.getAttribute('data-id');
      if (oid) openIds[oid] = true;
      if (el.classList.contains('is-edit')) panelState[oid] = 'is-edit';
      else if (el.classList.contains('is-counts')) panelState[oid] = 'is-counts';
      else if (el.classList.contains('is-drip')) panelState[oid] = 'is-drip';
    });
    list.innerHTML = items.map(function (it) {
      var s = it.settings || {};
      var totalVotes = 0;
      (it.options || []).forEach(function (o) { totalVotes += (o.voteCount || 0); });
      var opts = (it.options || []).map(function (o) {
        var vc = o.voteCount || 0;
        var pct = totalVotes > 0 ? Math.round((vc / totalVotes) * 100) : 0;
        return '<div class="cf-ia-opt" data-opt="' + esc(o.id) + '">' +
          '<div class="cf-ia-opt-main">' +
            '<div class="cf-ia-opt-l">' + esc(o.label) + '</div>' +
            '<div class="cf-ia-opt-m">' + vc + ' · ' + pct + '%</div>' +
            '<div class="cf-ia-bar" aria-hidden="true"><i style="width:' + pct + '%"></i></div>' +
          '</div>' +
          '<label class="cf-ia-mini">Now<input class="cf-ia-num" type="number" min="0" value="' + vc + '" data-vote-input inputmode="numeric"></label>' +
          '<label class="cf-ia-mini cf-ia-plus-votes">+Votes<input class="cf-ia-num" type="number" min="0" value="0" data-ramp-add inputmode="numeric"></label>' +
        '</div>';
      }).join('');

      var ramps = it.ramps || [];
      function rampLabel(r) {
        if (r.kind === 'like') return 'Likes';
        if (r.kind === 'dislike') return 'Dislikes';
        if (r.kind === 'option' && r.optionId) {
          var match = (it.options || []).find(function (o) { return o.id === r.optionId; });
          if (match) return 'Votes · ' + match.label;
          return 'Votes';
        }
        return r.kind;
      }
      function rampLine(r) {
        return '<div><strong>' + esc(rampLabel(r)) + '</strong>: ' +
          r.lastApplied + ' / ' + r.targetCount +
          ' · ' + Math.round((r.progress || 0) * 100) + '%' +
          ' · ' + fmtRemain(r.remainingSec) + ' left</div>';
      }
      var rampLive = ramps.length
        ? '<div class="cf-ia-ramp-live">' + ramps.map(rampLine).join('') + '</div>'
        : '';

      var voteOff = s.votingEnabled === false || s.votingEnabled === 0;
      var flags = [];
      if (it.type === 'poll') flags.push(voteOff ? 'votes off' : 'votes on');
      flags.push(s.allowReactions ? 'react on' : 'react off');
      if (s.pin) flags.push('pinned');
      if (ramps.length) flags.push('drip');

      return '<details class="cf-ia-card' + (panelState[it.id] ? ' ' + panelState[it.id] : '') + '"' +
        (openIds[it.id] ? ' open' : '') +
        ' data-id="' + esc(it.id) + '" data-type="' + esc(it.type) + '">' +
        '<summary class="cf-ia-sum">' +
          '<span class="cf-ia-title">' +
            '<strong>' + esc(it.title) + '</strong>' +
            '<em>' + esc(it.type) + ' · ' + esc(it.status) +
              (flags.length ? ' · ' + flags.join(' · ') : '') +
              ' · likes ' + (it.likeCount || 0) +
              (it.type === 'poll' ? ' · votes ' + (it.totalVotes != null ? it.totalVotes : totalVotes) : '') +
            '</em>' +
          '</span>' +
          '<span class="cf-ia-sum-hint" aria-hidden="true"></span>' +
        '</summary>' +
        '<div class="cf-ia-body-wrap">' +
          '<div class="cf-ia-top-actions">' +
            '<select class="cf-ia-sel" data-quick-status aria-label="Status">' +
              '<option value="active"' + optSelected('active', it.status) + '>Active</option>' +
              '<option value="draft"' + optSelected('draft', it.status) + '>Draft</option>' +
              '<option value="closed"' + optSelected('closed', it.status) + '>Closed</option>' +
              '<option value="archived"' + optSelected('archived', it.status) + '>Archived</option>' +
            '</select>' +
            '<select class="cf-ia-sel" data-panel aria-label="Manage">' +
              '<option value="">Manage…</option>' +
              '<option value="edit">Edit settings</option>' +
              '<option value="counts">Set counts</option>' +
              '<option value="drip">Gradual drip</option>' +
              '<option value="delete">Delete</option>' +
            '</select>' +
          '</div>' +
          (it.body ? '<p class="cf-ia-body">' + esc(it.body) + '</p>' : '') +
          rampLive +
          (opts ? '<div class="cf-ia-opts">' + opts + '</div>' : '') +
          '<div class="cf-ia-meta">Likes ' + (it.likeCount || 0) + ' · Dislikes ' + (it.dislikeCount || 0) +
            (it.type === 'poll' ? ' · Total votes ' + (it.totalVotes != null ? it.totalVotes : totalVotes) : '') + '</div>' +

          '<div class="cf-ia-panel cf-ia-panel-edit">' +
            '<label class="cf-ia-field">Title<input class="cf-ia-inp" data-edit-title value="' + esc(it.title) + '"></label>' +
            '<label class="cf-ia-field">Body<textarea class="cf-ia-inp" rows="2" data-edit-body>' + esc(it.body || '') + '</textarea></label>' +
            '<div class="cf-ia-row">' +
              (it.type === 'poll' ? '<select class="cf-ia-sel" data-edit-voting aria-label="Voting">' +
                '<option value="1"' + optSelected(1, voteOff ? 0 : 1) + '>Voting on</option>' +
                '<option value="0"' + optSelected(0, voteOff ? 0 : 1) + '>Voting off</option>' +
              '</select>' : '') +
              '<select class="cf-ia-sel" data-edit-react aria-label="Reactions">' +
                '<option value="1"' + optSelected(1, s.allowReactions ? 1 : 0) + '>Reactions on</option>' +
                '<option value="0"' + optSelected(0, s.allowReactions ? 1 : 0) + '>Reactions off</option>' +
              '</select>' +
              '<select class="cf-ia-sel" data-edit-guests aria-label="Guests">' +
                '<option value="1"' + optSelected(1, s.allowGuests ? 1 : 0) + '>Guests allowed</option>' +
                '<option value="0"' + optSelected(0, s.allowGuests ? 1 : 0) + '>Signed-in only</option>' +
              '</select>' +
            '</div>' +
            '<div class="cf-ia-row">' +
              '<select class="cf-ia-sel" data-edit-results aria-label="Results">' +
                '<option value="after_vote"' + optSelected('after_vote', s.showResults) + '>Results: after vote</option>' +
                '<option value="always"' + optSelected('always', s.showResults) + '>Results: always</option>' +
                '<option value="after_close"' + optSelected('after_close', s.showResults) + '>Results: after close</option>' +
                '<option value="never"' + optSelected('never', s.showResults) + '>Results: never</option>' +
              '</select>' +
              '<select class="cf-ia-sel" data-edit-pin aria-label="Pin">' +
                '<option value="0"' + optSelected(0, s.pin ? 1 : 0) + '>Not pinned</option>' +
                '<option value="1"' + optSelected(1, s.pin ? 1 : 0) + '>Pinned</option>' +
              '</select>' +
              (it.type === 'poll' ? '<select class="cf-ia-sel" data-edit-multi aria-label="Choices">' +
                '<option value="0"' + optSelected(0, s.allowMultiple ? 1 : 0) + '>Single choice</option>' +
                '<option value="1"' + optSelected(1, s.allowMultiple ? 1 : 0) + '>Multi choice</option>' +
              '</select>' : '') +
            '</div>' +
            '<div class="cf-ia-actions">' +
              '<button type="button" class="cf-admin-btn cf-ia-btn-sm" data-act="save-edit">Save settings</button>' +
              '<button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" data-act="close-panel">Close</button>' +
            '</div>' +
          '</div>' +

          '<div class="cf-ia-panel cf-ia-panel-counts">' +
            '<div class="cf-ia-row">' +
              '<label class="cf-ia-mini">Likes <input class="cf-ia-num" type="number" min="0" value="' + (it.likeCount || 0) + '" data-like-input></label>' +
              '<label class="cf-ia-mini">Dislikes <input class="cf-ia-num" type="number" min="0" value="' + (it.dislikeCount || 0) + '" data-dislike-input></label>' +
              '<button type="button" class="cf-admin-btn cf-ia-btn-sm" data-act="save-counts">Save counts</button>' +
              '<button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" data-act="close-panel">Close</button>' +
            '</div>' +
            '<p class="cf-ia-meta">Edit option Now numbers above, then save.</p>' +
          '</div>' +

          '<div class="cf-ia-panel cf-ia-panel-drip">' +
            (it.type === 'poll'
              ? '<div class="cf-ia-ramp-h">Poll votes</div>' +
                '<p class="cf-ia-meta" style="margin:0">Set <strong>+Votes</strong> on options above, then start.</p>' +
                '<div class="cf-ia-row">' +
                  '<select class="cf-ia-sel" data-vote-duration>' +
                    '<option value="900">15 min</option><option value="1800">30 min</option>' +
                    '<option value="3600" selected>1 hour</option><option value="10800">3 hours</option>' +
                    '<option value="21600">6 hours</option><option value="43200">12 hours</option>' +
                    '<option value="86400">24 hours</option>' +
                  '</select>' +
                  '<select class="cf-ia-sel" data-vote-curve>' +
                    '<option value="bursty" selected>Bursty</option><option value="linear">Linear</option>' +
                    '<option value="ease_out">Fast→slow</option><option value="ease_in">Slow→fast</option>' +
                  '</select>' +
                  '<button type="button" class="cf-admin-btn cf-ia-btn-sm" data-act="start-vote-ramp">Start votes</button>' +
                  '<button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" data-act="cancel-vote-ramp">Cancel</button>' +
                '</div>'
              : '') +
            '<div class="cf-ia-ramp-h"' + (it.type === 'poll' ? ' style="margin-top:.35rem"' : '') + '>Likes</div>' +
            '<div class="cf-ia-row">' +
              '<select class="cf-ia-sel" data-react-duration>' +
                '<option value="900">15 min</option><option value="1800">30 min</option>' +
                '<option value="3600" selected>1 hour</option><option value="10800">3 hours</option>' +
                '<option value="21600">6 hours</option><option value="43200">12 hours</option>' +
                '<option value="86400">24 hours</option>' +
              '</select>' +
              '<select class="cf-ia-sel" data-react-curve>' +
                '<option value="bursty" selected>Bursty</option><option value="linear">Linear</option>' +
                '<option value="ease_out">Fast→slow</option><option value="ease_in">Slow→fast</option>' +
              '</select>' +
            '</div>' +
            '<div class="cf-ia-row">' +
              '<label class="cf-ia-mini">+Likes <input class="cf-ia-num" type="number" min="0" value="0" data-ramp-likes></label>' +
              '<label class="cf-ia-mini">+Dislikes <input class="cf-ia-num" type="number" min="0" value="0" data-ramp-dislikes></label>' +
              '<button type="button" class="cf-admin-btn cf-ia-btn-sm" data-act="start-react-ramp">Start likes</button>' +
              '<button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" data-act="cancel-react-ramp">Cancel</button>' +
              '<button type="button" class="cf-admin-btn ghost cf-ia-btn-sm" data-act="close-panel">Close</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</details>';
    }).join('');
  }

  var refreshTimer = null;
  var lastItems = null;

  function isEditing() {
    var ae = document.activeElement;
    if (ae && list.contains(ae) && /^(INPUT|TEXTAREA|SELECT|BUTTON)$/.test(ae.tagName)) return true;
    if (list.querySelector('.cf-ia-card.is-edit, .cf-ia-card.is-counts, .cf-ia-card.is-drip')) return true;
    if (list.querySelector('.cf-ia-card[open] input:focus, .cf-ia-card[open] textarea:focus, .cf-ia-card[open] select:focus')) return true;
    return false;
  }

  function scheduleRefresh(ms) {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(function () { load(false); }, ms);
  }

  function load(force) {
    if (!force && isEditing()) {
      // Don't wipe open cards / focused inputs — auto-refresh waits.
      scheduleRefresh(8000);
      return;
    }
    fetch(API, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { list.textContent = 'Failed'; return; }
        if (!force && isEditing()) {
          scheduleRefresh(8000);
          return;
        }
        var items = d.items || [];
        lastItems = items;
        render(items);
        var hasRamp = items.some(function (it) { return (it.ramps || []).length > 0; });
        var anyOpen = !!list.querySelector('.cf-ia-card[open]');
        scheduleRefresh(anyOpen ? 45000 : (hasRamp ? 15000 : 30000));
      })
      .catch(function () { list.textContent = 'Failed'; });
  }

  function patch(id, body) {
    return fetch(API + '/' + encodeURIComponent(id), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  sel('in-refresh').onclick = function () { load(true); };
  sel('in-save').onclick = function () {
    var type = sel('in-type').value;
    var payload = {
      type: type,
      title: sel('in-title').value.trim(),
      body: sel('in-body').value.trim(),
      status: sel('in-status').value,
      settings: createSettings(),
      likeCount: parseInt(sel('in-likes').value, 10) || 0,
      dislikeCount: parseInt(sel('in-dislikes').value, 10) || 0
    };
    var ends = sel('in-ends').value;
    if (ends) payload.endsAt = Math.floor(new Date(ends).getTime() / 1000);
    if (type === 'poll') payload.options = parseOptionLines(sel('in-options').value);
    if (!payload.title) { show('Title required'); return; }
    show('Saving…');
    fetch(API, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.ok) { show((d && d.error) || 'Save failed'); return; }
      show('Created', true);
      sel('in-title').value = '';
      sel('in-body').value = '';
      sel('in-options').value = '';
      sel('in-likes').value = '0';
      sel('in-dislikes').value = '0';
      document.querySelector('.cf-ia-create').open = false;
      load(true);
    }).catch(function () { show('Save failed'); });
  };

  list.addEventListener('change', function (e) {
    var card = e.target.closest('.cf-ia-card');
    if (!card) return;
    var id = card.getAttribute('data-id');

    if (e.target.matches('[data-quick-status]')) {
      var status = e.target.value;
      patch(id, { status: status }).then(function (d) {
        if (d && d.ok) { show('Status updated', true); load(true); }
        else show((d && d.error) || 'Update failed');
      });
      return;
    }

    if (e.target.matches('[data-panel]')) {
      var panel = e.target.value;
      e.target.value = '';
      card.classList.remove('is-edit', 'is-counts', 'is-drip');
      if (panel === 'delete') {
        if (!confirm('Delete this item?')) return;
        fetch(API + '/' + encodeURIComponent(id), { method: 'DELETE', credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.ok) { show('Deleted', true); load(true); }
            else show('Delete failed');
          });
        return;
      }
      if (panel === 'edit') card.classList.add('is-edit');
      if (panel === 'counts') card.classList.add('is-counts');
      if (panel === 'drip') card.classList.add('is-drip');
    }
  });

  list.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;
    var card = btn.closest('.cf-ia-card');
    if (!card) return;
    var id = card.getAttribute('data-id');
    var act = btn.getAttribute('data-act');

    if (act === 'close-panel') {
      card.classList.remove('is-edit', 'is-counts', 'is-drip');
      return;
    }

    if (act === 'save-edit') {
      var settings = {
        allowReactions: (card.querySelector('[data-edit-react]') || {}).value === '1',
        allowGuests: (card.querySelector('[data-edit-guests]') || {}).value === '1',
        showResults: (card.querySelector('[data-edit-results]') || {}).value || 'after_vote',
        pin: (card.querySelector('[data-edit-pin]') || {}).value === '1'
      };
      var voteEl = card.querySelector('[data-edit-voting]');
      if (voteEl) settings.votingEnabled = voteEl.value === '1';
      var multiEl = card.querySelector('[data-edit-multi]');
      if (multiEl) settings.allowMultiple = multiEl.value === '1';
      var body = {
        title: (card.querySelector('[data-edit-title]') || {}).value || '',
        body: (card.querySelector('[data-edit-body]') || {}).value || '',
        settings: settings
      };
      btn.disabled = true;
      patch(id, body).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { show('Settings saved', true); load(true); }
        else show((d && d.error) || 'Save failed');
      }).catch(function () { btn.disabled = false; show('Save failed'); });
      return;
    }

    if (act === 'save-counts') {
      var optionVotes = [];
      card.querySelectorAll('.cf-ia-opt').forEach(function (opt) {
        var oid = opt.getAttribute('data-opt');
        var inp = opt.querySelector('[data-vote-input]');
        if (!oid || !inp) return;
        optionVotes.push({ id: oid, voteCount: parseInt(inp.value, 10) || 0 });
      });
      var body = {
        likeCount: parseInt((card.querySelector('[data-like-input]') || {}).value, 10) || 0,
        dislikeCount: parseInt((card.querySelector('[data-dislike-input]') || {}).value, 10) || 0
      };
      if (optionVotes.length) body.optionVotes = optionVotes;
      btn.disabled = true;
      patch(id, body).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { show('Counts saved', true); load(true); }
        else show((d && d.error) || 'Save failed');
      }).catch(function () { btn.disabled = false; show('Save failed'); });
      return;
    }

    if (act === 'start-vote-ramp') {
      var options = [];
      card.querySelectorAll('.cf-ia-opt').forEach(function (opt) {
        var oid = opt.getAttribute('data-opt');
        var add = parseInt((opt.querySelector('[data-ramp-add]') || {}).value, 10) || 0;
        if (oid && add > 0) options.push({ id: oid, add: add });
      });
      if (!options.length) {
        show('Set +Votes on at least one option, then start');
        return;
      }
      var votePayload = {
        durationSec: parseInt((card.querySelector('[data-vote-duration]') || {}).value, 10) || 3600,
        curve: (card.querySelector('[data-vote-curve]') || {}).value || 'bursty',
        options: options
      };
      btn.disabled = true;
      fetch(API + '/' + encodeURIComponent(id) + '/ramp', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(votePayload)
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { show('Vote drip started', true); load(true); }
        else show((d && d.error) || 'Vote drip failed');
      }).catch(function () { btn.disabled = false; show('Vote drip failed'); });
      return;
    }

    if (act === 'start-react-ramp') {
      var likes = parseInt((card.querySelector('[data-ramp-likes]') || {}).value, 10) || 0;
      var dislikes = parseInt((card.querySelector('[data-ramp-dislikes]') || {}).value, 10) || 0;
      if (!likes && !dislikes) {
        show('Set +Likes and/or +Dislikes first');
        return;
      }
      var reactPayload = {
        durationSec: parseInt((card.querySelector('[data-react-duration]') || {}).value, 10) || 3600,
        curve: (card.querySelector('[data-react-curve]') || {}).value || 'bursty',
        likes: likes,
        dislikes: dislikes
      };
      btn.disabled = true;
      fetch(API + '/' + encodeURIComponent(id) + '/ramp', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(reactPayload)
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { show('Likes drip started', true); load(true); }
        else show((d && d.error) || 'Likes drip failed');
      }).catch(function () { btn.disabled = false; show('Likes drip failed'); });
      return;
    }

    if (act === 'cancel-vote-ramp' || act === 'cancel-react-ramp') {
      var group = act === 'cancel-vote-ramp' ? 'votes' : 'reactions';
      btn.disabled = true;
      fetch(API + '/' + encodeURIComponent(id) + '/ramp/cancel', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ group: group })
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) { show(group === 'votes' ? 'Vote drips cancelled' : 'Likes drips cancelled', true); load(true); }
        else show('Cancel failed');
      }).catch(function () { btn.disabled = false; show('Cancel failed'); });
    }
  });

  // Keep open cards stable while typing; only force-refresh after saves/actions.
  list.addEventListener('focusin', function () {
    if (refreshTimer) clearInterval(refreshTimer);
  });
  list.addEventListener('focusout', function () {
    setTimeout(function () {
      if (!isEditing()) scheduleRefresh(20000);
    }, 50);
  });

  load(true);
})();
</script>
