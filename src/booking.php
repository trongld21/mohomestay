<?php

class BookingException extends RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 400) { parent::__construct($message); }
}

function stay_window(string $date, string $time, string $package): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new BookingException('Ngày không hợp lệ.');
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Ho_Chi_Minh'));
    if (!$day || $day->format('Y-m-d') !== $date || !isset(packages()[$package])) throw new BookingException('Ngày hoặc gói lưu trú không hợp lệ.');
    if ($package === 'overnight') {
        $time = (string)config('overnight_check_in', '');
        $checkout = (string)config('overnight_check_out', '');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $checkout)) throw new BookingException('Vui lòng liên hệ Lặng để xác nhận giờ qua đêm.');
        $start = new DateTimeImmutable("$date $time", new DateTimeZone('Asia/Ho_Chi_Minh'));
        $end = new DateTimeImmutable($day->modify('+1 day')->format('Y-m-d') . " $checkout", new DateTimeZone('Asia/Ho_Chi_Minh'));
    } else {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new BookingException('Giờ nhận phòng không hợp lệ.');
        $start = new DateTimeImmutable("$date $time", new DateTimeZone('Asia/Ho_Chi_Minh'));
        $end = $start->modify($package === '3h' ? '+3 hours' : '+6 hours');
    }
    return [$start, $end];
}

function quote_booking(array $input): array
{
    $room = rooms()[(string)($input['roomId'] ?? '')] ?? null;
    if (!$room) throw new BookingException('Phòng không hợp lệ.');
    $package = (string)($input['stayPackage'] ?? '');
    [$start, $end] = stay_window((string)($input['date'] ?? ''), (string)($input['time'] ?? ''), $package);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    if ($start <= $now) throw new BookingException('Vui lòng chọn giờ nhận phòng trong tương lai.');
    if ($start > $now->modify('+366 days')) throw new BookingException('Chỉ nhận đặt phòng trong vòng 12 tháng.');
    $guests = filter_var($input['numberOfGuests'] ?? null, FILTER_VALIDATE_INT);
    if (!$guests || $guests < 1 || $guests > 2) throw new BookingException('Mỗi phòng nhận từ 1 đến 2 khách.');
    $name = trim((string)($input['guestName'] ?? ''));
    $phone = preg_replace('/[\s.\-]/', '', (string)($input['guestPhone'] ?? ''));
    $email = trim((string)($input['guestEmail'] ?? ''));
    $note = trim((string)($input['guestNote'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new BookingException('Vui lòng nhập họ tên từ 2 đến 100 ký tự.');
    if (!preg_match('/^(?:0[35789]\d{8}|\+84[35789]\d{8})$/', $phone)) throw new BookingException('Số điện thoại Việt Nam chưa hợp lệ.');
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) throw new BookingException('Email chưa hợp lệ.');
    if (mb_strlen($note) > 1000) throw new BookingException('Lời nhắn tối đa 1.000 ký tự.');
    if (($input['acceptTerms'] ?? false) !== true) throw new BookingException('Vui lòng đồng ý điều kiện đặt phòng.');
    return compact('room','package','start','end','guests','name','phone','email','note') + ['total'=>(int)$room['prices'][$package]];
}

function mysql_datetime(DateTimeInterface $date): string
{
    return (new DateTimeImmutable($date->format(DateTimeInterface::ATOM)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
