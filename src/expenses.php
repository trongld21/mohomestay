<?php

declare(strict_types=1);

function expense_input(array $input): array
{
    $values = [];
    foreach (['category' => 150, 'recipient' => 150, 'note' => 2000] as $key => $limit) {
        if (!is_string($input[$key] ?? '')) {
            throw new BookingException('Thông tin phiếu chi không hợp lệ.', 422);
        }
        $values[$key] = trim($input[$key] ?? '');
        if (mb_strlen($values[$key]) > $limit) {
            throw new BookingException('Nội dung phiếu chi quá dài.', 422);
        }
    }
    $amount = $input['amount'] ?? null;
    if ((!is_int($amount) && !is_string($amount)) || filter_var($amount, FILTER_VALIDATE_INT) === false
        || (int)$amount < 1 || (int)$amount > 1000000000 || $values['category'] === '') {
        throw new BookingException('Nhập loại chi và số tiền nguyên từ 1 đến 1.000.000.000đ.', 422);
    }
    $time = $input['spentAt'] ?? '';
    $date = is_string($time) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $time, new DateTimeZone('Asia/Ho_Chi_Minh')) : false;
    if (!$date || $date->format('Y-m-d\TH:i') !== $time) {
        throw new BookingException('Thời gian chi không hợp lệ.', 422);
    }
    if (!is_bool($input['includeCashflow'] ?? null) || !is_string($input['requestId'] ?? null)
        || !preg_match('/^[a-f0-9]{32}$/', $input['requestId'])) {
        throw new BookingException('Yêu cầu lưu phiếu không hợp lệ.', 422);
    }
    return $values + [
        'amount' => (int)$amount, 'spent_at' => mysql_datetime($date),
        'include_cashflow' => (int)$input['includeCashflow'], 'request_id' => $input['requestId'],
    ];
}

function create_expense(PDO $pdo, array $input, string $actor): array
{
    $values = expense_input($input);
    $hash = hash('sha256', json_encode([$values, $actor], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $find = $pdo->prepare('SELECT * FROM expense_vouchers WHERE request_id=?');
    $find->execute([$values['request_id']]);
    $existing = $find->fetch();
    if (!$existing) {
        $row = $values + [
            'id' => uuid(), 'voucher_code' => 'PC-' . strtoupper(bin2hex(random_bytes(6))),
            'request_hash' => $hash, 'created_by' => $actor, 'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        try {
            $pdo->prepare('INSERT INTO expense_vouchers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
                ->execute(array_values($row));
            return $row;
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '23000') {
                throw $error;
            }
            $find->execute([$values['request_id']]);
            $existing = $find->fetch();
            if (!$existing) {
                throw $error;
            }
        }
    }
    if (!hash_equals($existing['request_hash'], $hash)) {
        throw new BookingException('Yêu cầu đã lưu với nội dung khác. Mở phiếu mới để tiếp tục.', 409);
    }
    return $existing;
}

function expense_history(PDO $pdo, array $filters): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    $from = $filters['from'] ?? $today;
    $to = $filters['to'] ?? $from;
    $start = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone) : false;
    $end = is_string($to) ? DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone) : false;
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
        || $end < $start || $end > $start->modify('+366 days')) {
        throw new BookingException('Chọn khoảng ngày hợp lệ, tối đa 367 ngày.', 422);
    }
    $q = $filters['q'] ?? '';
    if (!is_string($q) || mb_strlen($q) > 150) {
        throw new BookingException('Từ khóa tối đa 150 ký tự.', 422);
    }
    $where = ' WHERE spent_at>=? AND spent_at<?';
    $params = [mysql_datetime($start), mysql_datetime($end->modify('+1 day'))];
    if (trim($q) !== '') {
        $where .= ' AND (voucher_code LIKE ? OR category LIKE ? OR recipient LIKE ? OR note LIKE ?)';
        array_push($params, ...array_fill(0, 4, '%' . trim($q) . '%'));
    }
    $summary = $pdo->prepare('SELECT COUNT(*) count,COALESCE(SUM(amount),0) total,COALESCE(SUM(CASE WHEN include_cashflow=1 THEN amount ELSE 0 END),0) cashflow FROM expense_vouchers' . $where);
    $summary->execute($params);
    $totals = $summary->fetch();
    $page = max(1, min(100000, (int)($filters['page'] ?? 1)));
    $query = $pdo->prepare('SELECT * FROM expense_vouchers' . $where . ' ORDER BY spent_at ASC,id ASC LIMIT 30 OFFSET ' . (($page - 1) * 30));
    $query->execute($params);
    return ['vouchers' => $query->fetchAll(), 'count' => (int)$totals['count'], 'total' => (int)$totals['total'], 'cashflow' => (int)$totals['cashflow'], 'page' => $page];
}

function expenses_api(string $method): never
{
    header('Cache-Control: no-store');
    if ($method === 'GET') {
        json_response(expense_history(db(), $_GET));
    }
    if ($method === 'POST') {
        require_csrf();
        json_response(['voucher' => create_expense(db(), json_body(), (string)current_account()['id'])], 201);
    }
    json_response(['error' => 'Phương thức không được hỗ trợ.'], 405);
}
