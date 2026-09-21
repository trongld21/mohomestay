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

function resolve_bookable_package(array $room, string $packageId): array
{
    assert_room_bookable($room);
    foreach ((array)($room['packages'] ?? []) as $package) {
        if (($package['id'] ?? '') === $packageId && ($package['enabled'] ?? false)) return $package;
    }
    throw new BookingException('Gói giá không hợp lệ hoặc đã tạm tắt.', 409);
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

function quote_booking(array $input): array
{
    $roomId = (string)($input['roomId'] ?? '');
    $room = room_repository()->findById($roomId);
    if (!$room) throw new BookingException('Phòng không hợp lệ.');
    $packageId = (string)($input['packageId'] ?? '');
    if ($packageId === '' && isset($input['stayPackage'])) $packageId = $roomId.'-'.(string)$input['stayPackage'];
    $package = resolve_bookable_package($room, $packageId);
    [$start,$end] = package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''));
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    if ($start <= $now) throw new BookingException('Vui lòng chọn giờ nhận phòng trong tương lai.');
    if ($start > $now->modify('+366 days')) throw new BookingException('Chỉ nhận đặt phòng trong vòng 12 tháng.');
    $guests = filter_var($input['numberOfGuests'] ?? null, FILTER_VALIDATE_INT);
    if (!$guests || $guests < 1 || $guests > (int)$room['maxGuests']) throw new BookingException('Số khách vượt quá sức chứa của phòng.');
    $name = trim((string)($input['guestName'] ?? ''));
    $phone = preg_replace('/[\s.\-]/', '', (string)($input['guestPhone'] ?? ''));
    $email = trim((string)($input['guestEmail'] ?? ''));
    $note = trim((string)($input['guestNote'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new BookingException('Vui lòng nhập họ tên từ 2 đến 100 ký tự.');
    if (!preg_match('/^(?:0[35789]\d{8}|\+84[35789]\d{8})$/', $phone)) throw new BookingException('Số điện thoại Việt Nam chưa hợp lệ.');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) throw new BookingException('Email chưa hợp lệ.');
    if (mb_strlen($note) > 1000) throw new BookingException('Lời nhắn tối đa 1.000 ký tự.');
    if (($input['acceptTerms'] ?? false) !== true) throw new BookingException('Vui lòng đồng ý điều kiện đặt phòng.');
    return compact('room','package','start','end','guests','name','phone','email','note')
        + booking_package_snapshot($package) + ['total'=>(int)$package['price']];
}

function mysql_datetime(DateTimeInterface $date): string
{
    return (new DateTimeImmutable($date->format(DateTimeInterface::ATOM)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
