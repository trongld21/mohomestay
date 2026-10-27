<?php

declare(strict_types=1);

function render_manual_booking_dialog(): void
{
    ?>
    <dialog class="door-dialog manual-booking-dialog" data-manual-booking-dialog aria-labelledby="manual-booking-title" >
        <header class="door-dialog-heading">
            <div>
                <h2 id="manual-booking-title">Tạo đơn đặt phòng</h2>
            </div>
            <button class="icon-button" type="button" data-manual-close aria-label="Đóng biểu mẫu"><?= admin_icon('x') ?></button>
        </header>
        <form data-manual-booking-form>
            <div class="door-dialog-body">
                <h3 class="manual-section-title sm:col-span-2">Phòng & thời gian</h3>
                <label class="field">Phòng *
                    <select name="roomId" required data-native-select><option value="">Đang tải phòng…</option></select>
                </label>
                <label class="field">Cách chọn thời gian *
                    <select name="timingMode" required data-native-select>
                        <option value="PACKAGE" selected>Theo gói lưu trú</option>
                        <option value="CUSTOM">Thời gian tùy chọn</option>
                    </select>
                </label>
                <label class="field sm:col-span-2" data-manual-package-field hidden>Gói lưu trú *
                    <select name="packageId" required data-native-select><option value="">Chọn phòng trước</option></select>
                </label>
                <label class="flex min-h-11 items-center gap-3 rounded-lg border border-stone-200 bg-stone-50 p-3 text-sm sm:col-span-2" data-manual-now-field>
                    <input class="size-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" type="checkbox" name="startNow" value="1">
                    Nhận phòng ngay bây giờ
                </label>
                <div class="field manual-date-field">
                    <label for="manual-date-trigger">Ngày nhận phòng *</label>
                    <input name="date" type="hidden">
                    <button id="manual-date-trigger" class="manual-date-trigger" type="button" aria-haspopup="dialog" aria-controls="manual-date-popover" aria-expanded="false"><span data-manual-date-label>Chọn ngày</span><?= admin_icon('calendar') ?></button>
                    <div id="manual-date-popover" class="manual-date-popover" popover="auto" role="dialog" aria-label="Chọn ngày nhận phòng">
                        <div class="manual-calendar-heading">
                            <button type="button" data-calendar-previous aria-label="Tháng trước"><?= admin_icon('arrow-left') ?></button>
                            <strong data-calendar-title aria-live="polite"></strong>
                            <button type="button" data-calendar-next aria-label="Tháng sau"><?= admin_icon('arrow-right') ?></button>
                        </div>
                        <div class="manual-calendar-weekdays" aria-hidden="true"><span>T2</span><span>T3</span><span>T4</span><span>T5</span><span>T6</span><span>T7</span><span>CN</span></div>
                        <div class="manual-calendar-days" data-calendar-days role="group" aria-label="Các ngày trong tháng"></div>
                        <footer class="manual-calendar-footer"><span>Giờ Việt Nam · UTC+7</span><button type="button" data-calendar-today>Hôm nay</button></footer>
                    </div>
                </div>
                <fieldset class="manual-time-field">
                    <legend>Giờ nhận phòng · 24 giờ (Việt Nam) *</legend>
                    <input name="time" type="hidden">
                    <input name="arrivalHour" type="hidden">
                    <input name="arrivalMinute" type="hidden">
                    <div class="manual-time-picker">
                        <button type="button" data-time-trigger="hour" aria-haspopup="dialog" aria-controls="manual-time-popover" aria-expanded="false"><?= admin_icon('clock') ?><span data-time-hour-label>00 giờ</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                        <span aria-hidden="true">:</span>
                        <button type="button" data-time-trigger="minute" aria-haspopup="dialog" aria-controls="manual-time-popover" aria-expanded="false"><?= admin_icon('clock') ?><span data-time-minute-label>00 phút</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                    </div>
                    <div id="manual-time-popover" class="manual-time-popover" popover="auto" role="dialog" aria-label="Chọn giờ nhận phòng theo định dạng 24 giờ">
                        <section class="manual-time-column">
                            <h4 id="manual-hour-title"><?= admin_icon('clock') ?> Chọn giờ</h4>
                            <div class="manual-hour-options" role="listbox" aria-labelledby="manual-hour-title" data-time-hours></div>
                        </section>
                        <section class="manual-time-column manual-minute-column">
                            <h4 id="manual-minute-title"><?= admin_icon('clock') ?> Chọn phút</h4>
                            <div class="manual-minute-options" role="listbox" aria-labelledby="manual-minute-title" data-time-minutes></div>
                            <label class="manual-exact-minute">Phút chính xác
                                <input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact aria-label="Nhập phút chính xác từ 0 đến 59">
                            </label>
                        </section>
                    </div>
                </fieldset>
                <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2" data-manual-custom-end>
                    <label class="field">Ngày trả phòng *
                        <input class="min-h-11 rounded-lg border border-stone-300 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" name="endDate" type="date" required>
                    </label>
                    <fieldset class="min-w-0">
                        <legend class="mb-2 text-sm">Giờ trả phòng · 24 giờ (Việt Nam) *</legend>
                        <div class="flex items-center gap-2">
                            <label class="grid flex-1 gap-1 text-xs">Giờ
                                <input class="min-h-11 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" name="endHour" type="number" min="0" max="23" step="1" required>
                            </label>
                            <span class="pt-4" aria-hidden="true">:</span>
                            <label class="grid flex-1 gap-1 text-xs">Phút
                                <input class="min-h-11 w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" name="endMinute" type="number" min="0" max="59" step="1" required>
                            </label>
                        </div>
                    </fieldset>
                </div>
                <div class="manual-booking-summary sm:col-span-2" aria-live="polite" data-manual-summary>Chọn phòng và gói để xem thời gian, giá và lịch trống.</div>
                <h3 class="manual-section-title sm:col-span-2">Khách hàng</h3>
                <label class="field">Họ tên khách *
                    <input name="guestName" required minlength="2" maxlength="100" autocomplete="name">
                </label>
                <label class="field">Số điện thoại *
                    <input name="guestPhone" type="tel" required maxlength="20" autocomplete="tel" placeholder="0901 234 567">
                </label>
                <label class="field">Số khách *
                    <input name="numberOfGuests" type="number" required min="1" max="50" value="2">
                </label>
                <label class="field">Nguồn đặt phòng *
                    <select name="bookingSource" required data-native-select><option value="PHONE">Điện thoại</option><option value="ZALO">Zalo</option></select>
                </label>
                <label class="field sm:col-span-2">Email (không bắt buộc)
                    <input name="guestEmail" type="email" maxlength="254" autocomplete="email">
                </label>
                <label class="field sm:col-span-2">Ghi chú
                    <textarea name="guestNote" rows="3" maxlength="1000" placeholder="Yêu cầu của khách hoặc thông tin cần lưu ý"></textarea>
                </label>
                <?php render_booking_identity_fields(false); ?>
                <h3 class="manual-section-title sm:col-span-2">Thanh toán</h3>
                <label class="field sm:col-span-2">Giá chốt với khách (đ) *
                    <input name="agreedPrice" type="number" min="0" max="1000000000" step="1" required inputmode="numeric" >
                </label>
                <label class="field">Trạng thái thanh toán *
                    <select name="paymentStatus" required data-native-select><option value="UNPAID">Chưa thanh toán</option><option value="PAID">Đã thanh toán đủ</option></select>
                </label>
                <label class="field">Phương thức thanh toán *
                    <select name="paymentMethod" required data-native-select><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option></select>
                </label>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[#dfcdbc] bg-[#faf7f2] p-4 sm:col-span-2">
                    <input type="checkbox" name="guestCheckout" class="mt-1 h-4 w-4 accent-[#75472f] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#75472f]">
                    <span><strong>Tạo mã cổng & QR như khách tự đặt</strong><span class="mt-1 block text-sm text-[#786b60]">Chưa thanh toán: tạo QR, giữ phòng 15 phút; mã cổng được cấp sau thanh toán. Đã thanh toán: cấp mã cổng và mở trang xác nhận.</span></span>
                </label>
                <p class="door-form-error sm:col-span-2" data-manual-error role="alert" hidden></p>
                <div class="door-discard sm:col-span-2" data-manual-discard hidden>
                    <strong>Bỏ thông tin đang nhập?</strong>
                    <p>Đơn đặt phòng chưa được tạo.</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="button outline" data-manual-continue>Tiếp tục nhập</button>
                        <button type="button" class="button outline" data-manual-discard-confirm>Bỏ thay đổi</button>
                    </div>
                </div>
            </div>
            <footer class="door-dialog-footer">
                <button type="button" class="button outline" data-manual-close>Hủy</button>
                <button type="submit" class="button" data-manual-submit disabled><?= admin_icon('plus') ?> Tạo đơn & giữ lịch</button>
            </footer>
        </form>
    </dialog>
    <?php
}
