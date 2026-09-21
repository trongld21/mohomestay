<?php

declare(strict_types=1);

function normalize_slug(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i','ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u','ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function validate_room_input(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new BookingException('Tên phòng phải từ 2 đến 100 ký tự.', 422);
    $slug = normalize_slug((string)($input['slug'] ?? '')) ?: normalize_slug($name);
    if ($slug === '' || strlen($slug) > 100) throw new BookingException('Slug phòng không hợp lệ.', 422);
    $status = strtoupper((string)($input['status'] ?? 'ACTIVE'));
    if (!in_array($status, ['ACTIVE','COMING_SOON','HIDDEN'], true)) throw new BookingException('Trạng thái phòng không hợp lệ.', 422);

    $maxGuests = bounded_integer($input['maxGuests'] ?? 2, 1, 50, 'Sức chứa');
    $bedrooms = bounded_integer($input['bedrooms'] ?? 1, 0, 20, 'Số phòng ngủ');
    $bathrooms = bounded_integer($input['bathrooms'] ?? 1, 0, 20, 'Số phòng tắm');
    $sortOrder = bounded_integer($input['sortOrder'] ?? 0, -100000, 100000, 'Thứ tự');

    $tags = [];
    $seen = [];
    foreach ((array)($input['tags'] ?? []) as $rawTag) {
        $tag = trim((string)$rawTag);
        if ($tag === '') continue;
        if (mb_strlen($tag) > 40) throw new BookingException('Mỗi tag tối đa 40 ký tự.', 422);
        $normalized = mb_strtolower($tag, 'UTF-8');
        if (!isset($seen[$normalized])) { $seen[$normalized] = true; $tags[] = $tag; }
    }
    if (count($tags) > 30) throw new BookingException('Mỗi phòng có tối đa 30 tag.', 422);

    return [
        'name'=>$name,'slug'=>$slug,
        'subtitle'=>limited_text($input['subtitle'] ?? '', 255, 'Mô tả ngắn'),
        'description'=>limited_text($input['description'] ?? '', 5000, 'Mô tả'),
        'status'=>$status,'maxGuests'=>$maxGuests,'bedrooms'=>$bedrooms,'bathrooms'=>$bathrooms,
        'sortOrder'=>$sortOrder,'tags'=>$tags,'packages'=>validate_packages((array)($input['packages'] ?? [])),
    ];
}

function validate_packages(array $packages): array
{
    if (count($packages) > 30) throw new BookingException('Mỗi phòng có tối đa 30 gói giá.', 422);
    $result = [];
    foreach ($packages as $index => $raw) {
        if (!is_array($raw)) throw new BookingException('Gói giá không hợp lệ.', 422);
        $name = trim((string)($raw['name'] ?? ''));
        if (mb_strlen($name) < 1 || mb_strlen($name) > 80) throw new BookingException('Tên gói giá phải từ 1 đến 80 ký tự.', 422);
        $mode = strtoupper((string)($raw['mode'] ?? ''));
        if (!in_array($mode, ['DURATION','FIXED_TIME'], true)) throw new BookingException('Loại thời gian của gói không hợp lệ.', 422);
        $price = filter_var($raw['price'] ?? null, FILTER_VALIDATE_INT);
        if ($price === false || $price < 0 || $price > 1000000000) throw new BookingException('Giá gói phải từ 0 đến 1.000.000.000đ.', 422);
        $package = [
            'id'=>preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string)($raw['id'] ?? '')) ? (string)$raw['id'] : '',
            'name'=>$name,'mode'=>$mode,'durationMinutes'=>null,'checkInTime'=>null,'checkOutTime'=>null,
            'price'=>(int)$price,'enabled'=>filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'sortOrder'=>bounded_integer($raw['sortOrder'] ?? ($index + 1) * 10, -100000, 100000, 'Thứ tự gói'),
        ];
        if ($mode === 'DURATION') {
            $duration = filter_var($raw['durationMinutes'] ?? null, FILTER_VALIDATE_INT);
            if ($duration === false || $duration < 30 || $duration > 43200) throw new BookingException('Thời lượng phải từ 30 phút đến 30 ngày.', 422);
            $package['durationMinutes'] = (int)$duration;
        } else {
            $checkIn = (string)($raw['checkInTime'] ?? '');
            $checkOut = (string)($raw['checkOutTime'] ?? '');
            if (!valid_clock($checkIn) || !valid_clock($checkOut)) throw new BookingException('Giờ nhận/trả phòng không hợp lệ.', 422);
            $package['checkInTime'] = $checkIn;
            $package['checkOutTime'] = $checkOut;
        }
        $result[] = $package;
    }
    return $result;
}

function package_window(array $package, string $date, string $time): array
{
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Ho_Chi_Minh'));
    if (!$day || $day->format('Y-m-d') !== $date) throw new BookingException('Ngày không hợp lệ.');
    if (($package['mode'] ?? '') === 'DURATION') {
        if (!valid_clock($time)) throw new BookingException('Giờ nhận phòng không hợp lệ.');
        $start = new DateTimeImmutable($date.' '.$time, new DateTimeZone('Asia/Ho_Chi_Minh'));
        return [$start, $start->modify('+'.(int)$package['durationMinutes'].' minutes')];
    }
    if (($package['mode'] ?? '') !== 'FIXED_TIME' || !valid_clock((string)($package['checkInTime'] ?? '')) || !valid_clock((string)($package['checkOutTime'] ?? ''))) {
        throw new BookingException('Gói thời gian không hợp lệ.');
    }
    $start = new DateTimeImmutable($date.' '.$package['checkInTime'], new DateTimeZone('Asia/Ho_Chi_Minh'));
    $end = new DateTimeImmutable($date.' '.$package['checkOutTime'], new DateTimeZone('Asia/Ho_Chi_Minh'));
    if ($end <= $start) $end = $end->modify('+1 day');
    return [$start,$end];
}

function bounded_integer(mixed $value, int $min, int $max, string $label): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < $min || $number > $max) throw new BookingException($label.' không hợp lệ.', 422);
    return (int)$number;
}

function limited_text(mixed $value, int $max, string $label): string
{
    $text = trim((string)$value);
    if (mb_strlen($text) > $max) throw new BookingException($label.' vượt quá '.$max.' ký tự.', 422);
    return $text;
}

function valid_clock(string $value): bool { return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value); }
