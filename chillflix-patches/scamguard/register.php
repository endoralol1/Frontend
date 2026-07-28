<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/UserAuth.php';

UserAuth::start();
$next = trim($_GET['next'] ?? $_POST['next'] ?? '');
if (UserAuth::check()) {
    redirect($next !== '' ? $next : '/community.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!UserAuth::verifyCsrf($_POST['csrf'] ?? null)) {
        $error = 'Session expired — please try again.';
    } elseif (trim($_POST['website'] ?? '') !== '') {
        // Honeypot field: bots fill it, humans never see it.
        $error = 'Registration failed.';
    } else {
        $result = UserAuth::register(
            $_POST['username'] ?? '',
            $_POST['email'] ?? '',
            $_POST['password'] ?? ''
        );
        if ($result['ok']) {
            redirect($next !== '' ? $next : '/community.php');
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Create account — ' . get_setting('site_name', 'ScamGuard');
$robotsMeta = 'noindex,follow';
require __DIR__ . '/includes/header.php';
?>

<section class="section container auth-wrap">
    <div class="card auth-card">
        <h2 class="section-title" style="margin-top:0;">Create your account</h2>
        <p class="auth-sub">An account is required to report scams and join discussions — it keeps the community spam-free.</p>

        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <div class="hp-field" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
            <div class="field">
                <label>Username</label>
                <input type="text" name="username" placeholder="e.g. scamhunter42" value="<?= h($_POST['username'] ?? '') ?>" required minlength="3" maxlength="32" autocomplete="username">
            </div>
            <div class="field">
                <label>Email</label>
                <input type="email" name="email" placeholder="you@example.com" value="<?= h($_POST['email'] ?? '') ?>" required autocomplete="email">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Create account</button>
        </form>

        <p class="auth-alt">Already have an account?
            <a href="<?= BASE_PATH ?>/login.php<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Sign in</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
