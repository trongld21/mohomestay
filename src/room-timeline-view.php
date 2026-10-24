<?php

declare(strict_types=1);

require_once __DIR__ . '/datetime-picker-view.php';

function render_room_timeline(?array $room = null): void
{
    $staff = $room === null;
    ?>
    <section class="room-timeline <?= $staff ? 'staff-availability' : 'my-8' ?> rounded-2xl border border-[#e5dacb] bg-[#fffdf9] p-4 text-[#45382f] sm:p-6"
        data-room-timeline data-room-id="<?= e($room['id'] ?? '') ?>" data-staff="<?= $staff ? 'true' : 'false' ?>"
        aria-label="<?= $staff ? 'Tìm phòng trống' : 'Lịch trống của phòng' ?>">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="mb-1 text-xs font-semibold tracking-widest text-[#79543e]">LỊCH PHÒNG · GIỜ VIỆT NAM</p>
                <h2 class="font-sans text-xl font-semibold sm:text-2xl"><?= $staff ? 'Tìm phòng trống' : 'Giờ nào còn trống?' ?></h2>
                <?php if (!$staff): ?>
                    <p class="mt-2 text-sm text-[#736b61]">Mỗi ô là 1 giờ. Bấm ô trống để chọn gói khả dụng và xác nhận khung giờ.</p>
                <?php endif ?>
            </div>
            <button type="button" class="timeline-button" data-timeline-refresh>Làm mới lịch</button>
        </div>
        <?php if ($staff): ?>
            <form class="mb-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-timeline-search>
                <?php render_datetime_picker('from', 'Giờ vào'); ?>
                <?php render_datetime_picker('to', 'Giờ ra'); ?>
                <button class="timeline-button timeline-primary" type="submit">Tìm phòng trống</button>
            </form>
            <div class="mb-5 grid gap-3" data-timeline-results aria-live="polite"></div>
        <?php endif ?>
        <div class="mb-3 flex flex-wrap items-end gap-3">
            <label class="grid gap-1 text-sm font-medium">Từ ngày
                <input class="timeline-input" type="date" data-timeline-date required>
            </label>
            <label class="grid gap-1 text-sm font-medium">Số ngày
                <select class="timeline-input" data-timeline-days>
                    <option value="1" <?= $staff ? 'selected' : '' ?>>1 ngày</option>
                    <option value="3">3 ngày</option>
                    <option value="7" <?= !$staff ? 'selected' : '' ?>>7 ngày</option>
                </select>
            </label>
            <button class="timeline-button" type="button" data-timeline-now>Xem giờ hiện tại</button>
        </div>
        <div class="mb-3 flex flex-wrap gap-x-4 gap-y-2 text-xs" aria-label="Chú thích lịch">
            <span class="flex items-center gap-2"><i class="size-3 rounded border border-emerald-700 bg-emerald-50" aria-hidden="true"></i>Trống</span>
            <span class="flex items-center gap-2"><i class="size-3 rounded bg-amber-200" aria-hidden="true"></i>Đã đặt / dọn phòng</span>
            <span class="flex items-center gap-2"><i class="size-3 rounded bg-red-200" aria-hidden="true"></i>Đang khóa</span>
            <span class="flex items-center gap-2"><i class="size-3 rounded bg-sky-200" aria-hidden="true"></i>Đang giữ báo giá</span>
            <span class="flex items-center gap-2"><i class="size-3 rounded bg-stone-200" aria-hidden="true"></i>Đã qua / ngưng nhận</span>
        </div>
        <p class="mb-3 text-sm text-[#736b61]">Mỗi cột là một khoảng giờ. Ví dụ: trả phòng lúc 10:00 → dọn 10:00–11:00 → nhận khách mới từ 11:00.</p>
        <p class="mb-3 text-sm text-[#736b61]" data-timeline-status role="status">Đang kết nối lịch phòng…</p>
        <div class="<?= $staff ? 'overflow-x-auto rounded-none' : 'max-h-[30rem] overflow-auto rounded-xl' ?> border border-[#e5dacb] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800"
            data-timeline-scroll tabindex="0" role="region" aria-label="Ma trận lịch phòng, cuộn ngang để xem các giờ">
            <div data-timeline-grid aria-busy="true"></div>
        </div>
        <p class="mt-3 text-sm text-[#736b61]" data-timeline-selection aria-live="polite">Hệ thống kiểm tra lại lịch khi xác nhận đặt.</p>
        <section class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4" data-timeline-holds-section hidden aria-label="Mở khóa phòng đang giữ">
            <h3 class="text-lg font-semibold">Khung giờ đang giữ</h3>
            <p class="mt-2 text-sm text-stone-700" data-timeline-holds-status role="status"></p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2" data-timeline-holds></div>
        </section>
        <section class="mt-4 rounded-xl border border-[#d2bda7] bg-[#faf5ed] p-4" data-timeline-booking hidden aria-label="Chọn gói đặt phòng">
            <h3 class="text-lg font-semibold" data-timeline-package-title>Chọn gói khả dụng</h3>
            <p class="mt-2 text-sm text-[#736b61]" data-timeline-package-status role="status"></p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-timeline-packages></div>
            <p class="mt-3 text-sm font-semibold" data-timeline-booking-summary hidden></p>
            <div class="mt-4 flex flex-wrap gap-3">
                <button class="timeline-button timeline-primary" type="button" data-timeline-booking-confirm hidden>Xác nhận khung giờ · Nhập thông tin</button>
                <button class="timeline-button" type="button" data-timeline-booking-cancel>Hủy chọn</button>
            </div>
        </section>
        <div class="mt-5 border-t border-[#e5dacb] pt-4" data-timeline-operations hidden>
            <h3 class="text-lg font-semibold">Khách lưu trú & phụ thu</h3>
            <p class="mt-1 text-sm text-[#736b61]">Chỉ xác nhận trả khi khách đã rời phòng. Miễn 15 phút đầu, phần trễ còn lại làm tròn giờ × 50.000đ.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2" data-timeline-stays></div>
        </div>
        <dialog class="timeline-dialog" data-timeline-dialog aria-label="Xác nhận trả phòng và phụ thu">
            <div class="flex items-start justify-between gap-4 border-b border-[#e5dacb] p-4">
                <h3 class="text-lg font-semibold" data-checkout-title>Trả phòng & phụ thu</h3>
                <button class="timeline-button" type="button" data-checkout-close aria-label="Đóng cửa sổ phụ thu">Đóng</button>
            </div>
            <div class="p-4" data-checkout-body></div>
        </dialog>
    </section>
    <script defer src="<?= e(asset_url('/assets/room-timeline.js')) ?>"></script>
    <?php
}
