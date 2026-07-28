<?php
require_once __DIR__ . '/functions.php';

/**
 * Community user accounts (separate from admin_users).
 * Shares the PHP session with admin Auth but uses its own session keys.
 */
class UserAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => (defined('BASE_PATH') ? BASE_PATH : '/'),
                'secure'   => (SITE_ENV === 'production'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function ipHash(): string
    {
        return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . APP_SECRET);
    }

    /** @return array{ok:bool,error?:string} */
    public static function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = strtolower(trim($email));

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3–32 characters (letters, numbers, dot, dash, underscore).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $db = Database::getConnection();

        // Anti-spam: cap fresh registrations per IP.
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE ip_hash = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
        $stmt->execute([self::ipHash()]);
        if ((int) $stmt->fetchColumn() >= 3) {
            return ['ok' => false, 'error' => 'Too many new accounts from your network. Try again later.'];
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'That username or email is already registered.'];
        }

        $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, ip_hash) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), self::ipHash()]);

        self::loginSession((int) $db->lastInsertId(), $username);
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function attempt(string $usernameOrEmail, string $password): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([trim($usernameOrEmail), strtolower(trim($usernameOrEmail))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Wrong username/email or password.'];
        }
        if (!empty($user['is_banned'])) {
            return ['ok' => false, 'error' => 'This account has been suspended.'];
        }

        self::loginSession((int) $user['id'], (string) $user['username']);
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        return ['ok' => true];
    }

    private static function loginSession(int $id, string $username): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['user_username'] = $username;
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function username(): ?string
    {
        self::start();
        return $_SESSION['user_username'] ?? null;
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION['user_id'], $_SESSION['user_username']);
    }

    public static function requireLogin(string $next = ''): void
    {
        if (!self::check()) {
            $q = $next !== '' ? '?next=' . rawurlencode($next) : '';
            redirect('/login.php' . $q);
        }
        // Banned mid-session? Kick out.
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT is_banned FROM users WHERE id = ?');
        $stmt->execute([self::id()]);
        if ((int) $stmt->fetchColumn() === 1) {
            self::logout();
            redirect('/login.php');
        }
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        return $token !== null && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /** Anti-spam posting limits. @return array{ok:bool,error?:string} */
    public static function canPostThread(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM forum_threads WHERE user_id = ? AND created_at >= NOW() - INTERVAL 2 MINUTE');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= 1) {
            return ['ok' => false, 'error' => 'Please wait a couple of minutes between reports.'];
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM forum_threads WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= 15) {
            return ['ok' => false, 'error' => 'Daily report limit reached. Try again tomorrow.'];
        }
        return ['ok' => true];
    }

    /** @return array{ok:bool,error?:string} */
    public static function canPostComment(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT COUNT(*) FROM forum_comments WHERE user_id = ? AND created_at >= NOW() - INTERVAL 20 SECOND');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= 1) {
            return ['ok' => false, 'error' => 'You are commenting too fast — wait a few seconds.'];
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM forum_comments WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY');
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() >= 100) {
            return ['ok' => false, 'error' => 'Daily comment limit reached.'];
        }
        return ['ok' => true];
    }
}

/** "5 minutes ago" style timestamps for the forum */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    if ($diff < 86400 * 30) return floor($diff / 86400) . ' d ago';
    return date('M j, Y', $ts);
}

/** Category label map shared by report + forum pages */
function report_categories(): array
{
    return [
        'phishing' => 'Phishing',
        'fake_shop' => 'Fake online shop',
        'crypto_scam' => 'Crypto / investment scam',
        'tech_support_scam' => 'Tech support scam',
        'identity_theft' => 'Identity theft',
        'phone_spam' => 'Phone spam / scam call',
        'other' => 'Other',
    ];
}

function report_category_label(string $key): string
{
    return report_categories()[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/** Badge for thread review status */
function thread_review_badge(string $status): array
{
    return match ($status) {
        'approved' => ['label' => 'Verified by admins', 'class' => 'badge-safe'],
        'rejected' => ['label' => 'Rejected', 'class' => 'badge-scam'],
        default    => ['label' => 'Awaiting review', 'class' => 'badge-caution'],
    };
}

/** Subject-type chip label */
function thread_subject_label(string $type): string
{
    return match ($type) {
        'phone' => 'Phone',
        'crypto' => 'Crypto',
        'iban' => 'IBAN',
        'card' => 'Card',
        default => 'Website',
    };
}

/** Public profile URL for a community user */
function profile_path(string $username): string
{
    return base_path('profile.php?u=' . rawurlencode($username));
}

/**
 * Up/down vote totals for a set of threads or comments.
 * @return array<int, array{up:int,down:int}>
 */
function forum_vote_counts(PDO $db, string $type, array $ids): array
{
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return $out;
    }
    $in = implode(',', $ids);
    $stmt = $db->prepare(
        "SELECT subject_id,
                SUM(vote = 1) AS up,
                SUM(vote = -1) AS down
         FROM forum_votes
         WHERE subject_type = ? AND subject_id IN ($in)
         GROUP BY subject_id"
    );
    $stmt->execute([$type]);
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['subject_id']] = ['up' => (int) $row['up'], 'down' => (int) $row['down']];
    }
    return $out;
}

/**
 * The current user's own votes on a set of items.
 * @return array<int, int> subject_id => -1|1
 */
function forum_user_votes(PDO $db, ?int $userId, string $type, array $ids): array
{
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$userId || !$ids) {
        return $out;
    }
    $in = implode(',', $ids);
    $stmt = $db->prepare("SELECT subject_id, vote FROM forum_votes WHERE user_id = ? AND subject_type = ? AND subject_id IN ($in)");
    $stmt->execute([$userId, $type]);
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['subject_id']] = (int) $row['vote'];
    }
    return $out;
}

/**
 * Register/toggle a vote. Returns an error string or null on success.
 */
function forum_cast_vote(PDO $db, int $userId, string $type, int $subjectId, int $vote): ?string
{
    if (!in_array($type, ['thread', 'comment'], true) || !in_array($vote, [-1, 1], true)) {
        return 'Invalid vote.';
    }

    // Item must exist; no votes on your own posts.
    if ($type === 'thread') {
        $stmt = $db->prepare('SELECT user_id FROM forum_threads WHERE id = ?');
    } else {
        $stmt = $db->prepare('SELECT user_id FROM forum_comments WHERE id = ? AND is_deleted = 0');
    }
    $stmt->execute([$subjectId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false) {
        return 'That post no longer exists.';
    }
    if ((int) $ownerId === $userId) {
        return 'You cannot vote on your own post.';
    }

    // Light anti-abuse cap.
    $stmt = $db->prepare('SELECT COUNT(*) FROM forum_votes WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() >= 300) {
        return 'Daily vote limit reached.';
    }

    $stmt = $db->prepare('SELECT id, vote FROM forum_votes WHERE user_id = ? AND subject_type = ? AND subject_id = ?');
    $stmt->execute([$userId, $type, $subjectId]);
    $existing = $stmt->fetch();

    if ($existing && (int) $existing['vote'] === $vote) {
        // Same vote again = undo.
        $db->prepare('DELETE FROM forum_votes WHERE id = ?')->execute([$existing['id']]);
    } elseif ($existing) {
        $db->prepare('UPDATE forum_votes SET vote = ?, created_at = NOW() WHERE id = ?')->execute([$vote, $existing['id']]);
    } else {
        $db->prepare('INSERT INTO forum_votes (user_id, subject_type, subject_id, vote) VALUES (?, ?, ?, ?)')
           ->execute([$userId, $type, $subjectId, $vote]);
    }
    return null;
}
