<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once APP_ROOT . '/src/views.php';
require_once APP_ROOT . '/src/booking-success-view.php';

ob_start();
render_booking_success('MO-1234567890');
$html = (string)ob_get_clean();
foreach (['data-booking-success', 'data-code="MO-1234567890"', 'data-success-content', 'aria-busy="true"', 'booking-success.js'] as $marker) {
    if (!str_contains($html, $marker)) throw new RuntimeException('Missing success page marker: ' . $marker);
}
if (str_contains($html, 'Đặt phòng thành công!')) throw new RuntimeException('Success must not be asserted before verifying the booking.');
echo "Booking success view tests: OK\n";
