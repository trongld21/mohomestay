<?php

declare(strict_types=1);

function queue_staff_notification(PDO $pdo, array $booking, string $event, ?array $charge = null): void
{
    if (!empty($booking['is_test'])) return;
    if (!in_array($event, ['BOOKED', 'CHECKED_OUT', 'SURCHARGE'], true)) {
        throw new InvalidArgumentException('Unsupported staff notification.');
    }
    $query = $pdo->prepare('SELECT id FROM staff_email_notifications WHERE booking_id=? AND event_type=?');
    $query->execute([$booking['id'], $event]);
    if ($query->fetchColumn()) return;
    $roomQuery = $pdo->prepare('SELECT name FROM rooms WHERE id=?');
    $roomQuery->execute([$booking['room_id']]);
    // Snapshot only the information needed to operate the stay; never include PINs, identity images or tokens.
    $payload = [
        'code' => $booking['booking_code'],
        'room' => (string)($roomQuery->fetchColumn() ?: $booking['room_id']),
        'guest' => $booking['guest_name'] ?? '',
        'phone' => $booking['guest_phone'] ?? '',
        'checkIn' => $booking['check_in'],
        'checkOut' => $booking['check_out'],
        'total' => (int)($booking['total_price'] ?? 0),
        'paymentStatus' => $booking['payment_status'] ?? '',
        'actualCheckout' => $charge['actual_checkout_at'] ?? null,
        'surcharge' => (int)($charge['amount'] ?? 0),
        'billableHours' => (int)($charge['billable_hours'] ?? 0),
    ];
    $query = $pdo->prepare('INSERT INTO staff_email_notifications (id,booking_id,event_type,recipient,payload) VALUES (?,?,?,?,?)');
    $query->execute([
        uuid(), $booking['id'], $event, 'mohomestay01@gmail.com',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function build_staff_notification_email(string $event, array $payload): array
{
    $title = match ($event) {
        'BOOKED' => 'Có đặt phòng mới',
        'CHECKED_OUT' => 'Khách đã trả phòng',
        'SURCHARGE' => 'Phụ thu cần thu',
        default => throw new InvalidArgumentException('Unsupported staff notification.'),
    };
    $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = [
        'Mã đơn' => $payload['code'],
        'Phòng' => $payload['room'],
        'Khách' => $payload['guest'],
        'Điện thoại' => $payload['phone'],
        'Nhận phòng' => booking_mail_local_time($payload['checkIn']),
        'Trả dự kiến' => booking_mail_local_time($payload['checkOut']),
        'Tiền phòng' => number_format($payload['total'], 0, ',', '.') . 'đ',
        'Thanh toán phòng' => $payload['paymentStatus'] === 'PAID' ? 'Đã thanh toán' : 'Chưa thanh toán',
    ];
    if ($payload['actualCheckout']) {
        $rows['Trả thực tế'] = booking_mail_local_time($payload['actualCheckout']);
        $rows['Phụ thu'] = number_format($payload['surcharge'], 0, ',', '.') . 'đ';
    }
    if ($event === 'SURCHARGE') {
        $rows['Giờ tính phụ thu'] = $payload['billableHours'];
        $rows['Trạng thái phụ thu'] = 'Chưa thu';
    }
    $html = '<!doctype html><html lang="vi"><body style="font-family:Arial,sans-serif;color:#45382f">'
        . '<h2>' . $escape($title) . '</h2><table role="presentation" style="border-collapse:collapse">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="padding:6px 14px 6px 0">' . $escape($label)
            . '</td><td style="padding:6px 0"><strong>' . $escape($value) . '</strong></td></tr>';
    }
    $html .= '</table><p>Mở trang quản trị Mơ Home để kiểm tra chi tiết.</p></body></html>';
    return ['subject' => 'Mơ Home · ' . $title . ' · ' . $payload['code'], 'html' => $html];
}

function send_staff_notification_message(array $message, string $recipient, ?callable $transport = null, ?string $messageId = null): bool
{
    $from = trim((string)config('mail.from', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new BookingException('Chưa cấu hình MAIL_FROM hợp lệ trên hosting.', 422);
    }
    if ($transport === null && !function_exists('mail')) {
        throw new BookingException('Hosting chưa bật hàm PHP mail().', 503);
    }
    $headers = [
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/html; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'From' => mb_encode_mimeheader('Mơ Home', 'UTF-8') . ' <' . $from . '>',
        'Message-ID' => '<mo-staff-' . ($messageId ?? bin2hex(random_bytes(16))) . '@' . substr(strrchr($from, '@'), 1) . '>',
    ];
    $send = $transport ?? static fn($to, $subject, $html, $headers): bool => mail($to, $subject, $html, $headers);
    return (bool)$send($recipient, mb_encode_mimeheader($message['subject'], 'UTF-8'), $message['html'], $headers);
}

function send_staff_test_email(string $event, ?callable $transport = null): bool
{
    $now = time();
    $payload = [
        'code' => 'DEMO-' . gmdate('YmdHis', $now),
        'room' => 'Phòng minh họa',
        'guest' => 'Khách minh họa · không phải đơn thật',
        'phone' => '0900 000 000',
        'checkIn' => gmdate('Y-m-d H:i:s', $now - 10800),
        'checkOut' => gmdate('Y-m-d H:i:s', $now - 4200),
        'total' => 200000,
        'paymentStatus' => 'PAID',
        'actualCheckout' => $event === 'BOOKED' ? null : gmdate('Y-m-d H:i:s', $now),
        'surcharge' => $event === 'SURCHARGE' ? 50000 : 0,
        'billableHours' => $event === 'SURCHARGE' ? 1 : 0,
    ];
    $message = build_staff_notification_email($event, $payload);
    $message['subject'] = '[THỬ EMAIL] ' . $message['subject'];
    $message['html'] = str_replace('<h2>', '<p><strong>EMAIL THỬ · Không phát sinh đặt phòng hoặc thanh toán.</strong></p><h2>', $message['html']);
    return send_staff_notification_message($message, 'mohomestay01@gmail.com', $transport);
}

function dispatch_staff_notifications(PDO $pdo, ?string $bookingId = null, ?callable $transport = null): int
{
    $from = trim((string)config('mail.from', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return 0;
    $retryBefore = gmdate('Y-m-d H:i:s', time() - 900);
    $sql = "SELECT * FROM staff_email_notifications WHERE attempts<5 AND "
        . "(status='PENDING' OR (status IN ('FAILED','SENDING') AND last_attempt_at<?))";
    $params = [$retryBefore];
    if ($bookingId !== null) {
        $sql .= ' AND booking_id=?';
        $params[] = $bookingId;
    }
    $query = $pdo->prepare($sql . ' ORDER BY created_at,id LIMIT 20');
    $query->execute($params);
    $sent = 0;
    foreach ($query->fetchAll() as $notification) {
        $now = gmdate('Y-m-d H:i:s');
        $claim = $pdo->prepare("UPDATE staff_email_notifications SET status='SENDING',attempts=attempts+1,last_attempt_at=? "
            . "WHERE id=? AND attempts<5 AND (status='PENDING' OR (status IN ('FAILED','SENDING') AND last_attempt_at<?))");
        $claim->execute([$now, $notification['id'], $retryBefore]);
        if ($claim->rowCount() !== 1) continue;
        $ok = false;
        try {
            $message = build_staff_notification_email(
                $notification['event_type'],
                json_decode($notification['payload'], true, 512, JSON_THROW_ON_ERROR)
            );
            $ok = send_staff_notification_message($message, $notification['recipient'], $transport, $notification['id']);
        } catch (Throwable $error) {
            error_log('Staff notification preparation/transport failed; error type: ' . get_class($error));
        }
        if (!$ok) {
            error_log('Staff notification not accepted by mail transport; queued for retry.');
        }
        $pdo->prepare('UPDATE staff_email_notifications SET status=?,sent_at=? WHERE id=?')
            ->execute([$ok ? 'SENT' : 'FAILED', $ok ? $now : null, $notification['id']]);
        if ($ok) $sent++;
    }
    return $sent;
}

function try_dispatch_staff_notifications(PDO $pdo, string $bookingId): void
{
    try {
        dispatch_staff_notifications($pdo, $bookingId);
    } catch (Throwable) {
        error_log('Staff notification deferred; retry using staff-notifications-worker.php.');
    }
}
