<?php

declare(strict_types=1);

function render_promotions_page(): void
{
    $editable = can_use('rooms.edit');
    ?>
    <section
        class="mx-auto grid w-full max-w-6xl gap-6 p-4 text-stone-800 sm:p-6"
        data-promotions
        data-editable="<?= $editable ? 'true' : 'false' ?>">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-widest text-amber-900">MƠ HOME · QUẢN LÝ PHÒNG</p>
                <h1 class="mt-2 font-sans text-2xl font-semibold">Chương trình ưu đãi</h1>
                <p class="mt-2 text-sm text-stone-600">Lịch lặp theo giờ nhận phòng và ưu đãi dành cho khách quay lại.</p>
            </div>
            <?php if ($editable): ?>
                <button class="timeline-button timeline-primary" type="button" data-promotion-new>Tạo ưu đãi</button>
            <?php endif ?>
        </header>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed">
            Chỉ áp dụng mức giảm cao nhất trong các ưu đãi phù hợp, bao gồm giảm giá phòng/gói hiện có. Không
            cộng dồn; chỉ giảm tiền gói, không giảm phụ thu hoặc hàng bán.
        </p>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p role="status" aria-live="polite" data-promotion-status>Đang tải ưu đãi…</p>
            <button class="timeline-button" type="button" data-promotion-reload>Tải lại</button>
        </div>
        <div class="grid gap-4" data-promotion-list></div>
        <?php if ($editable): ?>
            <section
                class="rounded-2xl border border-stone-200 bg-amber-50/40 p-4 sm:p-6"
                data-promotion-editor
                hidden
                aria-labelledby="promotion-form-title">
                <h2 id="promotion-form-title" class="font-sans text-xl font-semibold" tabindex="-1">Tạo ưu đãi</h2>
                <form class="mt-5 grid gap-5" data-promotion-form>
                    <p class="text-sm text-red-800" role="alert" data-promotion-error tabindex="-1" hidden></p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-medium">Tên chương trình
                            <input class="timeline-input" name="name" required maxlength="120" autocomplete="off">
                        </label>
                        <label class="grid gap-2 text-sm font-medium">Loại ưu đãi
                            <select class="timeline-input" name="type">
                                <option value="SCHEDULE">Theo lịch lặp</option>
                                <option value="RETURNING">Khách quay lại</option>
                            </select>
                        </label>
                        <label class="grid gap-2 text-sm font-medium">Mức giảm (%)
                            <input
                                class="timeline-input"
                                type="number"
                                name="percent"
                                min="1"
                                max="99"
                                step="1"
                                value="10"
                                required>
                        </label>
                        <label class="flex min-h-11 items-center gap-3 self-end text-sm">
                            <input
                                class="h-5 w-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-amber-800"
                                type="checkbox"
                                name="enabled"
                                checked>
                            Bật chương trình
                        </label>
                        <label class="grid gap-2 text-sm font-medium">Ngày bắt đầu (tùy chọn)
                            <input class="timeline-input" type="date" name="startDate">
                        </label>
                        <label class="grid gap-2 text-sm font-medium">Ngày kết thúc (tùy chọn)
                            <input class="timeline-input" type="date" name="endDate">
                        </label>
                    </div>
                    <p class="text-sm text-stone-600">Ngày hiệu lực tính theo ngày nhận phòng, bao gồm cả hai mốc. Bỏ trống để không giới hạn.</p>
                    <fieldset class="grid gap-4 rounded-xl border border-stone-200 p-4" data-promotion-schedule>
                        <legend class="px-1 font-semibold">Lịch nhận phòng · giờ Việt Nam</legend>
                        <div class="flex flex-wrap gap-3">
                            <?php foreach ([1 => 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'] as $day => $label): ?>
                                <label class="flex min-h-11 items-center gap-2 rounded-lg border border-stone-300 bg-white px-3 text-sm">
                                    <input
                                        class="h-4 w-4 accent-amber-900 focus-visible:outline-2 focus-visible:outline-amber-800"
                                        type="checkbox"
                                        name="weekdays"
                                        value="<?= $day ?>"
                                        checked><?=
                                        $label
                                        ?>
                                </label>
                            <?php endforeach ?>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm">Giờ bắt đầu
                                <input
                                    class="timeline-input"
                                    name="startTime"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="06:00"
                                    pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                                    value="00:00"
                                    required>
                            </label>
                            <label class="grid gap-2 text-sm">Giờ kết thúc
                                <input
                                    class="timeline-input"
                                    name="endTime"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="15:00 hoặc 24:00"
                                    pattern="(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)"
                                    value="24:00"
                                    required>
                            </label>
                        </div>
                        <p class="text-sm text-stone-600">
                            Theo giờ nhận phòng thực tế, không theo lúc đặt. Tính mốc bắt đầu, không tính mốc
                            kết thúc. Giờ kết thúc phải lớn hơn giờ bắt đầu; lịch qua đêm cần tách làm hai
                            chương trình.
                        </p>
                    </fieldset>
                    <fieldset class="grid gap-3 rounded-xl border border-stone-200 p-4" data-promotion-returning hidden disabled>
                        <legend class="px-1 font-semibold">Điều kiện khách quay lại</legend>
                        <label class="grid max-w-sm gap-2 text-sm">Số lần lưu trú hoàn tất tối thiểu
                            <input
                                class="timeline-input"
                                type="number"
                                name="minStays"
                                min="1"
                                step="1"
                                value="1"
                                required>
                        </label>
                        <p class="text-sm text-stone-600">
                            Cần trùng ít nhất 2/3 thông tin: họ tên, email, số điện thoại trên cùng một đơn cũ. Chỉ đếm đơn đã trả phòng
                            hợp lệ, không tính đơn test, hủy hoặc chưa hoàn tất.
                        </p>
                    </fieldset>
                    <fieldset class="grid gap-3 rounded-xl border border-stone-200 p-4">
                        <legend class="px-1 font-semibold">Phạm vi phòng / gói</legend>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <input
                                class="h-5 w-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-amber-800"
                                type="checkbox"
                                name="allScope"
                                checked>
                            Tất cả phòng và gói
                        </label>
                        <p class="text-sm text-stone-600">
                                                         Hai bộ lọc được áp dụng đồng thời. Không chọn phòng
                            nghĩa là mọi phòng;                             không chọn gói nghĩa là mọi gói
                            trong các phòng phù hợp.                             Muốn chọn gói ở nhiều phòng,
                            chọn các phòng đó hoặc để bộ lọc phòng trống.
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2" data-promotion-scope hidden></div>
                    </fieldset>
                    <div class="flex flex-wrap gap-3">
                        <button class="timeline-button timeline-primary" type="submit" data-promotion-save>Lưu ưu đãi</button>
                        <button class="timeline-button" type="button" data-promotion-cancel>Hủy</button>
                    </div>
                </form>
            </section>
        <?php endif ?>
        <noscript><p>Cần bật JavaScript để quản lý chương trình ưu đãi.</p></noscript>
    </section>
    <script defer src="<?= e(asset_url('/assets/promotions.js')) ?>"></script>
    <?php
}
