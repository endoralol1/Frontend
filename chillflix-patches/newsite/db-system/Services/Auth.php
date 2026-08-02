<?php
declare(strict_types=1);

final class Auth
{
    public const COOKIE = 'ns_session';
    public const TTL = 60 * 60 * 24 * 30; // 30 days

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function now(): int
    {
        return time();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token === '' || strlen($token) < 32) {
            self::$user = null;
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.* FROM sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > ? LIMIT 1'
        );
        $stmt->execute([$token, self::now()]);
        $row = $stmt->fetch();
        if (!$row || ($row['status'] ?? '') !== 'active') {
            self::$user = null;
            return null;
        }
        self::$user = self::publicUser($row);
        return self::$user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
        }
        return $user;
    }

    public static function requireRole(string ...$roles): array
    {
        $user = self::requireUser();
        if (!in_array((string) $user['role'], $roles, true)) {
            json_response(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        return $user;
    }

    public static function isStaff(?array $user = null): bool
    {
        $user = $user ?? self::user();
        if (!$user) {
            return false;
        }
        return in_array((string) $user['role'], ['admin', 'moderator'], true);
    }

    public static function isAdmin(?array $user = null): bool
    {
        $user = $user ?? self::user();
        return $user && (string) $user['role'] === 'admin';
    }

    /** @param array<string,mixed> $row */
    public static function publicUser(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'language' => (string) ($row['language'] ?? 'en'),
            'autoplayEnabled' => (int) ($row['autoplay_enabled'] ?? 0) === 1,
            'autoNextEnabled' => (int) ($row['auto_next_enabled'] ?? 1) === 1,
            'watchlistEnabled' => (int) ($row['watchlist_enabled'] ?? 1) === 1,
            'continueEnabled' => (int) ($row['continue_enabled'] ?? 1) === 1,
            'createdAt' => (int) ($row['created_at'] ?? 0),
            'updatedAt' => (int) ($row['updated_at'] ?? 0),
            'lastLoginAt' => isset($row['last_login_at']) ? (int) $row['last_login_at'] : null,
        ];
    }

    public static function register(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Invalid name');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Password must be at least 6 characters');
        }

        $exists = Database::pdo()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            throw new InvalidArgumentException('Email already registered');
        }

        $id = self::uuid();
        $now = self::now();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (id, email, password_hash, name, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'user\', \'active\', ?, ?)'
        );
        $stmt->execute([$id, $email, $hash, $name, $now, $now]);
        return self::login($email, $password);
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            throw new InvalidArgumentException('Invalid email or password');
        }
        if (($row['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Account suspended');
        }

        $token = bin2hex(random_bytes(32));
        $now = self::now();
        $expires = $now + self::TTL;
        Database::pdo()->prepare(
            'INSERT INTO sessions (id, user_id, expires_at, created_at, user_agent, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $token,
            $row['id'],
            $expires,
            $now,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
        Database::pdo()->prepare('UPDATE users SET last_login_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$now, $now, $row['id']]);

        self::setCookie($token, $expires);
        self::$loaded = true;
        self::$user = self::publicUser($row);
        self::$user['lastLoginAt'] = $now;
        return self::$user;
    }

    public static function logout(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token !== '') {
            Database::pdo()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
        }
        self::setCookie('', time() - 3600);
        self::$loaded = true;
        self::$user = null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function verifyTurnstile(?string $token): bool
    {
        $secret = (string) config('turnstile_secret_key', '');
        if ($secret === '') {
            return true; // not configured
        }
        if (!$token) {
            return false;
        }
        $payload = http_build_query([
            'secret' => $secret,
            'response' => $token,
            'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 8,
            ],
        ]);
        $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['success']);
    }
}
