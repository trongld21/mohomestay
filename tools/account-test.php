<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/booking.php';
require dirname(__DIR__) . '/src/accounts.php';

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE accounts (id TEXT PRIMARY KEY, name TEXT, email TEXT UNIQUE, password_hash TEXT, is_active INTEGER, permissions TEXT, session_version INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    }
    return $pdo;
}

function config(string $key): string
{
    return $key === 'admin_email' ? 'root@example.test' : '';
}

function uuid(): string
{
    return bin2hex(random_bytes(16));
}

function require_admin(): void
{
    if (!is_admin()) throw new RuntimeException('Unauthenticated');
}

function is_admin(): bool
{
    return current_account() !== null;
}

function json_response(array $data, int $status): never
{
    throw new RuntimeException((string)$status);
}

function account_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$_SESSION = [];
account_check(!can_use('rooms'), 'Anonymous must be denied');
$_SESSION = ['admin_until' => time() + 3600];
account_check(can_use('accounts') && can_use('rooms') && can_use('door-codes'), 'Root must have all permissions');
save_account(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'test-password-123', 'is_active' => 'on'], false);
$row = db()->query('SELECT * FROM accounts')->fetch();
account_check(password_verify('test-password-123', $row['password_hash']), 'Passwords must be hashed');
$id = $row['id'];
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 1;
account_check(is_admin() && !can_use('rooms') && !can_use('accounts'), 'New staff must have no permissions');
account_check(account_home() === '/admin/no-access', 'Staff without grants must see no-access page');
try {
    save_account(['id' => $id, 'permissions' => ['rooms.view']], true);
    throw new LogicException('Staff escalated privileges');
} catch (RuntimeException $error) {
    account_check($error->getMessage() === '403', 'Staff cannot grant permissions');
}
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['rooms.view']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 1;
account_check(can_use('rooms') && !can_use('bookings') && !can_use('door-codes'), 'Grants must be isolated by feature');
account_check(account_home() === '/admin#rooms', 'Landing page must match granted feature');
db()->exec("UPDATE accounts SET permissions = '[]'");
account_check(!can_use('rooms'), 'Revocation must apply to existing sessions');
db()->exec('UPDATE accounts SET is_active = 0');
account_check(!is_admin(), 'Disabled account session must be rejected');
db()->exec('UPDATE accounts SET is_active = 1, session_version = 2');
account_check(!is_admin(), 'Old password session must be rejected');
unset($_SESSION['account_id'], $_SESSION['account_version']);
foreach (['root@example.test', 'staff@example.test'] as $email) {
    try {
        save_account(['name' => 'Duplicate', 'email' => $email, 'password' => 'test-password-123'], false);
        throw new LogicException('Duplicate email accepted');
    } catch (BookingException $error) {
        account_check($error->httpStatus === 422, 'Duplicate and reserved emails must be rejected');
    }
}
$_SESSION['admin_until'] = time() - 1;
account_check(!can_use('accounts'), 'Expired root session must be denied');
$_SESSION = ['admin_until' => time() + 3600];
require dirname(__DIR__) . '/src/account-view.php';
require dirname(__DIR__) . '/src/admin-layout.php';
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function asset_url(string $path): string
{
    return $path;
}

function csrf_token(): string
{
    return 'test-csrf';
}
ob_start();
render_accounts(true, '');
$html = ob_get_clean();
account_check(str_contains($html, 'name="permissions[]"') && str_contains($html, 'name="csrf"'), 'Permission forms must render grants and CSRF tokens');
ob_start();
render_admin_navigation('/admin/accounts');
$html = ob_get_clean();
account_check(str_contains($html, 'href="/admin/permissions"'), 'Root must see account navigation');
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
db()->exec("UPDATE accounts SET permissions = '[\"rooms\"]'");
ob_start();
render_admin_navigation('/admin');
$html = ob_get_clean();
account_check(str_contains($html, 'data-admin-tab="rooms"') && !str_contains($html, 'data-admin-tab="bookings"') && !str_contains($html, 'href="/admin/permissions"'), 'Navigation must hide all ungranted features');
// Legacy grants expand without losing existing access.
account_check(can_use('rooms.edit') && can_use('rooms.delete'), 'Legacy room grant must preserve existing access');
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['rooms.images']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
account_check(can_use('rooms') && can_use('rooms.images') && !can_use('rooms.edit') && !can_use('rooms.delete'), 'Image grant must add view without granting room mutations');
foreach ([
    ['/api/admin/rooms', 'GET', [], 'rooms.view'],
    ['/api/admin/rooms', 'POST', [], 'rooms.create'],
    ['/api/admin/rooms/r', 'PATCH', [], 'rooms.edit'],
    ['/api/admin/rooms/r', 'DELETE', [], 'rooms.delete'],
    ['/api/admin/rooms/r/images/i', 'DELETE', [], 'rooms.images'],
    ['/api/admin/rooms/r/visibility', 'PATCH', [], 'rooms.visibility'],
    ['/api/admin/bookings', 'GET', [], 'bookings.view'],
    ['/api/admin/bookings', 'POST', [], 'bookings.create'],
    ['/api/admin/bookings', 'PATCH', [], 'bookings.update'],
    ['/api/admin/bookings/b/payment', 'POST', [], 'bookings.payment'],
    ['/api/admin/bookings/b/stay', 'POST', [], 'accounts'],
    ['/api/admin/booking-rooms', 'GET', [], 'bookings.create'],
    ['/api/admin/door-codes/d', 'PATCH', ['status' => 'ACTIVE'], 'door-codes.toggle'],
    ['/api/admin/door-codes/d', 'PATCH', ['status' => 'ACTIVE', 'code' => '123456'], 'door-codes.edit'],
    ['/api/admin/door-codes/d', 'DELETE', [], 'door-codes.delete'],
    ['/api/admin/manual-access', 'POST', [], 'door-codes.create'],
    ['/api/admin/manual-access/a/retry', 'POST', [], 'door-codes.retry'],
    ['/api/admin/booking-access/b/retry', 'POST', [], 'door-codes.retry'],
    ['/api/admin/expenses', 'GET', [], 'expenses.view'],
    ['/api/admin/expenses', 'POST', [], 'expenses.create'],
    ['/api/admin/unknown', 'POST', [], 'accounts'],
] as [$path, $method, $body, $expected]) {
    account_check(admin_api_permission($path, $method, $body) === $expected, 'Wrong permission for ' . $method . ' ' . $path);
    if (!in_array($expected, ['rooms.images', 'rooms.view'], true)) {
        try {
            require_permission($expected);
            throw new LogicException('Image-only account performed ' . $expected);
        } catch (RuntimeException $error) {
            account_check($error->getMessage() === '403' || $expected === 'rooms.view', 'Unexpected authorization failure');
        }
    }
}
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['rooms.view', 'bookings.view', 'door-codes.view', 'availability.view', 'sales.view', 'products.view', 'inventory.view', 'expenses.view', 'people-counter.view']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
foreach (array_keys(account_permission_labels()) as $permission) {
    account_check(can_use($permission) === str_ends_with($permission, '.view'), 'View-only grants must deny mutation: ' . $permission);
}
unset($_SESSION['account_id'], $_SESSION['account_version']);
try {
    save_account(['id' => $id, 'permissions' => ['accounts']], true);
    throw new LogicException('Root management grant accepted');
} catch (BookingException $error) {
    account_check($error->httpStatus === 422, 'Root-only management must not be grantable');
}
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['bookings.overview']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
account_check(can_use('bookings.overview') && can_use('bookings') && !can_use('bookings.view'), 'Overview-only grant must not expose booking list');
account_check(account_home() === '/admin/overview', 'Overview-only user must be able to open dashboard');
account_check(admin_api_permission('/api/admin/booking-overview', 'GET') === 'bookings.overview', 'Overview API must require its own permission');
try {
    require_permission(admin_api_permission('/api/admin/bookings', 'GET'));
    throw new LogicException('Overview-only user could read booking list');
} catch (RuntimeException $error) {
    account_check($error->getMessage() === '403', 'Booking list must deny overview-only user');
}
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['bookings.view']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
account_check(can_use('bookings.view') && !can_use('bookings.overview'), 'Booking list must not implicitly grant overview');
$now = strtotime('2026-10-23 12:00:00 UTC');
$row = ['status' => 'CONFIRMED', 'payment_status' => 'PAID', 'total_price' => 287000, 'is_test' => 0, 'check_in' => '2026-10-23 11:00:00', 'check_out' => '2026-10-23 13:00:00'];
$summary = booking_overview([$row, array_replace($row, ['is_test' => 1]), array_replace($row, ['status' => 'CANCELLED', 'total_price' => 100000])], $now);
account_check($summary === ['paid' => 287000, 'confirmed' => 0, 'staying' => 2, 'reconcile' => 1], 'Overview must retain lifecycle counts and exclude cancelled and test payments');
account_check(array_keys(booking_overview([])) === ['paid', 'confirmed', 'staying', 'reconcile'], 'Overview must return aggregates only');
account_check(admin_api_permission('/api/admin/room-operations/holds', 'GET') === 'availability.hold', 'Hold list must require hold permission, independently of calendar view permission');

account_check(admin_api_permission('/api/admin/sales', 'GET') === 'sales.view', 'History must require view permission');
account_check(admin_api_permission('/api/admin/sales', 'POST') === 'sales.create', 'Creating sale must require create permission');
account_check(admin_api_permission('/api/admin/products', 'PATCH') === 'products.edit', 'Editing product must require edit permission');
account_check(admin_api_permission('/api/admin/products', 'POST') === 'products.create', 'Creating product must require create permission');
account_check(admin_api_permission('/api/admin/inventory', 'GET') === 'inventory.view', 'Stock viewing needs inventory view grant');
account_check(admin_api_permission('/api/admin/inventory', 'POST') === 'inventory.receive', 'Receiving stock needs receive grant');
account_check(admin_api_permission('/api/admin/sales/a/cancel', 'POST') === 'sales.cancel', 'Cancellation requires separate permission');
account_check(admin_api_permission('/api/admin/sales/a/complete', 'POST') === 'sales.complete', 'Manual completion requires separate permission');
account_check(admin_api_permission('/api/admin/bookings/a/continuation', 'GET') === 'bookings.create', 'Continuation context requires booking creation permission');
account_check(!can_use('people-counter.view'), 'Booking-only staff must not gain camera reports');
unset($_SESSION['account_id'], $_SESSION['account_version']);
save_account(['id' => $id, 'permissions' => ['people-counter.view']], true);
$_SESSION['account_id'] = $id;
$_SESSION['account_version'] = 2;
account_check(can_use('people-counter.view') && !can_use('bookings.view'), 'Counter-only staff permissions must remain isolated');
account_check(account_home() === '/admin/people-counter', 'Counter-only staff landing page is wrong');

echo "Account authorization tests: OK\n";
