<?php

declare(strict_types=1);

require_once __DIR__ . '/revenue-integration.php';
require_once __DIR__ . '/overview-dashboard.php';

function render_overview_page(): void
{
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    $from = $_GET['from'] ?? $today->format('Y-m-01');
    $to = $_GET['to'] ?? $today->format('Y-m-d');
    $report = null;
    $error = '';
    try {
        if (!is_string($from) || !is_string($to)) {
            throw new InvalidArgumentException('Bộ lọc ngày không hợp lệ.');
        }
        mo_revenue_dates($from, $to);
        $report = mo_revenue_report(db(), $from, $to);
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Overview report failed: ' . $exception->getMessage());
        $error = 'Chưa tải được số liệu thu tiền. Vui lòng thử lại.';
    }
    render_overview_dashboard($report, $error, is_string($from) ? $from : '', is_string($to) ? $to : '', $today);
}

function render_overview_dashboard(?array $report, string $error, string $from, string $to, DateTimeImmutable $today): void
{
    $money = static fn($value): string => number_format((float)$value, 0, ',', '.') . 'đ';
    $date = static fn($value): string => (new DateTimeImmutable($value))->format('d/m/Y');
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $types = ['room' => 'Tiền phòng', 'retail' => 'Bán hàng', 'surcharge' => 'Phụ thu', 'expense' => 'Phiếu chi'];
    $payments = ['cash' => 'Tiền mặt', 'transfer' => 'Chuyển khoản', 'other' => 'Khác'];
    $dashboard = $report ? overview_dashboard_data($report) : null;
    $presets = [
        'Hôm nay' => [$today, $today],
        'Hôm qua' => [$today->modify('-1 day'), $today->modify('-1 day')],
        '7 ngày qua' => [$today->modify('-6 days'), $today],
        'Tháng này' => [$today->modify('first day of this month'), $today],
        'Tháng trước' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
    ];
    ?>
    <section class="overview-dashboard" data-overview aria-labelledby="overview-title">
        <header class="overview-header">
            <div>
                <h1 id="overview-title">Tổng quan kinh doanh</h1>
                <p class="mt-2 max-w-2xl text-sm text-[#70645b]">Theo dõi tiền đã nhận và dòng tiền của Mơ trong khoảng thời gian bạn chọn.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button class="overview-button" type="button" data-overview-refresh hidden>Làm mới</button>
                <?php if ($report): ?>
                    <button class="overview-button overview-button-primary" type="button" data-overview-export hidden>Xuất dữ liệu</button>
                <?php endif ?>
            </div>
        </header>
        <form method="get" action="/admin/overview" class="overview-filter" data-overview-filter>
            <label class="grid gap-2 text-sm">Khoảng thời gian
                <select class="overview-field" data-overview-preset>
                    <option value="">Tùy chọn khoảng ngày</option>
                    <?php foreach ($presets as $label => [$start, $end]): ?>
                        <?php $value = $start->format('Y-m-d') . '/' . $end->format('Y-m-d'); ?>
                        <option value="<?= e($value) ?>" <?= $from . '/' . $to === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </label>
            <label class="grid gap-2 text-sm">Từ ngày
                <input class="overview-field" type="date" name="from" value="<?= e($from) ?>" required>
            </label>
            <label class="grid gap-2 text-sm">Đến ngày
                <input class="overview-field" type="date" name="to" value="<?= e($to) ?>" required>
            </label>
            <button class="overview-button overview-button-primary" type="submit">Xem tổng quan</button>
            <p class="w-full text-xs text-[#70645b]">Giờ Việt Nam · Tối đa 93 ngày, tính cả ngày bắt đầu và kết thúc.</p>
        </form>
        <?php if ($error !== ''): ?>
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><?= e($error) ?></p>
        <?php endif ?>
        <?php if ($report && $dashboard): ?>
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-[#70645b]">
                <span><?= e($date($from)) ?> – <?= e($date($to)) ?></span>
                <span>Cập nhật lúc <?= e((new DateTimeImmutable($report['updatedAt']))->setTimezone($zone)->format('H:i d/m/Y')) ?></span>
            </div>
            <section class="overview-panel" aria-labelledby="overview-summary-title">
                <header class="overview-panel-heading">
                    <div><h2 id="overview-summary-title">Bức tranh dòng tiền</h2><p>Tổng hợp theo ngày thanh toán thành công.</p></div>
                    <span class="text-xs text-[#70645b]">Đơn vị: VND</span>
                </header>
                <div class="overview-summary">
                    <div class="overview-summary-main">
                        <p>Tổng tiền nhận</p><strong><?= e($money($report['totals']['collected'])) ?></strong>
                        <span>Tiền phòng, bán hàng và phụ thu</span>
                    </div>
                    <?php foreach (['room' => 'Tiền phòng', 'retail' => 'Bán hàng', 'surcharge' => 'Phụ thu'] as $key => $label): ?>
                        <div class="overview-summary-source" data-source="<?= e($key) ?>">
                            <p><?= e($label) ?></p><strong><?= e($money($report['totals'][$key])) ?></strong>
                            <span><?= $report['totals']['collected'] > 0 ? number_format($report['totals'][$key] / $report['totals']['collected'] * 100, 1, ',', '.') : '0' ?>% tổng thu</span>
                        </div>
                    <?php endforeach ?>
                </div>
                <dl class="overview-facts">
                    <?php foreach ([
                        ['Lượt thu tiền', number_format($dashboard['receipt_count'])],
                        ['Trung bình mỗi lượt thu', $money($dashboard['average'])],
                        ['Phiếu chi', $money($report['totals']['expense'])],
                        ['Thu trừ chi', $money($dashboard['net'])],
                    ] as [$label, $value]): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach ?>
                </dl>
                <dl class="overview-payments">
                    <?php foreach ($payments as $key => $label): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($money($report['totals'][$key])) ?></dd></div>
                    <?php endforeach ?>
                </dl>
                <p class="border-t border-[#e7ded2] px-5 py-3 text-xs leading-5 text-[#70645b]">Không gồm đơn thử hoặc đơn phòng đã hủy. Thu trừ chi là dòng tiền, chưa phải lợi nhuận. Phiếu chi chỉ tính các phiếu được chọn đưa vào dòng tiền.</p>
            </section>
            <?php if ($report['totals']['collected'] == 0): ?>
                <p class="rounded-xl border border-dashed border-[#dfd3c4] bg-[#fffdf9] p-5 text-sm text-[#70645b]">Chưa có tiền nhận trong khoảng ngày đã chọn. Bạn có thể chọn khoảng ngày khác hoặc xem các phiếu chi bên dưới.</p>
            <?php endif ?>
            <div class="grid min-w-0 gap-5 xl:grid-cols-2">
                <?php foreach (['collected' => 'Tiền nhận theo thời gian', 'count' => 'Lượt thu tiền theo thời gian'] as $metric => $title): ?>
                    <section class="overview-panel min-w-0" data-overview-chart>
                        <header class="overview-panel-heading">
                            <div><h2><?= e($title) ?></h2><p><?= $metric === 'collected' ? 'Tiền thực nhận, không cộng phiếu chi.' : 'Số giao dịch thu tiền, không phải số khách lưu trú.' ?></p></div>
                        </header>
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-4">
                            <div class="flex gap-1" role="group" aria-label="Khoảng thời gian biểu đồ">
                                <button class="overview-chart-tab" type="button" data-chart-period="day" aria-pressed="true">Theo ngày</button>
                                <button class="overview-chart-tab" type="button" data-chart-period="hour" aria-pressed="false" hidden>Theo giờ</button>
                            </div>
                            <strong class="text-sm text-[#346044]"><?= e($metric === 'collected' ? $money($report['totals']['collected']) : $dashboard['receipt_count'] . ' lượt') ?></strong>
                        </div>
                        <div data-chart-series="day"><?php render_overview_chart($dashboard['days'], $metric, 'overview-' . $metric . '-day'); ?></div>
                        <div data-chart-series="hour" hidden><?php render_overview_chart($dashboard['hours'], $metric, 'overview-' . $metric . '-hour'); ?></div>
                        <p class="px-5 pb-4 text-xs text-[#70645b]">Theo giờ: cộng gộp giao dịch cùng khung giờ trong khoảng ngày đã chọn.</p>
                    </section>
                <?php endforeach ?>
            </div>
            <section class="overview-panel" aria-labelledby="overview-daily-title">
                <header class="overview-panel-heading">
                    <div><h2 id="overview-daily-title">Dòng tiền từng ngày</h2><p>Đối soát tiền phòng, bán hàng, phụ thu và chi phí.</p></div>
                </header>
                <div class="overflow-x-auto">
                    <table class="overview-table">
                        <caption class="sr-only">Tiền nhận từng ngày trong khoảng đã chọn</caption>
                        <thead><tr><th scope="col">Ngày</th><th scope="col">Tiền phòng</th><th scope="col">Bán hàng</th><th scope="col">Phụ thu</th><th scope="col">Phiếu chi</th><th scope="col">Tổng nhận</th></tr></thead>
                        <tbody>
                            <?php foreach ($report['days'] as $day): ?>
                                <tr>
                                    <th scope="row"><?= e($date($day['date'])) ?></th>
                                    <?php foreach (['room', 'retail', 'surcharge', 'expense', 'collected'] as $key): ?>
                                        <td class="<?= $key === 'collected' ? 'font-semibold text-[#346044]' : '' ?>"><?= e($money($day['totals'][$key])) ?></td>
                                    <?php endforeach ?>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                        <tfoot><tr><th scope="row">Tổng cộng</th>
                            <?php foreach (['room', 'retail', 'surcharge', 'expense', 'collected'] as $key): ?>
                                <td><?= e($money($report['totals'][$key])) ?></td>
                            <?php endforeach ?>
                        </tr></tfoot>
                    </table>
                </div>
            </section>
            <div class="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                <section class="overview-panel min-w-0" aria-labelledby="overview-rooms-title">
                    <header class="overview-panel-heading"><div><h2 id="overview-rooms-title">Tiền nhận theo phòng</h2><p>Tiền phòng và phụ thu · Top 10 trong kỳ</p></div></header>
                    <ol class="divide-y divide-[#e7ded2]">
                        <?php foreach (array_slice($dashboard['rooms'], 0, 10) as $index => $room): ?>
                            <li class="flex items-center gap-3 px-5 py-4 text-sm">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-[#f3eee6] text-xs font-semibold"><?= $index + 1 ?></span>
                                <span class="min-w-0 flex-1 break-words"><?= e($room['name']) ?></span>
                                <strong class="whitespace-nowrap text-[#346044] tabular-nums"><?= e($money($room['amount'])) ?></strong>
                            </li>
                        <?php endforeach ?>
                    </ol>
                    <?php if (!$dashboard['rooms']): ?><p class="p-5 text-sm text-[#70645b]">Chưa có tiền phòng hoặc phụ thu trong khoảng ngày này.</p><?php endif ?>
                </section>
                <section class="overview-panel min-w-0" aria-labelledby="overview-recent-title">
                    <header class="overview-panel-heading"><div><h2 id="overview-recent-title">Giao dịch gần đây</h2><p>10 lượt thu / chi mới nhất trong khoảng ngày đã chọn.</p></div></header>
                    <ul class="divide-y divide-[#e7ded2]">
                        <?php foreach (array_slice($dashboard['transactions'], 0, 10) as $transaction): ?>
                            <li class="flex items-start justify-between gap-3 px-5 py-4 text-sm">
                                <div class="min-w-0">
                                    <p class="break-words font-semibold"><?= e($transaction['code']) ?></p>
                                    <p class="mt-1 text-xs text-[#70645b]"><?= e($transaction['local_time']) ?> · <?= e($types[$transaction['type']]) ?></p>
                                    <p class="mt-1 break-words text-xs text-[#70645b]"><?= e($transaction['room']) ?></p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <strong class="tabular-nums <?= $transaction['type'] === 'expense' ? 'text-red-700' : 'text-[#346044]' ?>"><?= $transaction['type'] === 'expense' ? '−' : '+' ?><?= e($money($transaction['amount'])) ?></strong>
                                    <p class="mt-1 text-xs text-[#70645b]"><?= e($payments[$transaction['payment']] ?? 'Chi tiền') ?></p>
                                </div>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <?php if (!$dashboard['transactions']): ?><p class="p-5 text-sm text-[#70645b]">Chưa có giao dịch trong khoảng ngày này.</p><?php endif ?>
                </section>
            </div>
            <script type="application/json" data-overview-data><?= json_encode(['from' => $from, 'to' => $to, 'transactions' => $dashboard['transactions']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
        <?php endif ?>
        <script defer src="<?= e(function_exists('asset_url') ? asset_url('/assets/overview.js') : '/assets/overview.js') ?>"></script>
    </section>
    <?php
}
