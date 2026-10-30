<?php

declare(strict_types=1);

require __DIR__ . '/booking-recovery-test.php';
$pdo->exec('DELETE FROM bookings; DELETE FROM payments; DELETE FROM booking_payment_receipts; DELETE FROM booking_checkout_charges;');
$now = time();
$stamp = static fn(int $offset): string => gmdate('Y-m-d H:i:s', $now + $offset);
$add = $pdo->prepare("INSERT INTO bookings(id,room_id,booking_code,status,payment_status,total_price,check_in,check_out,hold_expires_at,is_test,booking_source) VALUES (?,'r',?,?,'UNPAID',159000,?,?,?,0,'WEBSITE')");
$add->execute(['previous', 'MO-PREVIOUS', 'CHECKED_OUT', $stamp(-21600), $stamp(-7200), $stamp(-20000)]);
$pdo->prepare('INSERT INTO booking_checkout_charges VALUES (?,?)')->execute(['previous', $stamp(-3600)]);
$add->execute(['receipt', 'MO-RECEIPT', 'PENDING', $stamp(-3000), $stamp(7200), $stamp(-2000)]);
$result = confirm_booking_receipt($pdo, 'receipt', 'bank_transfer', 'admin');
recovery_expect($result['payment_status'] === 'PAID' && $result['status'] === 'CONFIRMED', 'Elapsed cleaning must not block a retrospective audited receipt.');
recovery_expect($result['check_in'] === $stamp(-3000) && $result['check_out'] === $stamp(7200), 'Receipt must preserve the original booked time.');
confirm_booking_receipt($pdo, 'receipt', 'bank_transfer', 'admin');
recovery_expect((int)$pdo->query("SELECT COUNT(*) FROM booking_payment_receipts WHERE booking_id='receipt'")->fetchColumn() === 1, 'Receipt retries must keep one audit entry.');

$pdo->exec("UPDATE bookings SET payment_status='UNPAID',status='PENDING' WHERE id='receipt'");
$pdo->prepare("UPDATE booking_checkout_charges SET actual_checkout_at=? WHERE booking_id='previous'")->execute([$stamp(-2400)]);
try {
    confirm_booking_receipt($pdo, 'receipt', 'bank_transfer', 'admin');
    throw new RuntimeException('A real historical stay overlap was accepted.');
} catch (BookingException $error) {
    recovery_expect($error->httpStatus === 409, 'Actual occupancy overlap remains blocked.');
}
$pdo->exec("DELETE FROM bookings WHERE id='previous'; DELETE FROM booking_checkout_charges;");
$pdo->prepare("UPDATE bookings SET check_in=?,check_out=? WHERE id='receipt'")->execute([$stamp(-14400), $stamp(-7200)]);
$result = confirm_booking_receipt($pdo, 'receipt', 'bank_transfer', 'admin');
recovery_expect($result['status'] === 'CHECKED_OUT' && $result['payment_status'] === 'PAID', 'Past free bookings can record receipt without becoming active stays.');
recovery_expect(!booking_access_allowed($result), 'A completed historical receipt must not receive a new access code.');

$pdo->exec("DELETE FROM payments; DELETE FROM booking_payment_receipts; UPDATE bookings SET payment_status='UNPAID',status='PENDING';");
$pdo->prepare("UPDATE bookings SET check_in=?,check_out=? WHERE id='receipt'")->execute([$stamp(7200), $stamp(18000)]);
$add->execute(['future', 'MO-FUTURE', 'CONFIRMED', $stamp(1800), $stamp(6600), $stamp(900)]);
try {
    confirm_booking_receipt($pdo, 'receipt', 'bank_transfer', 'admin');
    throw new RuntimeException('Future cleaning conflict was accepted.');
} catch (BookingException $error) {
    recovery_expect($error->httpStatus === 409, 'Future stays still require all 30 minutes of cleaning.');
}
recovery_expect((int)$pdo->query('SELECT COUNT(*) FROM booking_payment_receipts')->fetchColumn() === 0, 'Rejected future conflict must not record receipt.');
echo "Past and future receipts: elapsed cleaning, actual conflicts, preserved dates, audit and no expired code OK\n";
