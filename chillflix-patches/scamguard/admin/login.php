<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Auth.php';

Auth::start();
if (Auth::check()) {
    redirect('/admin/app/');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::attempt($username, $password)) {
        log_admin_activity(Auth::id(), 'login', null, null);
        redirect('/admin/app/');
    } else {
        $error = 'Invalid username or password.';
        usleep(400000); // slow down brute-force attempts slightly
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — <?= h(get_setting('site_name', 'ScamGuard')) ?></title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
<div class="card" style="max-width:380px; width:100%;">
    <div class="brand" style="margin-bottom:20px;"><span class="brand-mark">🛡️</span> Admin Panel</div>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <div class="field">
            <label>Username</label>
            <input type="text" name="username" required autofocus>
        </div>
        <div class="field">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Log In</button>
    </form>
</div>
</body>
</html>
