<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function staff_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$_ENV['MAIL_FROM'] = 'booking@example.test';
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE rooms (id TEXT,name TEXT);
INSERT INTO rooms VALUES ('r','Vintage');
CREATE TABLE staff_email_notifications (id TEXT PRIMARY KEY,booking_id TEXT,event_type TEXT,recipient TEXT,payload TEXT,status TEXT DEFAULT 'PENDING',attempts INTEGER DEFAULT 0,last_attempt_at TEXT,sent_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(booking_id,event_type));");
$booking = [
    'id' => str_repeat('a', 32), 'room_id' => 'r', 'booking_code' => 'MO-TEST',
    'guest_name' => '<b>Bích</b>', 'guest_phone' => '0901234567',
    'check_in' => '2026-10-24 03:00:00', 'check_out' => '2026-10-24 05:00:00',
    'total_price' => 200000, 'payment_status' => 'PAID',
    'access_token' => 'secret-token', 'door_code' => 'secret-pin',
];
$charge = ['actual_checkout_at' => '2026-10-24 06:10:00', 'amount' => 50000, 'billable_hours' => 1];
foreach (['BOOKED', 'CHECKED_OUT', 'SURCHARGE'] as $event) {
    queue_staff_notification($pdo, $booking, $event, $charge);
    queue_staff_notification($pdo, $booking, $event, $charge);
}
queue_staff_notification($pdo, array_replace($booking, ['id' => 'test-booking', 'is_test' => 1]), 'BOOKED');
staff_expect((int)$pdo->query('SELECT COUNT(*) FROM staff_email_notifications')->fetchColumn() === 3, 'Events must enqueue once and exclude test bookings.');
$sent = [];
$transport = static function ($recipient, $subject, $html, $headers) use (&$sent): bool {
    $sent[] = compact('recipient', 'subject', 'html', 'headers');
    return true;
};
staff_expect(dispatch_staff_notifications($pdo, null, $transport) === 3, 'All three event types must send.');
staff_expect(dispatch_staff_notifications($pdo, null, $transport) === 0, 'Sent events must not be sent again.');
foreach ($sent as $mail) {
    staff_expect($mail['recipient'] === 'mohomestay01@gmail.com', 'Use the explicitly requested staff mailbox.');
    staff_expect(str_contains($mail['html'], '&lt;b&gt;Bích&lt;/b&gt;'), 'Escape customer content.');
    staff_expect(str_contains($mail['html'], '10:00, 24/10/2026'), 'Display Vietnam timezone.');
    staff_expect(!str_contains($mail['html'], 'secret-token') && !str_contains($mail['html'], 'secret-pin'), 'Exclude access credentials.');
}
$pdo->beginTransaction();
queue_staff_notification($pdo, array_replace($booking, ['id' => 'rollback']), 'BOOKED');
$pdo->rollBack();
staff_expect((int)$pdo->query('SELECT COUNT(*) FROM staff_email_notifications')->fetchColumn() === 3, 'Rolled-back booking must not leak an email event.');
queue_staff_notification($pdo, array_replace($booking, ['id' => 'retry']), 'BOOKED');
staff_expect(dispatch_staff_notifications($pdo, 'retry', static fn(): bool => false) === 0, 'Transport failure stays retryable.');
$pdo->exec("UPDATE staff_email_notifications SET last_attempt_at='2020-01-01 00:00:00' WHERE booking_id='retry'");
staff_expect(dispatch_staff_notifications($pdo, 'retry', $transport) === 1, 'Retry must send a failed event.');
$before = (int)$pdo->query('SELECT COUNT(*) FROM staff_email_notifications')->fetchColumn();
foreach (['BOOKED', 'CHECKED_OUT', 'SURCHARGE'] as $event) {
    staff_expect(send_staff_test_email($event, $transport), 'Email test must work without a real booking.');
}
staff_expect(count($sent) === 7, 'Each email test must use the same injected mail transport.');
staff_expect((int)$pdo->query('SELECT COUNT(*) FROM staff_email_notifications')->fetchColumn() === $before, 'Sending sample emails must not create queue or booking records.');
staff_expect(str_contains($sent[6]['html'], 'EMAIL THỬ') && str_contains($sent[6]['html'], '50.000đ'), 'Test surcharge email must be clearly marked and show sample amount.');
staff_expect(admin_api_permission('/api/admin/staff-email/test', 'POST') === 'accounts', 'Email test must require root-only permission.');
$_ENV['MAIL_FROM'] = '';
queue_staff_notification($pdo, array_replace($booking, ['id' => 'unconfigured']), 'BOOKED');
staff_expect(dispatch_staff_notifications($pdo, null, $transport) === 0, 'Missing sender configuration must retain pending events.');
echo "Staff notifications recipients, snapshots, idempotency, rollback and retry: OK
";
