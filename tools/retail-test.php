<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/retail.php';

function retail_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function retail_reject(callable $operation, int $status): void
{
    try {
        $operation();
        throw new RuntimeException('Invalid retail operation accepted.');
    } catch (BookingException $error) {
        retail_check($error->httpStatus === $status, 'Incorrect error status.');
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE retail_products (id TEXT PRIMARY KEY,name TEXT,price INTEGER,is_active INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT);
CREATE TABLE rooms (id TEXT PRIMARY KEY,name TEXT,status TEXT);
CREATE TABLE bookings (id TEXT PRIMARY KEY,room_id TEXT,booking_code TEXT,status TEXT,check_in TEXT,check_out TEXT);
CREATE TABLE retail_sales (booking_id TEXT,booking_code_snapshot TEXT,room_id TEXT,room_name_snapshot TEXT,id TEXT PRIMARY KEY,request_id TEXT UNIQUE,request_hash TEXT,sale_code TEXT UNIQUE,total INTEGER,listed_total INTEGER,status TEXT DEFAULT 'PENDING',order_code INTEGER UNIQUE,created_by TEXT,qr_code TEXT,checkout_url TEXT,payment_link_id TEXT,transaction_reference TEXT UNIQUE,paid_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE retail_sale_items (sale_id TEXT,product_id TEXT,product_name TEXT,unit_price INTEGER,quantity INTEGER,PRIMARY KEY(sale_id,product_id));");
$product = retail_save_product($pdo, ['name' => 'Nước suối', 'price' => 15000, 'isActive' => true]);
$input = ['requestId' => str_repeat('a', 32), 'items' => [['productId' => $product['id'], 'quantity' => 2]], 'total' => 30000];
$sale = retail_create_sale($pdo, $input, 'staff', false);
retail_check((int)$sale['total'] === 30000 && $sale['status'] === 'PENDING', 'Create pending sale with server price.');
$ret = retail_create_sale($pdo, $input, 'staff', false);
retail_check($ret['id'] === $sale['id'], 'Retry must reuse the sale.');
retail_reject(fn() => retail_create_sale($pdo, array_replace($input, ['total' => 1]), 'staff', false), 409);
retail_reject(fn() => retail_create_sale($pdo, array_replace($input, ['requestId' => str_repeat('b', 32), 'total' => 1]), 'staff', false), 403);
retail_reject(fn() => retail_create_sale($pdo, array_replace($input, ['requestId' => str_repeat('c', 32), 'items' => [['productId' => $product['id'], 'quantity' => 0]]]), 'staff', true), 422);
retail_check(retail_revenue($pdo) === 0, 'Pending sale must not count as revenue.');
retail_save_product($pdo, ['id' => $product['id'], 'name' => 'New name', 'price' => 20000, 'isActive' => false]);
$stored = retail_sale($pdo, $sale['id']);
retail_check($stored['items'][0]['product_name'] === 'Nước suối' && (int)$stored['items'][0]['unit_price'] === 15000, 'Sale snapshots survive product edits.');
retail_reject(fn() => retail_create_sale($pdo, array_replace($input, ['requestId' => str_repeat('d', 32)]), 'staff', true), 409);
$count = 0;
$create = static function (array $sale) use (&$count): array {
    $count++;
    return ['qrCode' => 'qr', 'checkoutUrl' => 'https://pay.payos.vn/web/retail', 'paymentLinkId' => 'retail-link'];
};
retail_ensure_payment($pdo, $sale['id'], $create);
retail_ensure_payment($pdo, $sale['id'], $create);
retail_check($count === 1, 'Retry must reuse QR.');
$data = ['orderCode' => $sale['order_code'], 'amount' => 30000, 'paymentLinkId' => 'retail-link', 'reference' => 'SALE-TX'];
retail_reject(fn() => retail_payment_callback($pdo, array_replace($data, ['amount' => 1])), 422);
retail_check(retail_payment_callback($pdo, $data), 'Callback must match sale.');
retail_check(retail_payment_callback($pdo, $data), 'Duplicate callback must be idempotent.');
retail_check(retail_revenue($pdo) === 30000, 'Revenue counts the sale exactly once.');
retail_check(!retail_payment_callback($pdo, array_replace($data, ['orderCode' => 1])), 'Other order codes fall through.');
retail_save_product($pdo, ['id' => $product['id'], 'name' => 'Nước suối', 'price' => 20000, 'isActive' => true]);
$discount = retail_create_sale($pdo, array_replace($input, ['requestId' => str_repeat('e', 32), 'total' => 35000]), 'manager', true);
retail_check((int)$discount['total'] === 35000 && (int)$discount['listed_total'] === 40000, 'Authorized price override must preserve list total.');
retail_reject(fn() => retail_create_sale($pdo, array_replace($input, ['requestId' => str_repeat('e', 32), 'total' => 35000]), 'other', true), 409);
try {
    retail_ensure_payment($pdo, $discount['id'], static function (): never { throw new RuntimeException('Provider offline'); });
    throw new LogicException('Provider failure ignored.');
} catch (RuntimeException $error) {
    retail_check($error->getMessage() === 'Provider offline', 'Expected provider error.');
}
retail_check(retail_sale($pdo, $discount['id'])['status'] === 'PENDING', 'Provider outage must preserve the pending sale.');
retail_check(retail_revenue($pdo) === 30000, 'Unpaid price override must not increase revenue.');
retail_reject(fn() => retail_save_product($pdo, ['name' => '', 'price' => 10]), 422);
retail_reject(fn() => retail_save_product($pdo, ['name' => 'Bad', 'price' => -1]), 422);
$pdo->exec("INSERT INTO rooms VALUES ('pink','Pink','ACTIVE'),('hidden','Hidden','HIDDEN')");
$roomInput = array_replace($input, ['requestId' => str_repeat('f', 32), 'roomId' => 'pink', 'total' => 40000]);
$roomSale = retail_create_sale($pdo, $roomInput, 'staff', false);
retail_check($roomSale['room_id'] === 'pink' && $roomSale['room_name_snapshot'] === 'Pink', 'Sale must store the selected room.');
$pdo->exec("UPDATE rooms SET name='Renamed' WHERE id='pink'");
retail_check(retail_sale($pdo, $roomSale['id'])['room_name_snapshot'] === 'Pink', 'History must preserve the room name at sale time.');
retail_reject(fn() => retail_create_sale($pdo, array_replace($roomInput, ['roomId' => 'hidden']), 'staff', false), 409);
retail_reject(fn() => retail_create_sale($pdo, array_replace($roomInput, ['requestId' => str_repeat('1', 32), 'roomId' => 'missing']), 'staff', false), 422);
retail_reject(fn() => retail_create_sale($pdo, array_replace($roomInput, ['requestId' => str_repeat('2', 32), 'roomId' => 'hidden']), 'staff', false), 422);
$bookingId = str_repeat('9', 32);
$pdo->prepare("INSERT INTO bookings VALUES (?,'pink','MO-ACTIVE','CONFIRMED',?,?)")
    ->execute([$bookingId, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 3600)]);
$active = retail_active_booking($pdo, 'pink');
retail_check($active['id'] === $bookingId, 'Current confirmed stay must be identified.');
$linkedInput = array_replace($roomInput, ['requestId' => str_repeat('3', 32), 'bookingId' => $bookingId]);
$linked = retail_create_sale($pdo, $linkedInput, 'staff', false);
retail_check($linked['booking_id'] === $bookingId && $linked['booking_code_snapshot'] === 'MO-ACTIVE', 'Sale must snapshot the active booking code.');
$pdo->exec("UPDATE bookings SET status='CHECKED_OUT'");
retail_check(retail_active_booking($pdo, 'pink') === null, 'Completed stay must not be shown as active.');
retail_reject(fn() => retail_create_sale($pdo, array_replace($linkedInput, ['requestId' => str_repeat('4', 32)]), 'staff', false), 409);
retail_check(retail_sale($pdo, $linked['id'])['booking_code_snapshot'] === 'MO-ACTIVE', 'History retains booking link after checkout.');
echo "Retail pricing, permissions, snapshots, idempotency, QR, revenue, rooms and active bookings: OK\n";
