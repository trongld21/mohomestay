<?php

declare(strict_types=1);

require_once __DIR__ . '/retail-stock-alerts.php';

function retail_integer(mixed $value, int $min, int $max): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) {
        throw new BookingException('Số lượng và giá phải là số nguyên hợp lệ.', 422);
    }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $min || $number > $max) {
        throw new BookingException('Số lượng hoặc giá vượt giới hạn cho phép.', 422);
    }
    return $number;
}

function retail_save_product(PDO $pdo, array $input): array
{
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    if ($name === '' || mb_strlen($name) > 150 || !is_bool($input['isActive'] ?? true)) {
        throw new BookingException('Nhập tên sản phẩm tối đa 150 ký tự và trạng thái hợp lệ.', 422);
    }
    $price = retail_integer($input['price'] ?? null, 1, 100000000);
    $active = ($input['isActive'] ?? true) ? 1 : 0;
    if (!is_bool($input['trackStock'] ?? true)) throw new BookingException('Trạng thái quản lý tồn không hợp lệ.', 422);
    $track = ($input['trackStock'] ?? true) ? 1 : 0;
    $id = (string)($input['id'] ?? '');
    if ($id === '') {
        $id = uuid();
        $pdo->prepare('INSERT INTO retail_products (id,name,price,is_active,track_stock) VALUES (?,?,?,?,?)')->execute([$id, $name, $price, $active, $track]);
    } else {
        $query = $pdo->prepare('SELECT id FROM retail_products WHERE id=?');
        $query->execute([$id]);
        if (!$query->fetchColumn()) throw new BookingException('Không tìm thấy sản phẩm.', 404);
        $pdo->prepare('UPDATE retail_products SET name=?,price=?,is_active=?,track_stock=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name, $price, $active, $track, $id]);
    }
    return ['id' => $id, 'name' => $name, 'price' => $price, 'is_active' => $active, 'track_stock' => $track];
}

function retail_receive_stock(PDO $pdo, array $input, string $actor): void
{
    $id = $input['requestId'] ?? '';
    $productId = $input['productId'] ?? '';
    $note = is_string($input['note'] ?? '') ? trim($input['note'] ?? '') : '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
        || !is_string($productId) || !preg_match('/^[a-f0-9]{32}$/D', $productId) || mb_strlen($note) > 255) {
        throw new BookingException('Thông tin phiếu nhập kho không hợp lệ.', 422);
    }
    $quantity = retail_integer($input['quantity'] ?? null, 1, 1000000);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM retail_products WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$productId]);
        $product = $query->fetch();
        if (!$product) throw new BookingException('Không tìm thấy sản phẩm.', 404);
        $existing = $pdo->prepare('SELECT * FROM retail_stock_movements WHERE id=?');
        $existing->execute([$id]);
        if ($row = $existing->fetch()) {
            if ($row['movement_type'] !== 'IN' || $row['product_id'] !== $productId || (int)$row['quantity_change'] !== $quantity
                || $row['created_by'] !== $actor || $row['note'] !== $note) {
                throw new BookingException('Mã phiếu nhập đã được sử dụng.', 409);
            }
            $pdo->commit();
            return;
        }
        if (!(bool)$product['track_stock']) throw new BookingException('Sản phẩm dịch vụ không quản lý tồn kho.', 422);
        $after = (int)$product['stock_quantity'] + $quantity;
        if ($after > 1000000000) throw new BookingException('Tồn kho vượt giới hạn.', 422);
        $pdo->prepare('UPDATE retail_products SET stock_quantity=? WHERE id=?')->execute([$after, $productId]);
        $pdo->prepare("INSERT INTO retail_stock_movements (id,product_id,product_name,movement_type,quantity_change,stock_after,reference_id,note,created_by) VALUES (?,?,?,'IN',?,?,?,?,?)")
            ->execute([$id, $productId, $product['name'], $quantity, $after, $id, $note, $actor]);
        retail_queue_low_stock_alert($pdo, $productId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_sale(PDO $pdo, string $id): array
{
    $query = $pdo->prepare('SELECT * FROM retail_sales WHERE id=?');
    $query->execute([$id]);
    $sale = $query->fetch();
    if (!$sale) throw new BookingException('Không tìm thấy đơn bán hàng.', 404);
    $query = $pdo->prepare('SELECT product_id,product_name,unit_price,quantity FROM retail_sale_items WHERE sale_id=? ORDER BY product_name');
    $query->execute([$id]);
    $sale['items'] = $query->fetchAll();
    return $sale;
}

function retail_active_booking(PDO $pdo, string $roomId, bool $lock = false): ?array
{
    $query = $pdo->prepare(
        "SELECT id,booking_code,check_in,check_out FROM bookings WHERE room_id=? "
        . "AND (status='CHECKED_IN' OR (status='CONFIRMED' AND check_in<=?)) "
        . 'ORDER BY check_in DESC,id DESC LIMIT 1' . ($lock ? room_operation_lock_suffix($pdo) : '')
    );
    $query->execute([$roomId, gmdate('Y-m-d H:i:s')]);
    return $query->fetch() ?: null;
}

function retail_create_sale(PDO $pdo, array $input, string $actor, bool $allowPrice, bool $allowDiscount = false): array
{
    $requestId = $input['requestId'] ?? '';
    if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)
        || !is_array($input['items'] ?? null) || count($input['items']) < 1 || count($input['items']) > 100) {
        throw new BookingException('Chọn từ 1 đến 100 sản phẩm và mã yêu cầu hợp lệ.', 422);
    }
    $items = [];
    foreach ($input['items'] as $item) {
        if (!is_array($item) || !is_string($item['productId'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $item['productId']) || isset($items[$item['productId']])) {
            throw new BookingException('Sản phẩm không hợp lệ hoặc bị lặp.', 422);
        }
        $items[$item['productId']] = retail_integer($item['quantity'] ?? null, 1, 999);
    }
    ksort($items);
    $discountPercent = retail_integer($input['discountPercent'] ?? 0, 0, 100);
    if ($discountPercent > 0 && !$allowDiscount) throw new BookingException('Bạn chưa có quyền giảm giá bán hàng.', 403);
    $totalRequested = isset($input['total']) ? retail_integer($input['total'], 1, 100000000) : null;
    $roomId = $input['roomId'] ?? '';
    if (!is_string($roomId) || ($roomId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $roomId))) {
        throw new BookingException('Phòng liên quan không hợp lệ.', 422);
    }
    $expectedBookingId = $input['bookingId'] ?? null;
    if ($expectedBookingId !== null && (!is_string($expectedBookingId)
        || ($expectedBookingId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $expectedBookingId)))) {
        throw new BookingException('Đơn đặt phòng liên quan không hợp lệ.', 422);
    }
    if ($roomId === '' && !empty($expectedBookingId)) {
        throw new BookingException('Chọn phòng trước khi liên kết đơn.', 422);
    }
    // Preserve retry identities for older clients sending an unassigned sale.
    $identity = [$items, $totalRequested];
    if ($roomId !== '') $identity[] = $roomId;
    if (!empty($expectedBookingId)) $identity[] = $expectedBookingId;
    if ($discountPercent > 0) $identity[] = ['discountPercent' => $discountPercent];
    $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id,request_hash,created_by FROM retail_sales WHERE request_id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$requestId]);
        if ($existing = $query->fetch()) {
            if ($existing['request_hash'] !== $hash || $existing['created_by'] !== $actor) {
                throw new BookingException('Mã yêu cầu đã được dùng cho đơn khác. Vui lòng tạo đơn mới.', 409);
            }
            $pdo->commit();
            return retail_sale($pdo, $existing['id']);
        }
        $roomName = null;
        $activeBooking = null;
        if ($roomId !== '') {
            $query = $pdo->prepare('SELECT name,status FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
            $query->execute([$roomId]);
            $room = $query->fetch();
            if (!$room || $room['status'] !== 'ACTIVE') {
                throw new BookingException('Phòng không tồn tại hoặc đang ẩn/ngừng nhận khách.', 422);
            }
            $roomName = (string)$room['name'];
            $activeBooking = retail_active_booking($pdo, $roomId, true);
            if ($expectedBookingId !== null && $expectedBookingId !== ($activeBooking['id'] ?? '')) {
                throw new BookingException('Đơn đang lưu trú của phòng vừa thay đổi. Làm mới và chọn lại phòng trước khi chốt.', 409);
            }
        }
        $snapshots = [];
        $listed = 0;
        foreach ($items as $productId => $quantity) {
            $query = $pdo->prepare('SELECT * FROM retail_products WHERE id=?' . room_operation_lock_suffix($pdo));
            $query->execute([$productId]);
            $product = $query->fetch();
            if (!$product || !(bool)$product['is_active']) throw new BookingException('Sản phẩm vừa ngừng bán. Vui lòng chọn lại.', 409);
            $listed += (int)$product['price'] * $quantity;
            if ($listed > 100000000) throw new BookingException('Tổng đơn tối đa 100.000.000đ.', 422);
            $snapshots[] = [$productId, $product['name'], (int)$product['price'], $quantity];
        }
        $total = $totalRequested ?? $listed;
        if (!$allowPrice && $total !== $listed) throw new BookingException('Bạn chưa có quyền chỉnh giá chốt hoặc giá sản phẩm vừa thay đổi.', 403);
        $subtotal = $total;
        $total = (int)round($subtotal * (100 - $discountPercent) / 100);
        $discountAmount = $subtotal - $total;
        $id = uuid();
        $code = 'BH-' . strtoupper(bin2hex(random_bytes(5)));
        $orderCode = random_int(700000000000000, 799999999999999);
        $pdo->prepare('INSERT INTO retail_sales (id,request_id,request_hash,sale_code,total,listed_total,order_code,created_by,room_id,room_name_snapshot,booking_id,booking_code_snapshot,subtotal,discount_percent,discount_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $requestId, $hash, $code, $total, $listed, $orderCode, $actor, $roomId !== '' ? $roomId : null, $roomName, $activeBooking['id'] ?? null, $activeBooking['booking_code'] ?? null, $subtotal, $discountPercent, $discountAmount]);
        $insert = $pdo->prepare('INSERT INTO retail_sale_items (sale_id,product_id,product_name,unit_price,quantity) VALUES (?,?,?,?,?)');
        foreach ($snapshots as $snapshot) $insert->execute([$id, ...$snapshot]);
        foreach ($items as $productId => $quantity) {
            $query = $pdo->prepare('SELECT name,track_stock,stock_quantity FROM retail_products WHERE id=?');
            $query->execute([$productId]);
            $product = $query->fetch();
            if (!(bool)$product['track_stock']) continue;
            $update = $pdo->prepare('UPDATE retail_products SET stock_quantity=stock_quantity-? WHERE id=? AND stock_quantity>=-1000000000+?');
            $update->execute([$quantity, $productId, $quantity]);
            if ($update->rowCount() !== 1) throw new BookingException('Số tồn kho vượt giới hạn hệ thống.', 409);
            $pdo->prepare("INSERT INTO retail_stock_movements (id,product_id,product_name,movement_type,quantity_change,stock_after,reference_id,note,created_by) VALUES (?,?,?,'SALE',?,?,?,?,?)")
                ->execute([uuid(), $productId, $product['name'], -$quantity, (int)$product['stock_quantity'] - $quantity, $id, $code, $actor]);
            retail_queue_low_stock_alert($pdo, $productId);
        }
        if ($total === 0) {
            $pdo->prepare("UPDATE retail_sales SET status='PAID',paid_at=?,completion_method='GIFT',completed_by=? WHERE id=?")
                ->execute([gmdate('Y-m-d H:i:s'), $actor, $id]);
        }
        $pdo->commit();
        return retail_sale($pdo, $id);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_payment_callback(PDO $pdo, array $data): bool
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM retail_sales WHERE order_code=?' . room_operation_lock_suffix($pdo));
        $query->execute([(string)($data['orderCode'] ?? '')]);
        $sale = $query->fetch();
        if (!$sale) {
            $pdo->commit();
            return false;
        }
        if (!isset($data['amount']) || (float)$data['amount'] !== (float)$sale['total']
            || !is_string($data['reference'] ?? null) || $data['reference'] === ''
            || (!empty($sale['payment_link_id']) && $sale['payment_link_id'] !== ($data['paymentLinkId'] ?? ''))) {
            throw new BookingException('Giao dịch không khớp đơn bán hàng.', 422);
        }
        if ($sale['status'] === 'CANCELLED') throw new BookingException('Đơn đã hủy. Cần đối soát giao dịch này.', 409);
        if ($sale['status'] !== 'PAID') {
            $pdo->prepare("UPDATE retail_sales SET status='PAID',transaction_reference=?,paid_at=?,completion_method='PAYOS' WHERE id=?")
                ->execute([$data['reference'], gmdate('Y-m-d H:i:s'), $sale['id']]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_create_payment(array $sale): array
{
    if (!config('payos.client_id') || !config('payos.api_key') || !config('payos.checksum_key') || !config('app_url')) {
        throw new BookingException('Chưa cấu hình payOS. Đơn đã lưu, có thể tạo QR lại sau.', 503);
    }
    $url = rtrim((string)config('app_url'), '/') . '/sale-payment';
    $fields = [
        'amount' => (int)$sale['total'], 'cancelUrl' => $url, 'returnUrl' => $url,
        'description' => $sale['sale_code'], 'orderCode' => (int)$sale['order_code'],
    ];
    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode($fields + ['signature' => payos_signature($fields)]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-client-id: ' . config('payos.client_id'), 'x-api-key: ' . config('payos.api_key')],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $response = json_decode((string)$raw, true);
    $data = $response['data'] ?? [];
    if ($http >= 200 && $http < 300 && ($response['code'] ?? '') === '00' && is_array($data)
        && payos_verify($data, (string)($response['signature'] ?? ''))
        && (string)($data['orderCode'] ?? '') === (string)$sale['order_code']
        && (int)($data['amount'] ?? -1) === (int)$sale['total'] && !empty($data['qrCode'])
        && parse_url((string)($data['checkoutUrl'] ?? ''), PHP_URL_HOST) === 'pay.payos.vn'
        && str_starts_with((string)$data['checkoutUrl'], 'https://')) {
        return $data;
    }
    // A previous POST may have succeeded before the connection timed out.
    $remote = payos_payment_status((string)$sale['order_code']);
    if ((string)($remote['orderCode'] ?? '') !== (string)$sale['order_code']
        || (int)($remote['amount'] ?? -1) !== (int)$sale['total']
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($remote['id'] ?? ''))
        || !in_array($remote['status'] ?? '', ['PENDING', 'PROCESSING', 'PAID'], true)) {
        throw new BookingException('Chưa tạo được QR. Đơn đã lưu, hãy thử lại từ lịch sử.', 503);
    }
    return $remote + ['checkoutUrl' => 'https://pay.payos.vn/web/' . $remote['id'], 'qrCode' => null, 'paymentLinkId' => $remote['id']];
}

function retail_ensure_payment(PDO $pdo, string $id, ?callable $create = null): array
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM retail_sales WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$id]);
        $sale = $query->fetch();
        if (!$sale) throw new BookingException('Không tìm thấy đơn.', 404);
        if ($sale['status'] === 'CANCELLED') throw new BookingException('Đơn đã hủy, không tạo QR.', 409);
        if ($sale['status'] !== 'PAID' && empty($sale['checkout_url'])) {
            $payment = ($create ?? 'retail_create_payment')($sale);
            $pdo->prepare('UPDATE retail_sales SET qr_code=?,checkout_url=?,payment_link_id=? WHERE id=?')
                ->execute([$payment['qrCode'] ?? null, $payment['checkoutUrl'], $payment['paymentLinkId'] ?? $payment['id'] ?? null, $id]);
        }
        $pdo->commit();
        return retail_sale($pdo, $id);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_refresh_payment(PDO $pdo, string $id): array
{
    $sale = retail_sale($pdo, $id);
    if ($sale['status'] !== 'PENDING') return $sale;
    $remote = payos_payment_status((string)$sale['order_code']);
    if (($remote['status'] ?? '') === 'PAID'
        && (string)($remote['orderCode'] ?? '') === (string)$sale['order_code']
        && (int)($remote['amount'] ?? -1) === (int)$sale['total']
        && (int)($remote['amountPaid'] ?? -1) >= (int)$sale['total']
        && !empty($remote['id'])) {
        retail_payment_callback($pdo, [
            'orderCode' => $sale['order_code'], 'amount' => $sale['total'],
            'paymentLinkId' => $remote['id'], 'reference' => 'PAYOS-' . $remote['id'],
        ]);
    }
    return retail_sale($pdo, $id);
}

function retail_revenue(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COALESCE(SUM(total),0) FROM retail_sales WHERE status='PAID'")->fetchColumn();
}
