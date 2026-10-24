<?php

declare(strict_types=1);

require __DIR__ . '/retail-test.php';
require_once APP_ROOT . '/src/retail-management.php';

$product = retail_save_product($pdo, ['name' => 'Gift water', 'price' => 10000, 'isActive' => true]);
$input = ['requestId' => str_repeat('a', 31) . '2', 'items' => [['productId' => $product['id'], 'quantity' => 2]], 'total' => 20000, 'discountPercent' => 100];
retail_reject(fn() => retail_create_sale($pdo, $input, 'cashier', false, false), 403);
$gift = retail_create_sale($pdo, $input, 'cashier', false, true);
retail_check((int)$gift['total'] === 0 && $gift['status'] === 'PAID' && $gift['completion_method'] === 'GIFT', '100% gift completes without a zero-value QR.');
retail_check((int)$gift['discount_amount'] === 20000, 'Gift discount is audited.');
$input['requestId'] = str_repeat('b', 31) . '2';
$input['discountPercent'] = 25;
$sale = retail_create_sale($pdo, $input, 'cashier', false, true);
retail_check((int)$sale['total'] === 15000, 'Percent discount uses the authoritative subtotal.');
$stock = (int)$pdo->query("SELECT stock_quantity FROM retail_products WHERE id='" . $product['id'] . "'")->fetchColumn();
retail_cancel_sale($pdo, $sale['id'], 'manager', 'Khách đổi ý', static function (): void {});
retail_cancel_sale($pdo, $sale['id'], 'manager', 'Retry', static function (): void {});
retail_check(retail_sale($pdo, $sale['id'])['status'] === 'CANCELLED', 'Pending sale can be cancelled.');
retail_check((int)$pdo->query("SELECT stock_quantity FROM retail_products WHERE id='" . $product['id'] . "'")->fetchColumn() === $stock + 2, 'Cancellation restores deducted stock once.');
retail_reject(fn() => retail_payment_callback($pdo, ['orderCode' => $sale['order_code'], 'amount' => 15000, 'reference' => 'LATE']), 409);
$input['requestId'] = str_repeat('c', 31) . '2';
$manual = retail_create_sale($pdo, $input, 'cashier', false, true);
retail_complete_sale($pdo, $manual['id'], 'manager', 'Đã kiểm tra ngân hàng', static function (): void {});
retail_complete_sale($pdo, $manual['id'], 'manager', 'Retry', static function (): void {});
retail_check(retail_sale($pdo, $manual['id'])['completion_method'] === 'MANUAL_TRANSFER', 'Manual transfer completion is audited.');
retail_reject(fn() => retail_cancel_sale($pdo, $manual['id'], 'manager', 'Paid', static function (): void {}), 409);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
$dayStart = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
foreach ([[$gift['id'], 0], [$manual['id'], 1], [$sale['id'], 2]] as [$rowId, $hours]) {
    $pdo->prepare('UPDATE retail_sales SET created_at=? WHERE id=?')->execute([mysql_datetime($dayStart->modify('+' . $hours . ' hours')), $rowId]);
}
$history = retail_history($pdo, ['from' => $today, 'to' => $today, 'q' => 'Gift water']);
retail_check($history['sales'][0]['id'] === $gift['id'], 'Default order is oldest first at the Vietnam day boundary.');
retail_check($history['count'] === 3, 'Search matches sale item names.');
retail_check($history['quantitySold'] === 4 && $history['revenue'] === 15000, 'Filtered counts include paid and gifted units, exclude cancelled.');
$filtered = retail_history($pdo, ['from' => $today, 'to' => $today, 'status' => 'CANCELLED', 'q' => 'Gift water']);
retail_check($filtered['count'] === 1 && $filtered['quantitySold'] === 0, 'Status filter is applied to quantity statistics.');
$defaultDay = retail_history($pdo, ['q' => 'Gift water']);
retail_check($defaultDay['from'] === $today && $defaultDay['to'] === $today, 'Default date must be today in Vietnam.');
retail_reject(fn() => retail_history($pdo, ['from' => '2026-02-30']), 422);
$input['requestId'] = str_repeat('d', 31) . '2';
$blocked = retail_create_sale($pdo, $input, 'cashier', false, true);
try {
    retail_cancel_sale($pdo, $blocked['id'], 'manager', 'Fail', static function (): never { throw new RuntimeException('Provider offline'); });
    throw new LogicException('Provider error ignored.');
} catch (RuntimeException $error) {
    retail_check($error->getMessage() === 'Provider offline', 'Expected remote cancellation failure.');
}
retail_check(retail_sale($pdo, $blocked['id'])['status'] === 'PENDING', 'Unconfirmed remote cancellation cannot release stock or close the local sale.');
echo "Retail discounts, gifts, cancellation stock return, manual completion and history filters: OK\n";
