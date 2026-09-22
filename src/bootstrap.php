<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
    $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if ($values === false) throw new RuntimeException('File .env khong hop le.');
    foreach ($values as $name => $value) {
        if (getenv((string)$name) !== false) continue;
        $_ENV[(string)$name] = (string)$value;
        putenv((string)$name . '=' . (string)$value);
    }
}
date_default_timezone_set((string) config('timezone', 'Asia/Ho_Chi_Minh'));

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        ini_set('session.cookie_secure', '1');
    }
    session_name('langhome_session');
    session_start();
}

function config(string $key, mixed $default = null): mixed
{
    if ($key === 'db') return [
        'host'=>env_value('DB_HOST','localhost'),'port'=>(int)env_value('DB_PORT','3306'),
        'name'=>env_value('DB_DATABASE',''),'user'=>env_value('DB_USERNAME',''),
        'password'=>env_value('DB_PASSWORD',''),'charset'=>env_value('DB_CHARSET','utf8mb4'),
    ];
    $map = [
        'app_url'=>'APP_URL','app_key'=>'APP_KEY','setup_key'=>'DEPLOY_HOOK_KEY',
        'timezone'=>'APP_TIMEZONE','overnight_check_in'=>'OVERNIGHT_CHECK_IN',
        'overnight_check_out'=>'OVERNIGHT_CHECK_OUT','booking_terms'=>'BOOKING_TERMS',
        'admin_email'=>'ADMIN_EMAIL','admin_password_hash'=>'ADMIN_PASSWORD_HASH',
        'payos.client_id'=>'PAYOS_CLIENT_ID','payos.api_key'=>'PAYOS_API_KEY',
        'payos.checksum_key'=>'PAYOS_CHECKSUM_KEY',
    ];
    if ($key === 'bookings_enabled') return filter_var(env_value('BOOKINGS_ENABLED','false'), FILTER_VALIDATE_BOOL);
    return isset($map[$key]) ? env_value($map[$key], $default) : $default;
}

function env_value(string $name, mixed $default = null): mixed
{
    $value = $_ENV[$name] ?? getenv($name);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = config('db', []);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'] ?? 3306, $c['name'], $c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function fallback_rooms(): array
{
    return [
        'pink' => ['id'=>'pink','name'=>'Pink','subtitle'=>'Một chút ngọt ngào, một chút mơ mộng.','description'=>'Dành một khoảng thời gian thật riêng cho nhau, trong không gian dịu dàng và ấm áp.','tag'=>'Dịu dàng & lãng mạn','image'=>'/images/pink-illustration.jpg','prices'=>['3h'=>200000,'6h'=>380000,'overnight'=>330000]],
        'white' => ['id'=>'white','name'=>'White','subtitle'=>'Nhẹ nhàng như một ngày không vội.','description'=>'Một căn phòng sáng, tinh giản và dễ chịu. Tạm gác những bộn bề để tận hưởng khoảng thời gian của riêng bạn.','tag'=>'Tinh giản & thư thái','image'=>'/images/white-illustration.jpg','prices'=>['3h'=>150000,'6h'=>350000,'overnight'=>250000]],
        'black' => ['id'=>'black','name'=>'Black','subtitle'=>'Một không gian, một sắc thái riêng.','description'=>'Không gian trầm ấm dành cho những ai yêu sự riêng tư. Thả mình nghỉ ngơi và để nhịp sống chậm lại.','tag'=>'Cá tính & riêng tư','image'=>'/images/black-illustration.jpg','prices'=>['3h'=>180000,'6h'=>300000,'overnight'=>320000]],
    ];
}

function rooms(bool $includeHidden = false): array
{
    try { return $includeHidden ? room_repository()->allRooms() : room_repository()->publicRooms(); }
    catch (Throwable $e) { error_log('Room repository fallback: '.$e->getMessage()); return fallback_rooms(); }
}

function find_room(string $idOrSlug, bool $includeHidden = false): ?array
{
    try {
        if ($includeHidden) return room_repository()->findById($idOrSlug) ?? current(array_filter(room_repository()->allRooms(),fn($room)=>$room['slug']===$idOrSlug)) ?: null;
        return room_repository()->findPublicBySlug($idOrSlug) ?? room_repository()->findById($idOrSlug);
    } catch (Throwable) { foreach(fallback_rooms() as $room)if($room['id']===$idOrSlug)return $room;return null; }
}

function packages(): array { return ['3h'=>'Gói 3 giờ','6h'=>'Gói 6 giờ','overnight'=>'Qua đêm']; }
function money(float|int $amount): string { return number_format((float)$amount, 0, ',', '.') . 'đ'; }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_body(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) json_response(['error'=>'Dữ liệu quá lớn.'], 413);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) json_response(['error'=>'Dữ liệu không hợp lệ.'], 400);
    return $body;
}
function redirect(string $url): never { header('Location: ' . $url); exit; }
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_csrf(?string $token = null): void
{
    $token ??= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) json_response(['error'=>'Phiên làm việc không hợp lệ.'], 419);
}
function is_admin(): bool { return isset($_SESSION['admin_until']) && $_SESSION['admin_until'] >= time(); }
function require_admin(): void { if (!is_admin()) json_response(['error'=>'Không có quyền truy cập.'], 401); }
function uuid(): string { return bin2hex(random_bytes(16)); }
function payment_enabled(): bool
{
    return (bool)(config('bookings_enabled') && config('booking_terms') && config('payos.client_id') && config('payos.api_key') && config('payos.checksum_key') && config('app_url'));
}

require_once APP_ROOT . '/src/booking.php';
require_once APP_ROOT . '/src/rooms.php';
require_once APP_ROOT . '/src/room-repository.php';
require_once APP_ROOT . '/src/uploads.php';
require_once APP_ROOT . '/src/payment.php';
