<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/UserAuth.php';
require_once __DIR__ . '/../includes/SupportChat.php';
Auth::requireLogin();

$pageTitle = 'Support Chat';
$db = Database::getConnection();
SupportChat::ensureSchema($db);
$flash = null;
$selectedId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    $action = $_POST['action'] ?? '';
    $selectedId = (int) ($_POST['conversation_id'] ?? $selectedId);
    $conv = $selectedId ? SupportChat::getById($db, $selectedId) : null;

    if ($action === 'toggle_enabled') {
        $enabled = isset($_POST['enabled']) ? '1' : '0';
        set_setting('support_chat_enabled', $enabled);
        $flash = $enabled === '1' ? 'Support chat enabled.' : 'Support chat disabled.';
    } elseif ($action === 'save_greeting') {
        set_setting('support_chat_greeting', trim((string) ($_POST['greeting'] ?? '')));
        set_setting('support_chat_offline', trim((string) ($_POST['offline'] ?? '')));
        $flash = 'Chat messages saved.';
    } elseif ($conv) {
        if ($action === 'reply') {
            $body = trim((string) ($_POST['message'] ?? ''));
            if ($body === '') {
                $flash = 'Reply cannot be empty.';
            } elseif (mb_strlen($body) > 4000) {
                $flash = 'Reply is too long.';
            } else {
                SupportChat::addMessage($db, $selectedId, 'admin', $body, Auth::username(), null);
                $db->prepare(
                    "UPDATE support_conversations
                     SET status = 'active',
                         assigned_admin_id = COALESCE(assigned_admin_id, ?),
                         assigned_admin_name = COALESCE(assigned_admin_name, ?),
                         unread_admin = 0,
                         updated_at = NOW()
                     WHERE id = ?"
                )->execute([Auth::id(), Auth::username(), $selectedId]);
                log_admin_activity(Auth::id(), 'support_reply', 'chat:' . $selectedId);
                $flash = 'Reply sent.';
            }
        } elseif ($action === 'assign') {
            $db->prepare(
                "UPDATE support_conversations
                 SET assigned_admin_id = ?, assigned_admin_name = ?,
                     status = IF(status = 'closed', 'closed', 'active'),
                     updated_at = NOW()
                 WHERE id = ?"
            )->execute([Auth::id(), Auth::username(), $selectedId]);
            SupportChat::addMessage($db, $selectedId, 'system', Auth::username() . ' joined the chat.', 'System');
            $flash = 'Assigned to you.';
        } elseif ($action === 'waiting') {
            $db->prepare("UPDATE support_conversations SET status = 'waiting', updated_at = NOW() WHERE id = ? AND status <> 'closed'")
               ->execute([$selectedId]);
            $flash = 'Marked waiting.';
        } elseif ($action === 'close') {
            $db->prepare(
                "UPDATE support_conversations
                 SET status = 'closed', closed_at = NOW(), unread_admin = 0, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$selectedId]);
            SupportChat::addMessage($db, $selectedId, 'system', 'Admin closed this chat.', 'System');
            log_admin_activity(Auth::id(), 'support_close', 'chat:' . $selectedId);
            $flash = 'Chat closed.';
        } elseif ($action === 'reopen') {
            $db->prepare(
                "UPDATE support_conversations
                 SET status = 'waiting', closed_at = NULL, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$selectedId]);
            SupportChat::addMessage($db, $selectedId, 'system', 'Admin reopened this chat.', 'System');
            $flash = 'Chat reopened.';
        } elseif ($action === 'mark_read') {
            $db->prepare('UPDATE support_conversations SET unread_admin = 0 WHERE id = ?')->execute([$selectedId]);
            $flash = 'Marked read.';
        }
    }
    redirect('/admin/chat.php' . ($selectedId ? ('?id=' . $selectedId) : '') . ($flash ? '&flash=' . rawurlencode($flash) : ''));
}

if (isset($_GET['flash'])) {
    $flash = (string) $_GET['flash'];
}

$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open', 'waiting', 'active', 'bot', 'closed', 'all'], true)) {
    $filter = 'open';
}

$counts = ['all' => 0, 'bot' => 0, 'waiting' => 0, 'active' => 0, 'closed' => 0, 'open' => 0];
foreach ($db->query('SELECT status, COUNT(*) c, SUM(unread_admin) u FROM support_conversations GROUP BY status')->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['c'];
    $counts['all'] += (int) $row['c'];
}
$counts['open'] = $counts['bot'] + $counts['waiting'] + $counts['active'];
$unreadTotal = (int) $db->query("SELECT COALESCE(SUM(unread_admin),0) FROM support_conversations WHERE status <> 'closed'")->fetchColumn();

$where = match ($filter) {
    'waiting' => "status = 'waiting'",
    'active' => "status = 'active'",
    'bot' => "status = 'bot'",
    'closed' => "status = 'closed'",
    'all' => '1=1',
    default => "status IN ('bot','waiting','active')",
};

$list = $db->query(
    "SELECT c.*,
            (SELECT body FROM support_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body
     FROM support_conversations c
     WHERE $where
     ORDER BY (c.unread_admin > 0) DESC, c.last_message_at DESC, c.id DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$selectedId && $list) {
    $selectedId = (int) $list[0]['id'];
}
$selected = $selectedId ? SupportChat::getById($db, $selectedId) : null;
$messages = $selected ? SupportChat::messages($db, $selectedId) : [];
if ($selected && (int) $selected['unread_admin'] > 0) {
    $db->prepare('UPDATE support_conversations SET unread_admin = 0 WHERE id = ?')->execute([$selectedId]);
    $selected['unread_admin'] = 0;
}

$enabled = get_setting('support_chat_enabled', '1') === '1';
$greeting = get_setting('support_chat_greeting', '');
$offline = get_setting('support_chat_offline', '');
$canned = SupportChat::cannedReplies();

require __DIR__ . '/includes/layout_top.php';
?>
<?php if ($flash): ?>
    <div class="alert alert-success" style="margin-bottom:14px;"><?= h($flash) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px;">
    <form method="post" style="display:flex; gap:16px; flex-wrap:wrap; align-items:center;">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="toggle_enabled">
        <label style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> onchange="this.form.submit()">
            <strong>Chat widget enabled</strong>
        </label>
        <span style="color:var(--faint); font-size:13px;">
            <?= (int) $unreadTotal ?> unread · <?= (int) $counts['waiting'] ?> waiting for admin
        </span>
    </form>
</div>

<div class="chat-admin">
    <aside class="chat-admin-list card">
        <div class="chat-admin-filters">
            <?php
            $tabs = [
                'open' => 'Open (' . $counts['open'] . ')',
                'waiting' => 'Waiting (' . $counts['waiting'] . ')',
                'active' => 'Active (' . $counts['active'] . ')',
                'bot' => 'Bot (' . $counts['bot'] . ')',
                'closed' => 'Closed',
                'all' => 'All',
            ];
            foreach ($tabs as $key => $label): ?>
                <a class="<?= $filter === $key ? 'is-active' : '' ?>" href="?filter=<?= h($key) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <ul class="chat-admin-threads">
            <?php if (!$list): ?>
                <li class="chat-admin-empty">No chats in this filter.</li>
            <?php endif; ?>
            <?php foreach ($list as $row): ?>
                <li>
                    <a class="chat-admin-thread <?= (int) $row['id'] === $selectedId ? 'is-active' : '' ?> <?= (int) $row['unread_admin'] > 0 ? 'has-unread' : '' ?>"
                       href="?filter=<?= h($filter) ?>&id=<?= (int) $row['id'] ?>">
                        <div class="chat-admin-thread-top">
                            <strong><?= h($row['visitor_name'] ?: ('Visitor #' . $row['id'])) ?></strong>
                            <span class="chat-admin-pill status-<?= h($row['status']) ?>"><?= h($row['status']) ?></span>
                        </div>
                        <div class="chat-admin-preview"><?= h(mb_strimwidth((string) ($row['last_body'] ?? ''), 0, 90, '…')) ?></div>
                        <div class="chat-admin-thread-meta">
                            <?= h(time_ago($row['last_message_at'] ?? $row['created_at'])) ?>
                            <?php if ((int) $row['unread_admin'] > 0): ?>
                                · <span class="chat-unread"><?= (int) $row['unread_admin'] ?> new</span>
                            <?php endif; ?>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <section class="chat-admin-main card">
        <?php if (!$selected): ?>
            <p class="check-empty" style="padding:24px;">Select a conversation to reply.</p>
        <?php else: ?>
            <div class="chat-admin-main-head">
                <div>
                    <h2 style="margin:0; font-size:1.15rem;"><?= h($selected['visitor_name'] ?: ('Visitor #' . $selected['id'])) ?></h2>
                    <div class="chat-admin-meta-line">
                        <span class="chat-admin-pill status-<?= h($selected['status']) ?>"><?= h($selected['status']) ?></span>
                        <?php if ($selected['assigned_admin_name']): ?>
                            · Assigned: <strong><?= h($selected['assigned_admin_name']) ?></strong>
                        <?php endif; ?>
                        <?php if ($selected['visitor_email']): ?>
                            · <?= h($selected['visitor_email']) ?>
                        <?php endif; ?>
                        <?php if ($selected['user_id']): ?>
                            · User #<?= (int) $selected['user_id'] ?>
                        <?php endif; ?>
                        <?php if ($selected['rating']): ?>
                            · Rated <?= (int) $selected['rating'] ?>/5
                        <?php endif; ?>
                    </div>
                    <?php if ($selected['page_url']): ?>
                        <div class="chat-admin-meta-line">Page: <a href="<?= h($selected['page_url']) ?>" target="_blank" rel="noopener"><?= h(mb_strimwidth($selected['page_url'], 0, 80, '…')) ?></a></div>
                    <?php endif; ?>
                </div>
                <div class="chat-admin-actions">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
                        <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                        <?php if ($selected['status'] !== 'closed'): ?>
                            <button name="action" value="assign" class="btn btn-secondary btn-sm">Assign me</button>
                            <button name="action" value="close" class="btn btn-secondary btn-sm">Close</button>
                        <?php else: ?>
                            <button name="action" value="reopen" class="btn btn-secondary btn-sm">Reopen</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="chat-admin-messages" id="chat-admin-messages">
                <?php foreach ($messages as $m): ?>
                    <div class="sgchat-msg sgchat-msg-<?= h($m['sender_type']) ?>">
                        <div class="sgchat-msg-meta"><?= h($m['sender_name'] ?: $m['sender_type']) ?> · <?= h($m['created_at']) ?></div>
                        <div class="sgchat-msg-body"><?= nl2br(h($m['body'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($selected['status'] !== 'closed'): ?>
                <div class="chat-admin-canned">
                    <?php foreach ($canned as $c): ?>
                        <button type="button" class="sgchat-chip" data-canned="<?= h($c) ?>"><?= h(mb_strimwidth($c, 0, 42, '…')) ?></button>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="chat-admin-reply">
                    <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
                    <input type="hidden" name="conversation_id" value="<?= (int) $selected['id'] ?>">
                    <input type="hidden" name="action" value="reply">
                    <textarea name="message" id="chat-reply-box" rows="3" required maxlength="4000" placeholder="Reply as admin…"></textarea>
                    <button type="submit" class="btn btn-primary">Send reply</button>
                </form>
            <?php else: ?>
                <p style="color:var(--faint); padding:12px 0 0;">This chat is closed. Reopen to reply.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<details class="card" style="margin-top:14px; padding:14px 16px;">
    <summary style="cursor:pointer; font-weight:700;">Bot greeting & offline message</summary>
    <form method="post" style="margin-top:12px; display:grid; gap:10px;">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="save_greeting">
        <label>Greeting
            <textarea name="greeting" rows="3"><?= h($greeting) ?></textarea>
        </label>
        <label>When visitor requests admin
            <textarea name="offline" rows="2"><?= h($offline) ?></textarea>
        </label>
        <button type="submit" class="btn btn-secondary" style="justify-self:start;">Save</button>
    </form>
</details>

<script>
(function () {
  var box = document.getElementById('chat-reply-box');
  document.querySelectorAll('[data-canned]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!box) return;
      box.value = btn.getAttribute('data-canned') || '';
      box.focus();
    });
  });
  var scroller = document.getElementById('chat-admin-messages');
  if (scroller) scroller.scrollTop = scroller.scrollHeight;
  // Light auto-refresh while inbox is open
  setTimeout(function () {
    if (document.hidden) return;
    var url = new URL(location.href);
    if (!url.searchParams.get('id')) return;
    location.reload();
  }, 20000);
})();
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
