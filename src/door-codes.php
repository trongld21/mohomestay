<?php

declare(strict_types=1);

// Effective status is derived on every read: expiry never depends on cron or an open admin page.
function door_code_status(array $row, ?string $now = null): string
{
    $now ??= gmdate('Y-m-d H:i:s');
    if ($row['ends_at'] <= $now) return 'EXPIRED';
    if ($row['status'] === 'DISABLED') return 'DISABLED';
    return $row['starts_at'] > $now ? 'SCHEDULED' : 'ACTIVE';
}

function select_door_codes(array $rows, string $checkIn): array
{
    $priority = ['DAY' => 0, 'WEEK' => 1, 'MONTH' => 2];
    $eligible = array_values(array_filter($rows, static fn(array $row): bool =>
        $row['status'] === 'ACTIVE' && $row['starts_at'] <= $checkIn && $checkIn < $row['ends_at']
    ));
    if (!$eligible) return [];
    $best = min(array_map(static fn(array $row): int => $priority[$row['period']], $eligible));
    return array_values(array_filter($eligible, static fn(array $row): bool => $priority[$row['period']] === $best));
}

function validate_door_code(array $body): array
{
    $code = trim((string)($body['code'] ?? ''));
    $period = (string)($body['period'] ?? '');
    $status = (string)($body['status'] ?? '');
    if ($code === '' || mb_strlen($code) > 100 || preg_match('/[[:cntrl:]]/', $code)) {
        throw new BookingException('Mã mở cửa cần từ 1 đến 100 ký tự, không chứa ký tự điều khiển.', 422);
    }
    if (!in_array($period, ['DAY', 'WEEK', 'MONTH'], true) || !in_array($status, ['ACTIVE', 'DISABLED'], true)) {
        throw new BookingException('Loại mã hoặc trạng thái không hợp lệ.', 422);
    }
    $dates = [];
    foreach (['startsAt', 'endsAt'] as $key) {
        $value = (string)($body[$key] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
            throw new BookingException('Thời gian không hợp lệ. Vui lòng nhập đầy đủ ngày và giờ.', 422);
        }
        $dates[] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    if ($dates[1] <= $dates[0]) throw new BookingException('Thời gian kết thúc phải sau thời gian bắt đầu.', 422);
    return ['code' => $code, 'period' => $period, 'status' => $status, 'starts_at' => $dates[0], 'ends_at' => $dates[1]];
}

function booking_door_codes(string $checkIn): array
{
    $stmt = db()->prepare("SELECT code,period,status,starts_at,ends_at FROM door_codes WHERE status='ACTIVE' AND starts_at<=? AND ends_at>? ORDER BY created_at,id");
    $stmt->execute([$checkIn, $checkIn]);
    return select_door_codes($stmt->fetchAll(), $checkIn);
}

function delete_door_code(PDO $pdo, string $id): void
{
    $delete = $pdo->prepare('DELETE FROM door_codes WHERE id=?');
    $delete->execute([$id]);
    if ($delete->rowCount() === 0) {
        throw new BookingException('Không tìm thấy mã mở cửa.', 404);
    }
}

function admin_door_codes_api(string $method, ?string $id = null): never
{
    require_admin();
    if ($method === 'GET') {
        $rows = db()->query('SELECT * FROM door_codes ORDER BY starts_at DESC,created_at DESC,id')->fetchAll();
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as &$row) $row['effectiveStatus'] = door_code_status($row, $now);
        unset($row);
        $access = db()->query('SELECT a.*,b.booking_code,b.guest_name,b.status booking_status,b.confirmation_email_status email_status FROM booking_access_codes a JOIN bookings b ON b.id=a.booking_id ORDER BY a.created_at DESC LIMIT 100')->fetchAll();
        json_response([
            'manualCodes' => db()->query('SELECT * FROM manual_access_codes ORDER BY created_at DESC,id DESC LIMIT 100')->fetchAll(),
            'codes' => $rows, 'bookingCodes' => $access, 'csrf' => csrf_token(),
            'ttlockEnabled' => (bool)config('ttlock.enabled'), 'ttlockConfigured' => ttlock_configured(),
        ]);
    }
    require_csrf();
    if ($method === 'DELETE' && $id !== null) {
        delete_door_code(db(), $id);
        json_response(['ok' => true]);
    }
    $row = validate_door_code(json_body());
    if ($method === 'POST') {
        $id = uuid();
        db()->prepare('INSERT INTO door_codes(id,code,period,status,starts_at,ends_at) VALUES(?,?,?,?,?,?)')
            ->execute([$id, $row['code'], $row['period'], $row['status'], $row['starts_at'], $row['ends_at']]);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM door_codes WHERE id=? FOR UPDATE');
            $find->execute([$id]);
            if (!$find->fetchColumn()) {
                $pdo->rollBack();
                json_response(['error' => 'Không tìm thấy mã mở cửa.'], 404);
            }
            $pdo->prepare('UPDATE door_codes SET code=?,period=?,status=?,starts_at=?,ends_at=? WHERE id=?')
                ->execute([$row['code'], $row['period'], $row['status'], $row['starts_at'], $row['ends_at'], $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    json_response(['id' => $id], $method === 'POST' ? 201 : 200);
}
