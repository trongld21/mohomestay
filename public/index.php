<?php
$requestedPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $requestedPath)) return false;
require dirname(__DIR__) . '/src/bootstrap.php';

$path = $requestedPath;
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/__dev/version' && dev_reload_enabled() && $method === 'GET') {
    $hash = hash_init('sha256');
    foreach (['src', 'public/assets', 'public/images'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && !$file->isLink()) {
                hash_update($hash, $file->getPathname() . ':' . hash_file('sha256', $file->getPathname()));
            }
        }
    }
    hash_update($hash, hash_file('sha256', __FILE__));
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo hash_final($hash);
    exit;
}

if (str_starts_with($path, '/api/')) {
    require APP_ROOT . '/src/api.php';
    handle_api($path, $method);
}

if ($path === '/logout' && $method === 'POST') {
    require_csrf((string)($_POST['csrf'] ?? ''));
    $_SESSION = [];
    session_destroy();
    redirect('/auth/login');
}
if ($path === '/auth/login' && $method === 'POST') {
    require_csrf((string)($_POST['csrf'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    try {
        if (account_login($email, $password)) {
            redirect(account_home());
        }
    } catch (Throwable $error) {
        error_log('Account login failed: ' . $error->getMessage());
    }
    $loginError = 'Thông tin đăng nhập không đúng hoặc tài khoản quản lý chưa được cấu hình.';
}

require APP_ROOT . '/src/views.php';

if (in_array($path, ['/admin/accounts', '/admin/permissions'], true)) {
    if (!is_admin()) redirect('/auth/login');
    require_permission('accounts');
    header('Cache-Control: no-store');
    require APP_ROOT . '/src/account-view.php';
    $permissionPage = $path === '/admin/permissions';
    $accountError = '';
    if ($method === 'POST') {
        require_csrf((string)($_POST['csrf'] ?? ''));
        try {
            save_account($_POST, $permissionPage);
            redirect($path . '?saved=1');
        } catch (BookingException $error) {
            $accountError = $error->getMessage();
        }
    }
    render_page($permissionPage ? 'Phân quyền tài khoản' : 'Quản lý tài khoản', fn() => render_accounts($permissionPage, $accountError), ['robots' => false]);
}
if ($path === '/admin/no-access') {
    if (!is_admin()) redirect('/auth/login');
    render_page('Quyền truy cập', fn() => print('<div class="p-6"><h1 class="text-2xl font-semibold">Chưa được cấp quyền</h1><p class="mt-3">Liên hệ Admin để được cấp quyền sử dụng chức năng.</p></div>'), ['robots' => false]);
}


if ($path === '/') render_page('Trang chủ', fn() => render_home());
elseif ($path === '/rooms') render_page('Phòng & bảng giá', fn() => render_rooms());
elseif (preg_match('#^/admin/rooms/([a-zA-Z0-9_-]+)/preview$#', $path, $m)) {
    require_permission('rooms');
    $room = find_room($m[1], true);
    if (!$room) {
        http_response_code(404);
        render_page('Không tìm thấy phòng', fn() => print('<div class="container section"><h1>Không tìm thấy phòng.</h1></div>'), ['robots' => false]);
    }
    render_page('Xem trước · ' . $room['name'], fn() => render_room($room), ['robots' => false]);
} elseif (preg_match('#^/rooms/([a-z0-9-]+)$#', $path, $m) && ($room = find_room($m[1]))) render_page($room['name'], fn() => render_room($room));
elseif ($path === '/calendar') render_page('Lịch phòng', fn() => render_calendar());
elseif ($path === '/bookings') render_page('Đặt phòng', fn() => render_bookings());
elseif ($path === '/payment') render_page('Thanh toán', fn() => render_payment());
elseif ($path === '/auth/login') render_page('Đăng nhập quản lý', fn() => render_login($loginError ?? ''), ['robots' => false]);
elseif ($path === '/admin/door-codes') {
    if (!is_admin()) redirect('/auth/login');
    require_permission('door-codes');
    header('Cache-Control: no-store');
    require APP_ROOT . '/src/door-code-view.php';
    render_page('Mã mở cửa', fn() => render_door_codes(), ['robots' => false]);
}
elseif ($path === '/admin') {
    if (!is_admin()) redirect('/auth/login');
    if (!can_use('bookings') && !can_use('rooms')) redirect(account_home());
    header('Cache-Control: no-store');
    render_page('Quản lý', fn() => render_admin(), ['robots' => false]);
} elseif ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /auth\nSitemap: " . rtrim((string)config('app_url'), '/') . "/sitemap.xml\n";
} elseif ($path === '/sitemap.xml') {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = ['/', '/rooms', '/calendar', '/bookings'];
    foreach (rooms() as $room) $urls[] = '/rooms/' . $room['slug'];
    foreach ($urls as $url) echo '<url><loc>' . e(rtrim((string)config('app_url'), '/') . $url) . '</loc></url>';
    echo '</urlset>';
} else {
    http_response_code(404);
    render_page('Không tìm thấy', fn() => print('<div class="container section text-center"><h1>Không tìm thấy trang.</h1><p class="muted"><a class="text-link" href="/">Về trang chủ</a></p></div>'));
}
