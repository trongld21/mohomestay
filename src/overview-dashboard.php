<?php

declare(strict_types=1);

function overview_dashboard_data(array $report): array
{
    $zone = new DateTimeZone('Asia/Ho_Chi_Minh');
    $transactions = [];
    $rooms = [];
    $days = [];
    $hours = [];
    $receiptCount = 0;
    for ($hour = 0; $hour < 24; $hour++) {
        $hours[$hour] = ['label' => sprintf('%02d:00', $hour), 'collected' => 0.0, 'count' => 0];
    }
    foreach (array_reverse($report['days']) as $day) {
        $count = 0;
        foreach ($day['transactions'] as $transaction) {
            $time = (new DateTimeImmutable($transaction['time']))->setTimezone($zone);
            $transaction['local_time'] = $time->format('d/m/Y H:i');
            $transactions[] = $transaction;
            if ($transaction['type'] === 'expense') {
                continue;
            }
            $receiptCount++;
            $count++;
            $hours[(int)$time->format('G')]['collected'] += $transaction['amount'];
            $hours[(int)$time->format('G')]['count']++;
            if (in_array($transaction['type'], ['room', 'surcharge'], true)) {
                $name = $transaction['room'] !== '' ? $transaction['room'] : 'Chưa xác định phòng';
                $rooms[$name] = ($rooms[$name] ?? 0.0) + $transaction['amount'];
            }
        }
        $days[] = [
            'label' => (new DateTimeImmutable($day['date']))->format('d/m'),
            'collected' => $day['totals']['collected'], 'count' => $count,
        ];
    }
    arsort($rooms);
    $rankedRooms = [];
    foreach ($rooms as $name => $amount) {
        $rankedRooms[] = ['name' => $name, 'amount' => $amount];
    }
    usort($transactions, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));
    return [
        'receipt_count' => $receiptCount,
        'average' => $receiptCount > 0 ? $report['totals']['collected'] / $receiptCount : 0,
        'net' => $report['totals']['collected'] - $report['totals']['expense'],
        'transactions' => $transactions, 'rooms' => $rankedRooms, 'days' => $days, 'hours' => $hours,
    ];
}

function render_overview_chart(array $series, string $metric, string $id): void
{
    $maximum = max(1, ...array_column($series, $metric));
    $count = max(1, count($series));
    $step = 640 / $count;
    $barWidth = max(2, min(38, $step * 0.65));
    $money = $metric === 'collected';
    if (!$money) {
        $maximum = max(3, ceil($maximum / 3) * 3);
    }
    ?>
    <svg class="overview-chart" viewBox="0 0 720 250" role="img" aria-labelledby="<?= e($id) ?>">
        <title id="<?= e($id) ?>"><?= $money ? 'Tiền nhận' : 'Số lượt thu tiền' ?> theo thời gian. Chi tiết đầy đủ trong bảng bên dưới.</title>
        <?php for ($line = 0; $line <= 3; $line++): ?>
            <?php
            $y = 200 - $line * 55;
            $value = $maximum * $line / 3;
            $label = $money ? ($value >= 1000000 ? round($value / 1000000, 1) . ' tr' : number_format($value, 0, ',', '.')) : (string)ceil($value);
            ?>
            <line x1="62" y1="<?= $y ?>" x2="708" y2="<?= $y ?>" class="overview-chart-grid" />
            <text x="52" y="<?= $y + 4 ?>" text-anchor="end" class="overview-chart-label"><?= e($label) ?></text>
        <?php endfor ?>
        <?php foreach ($series as $index => $point): ?>
            <?php
            $height = $point[$metric] / $maximum * 165;
            $x = 66 + $index * $step + ($step - $barWidth) / 2;
            $value = $money ? number_format($point[$metric], 0, ',', '.') . 'đ' : $point[$metric] . ' lượt thu';
            ?>
            <rect x="<?= round($x, 2) ?>" y="<?= round(200 - $height, 2) ?>" width="<?= round($barWidth, 2) ?>" height="<?= round($height, 2) ?>" rx="3" class="<?= $money ? 'overview-chart-money' : 'overview-chart-count' ?>">
                <title><?= e($point['label'] . ': ' . $value) ?></title>
            </rect>
            <?php if ($index % max(1, (int)ceil($count / 7)) === 0 || $index === $count - 1): ?>
                <text x="<?= round($x + $barWidth / 2, 2) ?>" y="224" text-anchor="middle" class="overview-chart-label"><?= e($point['label']) ?></text>
            <?php endif ?>
        <?php endforeach ?>
    </svg>
    <?php
}
