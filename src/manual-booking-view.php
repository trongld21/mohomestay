<?php

declare(strict_types=1);

function render_manual_booking_dialog(): void
{
    ?>
    <dialog class="door-dialog manual-booking-dialog" data-manual-booking-dialog aria-labelledby="manual-booking-title" aria-describedby="manual-booking-description">
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
                <label class="field">Gói lưu trú *
                    <select name="packageId" required data-native-select><option value="">Chọn phòng trước</option></select>
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
                <h3 class="manual-section-title sm:col-span-2">Thanh toán</h3>
                <label class="field">Trạng thái thanh toán *
                    <select name="paymentStatus" required data-native-select><option value="UNPAID">Chưa thanh toán</option><option value="PAID">Đã thanh toán đủ</option></select>
                </label>
                <label class="field">Phương thức thanh toán *
                    <select name="paymentMethod" required data-native-select><option value="cash">Tiền mặt</option><option value="bank_transfer">Chuyển khoản</option></select>
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
