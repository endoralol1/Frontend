<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::requireLogin();
header('Location: ' . (defined('BASE_PATH') ? BASE_PATH : '') . '/admin/app/#/discovery');
exit;
