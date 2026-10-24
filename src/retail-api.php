<?php

declare(strict_types=1);

require_once __DIR__ . '/retail.php';
require_once __DIR__ . '/retail-management.php';

function retail_api(string $path, string $method): never
{
    header('Cache-Control: no-store');
    $pdo = db();
    try_dispatch_retail_stock_alerts($pdo);
    if ($path === '/api/admin/inventory' && $method === 'GET') {
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $offset = ($page - 1) * 30;
        $count = (int)$pdo->query('SELECT COUNT(*) FROM retail_stock_movements')->fetchColumn();
        json_response([
            'products' => $pdo->query('SELECT id,name,is_active,stock_quantity,track_stock FROM retail_products ORDER BY name,id')->fetchAll(),
            'movements' => $pdo->query('SELECT * FROM retail_stock_movements ORDER BY created_at DESC,id DESC LIMIT 30 OFFSET ' . $offset)->fetchAll(),
            'page' => $page, 'count' => $count,
        ]);
    }
    if ($path === '/api/admin/inventory' && $method === 'POST') {
        require_csrf();
        retail_receive_stock($pdo, json_body(), (string)current_account()['id']);
        try_dispatch_retail_stock_alerts($pdo);
        json_response(['ok' => true]);
    }
    if ($path === '/api/admin/sales/catalog' && $method === 'GET') {
        $rooms = $pdo->query("SELECT id,name FROM rooms WHERE status='ACTIVE' AND is_active=1 ORDER BY sort_order,name,id")->fetchAll();
        foreach ($rooms as &$room) {
            $room['activeBooking'] = retail_active_booking($pdo, (string)$room['id']);
        }
        unset($room);
        json_response([
            'products' => $pdo->query('SELECT id,name,price,is_active,stock_quantity,track_stock FROM retail_products WHERE is_active=1 ORDER BY name,id')->fetchAll(),
            'rooms' => $rooms,
        ]);
    }
    if ($path === '/api/admin/products' && $method === 'GET') {
        json_response(['products' => $pdo->query('SELECT id,name,price,is_active,stock_quantity,track_stock FROM retail_products ORDER BY name,id')->fetchAll()]);
    }
    if ($path === '/api/admin/products' && in_array($method, ['POST', 'PATCH'], true)) {
        require_csrf();
        $body = json_body();
        if ($method === 'POST') unset($body['id']);
        if ($method === 'PATCH' && empty($body['id'])) throw new BookingException('Thiếu mã sản phẩm.', 422);
        json_response(['product' => retail_save_product($pdo, $body)]);
    }
    if ($path === '/api/admin/sales' && $method === 'GET') {
        json_response(retail_history($pdo, $_GET));
    }
    if ($path === '/api/admin/sales' && $method === 'POST') {
        require_csrf();
        $sale = retail_create_sale($pdo, json_body(), (string)current_account()['id'], can_use('sales.price'), can_use('sales.discount'));
        try_dispatch_retail_stock_alerts($pdo);
        try {
            $sale = retail_ensure_payment($pdo, $sale['id']);
            json_response(['sale' => $sale]);
        } catch (Throwable $error) {
            error_log('Retail QR creation failed for ' . $sale['id']);
            json_response(['sale' => $sale, 'paymentError' => 'Đơn đã lưu. Chưa tạo được QR; bấm Tạo lại QR trên đơn này.']);
        }
    }
    if (preg_match('#^/api/admin/sales/([a-f0-9]{32})(?:/(payment|check|cancel|complete))?$#', $path, $match)) {
        $sale = retail_sale($pdo, $match[1]);
        if (!can_use('sales.view') && $sale['created_by'] !== (string)current_account()['id']) {
            throw new BookingException('Bạn không có quyền xem đơn của nhân viên khác.', 403);
        }
        if ($method === 'GET' && empty($match[2])) json_response(['sale' => $sale]);
        if ($method === 'POST' && !empty($match[2])) {
            require_csrf();
            if ($match[2] === 'cancel' || $match[2] === 'complete') {
                $body = json_body();
                $note = $body['note'] ?? '';
                if (!is_string($note)) throw new BookingException('Nội dung xác nhận không hợp lệ.', 422);
                $sale = $match[2] === 'cancel'
                    ? retail_cancel_sale($pdo, $sale['id'], (string)current_account()['id'], $note)
                    : retail_complete_sale($pdo, $sale['id'], (string)current_account()['id'], $note);
                try_dispatch_retail_stock_alerts($pdo);
            } else {
                $sale = $match[2] === 'payment' ? retail_ensure_payment($pdo, $sale['id']) : retail_refresh_payment($pdo, $sale['id']);
            }
            json_response(['sale' => $sale]);
        }
    }
    throw new BookingException('Không tìm thấy API bán hàng.', 404);
}
