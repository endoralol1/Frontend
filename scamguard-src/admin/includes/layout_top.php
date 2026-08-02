<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/Auth.php';
Auth::requireLogin();

$currentPage = basename($_SERVER['SCRIPT_NAME']);
function nav_active(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Admin') ?> — Admin Panel</title>
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="admin-wrap">
    <aside class="admin-sidebar">
        <div class="brand"><span class="brand-mark">🛡️</span> Admin</div>
        <nav class="admin-nav">
            <a href="<?= BASE_PATH ?>/admin/index.php" class="<?= nav_active('index.php', $currentPage) ?>">📊 Dashboard</a>
            <a href="<?= BASE_PATH ?>/admin/domains.php" class="<?= nav_active('domains.php', $currentPage) ?>">🌐 Domains</a>
            <a href="<?= BASE_PATH ?>/admin/reports.php" class="<?= nav_active('reports.php', $currentPage) ?>">🚩 Reports</a>
            <a href="<?= BASE_PATH ?>/admin/discovery.php" class="<?= nav_active('discovery.php', $currentPage) ?>">🔍 Discovery</a>
            <a href="<?= BASE_PATH ?>/admin/scoring.php" class="<?= nav_active('scoring.php', $currentPage) ?>">⚖️ Scoring</a>
            <a href="<?= BASE_PATH ?>/admin/api_keys.php" class="<?= nav_active('api_keys.php', $currentPage) ?>">🔑 API Keys</a>
            <a href="<?= BASE_PATH ?>/admin/settings.php" class="<?= nav_active('settings.php', $currentPage) ?>">⚙️ Settings</a>
            <a href="<?= BASE_PATH ?>/admin/activity.php" class="<?= nav_active('activity.php', $currentPage) ?>">📜 Activity Log</a>
            <hr style="border-color: var(--border); margin: 14px 0;">
            <a href="<?= BASE_PATH ?>/" target="_blank">🔗 View Site</a>
            <a href="<?= BASE_PATH ?>/admin/logout.php">🚪 Log Out</a>
        </nav>
    </aside>
    <main class="admin-main">
        <div class="admin-topbar">
            <h1><?= h($pageTitle ?? 'Admin') ?></h1>
            <div style="color:var(--text-faint); font-size:14px;">Logged in as <strong style="color:var(--text);"><?= h(Auth::username()) ?></strong></div>
        </div>
