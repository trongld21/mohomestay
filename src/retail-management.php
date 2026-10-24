<?php

declare(strict_types=1);

require_once __DIR__ . '/retail.php';

/** Close a pending payment link before stock is returned or staff records an earlier transfer. */
function retail_close_remote_payment(array $sale): void
{
    if ((int)$sale['total'] === 0) return;
    if (!config('payos.client_id') || !config('payos.api_key')) {
        if (empty($sale['checkout_url']) && empty($sale['payment_link_id'])) return;
        throw new BookingException('Chưa có cấu hình để đóng QR đang hoạt động.', 503);
    }
    $ch = curl_init('https://api-merchant.payos.vn/v2/payment-requests/' . $sale['order_code'] . '/cancel');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => json_encode(['cancellationReason' => 'Nhan vien chot hoac huy don ban hang']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-client-id: ' . config('payos.client_id'), 'x-api-key: ' . config('payos.api_key')],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $result = json_decode((string)$raw, true);
    if ($http === 404 && empty($sale['checkout_url']) && empty($sale['payment_link_id'])) return;
    $data = $result['data'] ?? [];
    if ($http >= 200 && $http < 300 && ($result['code'] ?? '') === '00'
        && ($data['status'] ?? '') === 'CANCELLED' && (float)($data['amountPaid'] ?? -1) === 0.0
        && (string)($data['orderCode'] ?? '') === (string)$sale['order_code']
        && (int)($data['amount'] ?? -1) === (int)$sale['total']) return;
    // A previous cancellation may have succeeded before the response/transaction failed.
    try {
        $remote = payos_payment_status((string)$sale['order_code']);
        if (($remote['status'] ?? '') === 'CANCELLED' && (float)($remote['amountPaid'] ?? -1) === 0.0
            && (string)($remote['orderCode'] ?? '') === (string)$sale['order_code']
            && (int)($remote['amount'] ?? -1) === (int)$sale['total']) return;
    } catch (Throwable $error) {
        // No confirmation: keep the local sale pending and stock reserved.
    }
    throw new BookingException('Chưa đóng được QR hoặc QR đã nhận tiền. Bấm Kiểm tra thanh toán trước khi thử lại.', 409);
}

function retail_cancel_sale(PDO $pdo, string $id, string $actor, string $reason, ?callable $closePayment = null, bool $allowPaid = false): array
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) throw new BookingException('Nhập lý do hủy tối đa 255 ký tự.', 422);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM retail_sales WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$id]);
        $sale = $query->fetch();
        if (!$sale) throw new BookingException('Không tìm thấy đơn.', 404);
        if ($sale['status'] === 'CANCELLED') {
            $pdo->commit();
            return retail_sale($pdo, $id);
        }
        if ($sale['status'] !== 'PENDING' && !($allowPaid && $sale['status'] === 'PAID')) throw new BookingException('Đơn đã thanh toán không thể hủy; cần đối soát hoàn tiền riêng.', 409);
        if ($sale['status'] === 'PENDING') {
            ($closePayment ?? 'retail_close_remote_payment')($sale);
        }
        $moves = $pdo->prepare("SELECT * FROM retail_stock_movements WHERE movement_type='SALE' AND reference_id=? ORDER BY product_id");
        $moves->execute([$id]);
        foreach ($moves->fetchAll() as $move) {
            $query = $pdo->prepare('SELECT name,stock_quantity FROM retail_products WHERE id=?' . room_operation_lock_suffix($pdo));
            $query->execute([$move['product_id']]);
            $product = $query->fetch();
            if (!$product) throw new BookingException('Sản phẩm của đơn không còn để hoàn kho.', 409);
            $quantity = -(int)$move['quantity_change'];
            $after = (int)$product['stock_quantity'] + $quantity;
            $pdo->prepare('UPDATE retail_products SET stock_quantity=? WHERE id=?')->execute([$after, $move['product_id']]);
            $pdo->prepare("INSERT INTO retail_stock_movements (id,product_id,product_name,movement_type,quantity_change,stock_after,reference_id,note,created_by) VALUES (?,?,?,'RETURN',?,?,?,?,?)")
                ->execute([uuid(), $move['product_id'], $product['name'], $quantity, $after, $id, 'Hoàn kho ' . $sale['sale_code'], $actor]);
            retail_queue_low_stock_alert($pdo, (string)$move['product_id']);
        }
        $pdo->prepare("UPDATE retail_sales SET status='CANCELLED',cancelled_by=?,cancelled_at=?,cancel_reason=? WHERE id=?")
            ->execute([$actor, gmdate('Y-m-d H:i:s'), $reason, $id]);
        $pdo->commit();
        return retail_sale($pdo, $id);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_complete_sale(PDO $pdo, string $id, string $actor, string $note, ?callable $closePayment = null): array
{
    $note = trim($note);
    if ($note === '' || mb_strlen($note) > 255) throw new BookingException('Nhập thông tin đối soát chuyển khoản (tối đa 255 ký tự).', 422);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT * FROM retail_sales WHERE id=?' . room_operation_lock_suffix($pdo));
        $query->execute([$id]);
        $sale = $query->fetch();
        if (!$sale) throw new BookingException('Không tìm thấy đơn.', 404);
        if ($sale['status'] === 'PAID') {
            $pdo->commit();
            return retail_sale($pdo, $id);
        }
        if ($sale['status'] !== 'PENDING') throw new BookingException('Đơn đã hủy không thể hoàn thành.', 409);
        ($closePayment ?? 'retail_close_remote_payment')($sale);
        $pdo->prepare("UPDATE retail_sales SET status='PAID',paid_at=?,completion_method='MANUAL_TRANSFER',completed_by=?,completion_note=? WHERE id=?")
            ->execute([gmdate('Y-m-d H:i:s'), $actor, $note, $id]);
        $pdo->commit();
        return retail_sale($pdo, $id);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function retail_history(PDO $pdo, array $filters): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    $from = (string)($filters['from'] ?? $today);
    $to = (string)($filters['to'] ?? $from);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
        || $end < $start || $end > $start->modify('+366 days')) {
        throw new BookingException('Chọn khoảng ngày hợp lệ, tối đa 367 ngày.', 422);
    }
    $status = (string)($filters['status'] ?? '');
    if (!in_array($status, ['', 'PENDING', 'PAID', 'CANCELLED'], true)) throw new BookingException('Trạng thái lọc không hợp lệ.', 422);
    $q = trim((string)($filters['q'] ?? ''));
    if (mb_strlen($q) > 150) throw new BookingException('Từ khóa tối đa 150 ký tự.', 422);
    $where = ' WHERE s.created_at>=? AND s.created_at<?';
    $params = [mysql_datetime($start), mysql_datetime($end->modify('+1 day'))];
    if ($status !== '') { $where .= ' AND s.status=?'; $params[] = $status; }
    if ($q !== '') {
        $where .= ' AND (s.room_name_snapshot LIKE ? OR s.sale_code LIKE ? OR s.booking_code_snapshot LIKE ? OR EXISTS (SELECT 1 FROM retail_sale_items f WHERE f.sale_id=s.id AND f.product_name LIKE ?))';
        array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
    }
    $roomId = (string)($filters['roomId'] ?? '');
    if ($roomId !== '') { $where .= ' AND s.room_id=?'; $params[] = $roomId; }
    $sort = ($filters['sort'] ?? 'oldest') === 'newest' ? 'DESC' : 'ASC';
    $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
    $count = $pdo->prepare('SELECT COUNT(*) FROM retail_sales s' . $where);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $query = $pdo->prepare('SELECT s.* FROM retail_sales s' . $where . ' ORDER BY s.created_at ' . $sort . ',s.id ' . $sort . ' LIMIT 30 OFFSET ' . (($page - 1) * 30));
    $query->execute($params);
    $sales = $query->fetchAll();
    $summary = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN s.status='PAID' THEN s.total ELSE 0 END),0) FROM retail_sales s" . $where);
    $summary->execute($params);
    $items = $pdo->prepare("SELECT i.product_id,i.product_name,SUM(i.quantity) quantity FROM retail_sale_items i JOIN retail_sales s ON s.id=i.sale_id" . $where . " AND s.status='PAID' GROUP BY i.product_id,i.product_name ORDER BY quantity DESC,i.product_name");
    $items->execute($params);
    $productSales = $items->fetchAll();
    return [
        'sales' => $sales, 'count' => $total, 'page' => $page, 'from' => $from, 'to' => $to,
        'revenue' => (int)$summary->fetchColumn(),
        'quantitySold' => (int)array_sum(array_column($productSales, 'quantity')), 'productSales' => $productSales,
    ];
}
