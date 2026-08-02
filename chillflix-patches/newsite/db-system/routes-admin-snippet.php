$router->get('/admin', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/dashboard', [
        'adminUser' => $user,
        'seo' => ['title' => 'Admin | ' . config('site_name'), 'robots' => 'noindex'],
        'bodyClass' => 'page-admin page-admin-dashboard',
    ]);
});

$router->get('/admin/users', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/users', [
        'adminUser' => $user,
        'seo' => ['title' => 'Users | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/admin/sources', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/sources', [
        'adminUser' => $user,
        'seo' => ['title' => 'Sources | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

// Admin APIs
