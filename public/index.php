<?php
$requestedPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $requestedPath)) return false;
require dirname(__DIR__) . '/src/bootstrap.php';

$path = $requestedPath;
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($path, '/api/')) {
    require APP_ROOT . '/src/api.php';
    handle_api($path, $method);
}

if ($path === '/logout' && $method === 'POST') {
    require_csrf((string)($_POST['csrf'] ?? ''));
    $_SESSION = []; session_destroy(); redirect('/auth/login');
}
if ($path === '/auth/login' && $method === 'POST') {
    require_csrf((string)($_POST['csrf'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if ($email === strtolower((string)config('admin_email')) && password_verify($password, (string)config('admin_password_hash'))) {
        session_regenerate_id(true); $_SESSION['admin_until'] = time() + 8 * 3600; redirect('/admin');
    }
    $loginError = 'Thông tin đăng nhập không đúng hoặc tài khoản quản lý chưa được cấu hình.';
}

require APP_ROOT . '/src/views.php';

if ($path === '/') render_page('Trang chủ', fn() => render_home());
elseif ($path === '/rooms') render_page('Phòng & bảng giá', fn() => render_rooms());
elseif (preg_match('#^/admin/rooms/([a-zA-Z0-9_-]+)/preview$#', $path, $m)) { require_admin(); $room=find_room($m[1],true); if(!$room) { http_response_code(404); render_page('Không tìm thấy phòng',fn()=>print('<div class="container section"><h1>Không tìm thấy phòng.</h1></div>'),['robots'=>false]); } render_page('Xem trước · '.$room['name'],fn()=>render_room($room),['robots'=>false]); }
elseif (preg_match('#^/rooms/([a-z0-9-]+)$#', $path, $m) && ($room=find_room($m[1]))) render_page($room['name'], fn() => render_room($room));
elseif ($path === '/calendar') render_page('Lịch phòng', fn() => render_calendar());
elseif ($path === '/bookings') render_page('Đặt phòng', fn() => render_bookings());
elseif ($path === '/payment') render_page('Thanh toán', fn() => render_payment());
elseif ($path === '/auth/login') render_page('Đăng nhập quản lý', fn() => render_login($loginError ?? ''), ['robots'=>false]);
elseif ($path === '/admin') { if (!is_admin()) redirect('/auth/login'); render_page('Quản lý', fn() => render_admin(), ['robots'=>false]); }
elseif ($path === '/robots.txt') { header('Content-Type: text/plain'); echo "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /auth\nSitemap: ".rtrim((string)config('app_url'),'/')."/sitemap.xml\n"; }
elseif ($path === '/sitemap.xml') { header('Content-Type: application/xml'); echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'; $urls=['/','/rooms','/calendar','/bookings'];foreach(rooms() as $room)$urls[]='/rooms/'.$room['slug'];foreach ($urls as $url) echo '<url><loc>'.e(rtrim((string)config('app_url'),'/').$url).'</loc></url>'; echo '</urlset>'; }
else { http_response_code(404); render_page('Không tìm thấy', fn() => print('<div class="container section text-center"><h1>Không tìm thấy trang.</h1><p class="muted"><a class="text-link" href="/">Về trang chủ</a></p></div>')); }
