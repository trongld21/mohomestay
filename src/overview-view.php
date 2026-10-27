<?php

declare(strict_types=1);

require_once __DIR__ . '/revenue-integration.php';

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
    $money = static fn($value): string => number_format((float)$value, 0, ',', '.') . 'đ';
    $control = 'min-h-11 rounded-lg border border-[#d2bda7] bg-white px-4 py-2 text-[#70432c] hover:bg-[#faf5ed] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]';
    ?>
    <section class="space-y-6" aria-labelledby="overview-title">
        <div>
            <p class="text-sm font-medium uppercase tracking-widest text-[#79543e]">Dòng tiền của Mơ</p>
            <h1 id="overview-title" class="mt-3 text-3xl font-semibold">Tổng quan</h1>
            <p class="mt-2 text-sm text-stone-600">Tiền đã nhận theo ngày thanh toán thành công, giờ Việt Nam. Gồm tiền phòng, bán hàng và phụ thu; không gồm đơn thử hoặc đơn phòng đã hủy.</p>
        </div>
        <form method="get" action="/admin/overview" class="flex flex-wrap items-end gap-4 rounded-xl border border-[#e5dacb] bg-white p-5">
            <label class="grid gap-2 text-sm">Từ ngày
                <input class="<?= $control ?>" type="date" name="from" value="<?= e(is_string($from) ? $from : '') ?>" required>
            </label>
            <label class="grid gap-2 text-sm">Đến ngày
                <input class="<?= $control ?>" type="date" name="to" value="<?= e(is_string($to) ? $to : '') ?>" required>
            </label>
            <button class="<?= $control ?>" type="submit">Xem tiền nhận</button>
            <a class="<?= $control ?> inline-flex items-center" href="/admin/overview">Tháng này</a>
            <p class="w-full text-sm text-stone-500">Chọn tối đa 93 ngày. Bao gồm cả ngày bắt đầu và ngày kết thúc.</p>
        </form>
        <?php if ($error !== ''): ?>
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><?= e($error) ?></p>
        <?php endif ?>
        <?php if ($report !== null): ?>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <?php foreach (['collected' => 'Tổng tiền nhận', 'room' => 'Tiền phòng', 'retail' => 'Bán hàng', 'surcharge' => 'Phụ thu'] as $key => $label): ?>
                    <div class="rounded-xl border border-[#e5dacb] bg-white p-5">
                        <p class="text-sm text-stone-600"><?= e($label) ?></p>
                        <p class="mt-3 text-2xl font-semibold text-[#70432c]"><?= e($money($report['totals'][$key])) ?></p>
                    </div>
                <?php endforeach ?>
            </div>
            <div class="overflow-x-auto rounded-xl border border-[#e5dacb] bg-white p-4">
                <table class="w-full text-sm">
                    <caption class="mb-4 text-left text-lg font-semibold">Tiền nhận từng ngày</caption>
                    <thead>
                        <tr class="border-b border-[#e5dacb] text-left">
                            <th class="whitespace-nowrap p-3" scope="col">Ngày</th>
                            <?php foreach (['Tiền phòng', 'Bán hàng', 'Phụ thu', 'Tổng nhận'] as $label): ?>
                                <th class="whitespace-nowrap p-3 text-right" scope="col"><?= e($label) ?></th>
                            <?php endforeach ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['days'] as $day): ?>
                            <tr class="border-b border-stone-100 hover:bg-[#faf5ed]">
                                <th class="whitespace-nowrap p-3 text-left font-normal" scope="row"><?= e(DateTimeImmutable::createFromFormat('!Y-m-d', $day['date'])->format('d/m/Y')) ?></th>
                                <?php foreach (['room', 'retail', 'surcharge', 'collected'] as $key): ?>
                                    <td class="whitespace-nowrap p-3 text-right tabular-nums <?= $key === 'collected' ? 'font-semibold text-[#70432c]' : '' ?>"><?= e($money($day['totals'][$key])) ?></td>
                                <?php endforeach ?>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-[#faf5ed] font-semibold">
                            <th class="p-3 text-left" scope="row">Tổng cộng</th>
                            <?php foreach (['room', 'retail', 'surcharge', 'collected'] as $key): ?>
                                <td class="whitespace-nowrap p-3 text-right tabular-nums"><?= e($money($report['totals'][$key])) ?></td>
                            <?php endforeach ?>
                        </tr>
                    </tfoot>
                </table>
                <?php if ($report['totals']['collected'] == 0): ?>
                    <p class="mt-4 text-sm text-stone-600">Chưa có tiền nhận trong khoảng ngày đã chọn.</p>
                <?php endif ?>
            </div>
        <?php endif ?>
    </section>
    <?php
}
