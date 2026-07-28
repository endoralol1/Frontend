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
    } else {
        $result = UserAuth::attempt($_POST['login'] ?? '', $_POST['password'] ?? '');
        if ($result['ok']) {
            redirect($next !== '' ? $next : '/community.php');
        }
        $error = $result['error'];
    }
}

$pageTitle = 'Sign in — ' . get_setting('site_name', 'ScamGuard');
$robotsMeta = 'noindex,follow';
require __DIR__ . '/includes/header.php';
?>

<section class="section container auth-wrap">
    <div class="card auth-card">
        <h2 class="section-title" style="margin-top:0;">Sign in</h2>
        <p class="auth-sub">Report scams, discuss suspicious sites, and help others stay safe.</p>

        <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(UserAuth::csrfToken()) ?>">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <div class="field">
                <label>Username or email</label>
                <input type="text" name="login" value="<?= h($_POST['login'] ?? '') ?>" required autocomplete="username">
            </div>
            <div class="field">
                <label>Password</label>
                <input type="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Sign in</button>
        </form>

        <p class="auth-alt">New here?
            <a href="<?= BASE_PATH ?>/register.php<?= $next !== '' ? '?next=' . rawurlencode($next) : '' ?>">Create an account</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
