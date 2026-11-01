<?php

declare(strict_types=1);

function render_timesheet_page(): void
{
    ?>
    <section class="hr-module" data-timesheet data-csrf="<?= e(csrf_token()) ?>" aria-labelledby="timesheet-title">
        <header class="hr-toolbar border-b border-[var(--hr-line)] pb-5">
            <div>
                <h1 id="timesheet-title" class="hr-income">Chấm công</h1>
                <p class="hr-muted mt-2 text-sm">Import từ máy chấm công hoặc bảng Excel của nhân viên.</p>
            </div>
            <nav class="hr-actions hr-module-tabs" aria-label="Nhân sự và tính lương">
                <a class="hr-button" href="/admin/payroll"><?= admin_icon('file-description') ?>Bảng lương</a>
                <a class="hr-button" href="/admin/personnel"><?= admin_icon('user') ?>Nhân sự</a>
                <a class="hr-button" href="/admin/timesheet" aria-current="page"><?= admin_icon('calendar') ?>Chấm công</a>
            </nav>
        </header>
        <section class="hr-surface hr-timesheet-surface overflow-visible">
            <header class="border-b border-[var(--hr-line)] p-5">
                <h2>Dữ liệu chấm công</h2>
                <p class="hr-muted mt-1 text-sm" data-import-description>Chọn file từ máy chấm công và khoảng ngày cần xử lý.</p>
                <div class="hr-actions mt-4" aria-label="Loại dữ liệu chấm công">
                    <button class="hr-button hr-timesheet-mode" type="button" data-import-mode="machine" aria-pressed="true"><?= admin_icon('calendar') ?>Máy chấm công (.txt)</button>
                    <button class="hr-button hr-timesheet-mode" type="button" data-import-mode="excel" aria-pressed="false"><?= admin_icon('file-description') ?>Excel nhân viên (.xlsx)</button>
                </div>
            </header>
            <form class="grid items-start gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)_auto]" data-timesheet-form>
                <input type="hidden" name="source_format" value="machine">
                <label class="hr-label lg:col-span-3" data-excel-employee hidden>Nhân viên
                    <select class="hr-field" name="employee_id" disabled><option value="">Chọn nhân viên</option></select>
                </label>
                <div class="hr-label">
                    <span id="timesheet-file-label">File chấm công</span>
                    <label class="hr-field relative flex cursor-pointer items-center gap-2 border-dashed hr-info focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[var(--hr-accent)]" for="timesheet-file">
                        <?= admin_icon('file-description') ?>
                        <span class="min-w-0 truncate" data-file-name>Chọn file TXT hoặc CSV</span>
                        <input class="absolute inset-0 h-full w-full cursor-pointer opacity-0" id="timesheet-file" name="timesheet" type="file" accept=".txt,.csv" aria-labelledby="timesheet-file-label" required>
                    </label>
                </div>
                <div class="hr-label" data-date-range>
                    <span>Khoảng thời gian</span>
                    <input class="hr-field" name="start_date" type="date" aria-label="Từ ngày" required>
                    <input class="hr-field" name="end_date" type="date" aria-label="Đến ngày" required>
                    <div class="hr-actions" aria-label="Chọn nhanh khoảng ngày">
                        <button class="hr-button min-h-8 px-2 py-1 text-xs" type="button" data-range-preset="first">Đợt 1 tháng này</button>
                        <button class="hr-button min-h-8 px-2 py-1 text-xs" type="button" data-range-preset="second">Đợt 2 tháng này</button>
                    </div>
                </div>
                <button class="hr-button hr-positive lg:mt-7" type="submit" data-calculate disabled><?= admin_icon('calendar') ?>Tính giờ làm</button>
                <p class="hr-muted text-xs lg:col-span-3" data-format-hint>TXT/CSV UTF-8 từ máy chấm công, tối đa 5 MB. Mã nhân viên ở cột 3, tên ở cột 4, thời điểm quẹt ở cột 7.</p>
            </form>
        </section>
        <section class="border-y border-[var(--hr-line)] py-14 text-center" data-timesheet-empty aria-live="polite">
            <span class="mb-3 inline-flex hr-muted"><?= admin_icon('calendar') ?></span>
            <h2>Chưa có dữ liệu chấm công</h2>
            <p class="hr-muted mt-2 text-sm">Chọn file và khoảng ngày phía trên để bắt đầu tính giờ làm.</p>
        </section>
        <section class="grid gap-4" data-timesheet-results hidden aria-label="Kết quả chấm công"></section>
        <noscript><p class="hr-status" data-error="true">Bật JavaScript để nhập chấm công.</p></noscript>
    </section>
    <script defer src="<?= e(asset_url('/assets/payroll-dates.js')) ?>"></script>
    <script defer src="<?= e(asset_url('/assets/timesheet.js')) ?>"></script>
    <?php
}
