<?php

declare(strict_types=1);

function booking_access_allowed(array $booking): bool
{
    return empty($booking['is_test']) && ($booking['payment_status'] ?? '') === 'PAID'
        && in_array($booking['status'] ?? '', ['CONFIRMED', 'CHECKED_IN'], true);
}

// Serialize each booking across webhook, lookup, cron, and administrator retries.
function sync_booking_access(string $bookingId, ?TTLockClient $client = null, bool $force = false): ?array
{
    if (!config('ttlock.enabled')) return null;
    if (!ttlock_configured()) return null;
    $pdo = db();
    $lockName = 'mo-access-' . $bookingId;
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) return null;
    try {
        $find = $pdo->prepare('SELECT * FROM booking_access_codes WHERE booking_id=?');
        $find->execute([$bookingId]);
        $row = $find->fetch();
        $bookingQuery = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
        $bookingQuery->execute([$bookingId]);
        $booking = $bookingQuery->fetch();
        if (!$booking) return null;
        $mode = $row['code_mode'] ?? strtoupper((string)config('ttlock.code_mode', 'GENERATED'));
        if (!in_array($mode, ['GENERATED', 'CUSTOM'], true)) {
            throw new TTLockException('TTLOCK_CODE_MODE phải là GENERATED hoặc CUSTOM.');
        }
        $window = $mode === 'CUSTOM' ? ttlock_window($booking) : ttlock_generated_window($booking);
        if (!$row) {
            if (!booking_access_allowed($booking) || $window['ends_at'] <= gmdate('Y-m-d H:i:s')) return null;
            $pin = $mode === 'CUSTOM' ? (string)random_int(10000000, 99999999) : null;
            $pdo->prepare('INSERT INTO booking_access_codes(booking_id,lock_id,code,starts_at,ends_at,code_mode) VALUES(?,?,?,?,?,?)')
                ->execute([$bookingId, (int)config('ttlock.lock_id'), $pin, $window['starts_at'], $window['ends_at'], $mode]);
            $find->execute([$bookingId]);
            $row = $find->fetch();
        }
        if (($row['code_mode'] ?? 'CUSTOM') === 'GENERATED' && $row['remote_id'] === null && $row['sync_status'] === 'PENDING') {
            $pdo->prepare('UPDATE booking_access_codes SET starts_at=?,ends_at=? WHERE booking_id=?')
                ->execute([$window['starts_at'], $window['ends_at'], $bookingId]);
            $row = array_replace($row, $window);
        }
        if (($row['code_mode'] ?? 'CUSTOM') === 'GENERATED' && $row['ends_at'] <= gmdate('Y-m-d H:i:s')) {
            $pdo->prepare("UPDATE booking_access_codes SET sync_status='REVOKED',sync_error=NULL WHERE booking_id=?")->execute([$bookingId]);
            return null;
        }
        $row['booking_code'] = (string)$booking['booking_code'];
        $eligible = booking_access_allowed($booking) && $row['ends_at'] > gmdate('Y-m-d H:i:s');
        if ($row['sync_status'] === 'REVOKED') return null;
        if (!$force && in_array($row['sync_status'], ['FAILED', 'UNKNOWN'], true)
            && $row['last_attempt_at'] !== null
            && strtotime($row['last_attempt_at'] . ' UTC') > time() - 60) return null;
        if ($eligible && $row['sync_status'] === 'READY') return $row;
        // Preserve uncertain attempts even if PHP exits while waiting for the network.
        $allowCreate = $row['sync_status'] !== 'UNKNOWN';
        $pdo->prepare("UPDATE booking_access_codes SET sync_status='UNKNOWN',attempts=attempts+1,last_attempt_at=UTC_TIMESTAMP(),sync_error=NULL WHERE booking_id=?")
            ->execute([$bookingId]);
        $mutationStarted = false;
        $pdo->beginTransaction();
        try {
            // Status changes wait until physical provisioning has finished.
            $bookingQuery = $pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');
            $bookingQuery->execute([$bookingId]);
            $booking = $bookingQuery->fetch();
            $eligible = $booking && booking_access_allowed($booking) && $row['ends_at'] > gmdate('Y-m-d H:i:s');
            $client ??= new TTLockClient(ttlock_options());
            if (!$eligible) {
                if (($row['code_mode'] ?? 'CUSTOM') === 'GENERATED') {
                    throw new TTLockException('Đơn đã hủy: mã tự sinh không thể xác nhận thu hồi từ xa khi không có gateway. Cần xóa qua Bluetooth tại khóa; mã vẫn có thể dùng đến hết hạn.');
                }
                $mutationStarted = true;
                $client->deletePasscode($row);
                $pdo->prepare("UPDATE booking_access_codes SET sync_status='REVOKED',sync_error=NULL WHERE booking_id=?")->execute([$bookingId]);
                $pdo->commit();
                return null;
            }
            $beforeCreate = static function () use (&$mutationStarted): void {
                $mutationStarted = true;
            };
            if (($row['code_mode'] ?? 'CUSTOM') === 'GENERATED') {
                $result = $client->ensureGeneratedPasscode($row, $allowCreate, $beforeCreate);
            } else {
                $result = ['remote_id' => $client->ensurePasscode($row, $allowCreate, $beforeCreate), 'code' => $row['code']];
            }
            $pdo->prepare("UPDATE booking_access_codes SET sync_status='READY',remote_id=?,code=?,sync_error=NULL WHERE booking_id=?")
                ->execute([$result['remote_id'], $result['code'], $bookingId]);
            $pdo->commit();
            $find->execute([$bookingId]);
            return $find->fetch() ?: null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $uncertain = !$allowCreate || ($mutationStarted && (!($e instanceof TTLockException) || $e->uncertain));
            $message = $e instanceof TTLockException ? $e->getMessage() : 'Không hoàn tất đồng bộ TTLock. Vui lòng kiểm tra và thử đối soát lại.';
            $pdo->prepare('UPDATE booking_access_codes SET sync_status=?,sync_error=? WHERE booking_id=?')
                ->execute([$uncertain ? 'UNKNOWN' : 'FAILED', $message, $bookingId]);
            return null;
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function admin_booking_access_retry(string $bookingId): never
{
    require_admin();
    require_csrf();
    if (!config('ttlock.enabled') || !ttlock_configured()) {
        json_response(['error' => 'TTLock chưa được bật hoặc chưa cấu hình đủ.'], 422);
    }
    sync_booking_access($bookingId, null, true);
    // A mail failure must reuse the same provisioned PIN.
    db()->prepare("UPDATE bookings SET confirmation_email_attempts=0 WHERE id=? AND confirmation_email_status='FAILED'")
        ->execute([$bookingId]);
    dispatch_confirmation_email($bookingId);
    json_response(['ok' => true]);
}
