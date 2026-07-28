<?php
require_once __DIR__ . '/functions.php';

class Auth
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

    public static function attempt(string $username, string $password): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            self::start();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];

            $upd = $db->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?');
            $upd->execute([$user['id']]);

            return true;
        }

        // Community accounts with the admin role share the same panel login —
        // no separate admin account needed.
        $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin' AND is_banned = 0");
        $stmt->execute([$username, strtolower(trim($username))]);
        $communityAdmin = $stmt->fetch();

        if ($communityAdmin && password_verify($password, $communityAdmin['password_hash'])) {
            self::start();
            session_regenerate_id(true);
            // No admin_users row: admin_id stays unset so FK-bound logs record NULL.
            $_SESSION['admin_via_user'] = (int) $communityAdmin['id'];
            $_SESSION['admin_username'] = $communityAdmin['username'];
            $_SESSION['admin_role'] = 'admin';
            // Keep their community session active too (same account).
            $_SESSION['user_id'] = (int) $communityAdmin['id'];
            $_SESSION['user_username'] = $communityAdmin['username'];

            $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$communityAdmin['id']]);
            return true;
        }

        return false;
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['admin_id']) || isset($_SESSION['admin_via_user']);
    }

    public static function requireLogin(): void
    {
        self::start();
        if (!self::check()) {
            redirect('/admin/login.php');
        }
    }

    public static function id(): ?int
    {
        // Community-admin sessions return NULL: admin_activity_log/report FKs
        // reference admin_users, so those rows record a NULL admin id.
        self::start();
        return $_SESSION['admin_id'] ?? null;
    }

    public static function username(): ?string
    {
        self::start();
        return $_SESSION['admin_username'] ?? null;
    }

    public static function isSuperAdmin(): bool
    {
        self::start();
        return ($_SESSION['admin_role'] ?? '') === 'superadmin';
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
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
}
