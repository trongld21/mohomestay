<?php

declare(strict_types=1);

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
    $id = (string)($input['id'] ?? '');
    if ($id === '') {
        $id = uuid();
        $pdo->prepare('INSERT INTO retail_products (id,name,price,is_active) VALUES (?,?,?,?)')->execute([$id, $name, $price, $active]);
    } else {
        $query = $pdo->prepare('SELECT id FROM retail_products WHERE id=?');
        $query->execute([$id]);
        if (!$query->fetchColumn()) throw new BookingException('Không tìm thấy sản phẩm.', 404);
        $pdo->prepare('UPDATE retail_products SET name=?,price=?,is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name, $price, $active, $id]);
    }
    return ['id' => $id, 'name' => $name, 'price' => $price, 'is_active' => $active];
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

function retail_create_sale(PDO $pdo, array $input, string $actor, bool $allowPrice): array
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
    $totalRequested = isset($input['total']) ? retail_integer($input['total'], 1, 100000000) : null;
    $roomId = $input['roomId'] ?? '';
    if (!is_string($roomId) || ($roomId !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $roomId))) {
        throw new BookingException('Phòng liên quan không hợp lệ.', 422);
    }
    // Preserve retry identities for older clients sending an unassigned sale.
    $identity = [$items, $totalRequested];
    if ($roomId !== '') $identity[] = $roomId;
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
        if ($roomId !== '') {
            $query = $pdo->prepare('SELECT name,status FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
            $query->execute([$roomId]);
            $room = $query->fetch();
            if (!$room || $room['status'] !== 'ACTIVE') {
                throw new BookingException('Phòng không tồn tại hoặc đang ẩn/ngừng nhận khách.', 422);
            }
            $roomName = (string)$room['name'];
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
        $id = uuid();
        $code = 'BH-' . strtoupper(bin2hex(random_bytes(5)));
        $orderCode = random_int(700000000000000, 799999999999999);
        $pdo->prepare('INSERT INTO retail_sales (id,request_id,request_hash,sale_code,total,listed_total,order_code,created_by,room_id,room_name_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $requestId, $hash, $code, $total, $listed, $orderCode, $actor, $roomId !== '' ? $roomId : null, $roomName]);
        $insert = $pdo->prepare('INSERT INTO retail_sale_items (sale_id,product_id,product_name,unit_price,quantity) VALUES (?,?,?,?,?)');
        foreach ($snapshots as $snapshot) $insert->execute([$id, ...$snapshot]);
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
        if ($sale['status'] !== 'PAID') {
            $pdo->prepare("UPDATE retail_sales SET status='PAID',transaction_reference=?,paid_at=? WHERE id=?")
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
    if ($sale['status'] === 'PAID') return $sale;
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
