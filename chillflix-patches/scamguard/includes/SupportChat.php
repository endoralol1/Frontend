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
        $home = absolute_url('/');
        $community = absolute_url('/community.php');
        $report = absolute_url('/community.php?compose=1');
        $browse = absolute_url('/browse.php');
        $login = absolute_url('/login.php');
        $register = absolute_url('/register.php');
        $faq = absolute_url('/faq.php');
        return [
            [
                'q' => 'How do I check a website?',
                'a' => "Open {$home} and paste a domain into Quick check (e.g. example.com). {$site} scores registration age, TLS, DNS, threat feeds, and community reports.",
                'keys' => ['check', 'website', 'domain', 'url', 'scan', 'lookup', 'how to use', 'start'],
            ],
            [
                'q' => 'What does the trust score mean?',
                'a' => 'The score is a risk signal, not a legal verdict. Higher is safer. Low scores mean warning signals (new domain, threat lists, weak reviews, etc.). Always use judgment.',
                'keys' => ['score', 'trust', 'rating', 'safe', 'risky', 'scam', 'meaning', 'verdict'],
            ],
            [
                'q' => 'How do I report a scam?',
                'a' => "Report here: {$report} (sign in required). Describe what happened and submit. Admins review reports; verified ones can affect site scores.",
                'keys' => ['report', 'scam', 'phishing', 'fake', 'fraud', 'complaint'],
            ],
            [
                'q' => 'Where is the community forum?',
                'a' => "Community forum: {$community}. Browse reports, announcements, and discussions. Reporting requires signing in.",
                'keys' => ['community', 'forum', 'discussion', 'thread', 'announcement', 'link'],
            ],
            [
                'q' => 'Can I check phones, crypto, IBAN, or cards?',
                'a' => "Yes — use the type tabs on {$home} (Phone, Crypto, IBAN, Card). Results are entity checks, separate from website domains.",
                'keys' => ['phone', 'crypto', 'iban', 'card', 'wallet', 'bitcoin', 'address'],
            ],
            [
                'q' => 'How do accounts and points work?',
                'a' => "Register at {$register} or sign in at {$login}. Reputation points come from verified reports and helpful community feedback. Announcements do not count as reports.",
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
                'q' => 'Where can I browse checked sites?',
                'a' => "Browse checked domains here: {$browse}. FAQ: {$faq}.",
                'keys' => ['browse', 'list', 'directory', 'faq'],
            ],
            [
                'q' => 'How many sites have been scanned?',
                'a' => "The homepage counter shows live totals (domains scanned, likely safe, flagged scams, checked today). Ask me “how many sites?” and I’ll quote the current numbers from the database.",
                'keys' => ['how many', 'counter', 'total', 'scanned', 'statistics', 'stats', 'homepage'],
            ],
        ];
    }

    /** Important absolute links for the AI helper. */
    public static function siteLinks(): array
    {
        return [
            'home' => absolute_url('/'),
            'community' => absolute_url('/community.php'),
            'report' => absolute_url('/community.php?compose=1'),
            'browse' => absolute_url('/browse.php'),
            'login' => absolute_url('/login.php'),
            'register' => absolute_url('/register.php'),
            'faq' => absolute_url('/faq.php'),
            'phone_check' => absolute_url('/?type=phone'),
            'crypto_check' => absolute_url('/?type=crypto'),
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
            'Hi — I\'m the ScamGuard AI helper. Ask me anything about this site: checks, scores, reports, accounts, community. If I can\'t help, you can talk to an admin.'
        );
        self::addMessage($db, $id, 'bot', $greeting, 'ScamGuard AI');
        self::addMessage(
            $db,
            $id,
            'bot',
            "Try asking in your own words, or tap a suggestion:\n• How do website checks work?\n• What does a low score mean?\n• How do I report a scam?",
            'ScamGuard AI',
            null,
            true
        );

        return self::getById($db, $id) ?? ['id' => $id, 'public_token' => $public, 'status' => 'bot'];
    }

    public static function aiEnabled(): bool
    {
        $key = trim(get_setting('ai_api_key', ''));
        if ($key === '' && defined('AI_API_KEY')) {
            $key = trim((string) AI_API_KEY);
        }
        return $key !== '';
    }

    /**
     * @param list<array<string,mixed>> $history
     * @return array{reply:?string,escalate:bool,matched:?string,source:string}
     */
    public static function botReply(string $message, array $history = []): array
    {
        $text = mb_strtolower(trim($message));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if ($text === '' || mb_strlen($text) < 2) {
            return [
                'reply' => 'Tell me a bit more — for example how checks work, what a score means, or how to report a scam.',
                'escalate' => false,
                'matched' => null,
                'source' => 'rule',
            ];
        }

        // Explicit human request
        if (preg_match('/\b(human|admin|agent|staff|person|operator|real person|talk to (someone|you|admin|support)|live support|customer support)\b/i', $text)) {
            return [
                'reply' => null,
                'escalate' => true,
                'matched' => 'human',
                'source' => 'rule',
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
            return self::botReply($quickMap[$text], $history);
        }

        if (preg_match('/\b(hi|hello|hey|good (morning|afternoon|evening)|hola)\b/i', $text) && mb_strlen($text) < 40) {
            return [
                'reply' => 'Hello! Ask me anything about ScamGuard — website/phone/crypto checks, trust scores, reporting, community, or your account. Or tap “Talk to an admin” for a person.',
                'escalate' => false,
                'matched' => 'greeting',
                'source' => 'rule',
            ];
        }

        if (preg_match('/\b(thank|thanks|thx|appreciate)\b/i', $text) && mb_strlen($text) < 60) {
            return [
                'reply' => 'Glad to help. Ask another question anytime, or request an admin if you need a human.',
                'escalate' => false,
                'matched' => 'thanks',
                'source' => 'rule',
            ];
        }

        // Homepage / database counters — answer from live stats, never guess.
        if (self::wantsSiteStats($message)) {
            $statsReply = self::formatSiteStats(self::liveSiteStats());
            if ($statsReply !== '') {
                return [
                    'reply' => $statsReply,
                    'escalate' => false,
                    'matched' => 'site-stats',
                    'source' => 'stats',
                ];
            }
            return [
                'reply' => 'I normally read the live homepage counters from the database, but that lookup failed just now. Please refresh https://www.chillflix.lol/scamguard/ — the numbers are at the top of the page.',
                'escalate' => false,
                'matched' => 'site-stats-error',
                'source' => 'stats',
            ];
        }

        $lookups = self::lookupDomainsFromText($message);

        // Score / domain status questions: always return clean facts + recheck link.
        if ($lookups && preg_match('/\b(score|rated|rating|safe|risky|scam|status|trust|how (bad|good)|what about|check|lookup|look up)\b/i', $message)) {
            $direct = self::formatDomainLookups($lookups);
            if ($direct !== '') {
                return [
                    'reply' => $direct,
                    'escalate' => false,
                    'matched' => 'domain-lookup',
                    'source' => 'lookup',
                ];
            }
        }

        // Prefer AI for free-form questions when configured.
        if (self::aiEnabled()) {
            $ai = self::aiSupportReply($message, $history, $lookups, self::liveSiteStats());
            if ($ai !== null) {
                if (!empty($ai['escalate'])) {
                    return [
                        'reply' => null,
                        'escalate' => true,
                        'matched' => 'ai-escalate',
                        'source' => 'ai',
                    ];
                }
                if (!empty($ai['reply'])) {
                    $reply = self::sanitizeReply((string) $ai['reply']);
                    if (
                        $lookups
                        && preg_match('/\b(cannot|can\'t|unable to)\b.{0,40}\b(check|look\s*up|tell|provide)\b/i', $reply)
                    ) {
                        $direct = self::formatDomainLookups($lookups);
                        if ($direct !== '') {
                            $reply = $direct;
                        }
                    }
                    return [
                        'reply' => $reply,
                        'escalate' => false,
                        'matched' => 'ai',
                        'source' => 'ai',
                    ];
                }
            }
        }

        // Direct score answer if AI unavailable but we found domains in DB.
        if ($lookups) {
            $direct = self::formatDomainLookups($lookups);
            if ($direct !== '') {
                return [
                    'reply' => $direct,
                    'escalate' => false,
                    'matched' => 'domain-lookup',
                    'source' => 'lookup',
                ];
            }
        }

        // FAQ fallback if AI unavailable / failed
        $best = null;
        $bestScore = 0;
        foreach (self::knowledge() as $item) {
            $score = 0;
            foreach ($item['keys'] as $key) {
                if ($key !== '' && str_contains($text, $key)) {
                    $score += mb_strlen($key) >= 5 ? 3 : 2;
                }
            }
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
                'source' => 'faq',
            ];
        }

        return [
            'reply' => "I couldn't answer that confidently. Try rephrasing, or tap “Talk to an admin” and a person will reply here.",
            'escalate' => false,
            'matched' => null,
            'source' => 'rule',
        ];
    }

    /**
     * Pull domains mentioned in a message and resolve live ScamGuard records.
     *
     * @return list<array{domain:string,found:bool,score:?int,status:?string,label:?string,page:?string,last_checked:?string,notes:string}>
     */
    public static function lookupDomainsFromText(string $message): array
    {
        if (!preg_match_all('/\b(?:https?:\/\/)?(?:www\.)?([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)+)\b/i', $message, $m)) {
            return [];
        }

        require_once __DIR__ . '/DomainRepository.php';
        $repo = new DomainRepository();
        $out = [];
        $seen = [];
        foreach ($m[1] as $raw) {
            $domain = normalize_domain($raw);
            if (!$domain || isset($seen[$domain])) {
                continue;
            }
            // Skip generic non-site words that look like domains rarely; keep normal TLDs.
            $seen[$domain] = true;
            $row = $repo->find($domain);
            if (!$row && str_starts_with($domain, 'www.')) {
                $domain = substr($domain, 4);
                if (isset($seen[$domain])) {
                    continue;
                }
                $seen[$domain] = true;
                $row = $repo->find($domain);
            }

            if ($row) {
                $badge = status_badge((string) $row['status']);
                $page = domain_page_url($domain);
                $out[] = [
                    'domain' => $domain,
                    'found' => true,
                    'score' => (int) $row['trust_score'],
                    'status' => (string) $row['status'],
                    'label' => (string) ($badge['label'] ?? $row['status']),
                    'page' => $page,
                    'recheck' => $page . (str_contains($page, '?') ? '&' : '?') . 'refresh=1',
                    'last_checked' => $row['last_checked'] ?? null,
                    'notes' => !empty($row['manual_override']) ? 'manual override set' : '',
                ];
            } else {
                $check = absolute_url('/check-entity.php?type=website&q=' . rawurlencode($domain));
                $out[] = [
                    'domain' => $domain,
                    'found' => false,
                    'score' => null,
                    'status' => null,
                    'label' => null,
                    'page' => $check,
                    'recheck' => $check . '&refresh=1',
                    'last_checked' => null,
                    'notes' => 'not in database yet',
                ];
            }
            if (count($out) >= 3) {
                break;
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $lookups */
    public static function formatDomainLookups(array $lookups): string
    {
        if (!$lookups) {
            return '';
        }
        $parts = [];
        foreach ($lookups as $item) {
            $domain = (string) $item['domain'];
            $page = (string) ($item['page'] ?? '');
            $recheck = (string) ($item['recheck'] ?? $page);
            if (!empty($item['found'])) {
                $line = "{$domain} currently scores " . (int) $item['score'] . '/100 (' . ($item['label'] ?? $item['status']) . ') in ScamGuard.';
                if (!empty($item['last_checked'])) {
                    $line .= ' Last checked: ' . $item['last_checked'] . '.';
                }
                if ($page !== '') {
                    $line .= "\nDetails: {$page}";
                }
                if ($recheck !== '') {
                    $line .= "\nRecheck now: {$recheck}";
                }
                $parts[] = $line;
            } else {
                $parts[] = "{$domain} is not in our checked database yet.\nCheck now: {$page}\nRecheck/force scan: {$recheck}";
            }
        }
        return implode("\n\n", $parts);
    }

    public static function sanitizeReply(string $reply): string
    {
        // Fix models that break the scheme: "ht tps://"
        $reply = preg_replace('/\bhttps?\s*:\s*\/\s*\//i', 'https://', $reply) ?? $reply;
        // Collapse spaces only inside a URL (between URL-safe characters), not between words.
        for ($i = 0; $i < 8; $i++) {
            $next = preg_replace(
                '/(https:\/\/[^\s<]*?)\s+(?=[\/.?&#%=~@+\-a-z0-9_])/i',
                '$1',
                $reply
            );
            if ($next === null || $next === $reply) {
                break;
            }
            $reply = $next;
        }
        return trim($reply);
    }

    /**
     * Free-form AI helper using the same OpenAI-compatible key as domain analysis.
     *
     * @param list<array<string,mixed>> $history
     * @param list<array<string,mixed>> $lookups
     * @return array{reply:?string,escalate:bool}|null
     */
    /**
     * Live homepage counters from the domains table (same source as index.php).
     *
     * @return array{total_domains:int,flagged_scams:int,likely_safe:int,checked_today:int}|null
     */
    public static function liveSiteStats(): ?array
    {
        try {
            require_once __DIR__ . '/DomainRepository.php';
            $repo = new DomainRepository(Database::getConnection());
            $stats = $repo->stats();
            if (!isset($stats['total_domains'])) {
                return null;
            }
            return [
                'total_domains' => (int) $stats['total_domains'],
                'flagged_scams' => (int) ($stats['flagged_scams'] ?? 0),
                'likely_safe' => (int) ($stats['likely_safe'] ?? 0),
                'checked_today' => (int) ($stats['checked_today'] ?? 0),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function wantsSiteStats(string $message): bool
    {
        $t = mb_strtolower($message);
        if (preg_match('/\b(how many|how much|counter|statistic|stats|total)\b/i', $t)) {
            if (preg_match('/\b(site|sites|domain|domains|scanned|checked|scan|scans|homepage|home page|database|listed|tracked)\b/i', $t)) {
                return true;
            }
            // Short follow-ups like "how much u see?" after talking about the counter
            if (preg_match('/\b(how many|how much)\b/i', $t) && mb_strlen(trim($message)) < 50) {
                return true;
            }
        }
        if (preg_match('/\b(number of|count of)\b.{0,20}\b(site|sites|domain|domains|scan)\b/i', $t)) {
            return true;
        }
        return false;
    }

    /** @param array{total_domains:int,flagged_scams:int,likely_safe:int,checked_today:int}|null $stats */
    public static function formatSiteStats(?array $stats): string
    {
        if (!$stats) {
            return '';
        }
        $home = absolute_url('/');
        $browse = absolute_url('/browse.php');
        $total = number_format($stats['total_domains']);
        $safe = number_format($stats['likely_safe']);
        $scams = number_format($stats['flagged_scams']);
        $today = number_format($stats['checked_today']);

        return "Right now ScamGuard has {$total} domains scanned "
            . "({$safe} likely safe, {$scams} flagged as scam/blacklist, {$today} checked today).\n"
            . "Those are the same live counters on the homepage.\n"
            . "Home: {$home}\n"
            . "Browse: {$browse}";
    }

    public static function aiSupportReply(string $message, array $history = [], array $lookups = [], ?array $siteStats = null): ?array
    {
        $key = trim(get_setting('ai_api_key', ''));
        if ($key === '' && defined('AI_API_KEY')) {
            $key = trim((string) AI_API_KEY);
        }
        if ($key === '') {
            return null;
        }

        $url = trim(get_setting('ai_api_url', ''));
        if ($url === '' && defined('AI_API_URL')) {
            $url = trim((string) AI_API_URL);
        }
        if ($url === '') {
            $url = 'https://api.openai.com/v1/chat/completions';
        }

        $model = trim(get_setting('ai_model', ''));
        if ($model === '' && defined('AI_MODEL')) {
            $model = trim((string) AI_MODEL);
        }
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }

        $site = get_setting('site_name', 'ScamGuard');
        $facts = [];
        foreach (self::knowledge() as $item) {
            $facts[] = '- ' . $item['q'] . ' → ' . $item['a'];
        }
        $factBlock = implode("\n", $facts);
        $links = self::siteLinks();
        $linkBlock = "Important links (always paste the full URL when the user asks for a link or how to open a page):\n"
            . "- Home / Quick check: {$links['home']}\n"
            . "- Community forum: {$links['community']}\n"
            . "- Report a scam: {$links['report']}\n"
            . "- Browse domains: {$links['browse']}\n"
            . "- Sign in: {$links['login']}\n"
            . "- Register: {$links['register']}\n"
            . "- FAQ: {$links['faq']}\n"
            . "- Phone check: {$links['phone_check']}\n"
            . "- Crypto check: {$links['crypto_check']}\n";

        $system = "You are the {$site} support assistant in a live chat widget.\n"
            . "Help visitors with anything about this website and product: trust checks (website/phone/crypto/IBAN/card), "
            . "how scores work, reporting scams, community forum, announcements, accounts/login/points, browsing flagged domains, and using the site.\n"
            . "Be accurate, concise (2–5 short sentences), friendly, and practical. Use plain language.\n"
            . "CRITICAL: When the user asks for a link, URL, or how to open a page, include the full https:// URL from the Important links list on one line with NO spaces inside the URL.\n"
            . "CRITICAL: When LIVE SITE STATS are provided below, you MUST quote those exact numbers for questions about how many sites/domains are scanned, the homepage counter, totals, likely safe, flagged scams, or checked today. Never say you cannot see or do not have the exact number when LIVE SITE STATS are present.\n"
            . "CRITICAL: When LIVE DOMAIN LOOKUPS are provided below, you MUST use those exact scores/status values to answer. "
            . "Never say you cannot check a domain score if a lookup result is present. Quote score as N/100 and the status label. "
            . "ALWAYS include both full URLs on their own (no spaces): Details (page) AND Recheck now (recheck, includes refresh=1 so the visitor can rescan).\n"
            . "If a domain is listed as not in the database, say so and give the Check now / Recheck links.\n"
            . "Do not invent admin actions, refunds, legal advice, or features that are not described below.\n"
            . "Scores are risk signals, not legal proof of a scam.\n"
            . "If the user wants a human, set escalate=true.\n"
            . "If you truly cannot help from the facts, say so briefly and suggest Talk to an admin (escalate=false unless they asked for a human).\n"
            . "Never ask for passwords, seed phrases, full card numbers, or OTPs.\n\n"
            . "{$linkBlock}\n"
            . "Known facts about {$site}:\n{$factBlock}\n\n"
            . "Respond with ONLY JSON: {\"reply\":\"...\",\"escalate\":false}";

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        if ($siteStats) {
            $messages[] = [
                'role' => 'system',
                'content' => "LIVE SITE STATS from ScamGuard database (same counters as the homepage — authoritative):\n"
                    . json_encode($siteStats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($lookups) {
            $messages[] = [
                'role' => 'system',
                'content' => "LIVE DOMAIN LOOKUPS from ScamGuard database (authoritative — use these numbers):\n"
                    . json_encode($lookups, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        // Last few turns for context (skip system/quick noise somewhat)
        $recent = array_slice($history, -12);
        foreach ($recent as $m) {
            $type = (string) ($m['sender_type'] ?? '');
            $body = trim((string) ($m['body'] ?? ''));
            if ($body === '' || $type === 'system') {
                continue;
            }
            if ($type === 'visitor') {
                $messages[] = ['role' => 'user', 'content' => mb_substr($body, 0, 1200)];
            } elseif (in_array($type, ['bot', 'admin'], true)) {
                $messages[] = ['role' => 'assistant', 'content' => mb_substr($body, 0, 1200)];
            }
        }
        $userContent = mb_substr(trim($message), 0, 1500);
        if ($lookups) {
            $userContent .= "\n\n[System note: live lookups attached. Answer with the real score if asked.]";
        }
        if ($siteStats && self::wantsSiteStats($message)) {
            $userContent .= "\n\n[System note: live site stats attached. Quote the exact total_domains and related counters.]";
        }
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $payload = json_encode([
            'model' => $model,
            'temperature' => 0.35,
            'max_tokens' => 320,
            'messages' => $messages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false || $code >= 400) {
            return null;
        }

        $resp = json_decode($raw, true);
        $textOut = trim((string) ($resp['choices'][0]['message']['content'] ?? ''));
        if ($textOut === '') {
            return null;
        }

        $textOut = trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $textOut) ?? $textOut);
        $parsed = json_decode($textOut, true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $textOut, $m)) {
            $parsed = json_decode($m[0], true);
        }

        if (is_array($parsed)) {
            $reply = trim((string) ($parsed['reply'] ?? ''));
            $escalate = !empty($parsed['escalate']);
            if ($reply === '' && !$escalate) {
                return null;
            }
            if (mb_strlen($reply) > 1800) {
                $reply = mb_substr($reply, 0, 1800) . '…';
            }
            return ['reply' => $reply !== '' ? $reply : null, 'escalate' => $escalate];
        }

        // Plain-text fallback if model ignored JSON
        if (mb_strlen($textOut) > 1800) {
            $textOut = mb_substr($textOut, 0, 1800) . '…';
        }
        return ['reply' => $textOut, 'escalate' => false];
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
            'ScamGuard AI'
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
