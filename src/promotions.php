<?php

declare(strict_types=1);

function promotion_validate(array $input): array
{
    $fail = static function (): never {
        throw new BookingException('Cấu hình ưu đãi không hợp lệ.', 422);
    };
    if (!is_string($input['name'] ?? null)
        || (!is_int($input['percent'] ?? null) && !is_string($input['percent'] ?? null))) {
        $fail();
    }
    $name = trim($input['name']);
    $type = $input['type'] ?? '';
    $percent = filter_var($input['percent'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '' || mb_strlen($name) > 160 || !in_array($type, ['SCHEDULE', 'RETURNING'], true) || $percent < 1 || $percent > 99) {
        $fail();
    }
    $result = ['name' => $name, 'type' => $type, 'percent' => $percent];
    $enabled = $input['enabled'] ?? true;
    if (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
        $fail();
    }
    $result['enabled'] = (bool)$enabled;
    foreach (['startDate', 'endDate'] as $key) {
        $value = $input[$key] ?? null;
        if ($value === '') {
            $value = null;
        }
        if ($value !== null) {
            if (!is_string($value)) {
                $fail();
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) {
                $fail();
            }
        }
        $result[$key] = $value;
    }
    if ($result['startDate'] && $result['endDate'] && $result['endDate'] < $result['startDate']) {
        $fail();
    }
    foreach (['weekdays', 'roomIds', 'packageIds'] as $key) {
        $values = $input[$key] ?? [];
        if (!is_array($values) || count($values) > ($key === 'weekdays' ? 7 : 500)) {
            $fail();
        }
        foreach ($values as $value) {
            if ($key === 'weekdays') {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < 1 || $value > 7) {
                    $fail();
                }
            } elseif (!is_string($value) || trim($value) === '' || strlen($value) > 64) {
                $fail();
            }
        }
        $result[$key] = array_values(array_unique($key === 'weekdays' ? array_map('intval', $values) : $values));
    }
    $result['startTime'] = $input['startTime'] ?? '00:00';
    $result['endTime'] = $input['endTime'] ?? '24:00';
    foreach (['startTime', 'endTime'] as $key) {
        if (!is_string($result[$key]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $result[$key])) {
            if ($key !== 'endTime' || $result[$key] !== '24:00') {
                $fail();
            }
        }
    }
    $minStays = filter_var($input['minStays'] ?? 1, FILTER_VALIDATE_INT);
    if ($minStays === false || $minStays < 1 || $minStays > 100000) {
        $fail();
    }
    $result['minStays'] = $minStays;
    if ($type === 'SCHEDULE' && (!$result['weekdays'] || $result['endTime'] <= $result['startTime'])) {
        $fail();
    }
    if (isset($input['id'])) {
        if (!is_string($input['id']) || !preg_match('/^[a-f0-9]{32}$/D', $input['id'])) {
            $fail();
        }
        $result['id'] = $input['id'];
    }
    return $result;
}

function promotion_columns(): array
{
    return [
        'name' => 'name',
        'type' => 'type',
        'percent' => 'percent',
        'enabled' => 'enabled',
        'startDate' => 'start_date',
        'endDate' => 'end_date',
        'weekdays' => 'weekdays',
        'startTime' => 'start_time',
        'endTime' => 'end_time',
        'minStays' => 'min_stays',
        'roomIds' => 'room_ids',
        'packageIds' => 'package_ids',
    ];
}

function promotion_list(PDO $pdo): array
{
    $result = [];
    foreach ($pdo->query('SELECT * FROM promotions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = ['id' => $row['id']];
        foreach (promotion_columns() as $key => $column) {
            $item[$key] = in_array($key, ['weekdays', 'roomIds', 'packageIds'], true)
                ? json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR) : $row[$column];
        }
        $item['percent'] = (int)$item['percent'];
        $item['minStays'] = (int)$item['minStays'];
        $item['enabled'] = (bool)$item['enabled'];
        $result[] = $item;
    }
    return $result;
}

function promotion_save(PDO $pdo, array $input): array
{
    $item = promotion_validate($input);
    $exists = isset($item['id']);
    $item['id'] ??= bin2hex(random_bytes(16));
    $values = [];
    foreach (promotion_columns() as $key => $column) {
        $value = $item[$key];
        $values[] = is_array($value)
            ? json_encode($value, JSON_THROW_ON_ERROR)
            : (is_bool($value) ? (int)$value : $value);
    }
    if ($exists) {
        $query = $pdo->prepare('SELECT id FROM promotions WHERE id=?');
        $query->execute([$item['id']]);
        if (!$query->fetchColumn()) {
            throw new BookingException('Không tìm thấy ưu đãi.', 404);
        }
        $assignments = array_map(static fn(string $column): string => $column . '=?', array_values(promotion_columns()));
        $sql = 'UPDATE promotions SET ' . implode(',', $assignments) . ' WHERE id=?';
    } else {
        $sql = 'INSERT INTO promotions (' . implode(',', array_values(promotion_columns()))
            . ',id) VALUES (' . implode(',', array_fill(0, count($values) + 1, '?')) . ')';
    }
    $values[] = $item['id'];
    $pdo->prepare($sql)->execute($values);
    return $item;
}

function promotion_phone(string $phone): string
{
    $phone = preg_replace('/[\s().-]+/', '', trim($phone));
    if (str_starts_with($phone, '+84')) {
        $phone = '0' . substr($phone, 3);
    }
    return $phone;
}

function promotion_guest_name(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? '', 'UTF-8');
}

function promotion_completed_stays(PDO $pdo, string $phone, string $email, string $name = ''): int
{
    $phone = promotion_phone($phone);
    $email = strtolower(trim($email));
    $name = promotion_guest_name($name);
    if (count(array_filter([$phone, $email, $name], static fn(string $value): bool => $value !== '')) < 2) {
        return 0;
    }
    // Normalize only in the targeted SQL predicate; never load guest histories into PHP.
    $clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(b.guest_phone),' ',''),'-',''),'(',''),')',''),'.',''),CHAR(9),'')";
    $normalized = "CASE WHEN SUBSTR($clean,1,3)='+84' THEN "
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "'0' || SUBSTR($clean,4)" : "CONCAT('0',SUBSTR($clean,4))") . " ELSE $clean END";
    $parts = [];
    $params = [];
    if ($phone !== '') {
        $parts[] = "$normalized = ?";
        $params[] = $phone;
    }
    if ($email !== '') {
        $parts[] = 'LOWER(TRIM(b.guest_email)) = ?';
        $params[] = $email;
    }
    // Checkout charges records the real completion time and supersedes scheduled departure.
    $sql = "SELECT b.guest_name,b.guest_phone,b.guest_email FROM bookings b LEFT JOIN booking_checkout_charges c ON c.booking_id=b.id "
        . "WHERE b.status='CHECKED_OUT' AND b.is_test=0 AND COALESCE(c.actual_checkout_at,b.check_out)<=? AND (" . implode(' OR ', $parts) . ')';
    array_unshift($params, gmdate('Y-m-d H:i:s'));
    $query = $pdo->prepare($sql);
    $query->execute($params);
    $count = 0;
    while ($booking = $query->fetch(PDO::FETCH_ASSOC)) {
        $matches = (int)($phone !== '' && promotion_phone((string)$booking['guest_phone']) === $phone)
            + (int)($email !== '' && strtolower(trim((string)$booking['guest_email'])) === $email)
            + (int)($name !== '' && promotion_guest_name((string)$booking['guest_name']) === $name);
        if ($matches >= 2) {
            $count++;
        }
    }
    return $count;
}

function promotion_price(PDO $pdo, array $room, array $package, DateTimeImmutable $start, string $phone, string $email, string $name = ''): array
{
    $start = $start->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    $base = (int)($package['basePrice'] ?? $package['price']);
    $percent = (int)($package['effectiveDiscountPercent'] ?? 0);
    $winner = null;
    $stays = null;
    foreach (promotion_list($pdo) as $promotion) {
        if (!$promotion['enabled'] || $promotion['percent'] <= $percent) {
            continue;
        }
        $day = $start->format('Y-m-d');
        if (($promotion['startDate'] && $day < $promotion['startDate']) || ($promotion['endDate'] && $day > $promotion['endDate'])) {
            continue;
        }
        if (($promotion['roomIds'] && !in_array($room['id'], $promotion['roomIds'], true))
            || ($promotion['packageIds'] && !in_array($package['id'], $promotion['packageIds'], true))) {
            continue;
        }
        if ($promotion['type'] === 'SCHEDULE') {
            $clock = $start->format('H:i');
            if (!in_array((int)$start->format('N'), $promotion['weekdays'], true) || $clock < $promotion['startTime'] || $clock >= $promotion['endTime']) {
                continue;
            }
        } else {
            $stays ??= promotion_completed_stays($pdo, $phone, $email, $name);
            if ($stays < $promotion['minStays']) {
                continue;
            }
        }
        $winner = $promotion;
        $percent = $promotion['percent'];
    }
    return array_merge($package, [
        'basePrice' => $base,
        'price' => room_discount_price($base, $percent),
        'effectiveDiscountPercent' => $percent,
        'promotionName' => $winner['name'] ?? null,
        'promotionId' => $winner['id'] ?? null,
    ]);
}

function promotion_snapshot(PDO $pdo, string $bookingId, array $package): void
{
    $prefix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $query = $pdo->prepare($prefix . ' INTO booking_promotion_snapshots '
        . '(booking_id,base_price,price,discount_percent,promotion_id,promotion_name) VALUES (?,?,?,?,?,?)');
    $agreed = isset($package['agreedPrice']);
    $query->execute([
        $bookingId,
        (int)($package['basePrice'] ?? $package['price']),
        (int)($package['agreedPrice'] ?? $package['price']),
        $agreed ? 0 : (int)($package['effectiveDiscountPercent'] ?? 0),
        $agreed ? null : ($package['promotionId'] ?? null),
        $agreed ? null : ($package['promotionName'] ?? null),
    ]);
}
