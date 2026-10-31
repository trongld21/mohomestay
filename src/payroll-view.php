<?php

declare(strict_types=1);

function render_payroll_salary_fields(): void
{
    ?>
    <label class="hr-label">Loại lương
        <select class="hr-field" name="salary_type" data-native-select><option value="hourly">Theo giờ</option><option value="monthly">Theo tháng</option></select>
    </label>
    <label class="hr-label">Đơn giá giờ (đ)
        <input class="hr-field" name="hourly_rate" type="number" min="0" max="1000000000" step="1" value="0" required>
    </label>
    <label class="hr-label">Lương tháng (đ)
        <input class="hr-field" name="monthly_salary" type="number" min="0" max="1000000000" step="1" value="0" required>
    </label>
    <label class="hr-label">Ngày công chuẩn
        <input class="hr-field" name="expected_days" type="number" min="1" max="31" step="1" value="26" required>
    </label>
    <?php
}

function render_payroll_page(bool $personnel): void
{
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    ?>
    <section class="hr-module" data-payroll data-screen="<?= $personnel ? 'personnel' : 'payroll' ?>" data-csrf="<?= e(csrf_token()) ?>" aria-labelledby="hr-title">
        <header class="hr-toolbar">
            <div>
                <h1 id="hr-title"><?= $personnel ? 'Quản lý nhân sự' : 'Tính lương' ?></h1>
                <p class="hr-muted mt-2 text-sm"><?= $personnel ? 'Hồ sơ, vị trí và mức lương của đội ngũ Mơ.' : 'Quản lý ca làm, mức lương và các khoản cộng trừ theo kỳ.' ?></p>
            </div>
            <div class="hr-header-actions">
                <nav class="hr-actions hr-module-tabs" aria-label="Nhân sự và tính lương">
                    <a class="hr-button" href="/admin/payroll" <?= !$personnel ? 'aria-current="page"' : '' ?>><?= admin_icon('file-description') ?>Bảng lương</a>
                    <a class="hr-button" href="/admin/personnel" <?= $personnel ? 'aria-current="page"' : '' ?>><?= admin_icon('user') ?>Nhân sự</a>
                    <a class="hr-button" href="/admin/timesheet"><?= admin_icon('calendar') ?>Chấm công</a>
                </nav>
                <?php if ($personnel): ?>
                    <button class="hr-button hr-primary" type="button" data-new>Thêm nhân viên</button>
                <?php endif ?>
            </div>
        </header>
        <noscript><p class="hr-status" data-error="true">Bật JavaScript để quản lý nhân sự và tính lương.</p></noscript>
        <section class="hr-surface" data-create <?= $personnel ? 'hidden' : '' ?>>
            <h2 class="mb-5" data-create-title><?= $personnel ? 'Thêm nhân viên' : 'Tạo kỳ lương' ?></h2>
            <form data-create-form class="<?= $personnel ? '' : 'hr-period-create-form' ?>">
                <?php if ($personnel): ?>
                    <input type="hidden" name="id"><input type="hidden" name="version">
                    <div class="hr-form-grid">
                        <label class="hr-label">Họ và tên<input class="hr-field" name="name" maxlength="150" required autocomplete="name"></label>
                        <label class="hr-label">Vị trí / công việc<input class="hr-field" name="role" maxlength="100" placeholder="Ví dụ: Lễ tân, buồng phòng"></label>
                        <label class="hr-label">Điện thoại<input class="hr-field" name="phone" type="tel" maxlength="30" autocomplete="tel"></label>
                        <label class="hr-label">Email<input class="hr-field" name="email" type="email" maxlength="190" autocomplete="email"></label>
                        <label class="hr-label">Ngày vào làm<input class="hr-field" name="start_date" type="date" value="<?= $today->format('Y-m-d') ?>" required></label>
                        <label class="hr-label">Trạng thái<select class="hr-field" name="status" data-native-select><option value="active">Đang làm</option><option value="inactive">Đã nghỉ việc</option></select></label>
                        <label class="hr-label">Ngày nghỉ việc<input class="hr-field" name="end_date" type="date"></label>
                        <?php render_payroll_salary_fields(); ?>
                        <label class="hr-label sm:col-span-2">Ghi chú<textarea class="hr-field" name="note" rows="2" maxlength="2000"></textarea></label>
                    </div>
                <?php else: ?>
                    <div class="hr-form-grid">
                        <label class="hr-label">Tên kỳ lương<input class="hr-field" name="name" maxlength="150" placeholder="Lương tháng <?= $today->format('m/Y') ?>" required></label>
                        <div class="hr-label sm:col-span-2" data-date-range>
                            <span>Khoảng ngày</span>
                            <input class="hr-field" name="start_date" type="date" aria-label="Từ ngày" value="<?= $today->format('Y-m-01') ?>" required>
                            <input class="hr-field" name="end_date" type="date" aria-label="Đến ngày" value="<?= $today->format('Y-m-t') ?>" required>
                        </div>
                    </div>
                <?php endif ?>
                <div class="hr-actions mt-5" data-create-actions>
                    <button class="hr-button hr-primary" type="submit"><?= $personnel ? 'Lưu hồ sơ' : 'Tạo bảng lương' ?></button>
                    <button class="hr-button" type="button" data-close-create><?= $personnel ? 'Hủy' : 'Đặt lại' ?></button>
                </div>
                <?php if (!$personnel): ?>
                    <label class="mt-4 flex min-h-11 items-center gap-3 text-sm hr-muted"><input type="checkbox" name="copy_employees" checked>Thêm nhân viên đang làm vào kỳ mới.</label>
                <?php endif ?>
            </form>
        </section>
        <section data-list-section class="grid min-w-0 gap-4">
            <form class="hr-actions" data-filter role="search">
                <label class="hr-label flex-1">Tìm kiếm<input class="hr-field" type="search" name="q" placeholder="<?= $personnel ? 'Tên, mã, vị trí hoặc điện thoại' : 'Tên kỳ lương' ?>"></label>
                <?php if ($personnel): ?>
                    <label class="hr-label">Trạng thái<select class="hr-field" name="status" data-native-select><option value="">Tất cả</option><option value="active">Đang làm</option><option value="inactive">Đã nghỉ việc</option></select></label>
                <?php endif ?>
                <button class="hr-button hr-icon-button" type="submit" aria-label="Lọc" title="Lọc"><?= admin_icon('filter') ?></button>
                <button class="hr-button hr-icon-button" type="button" data-reload aria-label="Tải lại" title="Tải lại"><?= admin_icon('refresh') ?></button>
            </form>
            <div class="hr-surface hr-table-wrap" data-list aria-live="polite"><p class="hr-state">Đang tải dữ liệu…</p></div>
        </section>
        <?php if (!$personnel): ?>
            <section data-detail class="grid min-w-0 gap-5" hidden>
                <section class="hr-surface hr-ledger-overview">
                    <header class="hr-ledger-heading">
                        <div class="hr-actions min-w-0">
                            <button class="hr-button hr-icon-button hr-open-period" type="button" data-back aria-label="Danh sách kỳ lương" title="Danh sách kỳ lương"><?= admin_icon('arrow-left') ?></button>
                            <div class="min-w-0"><h2>Chi tiết bảng lương <span class="hr-tag" data-period-count></span></h2><p class="hr-muted mt-1 text-sm" data-period-name></p></div>
                        </div>
                        <div class="hr-ledger-actions">
                            <button class="hr-button hr-ledger-actions-trigger" type="button" data-period-actions popovertarget="hr-payroll-actions" aria-haspopup="menu" aria-expanded="false" aria-controls="hr-payroll-actions">Thao tác<svg class="admin-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m6 9 6 6 6-6"/></svg></button>
                            <div class="hr-ledger-actions-menu" id="hr-payroll-actions" popover="auto" role="menu" aria-label="Thao tác bảng lương" data-period-actions-menu>
                                <a class="hr-ledger-action-item" data-action-item data-export href="#" role="menuitem" title="Xuất toàn bộ nhân viên trong kỳ"><?= admin_icon('file-description') ?>Xuất Excel</a>
                                <button class="hr-ledger-action-item" type="button" data-action-item data-period-excel-open role="menuitem"><?= admin_icon('file-description') ?>Upload Excel nhân viên</button>
                                <button class="hr-ledger-action-item" type="button" data-action-item data-show-add role="menuitem" aria-controls="hr-add-period-employee"><?= admin_icon('plus') ?>Thêm nhân viên</button>
                            </div>
                        </div>
                    </header>
                    <div class="hr-summary hr-ledger-summary" data-summary></div>
                    <div class="hr-ledger-period"><p class="hr-muted text-sm" data-period-meta></p></div>
                    <form class="hr-actions px-5 pb-5" id="hr-add-period-employee" data-add-employee hidden>
                        <label class="hr-label min-w-0 flex-1">Thêm nhân viên vào kỳ<select class="hr-field" name="employee_id" data-native-select required></select></label>
                        <button class="hr-button" type="submit">Thêm vào bảng lương</button>
                    </form>
                </section>
                <section class="hr-surface hr-ledger-review">
                    <header class="hr-ledger-filters">
                        <div class="hr-toolbar">
                            <div><h2>Rà soát nhân viên</h2><p class="hr-muted mt-1 text-sm" data-review-count></p></div>
                            <details class="hr-ledger-columns"><summary class="hr-button">Cột hiển thị</summary><div class="hr-ledger-column-options">
                                <label><input type="checkbox" data-ledger-column="base">Lương cơ bản</label>
                                <label><input type="checkbox" data-ledger-column="allowance">Phụ cấp / thưởng</label>
                                <label><input type="checkbox" data-ledger-column="deduction">Khấu trừ</label>
                            </div></details>
                        </div>
                        <div class="hr-ledger-search" role="search" aria-label="Lọc nhân viên trong kỳ">
                            <label class="hr-label"><span class="sr-only">Tìm nhân viên trong kỳ</span><input class="hr-field" type="search" placeholder="Tìm theo mã, tên hoặc vị trí" data-review-search></label>
                            <label class="hr-label"><span class="sr-only">Lọc vị trí trong kỳ</span><select class="hr-field" data-review-role data-native-select><option value="">Tất cả vị trí</option></select></label>
                            <label class="hr-label"><span class="sr-only">Sắp xếp nhân viên trong kỳ</span><select class="hr-field" data-review-sort data-native-select><option value="hours">Giờ làm giảm dần</option><option value="name">Tên A–Z</option><option value="salary">Thực nhận giảm dần</option></select></label>
                        </div>
                        <div class="hr-actions" data-review-quick aria-label="Lọc nhanh nhân viên"></div>
                    </header>
                    <div class="hr-ledger-scroll" data-entries></div>
                    <footer class="hr-ledger-footer" data-review-total></footer>
                </section>
                <section class="hr-surface" data-entry-editor hidden>
                    <h2 class="mb-2" data-entry-name></h2>
                    <div class="mb-6 grid gap-3" data-timesheet-upload>
                        <label class="hr-label">
                            <input class="hr-field" type="file" accept=".xlsx,.txt" data-timesheet-file>
                        </label>
                        <label class="hr-label" data-text-source-label hidden>Nhân viên trong file TXT
                            <select class="hr-field" data-text-source></select>
                        </label>
                        <div class="grid gap-3" data-timesheet-preview hidden></div>
                    </div>
                    <form data-entry-form>
                        <input name="employee_id" type="hidden">
                        <div class="hr-form-grid">
                            <?php render_payroll_salary_fields(); ?>
                            <label class="hr-label">Cộng T7, CN (đ/giờ)<input class="hr-field" name="weekend_bonus_rate" type="number" min="0" max="1000000000" step="1" value="1000" required></label>
                            <label class="hr-label">Giờ Bonus T7/CN<input class="hr-field" name="weekend_hours" type="number" min="0" max="4800" step="0.01" placeholder="Tự tính từ ca T7/CN"></label>
                            <label class="hr-label">Ngày nghỉ hưởng lương<input class="hr-field" name="paid_leave_days" type="number" min="0" max="31" step="1" value="0" required></label>
                            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="prorate">Lương tháng tính theo ngày công</label>
                        </div>
                        <div class="mt-6 grid gap-3">
                            <div class="hr-toolbar"><h3>Ca làm</h3><button class="hr-button" type="button" data-add-row="shifts">Thêm ca</button></div>
                            <p class="hr-muted text-sm">Giờ ra trước giờ vào là ca qua đêm. Ngày công tính theo ngày bắt đầu ca.</p>
                            <div class="hr-repeat" data-rows="shifts"></div>
                        </div>
                        <?php foreach (['allowances' => 'Phụ cấp / thưởng', 'deductions' => 'Khấu trừ / tạm ứng'] as $key => $label): ?>
                            <div class="mt-6 grid gap-3">
                                <div class="hr-toolbar"><h3><?= $label ?></h3><button class="hr-button" type="button" data-add-row="<?= $key ?>">Thêm khoản</button></div>
                                <div class="hr-repeat" data-rows="<?= $key ?>"></div>
                            </div>
                        <?php endforeach ?>
                        <label class="hr-label mt-5">Ghi chú<textarea class="hr-field" name="note" rows="2" maxlength="2000"></textarea></label>
                        <div class="hr-actions mt-5"><button class="hr-button hr-positive" type="submit">Lưu và tính lương</button><button class="hr-button" type="button" data-close-entry>Đóng</button></div>
                    </form>
                </section>
            </section>
        <?php endif ?>
        <div class="hr-confirm" data-confirm hidden role="dialog" aria-modal="true" aria-labelledby="hr-confirm-title">
            <div class="hr-surface"><h2 id="hr-confirm-title">Xác nhận thao tác</h2><p class="mt-3" data-confirm-text></p><div class="hr-actions mt-5 justify-end"><button class="hr-button" type="button" data-confirm-cancel>Hủy</button><button class="hr-button hr-primary" type="button" data-confirm-accept>Xác nhận</button></div></div>
        </div>
    </section>
    <script defer src="<?= e(asset_url('/assets/payroll-dates.js')) ?>"></script>
    <script defer src="<?= e(asset_url('/assets/payroll-attendance.js')) ?>"></script>
    <script defer src="<?= e(asset_url('/assets/payroll-excel-import.js')) ?>"></script>
    <script defer src="<?= e(asset_url('/assets/payroll.js')) ?>"></script>
    <?php
}
