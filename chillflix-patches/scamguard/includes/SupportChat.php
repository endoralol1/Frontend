<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/UserAuth.php';

/**
 * Support chat: FAQ/bot first-line help, then optional admin handoff.
 */
class SupportChat
{
    public const COOKIE = 'sg_support_vt';
    public const COOKIE_DAYS = 30;

    public static function enabled(): bool
    {
        return get_setting('support_chat_enabled', '1') === '1';
    }

    public static function visitorToken(): string
    {
        UserAuth::start();
        $token = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $secure = defined('SITE_ENV') && SITE_ENV === 'production';
            setcookie(self::COOKIE, $token, [
                'expires' => time() + self::COOKIE_DAYS * 86400,
                'path' => defined('BASE_PATH') ? BASE_PATH : '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE[self::COOKIE] = $token;
        }
        return $token;
    }

    public static function ensureSchema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $exists = $db->query("SHOW TABLES LIKE 'support_conversations'")->fetchColumn();
        if (!$exists) {
            // Prefer the migration file via deploy; this is a safe runtime fallback.
            $db->exec(
                "CREATE TABLE IF NOT EXISTS support_conversations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    public_token CHAR(64) NOT NULL,
                    visitor_token CHAR(64) NOT NULL,
                    user_id INT UNSIGNED NULL,
                    visitor_name VARCHAR(80) NULL,
                    visitor_email VARCHAR(190) NULL,
                    status ENUM('bot','waiting','active','closed') NOT NULL DEFAULT 'bot',
                    assigned_admin_id INT UNSIGNED NULL,
                    assigned_admin_name VARCHAR(80) NULL,
                    page_url VARCHAR(500) NULL,
                    user_agent VARCHAR(500) NULL,
                    ip_hash CHAR(64) NULL,
                    subject VARCHAR(160) NULL,
                    rating TINYINT UNSIGNED NULL,
                    rating_comment VARCHAR(500) NULL,
                    unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
                    unread_visitor INT UNSIGNED NOT NULL DEFAULT 0,
                    last_message_at DATETIME NULL,
                    closed_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_support_public_token (public_token),
                    KEY idx_support_visitor_token (visitor_token),
                    KEY idx_support_status_last (status, last_message_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS support_messages (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    conversation_id INT UNSIGNED NOT NULL,
                    sender_type ENUM('visitor','bot','admin','system') NOT NULL,
                    sender_name VARCHAR(80) NULL,
                    sender_user_id INT UNSIGNED NULL,
                    body TEXT NOT NULL,
                    is_quick_action TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_support_msg_conv (conversation_id, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $done = true;
    }

    /** @return list<array{q:string,a:string,keys:list<string>}> */
    public static function knowledge(): array
    {
        $site = get_setting('site_name', 'ScamGuard');
        return [
            [
                'q' => 'How do I check a website?',
                'a' => "Paste a domain on the home page Quick check (e.g. example.com). {$site} scores registration age, TLS, DNS, threat feeds, and community reports.",
                'keys' => ['check', 'website', 'domain', 'url', 'scan', 'lookup', 'how to use', 'start'],
            ],
            [
                'q' => 'What does the trust score mean?',
                'a' => 'The score is a risk signal, not a legal verdict. Higher is safer. Low scores mean warning signals (new domain, threat lists, weak reviews, etc.). Always use judgment.',
                'keys' => ['score', 'trust', 'rating', 'safe', 'risky', 'scam', 'meaning', 'verdict'],
            ],
            [
                'q' => 'How do I report a scam?',
                'a' => 'Open Community → + New post (or Report Now). Sign in, describe what happened, and submit. Admins review reports; verified ones can affect site scores.',
                'keys' => ['report', 'scam', 'phishing', 'fake', 'fraud', 'complaint'],
            ],
            [
                'q' => 'Can I check phones, crypto, IBAN, or cards?',
                'a' => 'Yes. Use Quick check type tabs: Phone, Crypto, IBAN, or Card. Results are stored as entity checks, separate from website domains.',
                'keys' => ['phone', 'crypto', 'iban', 'card', 'wallet', 'bitcoin', 'address'],
            ],
            [
                'q' => 'How do accounts and points work?',
                'a' => 'Create a free account to report and comment. Reputation points come from verified reports and helpful community feedback. Announcements do not count as reports.',
                'keys' => ['account', 'login', 'register', 'points', 'reputation', 'profile', 'sign in'],
            ],
            [
                'q' => 'How often are domains re-checked?',
                'a' => 'Cached results refresh on the admin schedule (often every few days), and sooner when overrides or new reports land.',
                'keys' => ['refresh', 'cache', 'recheck', 'update', 'how often'],
            ],
            [
                'q' => 'Is a low score proof of a scam?',
                'a' => 'No. It means there are enough warning signals to be careful. New legitimate sites can score lower simply from lack of history.',
                'keys' => ['proof', 'guaranteed', 'false positive', 'legitimate', 'new business'],
            ],
            [
                'q' => 'Where is the community forum?',
                'a' => 'Use the Community link in the nav. You can browse reports, announcements, and discussions. Reporting requires signing in.',
                'keys' => ['community', 'forum', 'discussion', 'thread', 'announcement'],
            ],
        ];
    }

    /** @return list<array{id:string,label:string}> */
    public static function quickActions(): array
    {
        return [
            ['id' => 'check', 'label' => 'Check a website'],
            ['id' => 'report', 'label' => 'Report a scam'],
            ['id' => 'score', 'label' => 'How scores work'],
            ['id' => 'human', 'label' => 'Talk to an admin'],
        ];
    }

    /** @return list<string> */
    public static function cannedReplies(): array
    {
        return [
            'Thanks for reaching out — I\'m looking into this now.',
            'Please share the exact domain or entity and what happened.',
            'You can report it in Community → + New post so it is tracked publicly.',
            'A low score is a caution signal, not legal proof. Avoid sharing passwords or funds until you verify.',
            'We\'ve noted this. An admin will follow up here shortly.',
            'This chat is now resolved. Reply anytime if you need more help.',
        ];
    }

    public static function findOpenByVisitor(PDO $db, string $visitorToken): ?array
    {
        $stmt = $db->prepare(
            "SELECT * FROM support_conversations
             WHERE visitor_token = ? AND status IN ('bot','waiting','active')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$visitorToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getByTokenForVisitor(PDO $db, string $publicToken, string $visitorToken): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM support_conversations WHERE public_token = ? AND visitor_token = ? LIMIT 1'
        );
        $stmt->execute([$publicToken, $visitorToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getById(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM support_conversations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function messages(PDO $db, int $conversationId, int $afterId = 0): array
    {
        $stmt = $db->prepare(
            'SELECT id, sender_type, sender_name, body, is_quick_action, created_at
             FROM support_messages
             WHERE conversation_id = ? AND id > ?
             ORDER BY id ASC LIMIT 200'
        );
        $stmt->execute([$conversationId, $afterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function addMessage(
        PDO $db,
        int $conversationId,
        string $senderType,
        string $body,
        ?string $senderName = null,
        ?int $senderUserId = null,
        bool $quick = false
    ): int {
        $stmt = $db->prepare(
            'INSERT INTO support_messages
                (conversation_id, sender_type, sender_name, sender_user_id, body, is_quick_action)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $conversationId,
            $senderType,
            $senderName,
            $senderUserId,
            $body,
            $quick ? 1 : 0,
        ]);
        $id = (int) $db->lastInsertId();

        $status = (string) $db->query(
            'SELECT status FROM support_conversations WHERE id = ' . (int) $conversationId . ' LIMIT 1'
        )->fetchColumn();

        // Only ping admin inbox when a human is expected (waiting/active), not during bot FAQ mode.
        $unreadAdmin = ($senderType === 'visitor' && in_array($status, ['waiting', 'active'], true))
            ? 'unread_admin = unread_admin + 1,'
            : '';
        $unreadVisitor = in_array($senderType, ['bot', 'admin', 'system'], true)
            ? 'unread_visitor = unread_visitor + 1,'
            : '';
        $db->prepare(
            "UPDATE support_conversations
             SET {$unreadAdmin} {$unreadVisitor} last_message_at = NOW(), updated_at = NOW()
             WHERE id = ?"
        )->execute([$conversationId]);

        return $id;
    }

    public static function startConversation(
        PDO $db,
        string $visitorToken,
        ?int $userId,
        ?string $name,
        ?string $email,
        ?string $pageUrl
    ): array {
        $public = bin2hex(random_bytes(32));
        $stmt = $db->prepare(
            'INSERT INTO support_conversations
                (public_token, visitor_token, user_id, visitor_name, visitor_email, status,
                 page_url, user_agent, ip_hash, last_message_at)
             VALUES (?, ?, ?, ?, ?, \'bot\', ?, ?, ?, NOW())'
        );
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $stmt->execute([
            $public,
            $visitorToken,
            $userId,
            $name !== null && $name !== '' ? mb_substr($name, 0, 80) : null,
            $email !== null && $email !== '' ? mb_substr($email, 0, 190) : null,
            $pageUrl !== null ? mb_substr($pageUrl, 0, 500) : null,
            $ua !== '' ? $ua : null,
            UserAuth::ipHash(),
        ]);
        $id = (int) $db->lastInsertId();

        $greeting = get_setting(
            'support_chat_greeting',
            'Hi — I\'m the ScamGuard helper. Ask about checks, scores, reports, or accounts. If I can\'t help, you can talk to an admin.'
        );
        self::addMessage($db, $id, 'bot', $greeting, 'ScamGuard Bot');
        self::addMessage(
            $db,
            $id,
            'bot',
            "Quick help:\n• Check a website — paste any domain on the home page\n• Report a scam — Community → + New post\n• Scores — risk signals, not legal proof\n\nOr tap a suggestion below.",
            'ScamGuard Bot',
            null,
            true
        );

        return self::getById($db, $id) ?? ['id' => $id, 'public_token' => $public, 'status' => 'bot'];
    }

    /**
     * @return array{reply:?string,escalate:bool,matched:?string}
     */
    public static function botReply(string $message): array
    {
        $text = mb_strtolower(trim($message));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if ($text === '' || mb_strlen($text) < 2) {
            return [
                'reply' => 'Tell me a bit more — for example “how do I check a site?” or “I want to report a scam”.',
                'escalate' => false,
                'matched' => null,
            ];
        }

        // Explicit human request
        if (preg_match('/\b(human|admin|agent|staff|person|operator|real person|talk to (someone|you|admin|support)|live support|customer support)\b/i', $text)) {
            return [
                'reply' => null,
                'escalate' => true,
                'matched' => 'human',
            ];
        }

        // Quick-action ids from widget buttons
        $quickMap = [
            'check' => 'How do I check a website?',
            'report' => 'How do I report a scam?',
            'score' => 'What does the trust score mean?',
            'human' => 'talk to an admin',
        ];
        if (isset($quickMap[$text])) {
            return self::botReply($quickMap[$text]);
        }

        if (preg_match('/\b(hi|hello|hey|good (morning|afternoon|evening)|hola)\b/i', $text) && mb_strlen($text) < 40) {
            return [
                'reply' => 'Hello! I can help with website checks, trust scores, reporting scams, or your account. What do you need?',
                'escalate' => false,
                'matched' => 'greeting',
            ];
        }

        if (preg_match('/\b(thank|thanks|thx|appreciate)\b/i', $text) && mb_strlen($text) < 60) {
            return [
                'reply' => 'Glad to help. If anything else comes up — checks, reports, or talking to an admin — just ask.',
                'escalate' => false,
                'matched' => 'thanks',
            ];
        }

        $best = null;
        $bestScore = 0;
        foreach (self::knowledge() as $item) {
            $score = 0;
            foreach ($item['keys'] as $key) {
                if ($key !== '' && str_contains($text, $key)) {
                    $score += mb_strlen($key) >= 5 ? 3 : 2;
                }
            }
            // Soft match on question words
            $qWords = preg_split('/\W+/', mb_strtolower($item['q'])) ?: [];
            foreach ($qWords as $w) {
                if (mb_strlen($w) >= 4 && str_contains($text, $w)) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        if ($best && $bestScore >= 3) {
            return [
                'reply' => $best['a'] . "\n\nWas that helpful? If not, tap “Talk to an admin”.",
                'escalate' => false,
                'matched' => $best['q'],
            ];
        }

        // Domain-looking input → guide to checker
        if (preg_match('/\b([a-z0-9-]+\.)+[a-z]{2,}\b/i', $text)) {
            return [
                'reply' => 'To check that domain, paste it on the home page Quick check. I can also explain scores or help you report it — or connect you with an admin.',
                'escalate' => false,
                'matched' => 'domain-hint',
            ];
        }

        return [
            'reply' => "I'm not sure I can fully answer that. Try asking about checks, scores, reports, or accounts — or request an admin and a person will reply here.",
            'escalate' => false,
            'matched' => null,
        ];
    }

    public static function escalate(PDO $db, array $conversation): void
    {
        if (in_array($conversation['status'], ['waiting', 'active'], true)) {
            return;
        }
        $offline = get_setting(
            'support_chat_offline',
            'Admins may be offline right now. Leave a message and we\'ll reply here as soon as someone is available.'
        );
        $db->prepare(
            "UPDATE support_conversations
             SET status = 'waiting', unread_admin = unread_admin + 1, updated_at = NOW()
             WHERE id = ?"
        )->execute([(int) $conversation['id']]);
        self::addMessage(
            $db,
            (int) $conversation['id'],
            'system',
            'Visitor requested a human admin.',
            'System'
        );
        self::addMessage(
            $db,
            (int) $conversation['id'],
            'bot',
            "I've notified the admin team. " . $offline,
            'ScamGuard Bot'
        );
    }

    public static function publicPayload(array $conversation, array $messages = []): array
    {
        return [
            'id' => (int) $conversation['id'],
            'token' => $conversation['public_token'],
            'status' => $conversation['status'],
            'visitor_name' => $conversation['visitor_name'],
            'assigned_admin_name' => $conversation['assigned_admin_name'],
            'unread_visitor' => (int) $conversation['unread_visitor'],
            'rating' => $conversation['rating'] !== null ? (int) $conversation['rating'] : null,
            'quick_actions' => self::quickActions(),
            'messages' => array_map(static function (array $m): array {
                return [
                    'id' => (int) $m['id'],
                    'sender_type' => $m['sender_type'],
                    'sender_name' => $m['sender_name'],
                    'body' => $m['body'],
                    'created_at' => $m['created_at'],
                ];
            }, $messages),
        ];
    }
}
