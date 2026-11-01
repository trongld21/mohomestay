<?php

declare(strict_types=1);

function promotion_refresh_locked_quote(PDO $pdo, array $quote, array $room, array $packageRow): array
{
    $quote['room'] = array_replace($quote['room'], [
        'status' => $room['status'],
        'discountPercent' => (int)$room['discount_percent'],
        'discountStart' => $room['discount_start'] ?? null,
        'discountEnd' => $room['discount_end'] ?? null,
    ]);
    $package = locked_booking_package($room, $packageRow, $quote['start']->format('Y-m-d'));
    [$start, $end] = bookable_package_window(
        $package, $quote['requestedDate'],
        $package['mode'] === 'DURATION' ? $quote['start']->format('H:i') : ''
    );
    $quote['package'] = $package;
    $quote['start'] = $start;
    $quote['end'] = $end;
    $quote = array_replace($quote, booking_extra_hours_details($package, $end, $quote['extraHours']));
    return promotion_apply_quote($pdo, $quote);
}

function promotion_apply_quote(PDO $pdo, array $quote, ?int $agreedPrice = null): array
{
    if (!isset($quote['package'])) {
        return $quote;
    }
    $package = package_with_discount(
        $quote['package'],
        (int)($quote['room']['discountPercent'] ?? 0),
        $quote['start']->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d'),
        $quote['room']
    );
    $package = promotion_price(
        $pdo, $quote['room'], $package, $quote['start'],
        (string)($quote['phone'] ?? ''), (string)($quote['email'] ?? ''), (string)($quote['name'] ?? '')
    );
    $quote = array_replace($quote, booking_package_snapshot($package));
    if (($quote['extraHours'] ?? 0) > 0) {
        $extra = booking_extra_hours_details($package, $quote['end'], $quote['extraHours']);
        $quote['packageName'] = $extra['packageName'];
    }
    $quote['total'] = (int)$package['price'] + (int)($quote['extraHoursAmount'] ?? 0);
    if ($agreedPrice !== null) {
        if ($agreedPrice !== $quote['total']) {
            $package['agreedPrice'] = $agreedPrice;
        }
        $quote['total'] = $agreedPrice;
    }
    $quote['package'] = $package;
    return $quote;
}

function promotion_preview_for_room(PDO $pdo, array $room, array $input, bool $allowAnyDate = false): array
{
    foreach (['packageId', 'date', 'time', 'guestPhone', 'guestEmail', 'guestName'] as $key) {
        if (isset($input[$key]) && (!is_string($input[$key]) || strlen($input[$key]) > 254)) {
            throw new BookingException('Thông tin tính giá không hợp lệ.', 422);
        }
    }
    $package = resolve_bookable_slot($room, (string)($input['packageId'] ?? ''), (string)($input['date'] ?? ''));
    [$start] = $allowAnyDate
        ? package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''))
        : bookable_package_window($package, (string)($input['date'] ?? ''), (string)($input['time'] ?? ''));
    $quote = promotion_apply_quote($pdo, [
        'room' => $room, 'package' => $package, 'start' => $start,
        'phone' => $input['guestPhone'] ?? '', 'email' => $input['guestEmail'] ?? '',
        'name' => $input['guestName'] ?? '',
    ]);
    $package = $quote['package'];
    return [
        'basePrice' => $package['basePrice'], 'price' => $package['price'],
        'percent' => $package['effectiveDiscountPercent'],
        'promotionName' => $package['promotionName'] ?? null,
    ];
}

function promotion_validate_scope(PDO $pdo, array $input): array
{
    $item = promotion_validate($input);
    $rooms = (new RoomRepository($pdo))->allRooms();
    $roomIds = array_column($rooms, 'id');
    $packages = [];
    foreach ($rooms as $room) {
        foreach ($room['packages'] as $package) {
            if (!$item['roomIds'] || in_array($room['id'], $item['roomIds'], true)) {
                $packages[] = $package['id'];
            }
        }
    }
    if (array_diff($item['roomIds'], $roomIds) || array_diff($item['packageIds'], $packages)) {
        throw new BookingException('Phòng hoặc gói áp dụng không còn hợp lệ. Vui lòng tải lại danh sách.', 422);
    }
    return $item;
}

function promotion_api(string $path, string $method): never
{
    if ($path === '/api/booking-price' && $method === 'POST') {
        require_csrf();
        $input = json_body();
        $manual = ($input['manual'] ?? false) === true;
        if ($manual) {
            require_admin();
            require_permission('bookings.create');
        }
        $minute = (int)floor(time() / 60);
        if (($_SESSION['promotion_quote_minute'] ?? null) !== $minute) {
            $_SESSION['promotion_quote_minute'] = $minute;
            $_SESSION['promotion_quote_count'] = 0;
        }
        if (++$_SESSION['promotion_quote_count'] > 120) {
            throw new BookingException('Bạn đang kiểm tra giá quá nhanh. Vui lòng thử lại sau ít giây.', 429);
        }
        if (!is_string($input['roomId'] ?? null)) {
            throw new BookingException('Phòng không hợp lệ.', 422);
        }
        $pdo = db();
        $room = (new RoomRepository($pdo))->findById($input['roomId']);
        if (!$room) {
            throw new BookingException('Không tìm thấy phòng.', 404);
        }
        json_response(promotion_preview_for_room($pdo, $room, $input, $manual && !empty(current_account()['root'])));
    }
    $pdo = db();
    if ($path === '/api/admin/promotions' && $method === 'GET') {
        json_response(['promotions' => promotion_list($pdo), 'rooms' => array_values((new RoomRepository($pdo))->allRooms())]);
    }
    if ($path === '/api/admin/promotions' && $method === 'POST') {
        require_csrf();
        $input = json_body();
        unset($input['id']);
        json_response(['promotion' => promotion_save($pdo, promotion_validate_scope($pdo, $input))], 201);
    }
    if ($method === 'PATCH' && preg_match('#^/api/admin/promotions/([a-f0-9]{32})$#', $path, $match)) {
        require_csrf();
        $input = json_body();
        $input['id'] = $match[1];
        json_response(['promotion' => promotion_save($pdo, promotion_validate_scope($pdo, $input))]);
    }
    throw new BookingException('Phương thức ưu đãi không hợp lệ.', 405);
}
