<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Auth.php';
Auth::start();
log_admin_activity(Auth::id(), 'logout', null, null);
Auth::logout();
redirect('/admin/login.php');
