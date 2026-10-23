<?php

declare(strict_types=1);

function render_mock_payment_view(): void
{
    ?>
    <section class="mx-auto max-w-2xl px-5 py-12" data-mock-payments>
        <h1 class="mb-4 font-sans text-3xl font-semibold">Test đặt phòng & callback</h1>
        <p class="mb-6 rounded-xl border border-[#dccbb7] bg-[#f3ece1] p-4 text-sm leading-7">Chỉ dùng local và cần đăng nhập admin. Đơn thử giữ lịch 15 phút, không giao dịch ngân hàng, không gửi email hoặc mã cửa. Không dùng thông tin khách thật.</p>
        <form class="grid grid-cols-1 gap-4 rounded-xl border border-[#e6dccf] bg-white p-5 sm:grid-cols-2" data-mock-form>
            <label class="field">Phòng<select name="roomId" required></select></label>
            <label class="field">Gói lưu trú<select name="packageId" required></select></label>
            <label class="field">Ngày nhận<input name="date" type="date" required></label>
            <label class="field">Giờ nhận (24 giờ)<input name="time" type="time" required value="14:00"></label>
            <button class="button rounded-lg sm:col-span-2" type="submit">1. Tạo đơn thử chờ thanh toán</button>
        </form>
        <div class="mt-6 space-y-4" data-mock-result role="status"></div>
    </section>
    <script defer src="<?= e(asset_url('/assets/mock-payments.js')) ?>"></script>
    <?php
}
