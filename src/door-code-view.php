<?php

function render_door_codes(): void
{
    $field = 'min-h-11 w-full rounded-xl border border-stone-300 bg-white px-3 py-2 text-stone-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800';
    $button = 'inline-flex min-h-11 items-center justify-center rounded-xl border border-stone-300 px-4 py-2 font-medium enabled:hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800 disabled:opacity-50';
    ?>
    <div class="container section" data-door-codes>
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div><span class="eyebrow">KHÔNG GIAN QUẢN LÝ</span><h1>Mã mở cửa</h1><p class="mt-2 text-stone-600">Quản lý mã dùng chung và thời gian sử dụng tại Mơ.</p></div>
            <a href="/admin" class="<?= e($button) ?>">Về trang quản lý</a>
        </div>
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(280px,360px)_1fr]">
            <section class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-6" aria-labelledby="door-form-title">
                <h2 id="door-form-title" class="mb-5 text-xl font-semibold">Thêm mã mở cửa</h2>
                <form data-door-form class="grid gap-4">
                    <input name="id" type="hidden">
                    <label class="grid gap-2 font-medium">Mã mở cửa<input name="code" class="<?= e($field) ?>" required maxlength="100" autocomplete="off" spellcheck="false" placeholder="Ví dụ: 012345#"></label>
                    <label class="grid gap-2 font-medium">Loại mã<select name="period" class="<?= e($field) ?>"><option value="DAY">Theo ngày</option><option value="WEEK">Theo tuần</option><option value="MONTH">Theo tháng</option></select></label>
                    <label class="grid gap-2 font-medium">Bắt đầu<input type="datetime-local" name="startsAt" class="<?= e($field) ?>" required></label>
                    <label class="grid gap-2 font-medium">Kết thúc<input type="datetime-local" name="endsAt" class="<?= e($field) ?>" required></label>
                    <p class="text-sm text-stone-600">Giờ Việt Nam (UTC+7). Hết thời gian này, mã tự chuyển sang Hết hạn. Bạn có thể sửa thời gian để gia hạn.</p>
                    <label class="grid gap-2 font-medium">Trạng thái thủ công<select name="status" class="<?= e($field) ?>"><option value="ACTIVE">Bật sử dụng</option><option value="DISABLED">Tắt sử dụng</option></select></label>
                    <p data-door-error role="alert" class="text-sm text-red-800" hidden></p>
                    <div class="flex flex-wrap gap-2"><button type="submit" class="<?= e($button) ?> bg-amber-900 text-white enabled:hover:bg-amber-800" data-door-save>Lưu mã</button><button type="button" class="<?= e($button) ?>" data-door-reset>Nhập mã mới</button></div>
                </form>
            </section>
            <section aria-labelledby="door-list-title" class="min-w-0">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 id="door-list-title" class="text-xl font-semibold">Danh sách mã</h2><button type="button" class="<?= e($button) ?>" data-door-refresh>Làm mới</button></div>
                <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Email xác nhận sau thanh toán chọn mã theo thời gian nhận phòng, ưu tiên Ngày → Tuần → Tháng. Nếu có nhiều mã cùng mức ưu tiên, khách nhận các mã đó kèm thời hạn.</p>
                <p data-door-notice role="status" class="mb-3 text-sm text-stone-600" aria-live="polite"></p>
                <div data-door-list class="grid gap-3"><p>Đang tải mã mở cửa…</p></div>
            </section>
        </div>
    </div>
    <script defer src="<?= e(asset_url('/assets/door-codes.js')) ?>"></script>
    <?php
}
