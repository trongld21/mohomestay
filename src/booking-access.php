<?php

declare(strict_types=1);

function booking_access_allowed(array $booking): bool
{
    return empty($booking['is_test']) && ($booking['payment_status'] ?? '') === 'PAID'
        && in_array($booking['status'] ?? '', ['CONFIRMED', 'CHECKED_IN'], true);
}

// Serialize each booking across webhook, lookup, cron, and administrator retries.
function sync_booking_access(string $bookingId, ?TTLockClient $client = null, bool $force = false, bool $regenerate = false): ?array
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
        // Only an explicit administrator action may create after an uncertain attempt.
        // The client still scans TTLock completely and recovers existing codes first.
        $allowCreate = $row['sync_status'] !== 'UNKNOWN' || $regenerate;
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
            $uncertain = $row['sync_status'] === 'UNKNOWN' || ($mutationStarted && (!($e instanceof TTLockException) || $e->uncertain));
            if ($e instanceof TTLockException) {
                $message = $e->getMessage();
            } elseif ($e instanceof PDOException) {
                $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
                $message = $sqlState === '23000'
                    ? 'Không lưu được mã do trùng dữ liệu. Cần kiểm tra mã trên TTLock và các đơn khác trước khi thử lại.'
                    : 'Không lưu được kết quả TTLock vào cơ sở dữ liệu. Vui lòng thử đối soát lại.';
                error_log('Booking access persistence failed: booking=' . $bookingId . ' sqlstate=' . $sqlState);
            } else {
                $message = 'Không hoàn tất đồng bộ TTLock do lỗi xử lý. Vui lòng thử đối soát lại hoặc tạo lại mã nếu chưa có mã.';
                // Never log exception messages, request bodies, PINs or tokens.
                error_log('Booking access processing failed: booking=' . $bookingId . ' type=' . get_class($e));
            }
            $pdo->prepare('UPDATE booking_access_codes SET sync_status=?,sync_error=? WHERE booking_id=?')
                ->execute([$uncertain ? 'UNKNOWN' : 'FAILED', $message, $bookingId]);
            return null;
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

function retry_booking_access(string $bookingId, bool $regenerate = false, ?TTLockClient $client = null): array
{
    $find = db()->prepare('SELECT * FROM bookings WHERE id=?');
    $find->execute([$bookingId]);
    $booking = $find->fetch();
    if (!$booking) throw new BookingException('Không tìm thấy đơn đặt phòng.', 404);
    if ($regenerate && (!booking_access_allowed($booking) || ttlock_generated_window($booking)['ends_at'] <= gmdate('Y-m-d H:i:s'))) {
        throw new BookingException('Chỉ tạo lại mã cho đơn đã thanh toán, còn thời hạn và đang được xác nhận hoặc lưu trú.', 422);
    }
    $access = sync_booking_access($bookingId, $client, true, $regenerate);
    if ($access !== null && $access['sync_status'] === 'READY') return $access;

    $find = db()->prepare('SELECT * FROM booking_access_codes WHERE booking_id=?');
    $find->execute([$bookingId]);
    $row = $find->fetch();
    $message = $row['sync_error'] ?? null;
    throw new BookingException($message ?: 'Chưa cấp được mã. Đơn có thể đang được xử lý, đã hết hạn hoặc không đủ điều kiện. Vui lòng làm mới và kiểm tra lại.', 409);
}

function admin_booking_access_retry(string $bookingId, bool $regenerate = false): never
{
    require_admin();
    require_csrf();
    if (!config('ttlock.enabled') || !ttlock_configured()) {
        json_response(['error' => 'TTLock chưa được bật hoặc chưa cấu hình đủ.'], 422);
    }
    $access = retry_booking_access($bookingId, $regenerate);
    // A mail failure must reuse the same provisioned PIN.
    db()->prepare("UPDATE bookings SET confirmation_email_attempts=0 WHERE id=? AND confirmation_email_status='FAILED'")
        ->execute([$bookingId]);
    try {
        $emailSent = dispatch_confirmation_email($bookingId);
    } catch (Throwable $error) {
        $emailSent = false;
        error_log('Booking access email pending: booking=' . $bookingId . ' type=' . get_class($error));
    }
    json_response([
        'ok' => true,
        'accessCode' => $access,
        'message' => 'Mã đã sẵn sàng.' . ($emailSent ? ' Email đã gửi.' : ' Xem trạng thái email trong đơn.'),
    ]);
}
