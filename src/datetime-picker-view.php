<?php

declare(strict_types=1);

function render_datetime_picker(string $name, string $label): void
{
    $prefix = 'timeline-' . $name;
    ?>
    <div class="grid min-w-0 gap-2" data-timeline-datetime="<?= e($name) ?>">
        <span class="text-sm font-medium"><?= e($label) ?> · Giờ Việt Nam</span>
        <input name="<?= e($name) ?>" type="hidden">
        <div class="grid min-w-0 grid-cols-1 gap-2 min-[480px]:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="manual-date-field">
                <input name="date" type="hidden">
                <button id="<?= e($prefix) ?>-date-trigger" class="manual-date-trigger" type="button" aria-label="<?= e($label) ?>: chọn ngày" aria-haspopup="dialog" aria-controls="<?= e($prefix) ?>-date-panel" aria-expanded="false"><span data-manual-date-label>Chọn ngày</span><?= admin_icon('calendar') ?></button>
                <div id="<?= e($prefix) ?>-date-panel" class="manual-date-popover" popover="auto" role="dialog" aria-label="<?= e($label) ?>: chọn ngày">
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
            <div class="manual-time-field">
                <input type="hidden" data-datetime-time><input type="hidden" data-datetime-hour><input type="hidden" data-datetime-minute>
                <div class="manual-time-picker">
                    <button type="button" data-time-trigger="hour" aria-label="<?= e($label) ?>: chọn giờ" aria-haspopup="dialog" aria-controls="<?= e($prefix) ?>-time-panel" aria-expanded="false"><span data-time-hour-label>Chọn giờ</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                    <span aria-hidden="true">:</span>
                    <button type="button" data-time-trigger="minute" aria-label="<?= e($label) ?>: chọn phút" aria-haspopup="dialog" aria-controls="<?= e($prefix) ?>-time-panel" aria-expanded="false"><span data-time-minute-label>Phút</span><span class="manual-time-chevron" aria-hidden="true"></span></button>
                </div>
                <div id="<?= e($prefix) ?>-time-panel" class="manual-time-popover" popover="auto" role="dialog" aria-label="<?= e($label) ?>: chọn giờ 24 giờ">
                    <section class="manual-time-column">
                        <h4 id="<?= e($prefix) ?>-hour-title">Chọn giờ</h4>
                        <div class="manual-hour-options" role="listbox" aria-labelledby="<?= e($prefix) ?>-hour-title" data-time-hours></div>
                    </section>
                    <section class="manual-time-column manual-minute-column">
                        <h4 id="<?= e($prefix) ?>-minute-title">Chọn phút</h4>
                        <div class="manual-minute-options" role="listbox" aria-labelledby="<?= e($prefix) ?>-minute-title" data-time-minutes></div>
                        <label class="manual-exact-minute">Phút chính xác<input type="number" min="0" max="59" step="1" inputmode="numeric" data-time-exact aria-label="Phút chính xác từ 0 đến 59"></label>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <?php
}
