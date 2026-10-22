<?php

declare(strict_types=1);

function validate_manual_access(array $body): array
{
    $id = (string)($body['id'] ?? '');
    $name = trim((string)($body['name'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $name === '' || mb_strlen($name) > 50 || preg_match('/[[:cntrl:]]/', $name)) {
        throw new BookingException('Tên mã cần từ 1 đến 50 ký tự và yêu cầu tạo mã phải hợp lệ.', 422);
    }
    $dates = validate_door_code(array_merge($body, ['code' => 'validation', 'period' => 'DAY', 'status' => 'ACTIVE']));
    $start = strtotime($dates['starts_at'] . ' UTC');
    $end = strtotime($dates['ends_at'] . ' UTC');
    $start = (int)(floor($start / 3600) * 3600);
    $end = (int)(ceil($end / 3600) * 3600);
    if ($start < (int)(floor(time() / 3600) * 3600) || $end <= time() || $end - $start > 365 * 86400 || $end > time() + 365 * 86400) {
        throw new BookingException('Giờ bắt đầu không được ở quá khứ; thời hạn tối đa 365 ngày tới.', 422);
    }
    return [
        'id' => $id, 'name' => $name,
        'starts_at' => gmdate('Y-m-d H:i:s', (int)(floor($start / 3600) * 3600)),
        'ends_at' => gmdate('Y-m-d H:i:s', (int)(ceil($end / 3600) * 3600)),
    ];
}

function sync_manual_access(PDO $pdo, string $id, TTLockClient $client): array
{
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute(['mo-manual-' . $id]);
    if ((int)$lock->fetchColumn() !== 1) throw new BookingException('Mã đang được xử lý. Vui lòng làm mới danh sách.', 409);
    try {
        $find = $pdo->prepare('SELECT * FROM manual_access_codes WHERE id=?');
        $find->execute([$id]);
        $row = $find->fetch();
        if (!$row) throw new BookingException('Không tìm thấy yêu cầu tạo mã.', 404);
        if ($row['sync_status'] === 'READY') return $row;
        if ($row['ends_at'] <= gmdate('Y-m-d H:i:s')) throw new BookingException('Thời hạn mã đã kết thúc.', 422);
        $mutationStarted = false;
        try {
            // Immutable identity makes retry/reconciliation independent of display-name collisions.
            $remote = $client->ensureGeneratedPasscode($row + [
                'booking_id' => $id, 'booking_code' => $row['name'] . ' [WEB-' . $id . ']',
            ], $row['sync_status'] !== 'UNKNOWN', function () use ($pdo, $id, &$mutationStarted): void {
                $pdo->prepare("UPDATE manual_access_codes SET sync_status='UNKNOWN',sync_error=NULL WHERE id=?")->execute([$id]);
                $mutationStarted = true;
            });
            $pdo->prepare("UPDATE manual_access_codes SET code=?,remote_id=?,sync_status='READY',sync_error=NULL WHERE id=?")
                ->execute([$remote['code'], $remote['remote_id'], $id]);
        } catch (TTLockException $error) {
            $unknown = $row['sync_status'] === 'UNKNOWN' || ($mutationStarted && $error->uncertain);
            $pdo->prepare('UPDATE manual_access_codes SET sync_status=?,sync_error=? WHERE id=?')
                ->execute([$unknown ? 'UNKNOWN' : 'FAILED', $error->getMessage(), $id]);
        }
        $find->execute([$id]);
        return $find->fetch();
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['mo-manual-' . $id]);
    }
}

function admin_manual_access_api(?string $id = null): never
{
    require_admin();
    require_csrf();
    if (!config('ttlock.enabled') || !ttlock_configured()) throw new BookingException('TTLock chưa bật hoặc thiếu cấu hình.', 422);
    $pdo = db();
    if ($id === null) {
        $row = validate_manual_access(json_body());
        $id = $row['id'];
        $pdo->prepare('INSERT IGNORE INTO manual_access_codes(id,name,lock_id,starts_at,ends_at) VALUES(?,?,?,?,?)')
            ->execute([$id, $row['name'], ttlock_options()['lock_id'], $row['starts_at'], $row['ends_at']]);
    }
    $row = sync_manual_access($pdo, $id, new TTLockClient(ttlock_options()));
    json_response(['manualCode' => $row]);
}
