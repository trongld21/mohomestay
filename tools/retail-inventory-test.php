<?php

declare(strict_types=1);

require __DIR__ . '/retail-test.php';

$product = retail_save_product($pdo, ['name' => 'Coca', 'price' => 15000, 'isActive' => true]);
$receipt = ['requestId' => str_repeat('5', 32), 'productId' => $product['id'], 'quantity' => 5, 'note' => 'Nhập lô đầu'];
retail_receive_stock($pdo, $receipt, 'storekeeper');
retail_receive_stock($pdo, $receipt, 'storekeeper');
$stock = static function () use ($pdo, $product): int {
    $query = $pdo->prepare('SELECT stock_quantity FROM retail_products WHERE id=?');
    $query->execute([$product['id']]);
    return (int)$query->fetchColumn();
};
retail_check($stock() === 5, 'Repeated receipt must not double inventory.');
retail_reject(fn() => retail_receive_stock($pdo, array_replace($receipt, ['quantity' => 6]), 'storekeeper'), 409);
$input = ['requestId' => str_repeat('6', 32), 'items' => [['productId' => $product['id'], 'quantity' => 2]], 'total' => 30000];
$sale = retail_create_sale($pdo, $input, 'seller', false);
retail_create_sale($pdo, $input, 'seller', false);
retail_check($stock() === 3, 'Sale and retry must decrement once.');
$oversellInput = array_replace($input, ['requestId' => str_repeat('7', 32), 'items' => [['productId' => $product['id'], 'quantity' => 4]], 'total' => 60000]);
retail_create_sale($pdo, $oversellInput, 'seller', false);
retail_create_sale($pdo, $oversellInput, 'seller', false);
retail_check($stock() === -1, 'Overselling must result in negative stock exactly once.');
retail_payment_callback($pdo, ['orderCode' => $sale['order_code'], 'amount' => 30000, 'reference' => 'STOCK-TX']);
retail_check($stock() === -1, 'Payment must not decrement stock again.');
$service = retail_save_product($pdo, ['name' => 'Late fee', 'price' => 50000, 'isActive' => true, 'trackStock' => false]);
retail_create_sale($pdo, ['requestId' => str_repeat('0', 32), 'items' => [['productId' => $service['id'], 'quantity' => 1]], 'total' => 50000], 'seller', false);
retail_check((int)$pdo->query("SELECT COUNT(*) FROM retail_stock_movements WHERE movement_type='SALE' AND product_id='" . $product['id'] . "'")->fetchColumn() === 2, 'Audit must contain both stock deductions.');
$other = retail_save_product($pdo, ['name' => 'No stock', 'price' => 1000, 'isActive' => true]);
retail_create_sale($pdo, [
    'requestId' => str_repeat('a', 31) . '1',
    'items' => [['productId' => $product['id'], 'quantity' => 1], ['productId' => $other['id'], 'quantity' => 1]],
    'total' => 16000,
], 'seller', false);
retail_check($stock() === -2, 'Products at zero or negative stock must remain sellable.');
retail_receive_stock($pdo, array_replace($receipt, ['requestId' => str_repeat('c', 31) . '1', 'quantity' => 5]), 'storekeeper');
retail_check($stock() === 3, 'Receiving stock must offset the negative balance.');
retail_reject(fn() => retail_receive_stock($pdo, array_replace($receipt, ['requestId' => str_repeat('b', 31) . '1', 'quantity' => 0]), 'storekeeper'), 422);
echo "Inventory receipts, audit, service items, negative stock and sale/payment retries: OK\n";
