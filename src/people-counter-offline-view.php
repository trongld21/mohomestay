<?php

declare(strict_types=1);

function render_people_counter_offline_page(): void
{
    ?>
    <section class="mx-auto grid w-full min-w-0 max-w-[1440px] gap-5 text-[#45382f] sm:gap-6" data-offline-counter>
        <header class="grid gap-3">
            <p class="text-xs font-semibold tracking-widest text-[#79543e]">MƠ HOME · VẬN HÀNH</p>
            <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="min-w-0 font-sans text-2xl font-semibold sm:text-3xl">Đối soát lượt vào / ra</h1>
                <?php render_people_counter_tabs(true); ?>
            </div>
            <p class="max-w-2xl text-sm leading-relaxed text-stone-600">Xử lý video đã ghi theo ca hoặc ngày. Máy tính chỉ cần bật khi xử lý video.</p>
        </header>

        <section class="rounded-2xl border border-[#e5dacb] bg-[#fffdf9] p-4 sm:p-6" aria-labelledby="offline-mode-title">
            <h2 id="offline-mode-title" class="font-sans text-lg font-semibold">Chế độ video đã ghi</h2>
            <p class="mt-2 text-sm leading-relaxed text-stone-600">Báo cáo từ video đã ghi được xử lý riêng với tab Trực tiếp. Bạn không cần mở Imou PC hay cầu nối camera để xem báo cáo đã xuất.</p>
            <ol class="mt-5 grid list-none gap-4 p-0 text-sm leading-relaxed md:grid-cols-3">
                <li class="rounded-xl bg-white p-4">
                    <span class="font-semibold text-[#79543e]">01 · Xuất video</span>
                    <p class="mt-2 text-stone-600">Xuất file MP4 gốc từ Imou, giữ đủ vùng cửa ra/vào.</p>
                </li>
                <li class="rounded-xl bg-white p-4">
                    <span class="font-semibold text-[#79543e]">02 · Chạy công cụ đếm</span>
                    <p class="mt-2 text-stone-600">Mở công cụ Đếm video trên máy tính, chọn video và chờ xuất báo cáo.</p>
                </li>
                <li class="rounded-xl bg-white p-4">
                    <span class="font-semibold text-[#79543e]">03 · Đối soát</span>
                    <p class="mt-2 text-stone-600">Mở báo cáo bên dưới, kiểm tra các lượt trong CSV và video đã đánh dấu.</p>
                </li>
            </ol>
        </section>

        <section class="grid min-w-0 gap-4 rounded-2xl border border-[#e5dacb] bg-white p-4 sm:p-6" aria-labelledby="offline-open-title">
            <div>
                <h2 id="offline-open-title" class="font-sans text-lg font-semibold">Mở báo cáo đã xử lý</h2>
                <p class="mt-2 text-sm leading-relaxed text-stone-600" id="offline-file-help">Chọn file report.json trong thư mục kết quả. File được đọc trên trình duyệt, không tải lên máy chủ.</p>
            </div>
            <label class="grid min-w-0 gap-2 text-sm font-medium">File báo cáo
                <input class="min-h-11 w-full min-w-0 rounded-xl border border-[#d8c9b8] bg-[#fffdf9] p-2 text-sm file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-[#79543e] file:px-4 file:text-sm file:font-semibold file:text-white hover:file:bg-[#624734] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]" type="file" accept=".json,application/json" aria-describedby="offline-file-help" data-offline-file>
            </label>
            <p class="break-words text-sm leading-relaxed text-stone-600" role="status" data-offline-status>Chưa mở báo cáo. Số liệu sẽ hiển thị sau khi bạn chọn file.</p>
            <p class="hidden break-words rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert" data-offline-error></p>
        </section>

        <section class="hidden min-w-0 gap-5" aria-label="Kết quả đối soát video" data-offline-results>
            <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900" data-offline-review></p>
            <div class="grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 sm:p-6">
                    <p class="text-sm font-semibold text-emerald-800">Lượt vào</p>
                    <p class="mt-3 text-3xl font-semibold tabular-nums text-emerald-900" data-offline-in>—</p>
                </div>
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 sm:p-6">
                    <p class="text-sm font-semibold text-red-800">Lượt ra</p>
                    <p class="mt-3 text-3xl font-semibold tabular-nums text-red-900" data-offline-out>—</p>
                </div>
                <div class="col-span-2 rounded-2xl border border-[#e5dacb] bg-white p-4 sm:col-span-1 sm:p-6">
                    <p class="text-sm font-semibold text-[#79543e]">Tổng lượt qua cửa</p>
                    <p class="mt-3 text-3xl font-semibold tabular-nums" data-offline-total>—</p>
                </div>
            </div>
            <div class="rounded-2xl border border-[#e5dacb] bg-white p-4 sm:p-6">
                <h2 class="font-sans text-lg font-semibold">Thông tin video</h2>
                <dl class="mt-4 grid min-w-0 gap-4 text-sm sm:grid-cols-2">
                    <div class="min-w-0">
                        <dt class="text-stone-500">File video</dt>
                        <dd class="m-0 mt-1 break-words font-medium" data-offline-video></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Thời lượng đã xử lý</dt>
                        <dd class="m-0 mt-1 font-medium" data-offline-duration></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Giờ bắt đầu camera</dt>
                        <dd class="m-0 mt-1 font-medium" data-offline-start></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Hiệu chỉnh vạch đếm</dt>
                        <dd class="m-0 mt-1 font-medium" data-offline-calibration></dd>
                    </div>
                </dl>
            </div>
            <div class="min-w-0 overflow-x-auto rounded-2xl border border-[#e5dacb] bg-white p-4 sm:p-6">
                <table class="w-full text-left text-sm">
                    <caption class="mb-4 text-left font-sans text-lg font-semibold">So với vận hành thực tế</caption>
                    <thead>
                        <tr class="border-b border-[#e5dacb] text-[#79543e]">
                            <th class="py-3 pr-3" scope="col">Lượt</th>
                            <th class="px-2 py-3" scope="col">AI</th>
                            <th class="px-2 py-3" scope="col">Thực tế</th>
                            <th class="py-3 pl-2" scope="col">AI − thực tế</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-stone-100">
                            <th class="py-3 pr-3 font-medium" scope="row">Vào</th>
                            <td class="px-2 py-3" data-offline-ai-in></td>
                            <td class="px-2 py-3" data-offline-expected-in></td>
                            <td class="py-3 pl-2" data-offline-diff-in></td>
                        </tr>
                        <tr>
                            <th class="py-3 pr-3 font-medium" scope="row">Ra</th>
                            <td class="px-2 py-3" data-offline-ai-out></td>
                            <td class="px-2 py-3" data-offline-expected-out></td>
                            <td class="py-3 pl-2" data-offline-diff-out></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-sm leading-relaxed text-stone-600">Xem events.csv và annotated.mp4 trong thư mục kết quả để kiểm tra từng lượt. Báo cáo này không tự cộng vào dữ liệu vận hành trên web.</p>
        </section>
    </section>
    <script defer src="<?= e(asset_url('/assets/people-counter-offline.js')) ?>"></script>
    <?php
}
