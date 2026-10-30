<?php

declare(strict_types=1);

class BookingException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 400) { parent::__construct($message); }
}

function assert_room_bookable(array $room): void
{
    if (($room['status'] ?? '') === 'COMING_SOON') throw new BookingException('Phòng sắp ra mắt, chưa thể đặt.', 409);
    if (($room['status'] ?? '') !== 'ACTIVE') throw new BookingException('Phòng đang tạm ngưng nhận khách.', 409);
}

function resolve_bookable_package(array $room, string $packageId, ?string $arrivalDate = null): array
{
    assert_room_bookable($room);
    foreach ((array)($room['packages'] ?? []) as $package) {
        if (($package['id'] ?? '') === $packageId && ($package['enabled'] ?? false)) return package_with_discount($package, (int)($room['discountPercent'] ?? 0), $arrivalDate, $room);
    }
    throw new BookingException('Gói giá không hợp lệ hoặc đã tạm tắt.', 409);
}

function resolve_bookable_slot(array $room, string $packageId, ?string $arrivalDate = null): array
{
    $package = resolve_bookable_package($room, $packageId, $arrivalDate);
    if (!in_array(($package['mode'] ?? ''), ['FIXED_TIME','DURATION'], true)) {
        throw new BookingException('Kiểu thời gian của gói không hợp lệ.', 409);
    }
    return $package;
}

// Resolve the payable price from the rows locked by the booking transaction.
function locked_booking_package(array $room, array $package, ?string $arrivalDate = null): array
{
    return resolve_bookable_slot([
        'status' => $room['status'],
        'discountPercent' => (int)$room['discount_percent'],
        'discountStart' => $room['discount_start'] ?? null,
        'discountEnd' => $room['discount_end'] ?? null,
        'packages' => [[
            'id' => $package['id'],
            'name' => $package['name'],
            'mode' => $package['timing_mode'],
            'durationMinutes' => $package['duration_minutes'] === null ? null : (int)$package['duration_minutes'],
            'checkInTime' => $package['check_in_time'] === null ? null : substr((string)$package['check_in_time'], 0, 5),
            'checkOutTime' => $package['check_out_time'] === null ? null : substr((string)$package['check_out_time'], 0, 5),
            'price' => (int)$package['price'],
            'discountPercent' => $package['discount_percent'] === null ? null : (int)$package['discount_percent'],
            'discountStart' => $package['discount_start'] ?? null,
            'discountEnd' => $package['discount_end'] ?? null,
            'enabled' => (bool)$package['is_enabled'],
        ]],
    ], (string)$package['id'], $arrivalDate);
}

function booking_package_snapshot(array $package): array
{
    return [
        'packageId'=>(string)$package['id'],'packageName'=>(string)$package['name'],
        'packageMode'=>(string)$package['mode'],'packageDuration'=>$package['durationMinutes'] ?? null,
        'packageCheckIn'=>$package['checkInTime'] ?? null,'packageCheckOut'=>$package['checkOutTime'] ?? null,
        'packagePrice'=>(int)$package['price'],
    ];
}

// Transitional helper kept for old clients and the original booking contract tests.
function stay_window(string $date, string $time, string $package): array
{
    if (!isset(packages()[$package])) throw new BookingException('Gói lưu trú không hợp lệ.');
    if ($package === 'overnight') {
        $checkIn = (string)config('overnight_check_in', '');
        $checkOut = (string)config('overnight_check_out', '');
        if (!valid_clock($checkIn) || !valid_clock($checkOut)) throw new BookingException('Giờ qua đêm chưa được cấu hình.');
        return package_window(['mode'=>'FIXED_TIME','checkInTime'=>$checkIn,'checkOutTime'=>$checkOut], $date, '');
    }
    return package_window(['mode'=>'DURATION','durationMinutes'=>$package === '3h' ? 180 : 360], $date, $time);
}

function bookable_package_window(array $package, string $date, string $time, ?DateTimeImmutable $now = null): array
{
    [$start, $end] = package_window($package, $date, $time);
    if ((int)$start->format('i') % 30 !== 0) {
        throw new BookingException('Chỉ chọn giờ nhận phòng theo mốc 30 phút: 00 hoặc 30 phút.', 422);
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    $overnight = ($package['mode'] ?? '') === 'FIXED_TIME'
        && $package['checkOutTime'] < $package['checkInTime'];
    if (($package['mode'] ?? '') === 'FIXED_TIME' && !$overnight && $start <= $now && $end > $now) {
        // Late arrivals keep the original checkout and full package price.
        $start = $now->setTimestamp((int)(ceil($now->getTimestamp() / 1800) * 1800));
    }
    if ($end <= $now || $end <= $start) throw new BookingException('Gói lưu trú này đã hết giờ. Vui lòng chọn gói khác.');
    if ($start <= $now && ($package['mode'] ?? '') !== 'FIXED_TIME') {
        throw new BookingException('Vui lòng chọn giờ nhận phòng trong tương lai.');
    }
    return [$start, $end];
}

function quote_booking(array $input): array
{
    $roomId = (string)($input['roomId'] ?? '');
    $room = room_repository()->findById($roomId);
    if (!$room) throw new BookingException('Phòng không hợp lệ.');
    return quote_booking_for_room($room, $input);
}

function quote_booking_for_room(array $room, array $input, bool $manual = false, bool $allowAnyDate = false): array
{
    $roomId = (string)$room['id'];
    $packageId = (string)($input['packageId'] ?? '');
    if ($packageId === '' && isset($input['stayPackage'])) $packageId = $roomId.'-'.(string)$input['stayPackage'];
    $package = resolve_bookable_slot($room, $packageId);
    $unrestricted = $manual && $allowAnyDate;
    [$start, $end] = $unrestricted
        ? package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''))
        : bookable_package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''));
    if ((int)$start->format('i') % 30 !== 0) {
        throw new BookingException('Chỉ chọn giờ nhận phòng theo mốc 30 phút: 00 hoặc 30 phút.', 422);
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    $package = package_with_discount($package, (int)($room['discountPercent'] ?? 0), $start->format('Y-m-d'), $room);
    if (!$unrestricted && $start > $now->modify('+366 days')) throw new BookingException('Chỉ nhận đặt phòng trong vòng 12 tháng.');
    $guestDetails = booking_guest_details($room, $input, $manual);
    $requestedDate = ($package['mode'] ?? '') === 'FIXED_TIME'
        && $package['checkOutTime'] < $package['checkInTime']
        ? $end->modify('-1 day')->format('Y-m-d')
        : (string)($input['date'] ?? '');
    return array_replace(
        compact('room', 'package', 'start', 'end') + $guestDetails
            + booking_package_snapshot($package) + ['requestedDate' => $requestedDate],
        booking_extra_hours_details($package, $end, $input['extraHours'] ?? 0)
    );
}

function booking_extra_hours_details(array $package, DateTimeImmutable $end, mixed $hours = 0): array
{
    if ((!is_int($hours) && !is_string($hours)) || !preg_match('/^[012]$/D', (string)$hours)) {
        throw new BookingException('Chỉ được chọn phát sinh 1 giờ hoặc 2 giờ.', 422);
    }
    $extraHours = (int)$hours;
    $extraHoursAmount = $extraHours * 50000;
    $name = (string)$package['name'];
    if ($extraHours > 0) {
        $name .= ' + ' . $extraHours . ' giờ (phụ thu ' . number_format($extraHoursAmount, 0, ',', '.') . 'đ)';
    }
    return [
        'end' => $end->modify('+' . $extraHours . ' hours'),
        'extraHours' => $extraHours,
        'extraHoursAmount' => $extraHoursAmount,
        'total' => (int)$package['price'] + $extraHoursAmount,
        'packageName' => $name,
    ];
}

function booking_guest_details(array $room, array $input, bool $manual): array
{
    $guests = filter_var($input['numberOfGuests'] ?? null, FILTER_VALIDATE_INT);
    if (!$guests || $guests < 1 || $guests > (int)$room['maxGuests']) throw new BookingException('Số khách vượt quá sức chứa của phòng.');
    $name = trim((string)($input['guestName'] ?? ''));
    $phone = preg_replace('/[\s.\-]/', '', (string)($input['guestPhone'] ?? ''));
    $email = trim((string)($input['guestEmail'] ?? ''));
    $note = trim((string)($input['guestNote'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new BookingException('Vui lòng nhập họ tên từ 2 đến 100 ký tự.');
    if (!preg_match('/^(?:0[35789]\d{8}|\+84[35789]\d{8})$/', $phone)) throw new BookingException('Số điện thoại Việt Nam chưa hợp lệ.');
    if ((!$manual || $email !== '') && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) throw new BookingException('Vui lòng nhập email hợp lệ để nhận xác nhận đặt phòng.');
    if (mb_strlen($note) > 1000) throw new BookingException('Lời nhắn tối đa 1.000 ký tự.');
    if (!$manual && ($input['acceptTerms'] ?? false) !== true) throw new BookingException('Vui lòng đồng ý điều kiện đặt phòng.');
    return compact('guests', 'name', 'phone', 'email', 'note');
}

function mysql_datetime(DateTimeInterface $date): string
{
    return (new DateTimeImmutable($date->format(DateTimeInterface::ATOM)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function booking_lifecycle(array $booking, ?int $now = null): array
{
    $now ??= time();
    $status = (string)$booking['status'];
    $source = in_array($status, ['CHECKED_IN', 'CHECKED_OUT', 'CANCELLED'], true) ? 'MANUAL' : 'AUTO';
    $holdDeadline = !empty($booking['hold_expires_at']) ? strtotime($booking['hold_expires_at'] . ' UTC') : false;
    if ($status === 'PENDING' && ($booking['payment_status'] ?? '') !== 'PAID'
        && $holdDeadline !== false && $holdDeadline <= $now) {
        // Display the same expiry that inventory already uses; keep persisted state
        // available for late payment reconciliation instead of cancelling the order.
        $status = 'EXPIRED';
    }
    if (in_array($status, ['CONFIRMED', 'CHECKED_IN'], true)) {
        if ($status === 'CONFIRMED' && $now >= strtotime($booking['check_in'] . ' UTC')) {
            $status = 'CHECKED_IN';
            $source = 'AUTO';
        }
    }
    $continuing = !empty($booking['continued_to_booking_id']) && in_array($status, ['CONFIRMED', 'CHECKED_IN'], true)
        && $now >= strtotime($booking['check_out'] . ' UTC');
    if ($continuing) {
        $status = 'CHECKED_OUT';
        $source = 'AUTO';
    }
    return array_replace($booking, [
        'continuingStay' => $continuing,
        'status' => $status,
        'statusSource' => $source,
        'isOverdue' => $status === 'CHECKED_IN' && $now >= strtotime($booking['check_out'] . ' UTC'),
    ]);
}

function booking_transition_allowed(string $from, string $to, bool $superAdmin): bool
{
    if ($superAdmin && $to === 'CANCELLED'
        && in_array($from, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'EXPIRED', 'CANCELLED'], true)) {
        return true;
    }
    $allowed = [
        'PENDING' => ['CANCELLED'],
        'EXPIRED' => ['CANCELLED'],
        'CONFIRMED' => ['CHECKED_IN', 'CANCELLED'],
        'CHECKED_IN' => ['CHECKED_OUT'],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

function update_booking_lifecycle(PDO $pdo, string $id, string $status): void
{
    if ($status === 'CHECKED_OUT') {
        confirm_room_checkout($pdo, $id, (string)(current_account()['id'] ?? 'admin'));
        return;
    }
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT room_id FROM bookings WHERE id=?');
        $find->execute([$id]);
        $roomId = $find->fetchColumn();
        if (!$roomId) throw new BookingException('Không tìm thấy đơn.', 404);
        $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=?' . room_operation_lock_suffix($pdo));
        $lock->execute([$roomId]);
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id=?' . room_operation_lock_suffix($pdo));
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        if (!$booking) throw new BookingException('Không tìm thấy đơn.', 404);
        $effective = booking_lifecycle($booking);
        $superAdmin = (bool)(current_account()['root'] ?? false);
        if (!booking_transition_allowed($effective['status'], $status, $superAdmin)) {
            throw new BookingException('Trạng thái đơn đã thay đổi. Vui lòng làm mới danh sách.', 409);
        }
        $start = $booking['check_in'];
        if ($status === 'CHECKED_IN' && strtotime($start . ' UTC') > time()) {
            $start = gmdate('Y-m-d H:i:s');
            assert_room_inventory($pdo, (string)$roomId, $start, $booking['check_out'], $id);
            $blocked = $pdo->prepare('SELECT id FROM room_availability WHERE room_id=? AND available=0 AND `date` BETWEEN ? AND ? LIMIT 1');
            $local = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
            $end = (new DateTimeImmutable($booking['check_out'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
            $blocked->execute([$roomId, $local->format('Y-m-d'), $end->modify('-1 second')->format('Y-m-d')]);
            if ($blocked->fetch()) throw new BookingException('Phòng đang bị khóa trong khoảng nhận sớm.', 409);
        }
        if ($status === 'CANCELLED') {
            close_booking_payment($booking);
            detach_cancelled_booking_continuation($pdo, $booking);
        }
        $pdo->prepare('UPDATE bookings SET status=?,check_in=? WHERE id=?')->execute([$status, $start, $id]);
        if ($status === 'CANCELLED') {
            $pdo->prepare('UPDATE room_emergency_locks SET ended_at=?,ended_by=? WHERE booking_id=? AND ended_at IS NULL')
                ->execute([gmdate('Y-m-d H:i:s'), (string)(current_account()['id'] ?? 'admin'), $id]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function booking_overview(array $rows, ?int $now = null): array
{
    $now ??= time();
    $overview = ['paid' => 0, 'confirmed' => 0, 'staying' => 0, 'reconcile' => 0];
    foreach ($rows as $row) {
        $booking = booking_lifecycle($row, $now);
        if ($booking['payment_status'] === 'PAID' && $booking['status'] !== 'CANCELLED' && empty($booking['is_test'])) {
            $overview['paid'] += (int)$booking['total_price'];
        }
        if ($booking['status'] === 'CONFIRMED') {
            $overview['confirmed']++;
        }
        if ($booking['status'] === 'CHECKED_IN') {
            $overview['staying']++;
        }
        if ($booking['status'] === 'CANCELLED' && $booking['payment_status'] === 'PAID') {
            $overview['reconcile']++;
        }
    }
    return $overview;
}
