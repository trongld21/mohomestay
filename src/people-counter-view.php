<?php

declare(strict_types=1);

function render_people_counter_tabs(bool $offline): void
{
    $tabClass = 'flex min-h-11 flex-1 items-center justify-center rounded-xl border px-4 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e] sm:flex-none';
    $activeClass = 'border-[#79543e] bg-[#79543e] text-white! hover:bg-[#624734]';
    $inactiveClass = 'border-[#d8c9b8] bg-white text-[#624734] hover:bg-[#f4ece1]';
    ?>
    <nav class="flex w-full min-w-0 shrink-0 gap-2 sm:w-auto" aria-label="Chế độ đếm người" data-counter-tabs>
        <a class="<?= $tabClass ?> <?= !$offline ? $activeClass : $inactiveClass ?>" href="/admin/people-counter" <?= !$offline ? 'aria-current="page"' : '' ?>>Trực tiếp</a>
        <a class="<?= $tabClass ?> <?= $offline ? $activeClass : $inactiveClass ?>" href="/admin/people-counter?tab=offline" <?= $offline ? 'aria-current="page"' : '' ?>>Video đã ghi</a>
    </nav>
    <?php
}

function render_people_counter_page(): void
{
    $offline = ($_GET['tab'] ?? '') === 'offline';
    if ($offline) {
        require_once __DIR__ . '/people-counter-offline-view.php';
        render_people_counter_offline_page();
        return;
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
    $button = 'min-h-11 rounded-xl border border-[#d8c9b8] bg-white px-4 py-2 text-sm font-semibold text-[#624734] enabled:hover:bg-[#f4ece1] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e] disabled:cursor-not-allowed disabled:opacity-40';
    $input = 'box-border min-h-11 w-full min-w-0 max-w-full rounded-xl border border-[#d8c9b8] bg-white px-3 py-2 text-base text-[#45382f] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e] sm:text-sm';
    ?>
    <section class="mx-auto grid w-full min-w-0 max-w-[1680px] gap-4 text-[#45382f] sm:gap-5" data-people-counter>
        <header class="grid gap-3">
            <p class="text-xs font-semibold tracking-widest text-[#79543e]">MƠ HOME · VẬN HÀNH</p>
            <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="min-w-0 font-sans text-3xl font-semibold">Lượt vào / ra</h1>
                <?php render_people_counter_tabs(false); ?>
            </div>
        </header>

        <div class="rounded-xl border border-[#e5dacb] bg-[#fffdf9] p-4 text-sm leading-relaxed" data-counter-notice role="status">Đang tải tình trạng camera.</div>
        <p class="hidden rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-counter-error role="alert"></p>

        <form class="grid min-w-0 items-end gap-3 min-[360px]:grid-cols-2 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto]" data-counter-filter>
            <label class="grid min-w-0 gap-2 text-sm font-medium min-[360px]:col-span-2 lg:col-span-4">Loại báo cáo
                <select class="<?= $input ?>" name="mode">
                    <option value="trial">Lượt AI ghi nhận · chưa đối soát</option>
                    <option value="operational">Dữ liệu vận hành</option>
                </select>
            </label>
            <label class="grid min-w-0 gap-2 text-sm font-medium min-[360px]:col-span-2 lg:col-span-1">Camera
                <select class="<?= $input ?>" name="cameraId"><option value="entrance">Lễ tân / cửa chính</option></select>
            </label>
            <label class="grid min-w-0 gap-2 text-sm font-medium">Từ ngày
                <input class="<?= $input ?>" type="date" name="from" value="<?= e($today) ?>" required>
            </label>
            <label class="grid min-w-0 gap-2 text-sm font-medium">Đến ngày
                <input class="<?= $input ?>" type="date" name="to" value="<?= e($today) ?>" required>
            </label>
            <button class="<?= $button ?> min-[360px]:col-span-2 lg:col-span-1" type="submit">Xem báo cáo</button>
        </form>

        <div class="grid min-w-0 gap-4 2xl:grid-cols-[minmax(0,1fr)_16rem]">
            <section class="min-w-0 overflow-hidden rounded-2xl border border-[#e5dacb] bg-white" aria-labelledby="counter-camera-title">
                <header class="flex flex-wrap items-center justify-between gap-3 p-3 sm:p-4">
                    <div class="min-w-0 flex-1 basis-48">
                        <h2 id="counter-camera-title" class="font-sans text-lg font-semibold">Camera · theo dõi AI đếm người</h2>
                        <p class="mt-1 text-xs text-stone-600" data-counter-session-label>Phiên AI thử nghiệm · đang chờ số đếm trực tiếp</p>
                    </div>
                    <button class="<?= $button ?> w-full sm:w-auto" type="button" data-counter-pause aria-pressed="false">Tạm dừng hình ảnh</button>
                </header>
                <div class="relative flex aspect-video min-h-44 w-full max-h-[60svh] items-center justify-center overflow-hidden bg-stone-900 sm:min-h-52 lg:max-h-[45svh] 2xl:max-h-[50svh]" data-counter-screen>
                    <img class="absolute inset-0 hidden h-full w-full object-contain" alt="Camera với khung theo dõi người và vạch đếm vào ra" data-counter-image>
                    <p class="max-w-md px-4 py-3 text-center text-xs leading-relaxed text-stone-200 sm:px-6 sm:text-sm" data-counter-camera-empty>Đang chờ hình từ dịch vụ lấy video.</p>
                </div>
                <footer class="flex min-w-0 flex-wrap justify-between gap-2 p-3 text-xs text-stone-600 sm:p-4">
                    <span class="flex flex-wrap gap-x-3 gap-y-1"><span class="text-emerald-800">● IN / Vào</span><span class="text-red-800">● OUT / Ra</span><span class="text-amber-800">● Đang theo dõi</span></span>
                    <span class="min-w-0 break-words" data-counter-frame-status role="status">Đang kiểm tra hình ảnh…</span>
                </footer>
            </section>

            <div class="grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-1 2xl:grid-rows-3" aria-label="Số lượt trực tiếp trong phiên AI thử nghiệm" data-counter-stats>
                <div class="min-w-0 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 sm:p-5">
                    <p class="text-sm font-semibold text-emerald-800">↘ Lượt vào</p>
                    <p class="mt-2 break-words text-3xl font-semibold tabular-nums text-emerald-900 sm:mt-3 sm:text-4xl" data-counter-in>—</p>
                    <p class="mt-2 text-xs text-emerald-800">Đi từ ngoài vào home</p>
                </div>
                <div class="min-w-0 rounded-2xl border border-red-200 bg-red-50 p-3 sm:p-5">
                    <p class="text-sm font-semibold text-red-800">↗ Lượt ra</p>
                    <p class="mt-2 break-words text-3xl font-semibold tabular-nums text-red-900 sm:mt-3 sm:text-4xl" data-counter-out>—</p>
                    <p class="mt-2 text-xs text-red-800">Đi từ home ra ngoài</p>
                </div>
                <div class="col-span-2 min-w-0 rounded-2xl border border-[#e5dacb] bg-[#fffdf9] p-3 sm:col-span-1 sm:p-5">
                    <p class="text-sm font-semibold text-[#79543e]">Tổng lượt qua cửa</p>
                    <p class="mt-2 break-words text-3xl font-semibold tabular-nums sm:mt-3 sm:text-4xl" data-counter-total>—</p>
                    <p class="mt-2 text-xs text-stone-600">Một người có thể có nhiều lượt</p>
                </div>
            </div>
        </div>

        <section class="min-w-0 rounded-2xl border border-[#e5dacb] bg-white p-4 sm:p-5" aria-labelledby="counter-chart-title">
            <p class="mb-3 text-sm font-semibold" data-counter-report-title>Báo cáo AI · khoảng ngày đã chọn</p>
            <p class="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <span>Vào: <strong data-counter-report-in>—</strong></span>
                <span>Ra: <strong data-counter-report-out>—</strong></span>
                <span>Tổng: <strong data-counter-report-total>—</strong></span>
            </p>
            <p class="mb-4 text-xs text-stone-600" data-counter-report-note>Lịch sử bắt đầu từ khi bật lưu từng lượt. Tổng phiên cạnh camera có thể bao gồm lượt cũ chưa có thời gian; dữ liệu AI cần đối soát thực tế.</p>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="counter-chart-title" class="font-sans text-lg font-semibold">Nhịp ra vào theo giờ</h2>
                </div>
                <p class="flex gap-4 text-xs"><span class="text-emerald-800">● Vào</span><span class="text-red-800">● Ra</span></p>
            </div>
            <div class="mt-5 overflow-x-auto" tabindex="0" aria-label="Biểu đồ lượt qua cửa, có thể cuộn ngang" data-counter-chart></div>
        </section>

        <section class="min-w-0 overflow-hidden rounded-2xl border border-[#e5dacb] bg-white" aria-labelledby="counter-history-title">
            <header class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
                <h2 id="counter-history-title" class="font-sans text-lg font-semibold">Lịch sử qua cửa</h2>
                <p class="text-xs text-stone-600" data-counter-updated></p>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-y border-[#e5dacb] bg-[#faf6ef] text-stone-600">
                        <tr>
                            <th class="px-3 py-3 font-medium sm:px-5" scope="col">Thời điểm</th>
                            <th class="px-3 py-3 font-medium sm:px-5" scope="col">Hướng</th>
                            <th class="hidden px-5 py-3 font-medium sm:table-cell" scope="col">Camera</th>
                        </tr>
                    </thead>
                    <tbody data-counter-events>
                        <tr><td class="px-5 py-8 text-stone-600" colspan="3">Đang tải lịch sử…</td></tr>
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-col gap-3 border-t border-[#e5dacb] p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                <p class="text-xs text-stone-600 sm:text-sm" data-counter-page></p>
                <div class="flex gap-2">
                    <button class="<?= $button ?> flex-1 sm:flex-none" type="button" data-counter-prev disabled>Trước</button>
                    <button class="<?= $button ?> flex-1 sm:flex-none" type="button" data-counter-next disabled>Sau</button>
                </div>
            </footer>
        </section>
    </section>
    <script defer src="<?= e(asset_url('/assets/people-counter.js')) ?>"></script>
    <?php
}
