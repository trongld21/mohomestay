<?php

declare(strict_types=1);

function booking_access_allowed(array $booking): bool
{
    return ($booking['payment_status'] ?? '') === 'PAID'
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
        $window = ttlock_window($booking);
        if (!$row) {
            if (!booking_access_allowed($booking) || $window['ends_at'] <= gmdate('Y-m-d H:i:s')) return null;
            $pin = (string)random_int(10000000, 99999999);
            $pdo->prepare('INSERT INTO booking_access_codes(booking_id,lock_id,code,starts_at,ends_at) VALUES(?,?,?,?,?)')
                ->execute([$bookingId, (int)config('ttlock.lock_id'), $pin, $window['starts_at'], $window['ends_at']]);
            $find->execute([$bookingId]);
            $row = $find->fetch();
        }
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
                $mutationStarted = true;
                $client->deletePasscode($row);
                $pdo->prepare("UPDATE booking_access_codes SET sync_status='REVOKED',sync_error=NULL WHERE booking_id=?")->execute([$bookingId]);
                $pdo->commit();
                return null;
            }
            $remoteId = $client->ensurePasscode($row, $allowCreate, static function () use (&$mutationStarted): void {
                $mutationStarted = true;
            });
            $pdo->prepare("UPDATE booking_access_codes SET sync_status='READY',remote_id=?,sync_error=NULL WHERE booking_id=?")
                ->execute([$remoteId, $bookingId]);
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
