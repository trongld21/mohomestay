<?php

declare(strict_types=1);

function pending_booking_continuation(PDO $pdo, string $parentId): ?array
{
    $query = $pdo->prepare("SELECT * FROM bookings WHERE continued_from_booking_id=? AND status='PENDING' AND payment_method='payos' AND hold_expires_at>? LIMIT 1");
    $query->execute([$parentId, gmdate('Y-m-d H:i:s')]);
    return $query->fetch() ?: null;
}

function booking_continuation_identity(PDO $pdo, array $parent): array
{
    $seen = [];
    $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
    while (!empty($parent['continued_from_booking_id']) && !isset($seen[$parent['id']])) {
        $seen[$parent['id']] = true;
        $query->execute([$parent['continued_from_booking_id']]);
        $previous = $query->fetch();
        if (!$previous || $previous['room_id'] !== $parent['room_id'] || $previous['guest_phone'] !== $parent['guest_phone']) break;
        $parent = $previous;
    }
    $images = $pdo->prepare('SELECT side FROM booking_identity_images WHERE booking_id=?');
    $images->execute([$parent['id']]);
    return [
        'id' => $parent['id'],
        'code' => $parent['booking_code'],
        'missing' => array_values(array_diff(['front', 'back'], $images->fetchAll(PDO::FETCH_COLUMN))),
    ];
}

/** A continuation is always an explicit staff action for the existing guest. */
function prepare_booking_continuation(PDO $pdo, array $input): array
{
    $parentId = $input['continuedFromBookingId'] ?? '';
    if (!is_string($parentId) || !preg_match('/^[a-f0-9]{32}$/D', $parentId)) {
        throw new BookingException('Đơn ở tiếp không hợp lệ.', 422);
    }
    $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . room_operation_lock_suffix($pdo));
    $query->execute([$parentId]);
    $parent = $query->fetch();
    if (!$parent || $parent['room_id'] !== ($input['roomId'] ?? '')
        || !in_array($parent['status'], ['CONFIRMED', 'CHECKED_IN'], true)
        || !empty($parent['is_test']) || !empty($parent['continued_to_booking_id'])) {
        throw new BookingException('Chỉ ở tiếp từ đơn còn hiệu lực, cùng phòng và chưa nối sang gói khác.', 409);
    }
    $checkout = $pdo->prepare('SELECT booking_id FROM booking_checkout_charges WHERE booking_id=?' . room_operation_lock_suffix($pdo));
    $checkout->execute([$parentId]);
    if ($checkout->fetchColumn()) throw new BookingException('Đơn đã xác nhận trả phòng, không thể nối gói.', 409);
    if (pending_booking_continuation($pdo, $parentId)) {
        throw new BookingException('Đơn đã có gói ở tiếp đang chờ thanh toán.', 409);
    }
    $boundary = new DateTimeImmutable($parent['check_out'], new DateTimeZone('UTC'));
    $boundary = $boundary->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    $input = array_replace($input, [
        'continuedFromBookingId' => $parentId,
        'roomId' => $parent['room_id'],
        'timingMode' => 'PACKAGE',
        'date' => $boundary->format('Y-m-d'),
        'time' => $boundary->format('H:i'),
        'numberOfGuests' => (int)$parent['number_of_guests'],
        'guestName' => $parent['guest_name'],
        'guestPhone' => $parent['guest_phone'],
        'guestEmail' => $parent['guest_email'] ?? '',
        'guestNote' => 'Ở tiếp đơn ' . $parent['booking_code'],
        'bookingSource' => 'PHONE',
    ]);
    unset($input['agreedPrice'], $input['startNow'], $input['endDate'], $input['endTime']);
    return [$parent, $input];
}

function booking_continuation_payment_parent(PDO $pdo, array $child): ?string
{
    $parentId = $child['continued_from_booking_id'] ?? null;
    if (!$parentId) return null;
    $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . room_operation_lock_suffix($pdo));
    $query->execute([$parentId]);
    $parent = $query->fetch();
    $checkout = $pdo->prepare('SELECT booking_id FROM booking_checkout_charges WHERE booking_id=?' . room_operation_lock_suffix($pdo));
    $checkout->execute([$parentId]);
    if (!$parent || $parent['room_id'] !== $child['room_id'] || $parent['check_out'] !== $child['check_in']
        || $parent['guest_phone'] !== $child['guest_phone'] || !in_array($parent['status'], ['CONFIRMED', 'CHECKED_IN'], true)
        || (!empty($parent['continued_to_booking_id']) && $parent['continued_to_booking_id'] !== $child['id'])
        || $checkout->fetchColumn()) {
        throw new BookingException('Đơn gốc đã thay đổi hoặc đã trả phòng. Cần đối soát gói ở tiếp.', 409);
    }
    return $parentId;
}

function link_confirmed_booking_continuation(PDO $pdo, array $booking): void
{
    if (!empty($booking['continued_from_booking_id'])) {
        $pdo->prepare('UPDATE bookings SET continued_to_booking_id=? WHERE id=?')
            ->execute([$booking['id'], $booking['continued_from_booking_id']]);
    }
}

function validate_booking_continuation(array $parent, array $quote): void
{
    if ($parent['room_id'] !== $quote['room']['id']
        || (string)$parent['guest_phone'] !== (string)$quote['phone']
        || $parent['check_out'] !== mysql_datetime($quote['start'])) {
        throw new BookingException('Gói ở tiếp phải bắt đầu đúng giờ trả gói trước, cùng phòng và cùng khách.', 409);
    }
}

/** Optional columns keep legacy fixtures and clients safe until a booking is linked. */
function booking_has_live_continuation(array $parent, array $bookings): bool
{
    $nextId = $parent['continued_to_booking_id'] ?? '';
    if ($nextId === '' || $nextId === null || !in_array($parent['status'], ['CONFIRMED', 'CHECKED_IN'], true)) return false;
    foreach ($bookings as $child) {
        if (($child['id'] ?? '') !== $nextId || ($child['continued_from_booking_id'] ?? '') !== ($parent['id'] ?? '')) continue;
        return in_array($child['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)
            && ($parent['room_id'] ?? '') === ($child['room_id'] ?? '')
            && !empty($parent['guest_phone']) && $parent['guest_phone'] === ($child['guest_phone'] ?? null)
            && $parent['check_out'] === $child['check_in'];
    }
    return false;
}

/** Call before checkout or editing dates: do not break a confirmed continuous chain. */
function assert_booking_has_no_active_continuation(PDO $pdo, array $booking): void
{
    if (empty($booking['continued_to_booking_id'])) return;
    $query = $pdo->prepare('SELECT status FROM bookings WHERE id=?');
    $query->execute([$booking['continued_to_booking_id']]);
    if (in_array($query->fetchColumn(), ['CONFIRMED', 'CHECKED_IN'], true)) {
        throw new BookingException('Đơn đã nối gói ở tiếp. Hãy xử lý gói cuối hoặc hủy gói ở tiếp trước.', 409);
    }
}

function assert_no_issued_continuation_checkout(PDO $pdo, string $parentId): void
{
    $query = $pdo->prepare(
        "SELECT b.booking_code FROM bookings b JOIN payments p ON p.booking_id=b.id
         WHERE b.continued_from_booking_id=? AND b.status='PENDING' AND b.payment_method='payos'
         AND p.checkout_url<>'' LIMIT 1" . room_operation_lock_suffix($pdo)
    );
    $query->execute([$parentId]);
    if ($bookingCode = $query->fetchColumn()) {
        throw new BookingException('Gói ở tiếp ' . $bookingCode . ' đã có QR. Hãy kiểm tra thanh toán hoặc hủy QR của gói đó trước.', 409);
    }
}

function detach_cancelled_booking_continuation(PDO $pdo, array $booking): void
{
    assert_booking_has_no_active_continuation($pdo, $booking);
    assert_no_issued_continuation_checkout($pdo, $booking['id']);
    if (!empty($booking['continued_from_booking_id'])) {
        $pdo->prepare('UPDATE bookings SET continued_to_booking_id=NULL WHERE id=? AND continued_to_booking_id=?')
            ->execute([$booking['continued_from_booking_id'], $booking['id']]);
    }
}

function booking_continuation_context(PDO $pdo, string $bookingId): array
{
    $query = $pdo->prepare('SELECT * FROM bookings WHERE id=?');
    $query->execute([$bookingId]);
    $parent = $query->fetch();
    if (!$parent) throw new BookingException('Không tìm thấy đơn.', 404);
    $pending = pending_booking_continuation($pdo, $bookingId);
    if ($pending) {
        return ['bookingCode' => $parent['booking_code'], 'pendingBookingCode' => $pending['booking_code'], 'packages' => []];
    }
    [$parent] = prepare_booking_continuation($pdo, ['continuedFromBookingId' => $bookingId, 'roomId' => $parent['room_id']]);
    $identity = booking_continuation_identity($pdo, $parent);
    $room = (new RoomRepository($pdo))->findById($parent['room_id']);
    if (!$room) throw new BookingException('Không tìm thấy phòng.', 404);
    $date = (new DateTimeImmutable($parent['check_out'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    $packages = [];
    foreach ($room['packages'] as $configured) {
        if (empty($configured['enabled'])) continue;
        try {
            $package = resolve_bookable_slot($room, $configured['id']);
            [$start, $end] = package_window($package, $date->format('Y-m-d'), $date->format('H:i'));
            if (mysql_datetime($start) !== $parent['check_out']) continue;
            if ($end->getTimestamp() <= time()) continue;
            assert_room_inventory($pdo, $room['id'], mysql_datetime($start), mysql_datetime($end), $parent['id']);
            $query = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
            $query->execute([$room['id'], $start->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
            if ($query->fetchColumn()) continue;
            $quote = promotion_apply_quote($pdo, [
                'room' => $room, 'package' => $package, 'start' => $start,
                'phone' => $parent['guest_phone'], 'email' => $parent['guest_email'] ?? '', 'name' => $parent['guest_name'],
            ]);
            $packages[] = ['id' => $package['id'], 'name' => $package['name'], 'price' => $quote['total'], 'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM)];
        } catch (BookingException $error) {
            continue;
        }
    }
    return [
        'bookingId' => $parent['id'], 'bookingCode' => $parent['booking_code'],
        'roomId' => $room['id'], 'roomName' => $room['name'], 'packages' => $packages,
        'identityBookingCode' => $identity['code'], 'missingIdentitySides' => $identity['missing'],
    ];
}

function create_booking_continuation(PDO $pdo, string $parentId, array $input, array $identity = []): array
{
    $query = $pdo->prepare('SELECT room_id FROM bookings WHERE id=?');
    $query->execute([$parentId]);
    $roomId = $query->fetchColumn();
    if (!$roomId) throw new BookingException('Không tìm thấy đơn.', 404);
    $booking = create_manual_booking($pdo, [
        'roomId' => (string)$roomId,
        'continuedFromBookingId' => $parentId,
        'requestId' => $input['requestId'] ?? '',
        'packageId' => $input['packageId'] ?? '',
        'paymentStatus' => $input['paymentStatus'] ?? 'UNPAID',
        'paymentMethod' => $input['paymentMethod'] ?? 'bank_transfer',
        'guestCheckout' => $input['guestCheckout'] ?? false,
        'expectedPrice' => $input['expectedPrice'] ?? null,
    ], false, $identity);
    if (($booking['continued_from_booking_id'] ?? '') !== $parentId) {
        throw new BookingException('Mã yêu cầu đã được dùng cho đơn khác.', 409);
    }
    return $booking;
}

function create_continuation_payment(array $booking, ?callable $create = null, ?callable $fetch = null): array
{
    try {
        return ($create ?? 'create_payment')($booking);
    } catch (Throwable $error) {
        // A timeout can occur after payOS creates the QR. Recover the same order code.
        $remote = ($fetch ?? 'payos_payment_status')((string)$booking['orderCode']);
        if ((string)($remote['orderCode'] ?? '') !== (string)$booking['orderCode']
            || (int)($remote['amount'] ?? -1) !== (int)$booking['totalPrice']
            || !preg_match('/^[a-f0-9]{32}$/D', (string)($remote['id'] ?? ''))
            || !in_array($remote['status'] ?? '', ['PENDING', 'PROCESSING', 'PAID'], true)) {
            throw new BookingException('Chưa xác minh được phiên thanh toán gói ở tiếp.', 503);
        }
        return ['checkoutUrl' => 'https://pay.payos.vn/web/' . $remote['id'], 'qrCode' => null];
    }
}

function prepare_continuation_checkout(PDO $pdo, string $bookingId, ?callable $create = null): ?string
{
    $pdo->beginTransaction();
    try {
        // Share the room lock with cancellation and callbacks before making the QR available.
        $booking = room_operation_booking($pdo, $bookingId);
        $url = null;
        if ($booking['status'] === 'PENDING' && $booking['payment_method'] === 'payos') {
            assert_payment_booking_inventory($pdo, $booking);
            $url = prepare_manual_guest_checkout($pdo, $booking, $create ?? 'create_continuation_payment');
        }
        $pdo->commit();
        return $url;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
