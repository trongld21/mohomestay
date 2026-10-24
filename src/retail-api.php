<?php

declare(strict_types=1);

require_once __DIR__ . '/retail.php';

function retail_api(string $path, string $method): never
{
    header('Cache-Control: no-store');
    $pdo = db();
    if ($path === '/api/admin/sales/catalog' && $method === 'GET') {
        json_response([
            'products' => $pdo->query('SELECT id,name,price,is_active FROM retail_products WHERE is_active=1 ORDER BY name,id')->fetchAll(),
            'rooms' => $pdo->query("SELECT id,name FROM rooms WHERE status='ACTIVE' AND is_active=1 ORDER BY sort_order,name,id")->fetchAll(),
        ]);
    }
    if ($path === '/api/admin/products' && $method === 'GET') {
        json_response(['products' => $pdo->query('SELECT id,name,price,is_active FROM retail_products ORDER BY name,id')->fetchAll()]);
    }
    if ($path === '/api/admin/products' && in_array($method, ['POST', 'PATCH'], true)) {
        require_csrf();
        $body = json_body();
        if ($method === 'POST') unset($body['id']);
        if ($method === 'PATCH' && empty($body['id'])) throw new BookingException('Thiếu mã sản phẩm.', 422);
        json_response(['product' => retail_save_product($pdo, $body)]);
    }
    if ($path === '/api/admin/sales' && $method === 'GET') {
        $page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $offset = ($page - 1) * 30;
        $search = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($search) > 150) throw new BookingException('Từ khóa tối đa 150 ký tự.', 422);
        $where = $search !== '' ? ' WHERE (room_name_snapshot LIKE ? OR sale_code LIKE ?)' : '';
        $params = $search !== '' ? ['%' . $search . '%', '%' . $search . '%'] : [];
        $count = $pdo->prepare('SELECT COUNT(*) FROM retail_sales' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $query = $pdo->prepare('SELECT id,sale_code,total,listed_total,status,created_by,created_at,paid_at,room_id,room_name_snapshot FROM retail_sales'
            . $where . ' ORDER BY created_at DESC,id DESC LIMIT 30 OFFSET ' . $offset);
        $query->execute($params);
        $rows = $query->fetchAll();
        json_response(['sales' => $rows, 'revenue' => retail_revenue($pdo), 'count' => $total, 'page' => $page]);
    }
    if ($path === '/api/admin/sales' && $method === 'POST') {
        require_csrf();
        $sale = retail_create_sale($pdo, json_body(), (string)current_account()['id'], can_use('sales.price'));
        try {
            $sale = retail_ensure_payment($pdo, $sale['id']);
            json_response(['sale' => $sale]);
        } catch (Throwable $error) {
            error_log('Retail QR creation failed for ' . $sale['id']);
            json_response(['sale' => $sale, 'paymentError' => 'Đơn đã lưu. Chưa tạo được QR; bấm Tạo lại QR trên đơn này.']);
        }
    }
    if (preg_match('#^/api/admin/sales/([a-f0-9]{32})(?:/(payment|check))?$#', $path, $match)) {
        $sale = retail_sale($pdo, $match[1]);
        if (!can_use('sales.view') && $sale['created_by'] !== (string)current_account()['id']) {
            throw new BookingException('Bạn không có quyền xem đơn của nhân viên khác.', 403);
        }
        if ($method === 'GET' && empty($match[2])) json_response(['sale' => $sale]);
        if ($method === 'POST' && !empty($match[2])) {
            require_csrf();
            $sale = $match[2] === 'payment' ? retail_ensure_payment($pdo, $sale['id']) : retail_refresh_payment($pdo, $sale['id']);
            json_response(['sale' => $sale]);
        }
    }
    throw new BookingException('Không tìm thấy API bán hàng.', 404);
}
