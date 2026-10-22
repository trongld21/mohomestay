<?php

function render_door_codes(): void
{
    $field = 'min-h-12 w-full rounded-lg border border-[#ddd5ca] bg-white px-3 py-3 text-sm text-[#302d29] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]';
    ?>
    <div data-door-codes>
        <div class="admin-page-heading door-page-heading">
            <div>
                <h1>Mã mở cửa</h1>
                <p class="muted">Quản lý mã cổng theo đơn và mã nhập thủ công tại Mơ.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="button outline" data-door-refresh><?= admin_icon('refresh') ?> Làm mới</button>
                <button type="button" class="button" data-door-add><?= admin_icon('plus') ?> Thêm mã mở cửa</button>
            </div>
        </div>
        <section class="mb-6 rounded-2xl border border-stone-200 bg-white p-5 sm:p-6" aria-labelledby="booking-access-title">
            <h2 id="booking-access-title" class="text-xl font-semibold">Mã cổng theo đơn đặt phòng</h2>
            <p data-ttlock-status role="status" class="mt-2 text-sm text-stone-600"></p>
            <p class="mt-2 text-sm text-stone-600">Mã tự sinh không cần gateway. Đệm ít nhất 1 giờ trước/sau lưu trú, làm tròn ra ngoài theo giờ. Dùng lần đầu trong 24 giờ từ lúc bắt đầu hiệu lực. Hủy đơn cần xóa mã tại khóa nếu không có gateway.</p>
            <div data-booking-access-list class="mt-4 grid gap-3" aria-live="polite"></div>
        </section>
        <section class="door-list-panel" aria-labelledby="door-list-title">
            <div class="door-list-heading">
                <div>
                    <h2 id="door-list-title">Danh sách mã <span class="admin-count" data-door-count>—</span></h2>
                    <p data-door-summary>Trạng thái được cập nhật theo thời gian hiệu lực của từng mã.</p>
                </div>
                <span class="door-time-note">Giờ Việt Nam · UTC+7</span>
            </div>
            <div class="admin-filters door-filters">
                <label class="admin-search">Tìm mã mở cửa<input type="search" data-door-search placeholder="Nhập mã cần tìm…" autocomplete="off"></label>
                <label>Trạng thái
                    <select data-door-filter>
                        <option value="all">Tất cả trạng thái</option>
                        <option value="ACTIVE">Đang dùng</option>
                        <option value="SCHEDULED">Chưa đến hạn</option>
                        <option value="EXPIRED">Hết hạn</option>
                        <option value="DISABLED">Đã tắt</option>
                    </select>
                </label>
            </div>
            <p data-door-notice role="status" class="door-notice" aria-live="polite" hidden></p>
            <div data-door-list aria-busy="true"><p class="door-loading" role="status">Đang tải mã mở cửa…</p></div>
        </section>
        <dialog class="door-dialog" data-door-dialog aria-labelledby="door-form-title" aria-describedby="door-form-description">
            <header class="door-dialog-heading">
                <div>
                    <h2 id="door-form-title">Thêm mã mở cửa</h2>
                    <p id="door-form-description">Thiết lập mã và khoảng thời gian sử dụng.</p>
                </div>
                <button class="icon-button" type="button" data-door-close aria-label="Đóng biểu mẫu"><?= admin_icon('x') ?></button>
            </header>
            <p class="mb-4 text-sm text-stone-600">Mã nhập thủ công chỉ lưu trên web, không tự ghi vào khóa. Khi bật TTLock, email dùng mã riêng theo đơn.</p>
                <form data-door-form>
                <input name="id" type="hidden">
                <div class="door-dialog-body">
                    <label class="grid gap-2 font-medium sm:col-span-2">Mã mở cửa <span class="sr-only">(bắt buộc)</span>
                        <input name="code" class="<?= e($field) ?>" required maxlength="100" autocomplete="off" spellcheck="false" placeholder="Ví dụ: 012345#" autofocus>
                    </label>
                    <label class="grid gap-2 font-medium">Loại mã
                        <select name="period" data-native-select class="<?= e($field) ?>">
                            <option value="DAY">Theo ngày</option>
                            <option value="WEEK">Theo tuần</option>
                            <option value="MONTH">Theo tháng</option>
                        </select>
                    </label>
                    <label class="grid gap-2 font-medium">Trạng thái sử dụng
                        <select name="status" data-native-select class="<?= e($field) ?>">
                            <option value="ACTIVE">Bật sử dụng</option>
                            <option value="DISABLED">Tắt sử dụng</option>
                        </select>
                    </label>
                    <div class="door-time-heading sm:col-span-2"><h3>Thời gian hiệu lực</h3><span>Giờ Việt Nam · UTC+7</span></div>
                    <label class="grid gap-2 font-medium">Bắt đầu
                        <input type="datetime-local" name="startsAt" class="<?= e($field) ?>" required>
                    </label>
                    <label class="grid gap-2 font-medium">Kết thúc
                        <input type="datetime-local" name="endsAt" class="<?= e($field) ?>" required>
                    </label>
                    <p class="door-time-help sm:col-span-2">Khi đến thời điểm kết thúc, mã tự chuyển sang Hết hạn. Bạn có thể chỉnh thời gian để gia hạn.</p>
                    <p data-door-error role="alert" class="door-form-error sm:col-span-2" hidden></p>
                    <div data-door-discard class="door-discard sm:col-span-2" hidden>
                        <strong>Bỏ thay đổi chưa lưu?</strong>
                        <p>Nội dung vừa nhập sẽ không được lưu.</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="button outline" data-door-continue>Tiếp tục sửa</button>
                            <button type="button" class="button outline" data-door-discard-confirm>Bỏ thay đổi</button>
                        </div>
                    </div>
                </div>
                <footer class="door-dialog-footer">
                    <button type="button" class="button outline" data-door-close>Hủy</button>
                    <button type="submit" class="button" data-door-save><?= admin_icon('device-floppy') ?> Lưu mã</button>
                </footer>
            </form>
        </dialog>
    </div>
    <script defer src="<?= e(asset_url('/assets/door-codes.js')) ?>"></script>
    <?php
}
