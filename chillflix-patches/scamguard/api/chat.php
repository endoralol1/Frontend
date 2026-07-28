<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/UserAuth.php';
require_once __DIR__ . '/../includes/SupportChat.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

UserAuth::start();
$db = Database::getConnection();
SupportChat::ensureSchema($db);

function chat_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_rate_limit(string $bucket, int $max, int $windowSec): bool
{
    UserAuth::start();
    $key = 'chat_rl_' . $bucket;
    $now = time();
    $hits = $_SESSION[$key] ?? [];
    if (!is_array($hits)) {
        $hits = [];
    }
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $windowSec));
    if (count($hits) >= $max) {
        $_SESSION[$key] = $hits;
        return false;
    }
    $hits[] = $now;
    $_SESSION[$key] = $hits;
    return true;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!SupportChat::enabled() && $action !== 'bootstrap') {
    chat_json(['ok' => false, 'error' => 'Support chat is currently disabled.'], 503);
}

$visitorToken = SupportChat::visitorToken();
$userId = UserAuth::id();
$userName = UserAuth::check() ? UserAuth::username() : null;

if ($action === 'bootstrap') {
    $open = SupportChat::findOpenByVisitor($db, $visitorToken);
    $payload = [
        'ok' => true,
        'enabled' => SupportChat::enabled(),
        'ai_enabled' => SupportChat::aiEnabled(),
        'csrf' => UserAuth::csrfToken(),
        'logged_in' => (bool) $userId,
        'username' => $userName,
        'quick_actions' => SupportChat::quickActions(),
        'conversation' => null,
    ];
    if ($open) {
        $msgs = SupportChat::messages($db, (int) $open['id']);
        $db->prepare('UPDATE support_conversations SET unread_visitor = 0 WHERE id = ?')->execute([(int) $open['id']]);
        $open['unread_visitor'] = 0;
        $payload['conversation'] = SupportChat::publicPayload($open, $msgs);
    }
    chat_json($payload);
}

if ($method !== 'POST') {
    chat_json(['ok' => false, 'error' => 'POST required.'], 405);
}

$csrf = $_POST['csrf'] ?? '';
if (!UserAuth::verifyCsrf(is_string($csrf) ? $csrf : null)) {
    chat_json(['ok' => false, 'error' => 'Session expired — refresh and try again.'], 403);
}

if ($action === 'start') {
    if (!chat_rate_limit('start', 8, 3600)) {
        chat_json(['ok' => false, 'error' => 'Too many chats started. Please wait a bit.'], 429);
    }
    $existing = SupportChat::findOpenByVisitor($db, $visitorToken);
    if ($existing) {
        $msgs = SupportChat::messages($db, (int) $existing['id']);
        chat_json(['ok' => true, 'conversation' => SupportChat::publicPayload($existing, $msgs)]);
    }
    $name = trim((string) ($_POST['name'] ?? ($userName ?? '')));
    $email = trim((string) ($_POST['email'] ?? ''));
    $pageUrl = trim((string) ($_POST['page_url'] ?? ''));
    if ($userId && $email === '') {
        $stmtEmail = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $stmtEmail->execute([$userId]);
        $email = trim((string) ($stmtEmail->fetchColumn() ?: ''));
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        chat_json(['ok' => false, 'error' => 'Please enter a valid email (or leave it blank).'], 422);
    }
    $conv = SupportChat::startConversation(
        $db,
        $visitorToken,
        $userId,
        $name !== '' ? $name : null,
        $email !== '' ? $email : null,
        $pageUrl !== '' ? $pageUrl : null
    );
    $msgs = SupportChat::messages($db, (int) $conv['id']);
    chat_json(['ok' => true, 'conversation' => SupportChat::publicPayload($conv, $msgs)]);
}

$token = trim((string) ($_POST['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    chat_json(['ok' => false, 'error' => 'Missing chat session.'], 400);
}
$conv = SupportChat::getByTokenForVisitor($db, $token, $visitorToken);
if (!$conv) {
    chat_json(['ok' => false, 'error' => 'Chat not found.'], 404);
}
$convId = (int) $conv['id'];

if ($action === 'poll') {
    $after = (int) ($_POST['after_id'] ?? 0);
    $msgs = SupportChat::messages($db, $convId, $after);
    $fresh = SupportChat::getById($db, $convId);
    if ($fresh) {
        $db->prepare('UPDATE support_conversations SET unread_visitor = 0 WHERE id = ?')->execute([$convId]);
        $fresh['unread_visitor'] = 0;
    }
    chat_json([
        'ok' => true,
        'status' => $fresh['status'] ?? $conv['status'],
        'assigned_admin_name' => $fresh['assigned_admin_name'] ?? null,
        'messages' => SupportChat::publicPayload($fresh ?? $conv, $msgs)['messages'],
    ]);
}

if ($action === 'send') {
    if (($conv['status'] ?? '') === 'closed') {
        chat_json(['ok' => false, 'error' => 'This chat is closed. Start a new one from the widget.'], 409);
    }
    if (!chat_rate_limit('send', 40, 600)) {
        chat_json(['ok' => false, 'error' => 'You are sending messages too quickly.'], 429);
    }
    $body = trim((string) ($_POST['message'] ?? ''));
    $quick = trim((string) ($_POST['quick'] ?? ''));
    if ($quick !== '') {
        $body = $quick;
    }
    if (mb_strlen($body) < 1) {
        chat_json(['ok' => false, 'error' => 'Type a message first.'], 422);
    }
    if (mb_strlen($body) > 2000) {
        chat_json(['ok' => false, 'error' => 'Message is too long (max 2000 characters).'], 422);
    }

    $visitorName = $conv['visitor_name'] ?: ($userName ?: 'Visitor');
    SupportChat::addMessage($db, $convId, 'visitor', $body, $visitorName, $userId);

    if (($conv['status'] ?? '') === 'bot') {
        $history = SupportChat::messages($db, $convId);
        $decision = SupportChat::botReply($body, $history);
        if (!empty($decision['escalate'])) {
            SupportChat::escalate($db, $conv);
        } elseif (!empty($decision['reply'])) {
            $botName = (($decision['source'] ?? '') === 'ai') ? 'ScamGuard AI' : 'ScamGuard Bot';
            SupportChat::addMessage($db, $convId, 'bot', (string) $decision['reply'], $botName);
        }
    }

    $fresh = SupportChat::getById($db, $convId);
    $after = (int) ($_POST['after_id'] ?? 0);
    $msgs = SupportChat::messages($db, $convId, $after);
    chat_json([
        'ok' => true,
        'conversation' => SupportChat::publicPayload($fresh ?? $conv, $msgs),
    ]);
}

if ($action === 'request_human') {
    if (($conv['status'] ?? '') === 'closed') {
        chat_json(['ok' => false, 'error' => 'This chat is closed.'], 409);
    }
    if (($conv['status'] ?? '') === 'bot') {
        SupportChat::escalate($db, $conv);
    } else {
        SupportChat::addMessage($db, $convId, 'system', 'Visitor pinged for admin attention.', 'System');
        $db->prepare(
            "UPDATE support_conversations
             SET status = IF(status = 'active', 'active', 'waiting'),
                 unread_admin = unread_admin + 1, updated_at = NOW()
             WHERE id = ?"
        )->execute([$convId]);
        SupportChat::addMessage(
            $db,
            $convId,
            'bot',
            'An admin has been notified again. Hang tight — replies appear in this chat.',
            'ScamGuard Bot'
        );
    }
    $fresh = SupportChat::getById($db, $convId);
    $msgs = SupportChat::messages($db, $convId);
    chat_json(['ok' => true, 'conversation' => SupportChat::publicPayload($fresh ?? $conv, $msgs)]);
}

if ($action === 'close') {
    $db->prepare(
        "UPDATE support_conversations
         SET status = 'closed', closed_at = NOW(), updated_at = NOW()
         WHERE id = ?"
    )->execute([$convId]);
    SupportChat::addMessage($db, $convId, 'system', 'Visitor ended the chat.', 'System');
    $fresh = SupportChat::getById($db, $convId);
    $msgs = SupportChat::messages($db, $convId);
    chat_json(['ok' => true, 'conversation' => SupportChat::publicPayload($fresh ?? $conv, $msgs)]);
}

if ($action === 'rate') {
    $rating = (int) ($_POST['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) {
        chat_json(['ok' => false, 'error' => 'Rating must be 1–5.'], 422);
    }
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if (mb_strlen($comment) > 500) {
        $comment = mb_substr($comment, 0, 500);
    }
    $db->prepare(
        'UPDATE support_conversations
         SET rating = ?, rating_comment = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([$rating, $comment !== '' ? $comment : null, $convId]);
    SupportChat::addMessage($db, $convId, 'system', 'Visitor rated this chat ' . $rating . '/5.', 'System');
    chat_json(['ok' => true]);
}

chat_json(['ok' => false, 'error' => 'Unknown action.'], 400);
