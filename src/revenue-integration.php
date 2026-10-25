<?php

declare(strict_types=1);

function mo_revenue_authorized(string $expected, string $provided): bool
{
    return strlen($expected) >= 32 && strlen($provided) >= 32 && hash_equals($expected, $provided);
}

function mo_revenue_dates(string $from, string $to): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
        || $start > $end || $start->diff($end)->days > 92) {
        throw new InvalidArgumentException('Chọn khoảng ngày hợp lệ, tối đa 93 ngày.');
    }
    return [$start, $end->modify('+1 day')];
}

function mo_revenue_report(PDO $pdo, string $from, string $to): array
{
    [$start, $end] = mo_revenue_dates($from, $to);
    $metrics = array_fill_keys(['room', 'retail', 'surcharge', 'collected', 'expense', 'cash', 'transfer', 'other', 'bookings'], 0.0);
    $days = [];
    for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
        $key = $date->format('Y-m-d');
        $days[$key] = ['date' => $key, 'totals' => $metrics, 'transactions' => []];
    }
    $utc = new DateTimeZone('UTC');
    $params = [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    // The payment record is the receipt. Never add booking.total_price on top of it.
    $queries = [
        'room' => "SELECT p.paid_at AS happened_at,p.amount,b.booking_code AS code,r.name AS room,p.payment_method AS method
            FROM payments p JOIN bookings b ON b.id=p.booking_id LEFT JOIN rooms r ON r.id=b.room_id
            WHERE p.status='PAID' AND b.payment_status='PAID' AND b.status<>'CANCELLED' AND b.is_test=0
            AND p.paid_at>=? AND p.paid_at<?",
        'surcharge' => "SELECT c.paid_at AS happened_at,c.amount,b.booking_code AS code,r.name AS room,'payos' AS method
            FROM booking_checkout_charges c JOIN bookings b ON b.id=c.booking_id LEFT JOIN rooms r ON r.id=b.room_id
            WHERE c.payment_status='PAID' AND b.status<>'CANCELLED' AND b.is_test=0 AND c.paid_at>=? AND c.paid_at<?",
        'retail' => "SELECT paid_at AS happened_at,total AS amount,sale_code AS code,room_name_snapshot AS room,completion_method AS method
            FROM retail_sales WHERE status='PAID' AND paid_at>=? AND paid_at<?",
        'expense' => "SELECT spent_at AS happened_at,amount,voucher_code AS code,category AS room,'' AS method
            FROM expense_vouchers WHERE include_cashflow=1 AND spent_at>=? AND spent_at<?",
    ];
    foreach ($queries as $type => $query) {
        $statement = $pdo->prepare($query);
        $statement->execute($params);
        while ($row = $statement->fetch()) {
            $time = new DateTimeImmutable($row['happened_at'], $utc);
            $key = $time->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
            $amount = (float)$row['amount'];
            $days[$key]['totals'][$type] += $amount;
            $method = strtolower((string)$row['method']);
            $payment = in_array($method, ['cash', 'manual_cash'], true) ? 'cash'
                : (in_array($method, ['payos', 'transfer', 'bank_transfer', 'manual_transfer'], true) ? 'transfer' : 'other');
            if ($type !== 'expense') {
                $days[$key]['totals']['collected'] += $amount;
                $days[$key]['totals'][$payment] += $amount;
            }
            if ($type === 'room') $days[$key]['totals']['bookings']++;
            $days[$key]['transactions'][] = ['type' => $type, 'code' => (string)$row['code'],
                'room' => (string)($row['room'] ?? ''), 'amount' => $amount,
                'time' => $time->format(DATE_ATOM), 'payment' => $type === 'expense' ? null : $payment];
        }
    }
    $totals = $metrics;
    foreach ($days as &$day) {
        foreach ($day['totals'] as $key => $value) $totals[$key] += $value;
        usort($day['transactions'], static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));
    }
    unset($day);
    return ['version' => 1, 'source' => 'mohomestay', 'from' => $from, 'to' => $to,
        'updatedAt' => gmdate(DATE_ATOM), 'totals' => $totals, 'days' => array_reverse(array_values($days))];
}

function mo_revenue_api(string $method): never
{
    header('Cache-Control: no-store');
    if ($method !== 'GET') json_response(['error' => 'Method not allowed'], 405);
    if (!mo_revenue_authorized(trim((string)env_value('REVENUE_INTEGRATION_KEY', '')),
        (string)($_SERVER['HTTP_X_REVENUE_KEY'] ?? ''))) {
        json_response(['error' => 'Unauthorized'], 401);
    }
    try {
        $from = $_GET['from'] ?? '';
        $to = $_GET['to'] ?? '';
        if (!is_string($from) || !is_string($to)) throw new InvalidArgumentException('Bộ lọc không hợp lệ.');
        mo_revenue_dates($from, $to);
        json_response(mo_revenue_report(db(), $from, $to));
    } catch (InvalidArgumentException $error) {
        json_response(['error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        json_response(['error' => 'Chưa tải được dữ liệu.'], 503);
    }
}
