<?php

declare(strict_types=1);

function render_booking_success(string $code): void
{
    ?>
    <section class="booking-success-page" data-booking-success data-code="<?= e($code) ?>">
        <div class="booking-success-shell">
            <div class="booking-success-heading">
                <span class="booking-success-mark" data-success-mark aria-hidden="true" hidden><?= room_detail_icon('check') ?></span>
                <h1 data-success-title>Đang kiểm tra đặt phòng</h1>
                <p data-success-intro>Vui lòng chờ Mơ xác nhận thông tin đơn của bạn.</p>
            </div>
            <div data-success-content aria-live="polite" aria-busy="true"><p class="text-center text-sm text-[#736b61]">Đang tải thông tin đặt phòng…</p></div>
            <noscript><p class="notice">Vui lòng bật JavaScript hoặc gọi <a href="tel:0357907153">0357 907 153</a> để kiểm tra đặt phòng.</p></noscript>
        </div>
    </section>
    <script defer src="<?= e(asset_url('/assets/booking-success.js')) ?>"></script>
    <?php
}
