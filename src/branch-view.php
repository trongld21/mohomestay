<?php

declare(strict_types=1);

function render_branch_management(array $profiles, int $selectedId, array $values, string $error): void
{
    ?>
    <section class="branch-management mx-auto grid max-w-6xl gap-6 p-4 text-[#49382c] sm:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-[#8b7059]">Mơ Home · Không gian quản lý</p>
                <h1>Quản lý chi nhánh</h1>
                <p class="mt-3 max-w-2xl text-sm text-[#786858]">Cập nhật tên và thông tin liên hệ cho từng không gian của Mơ.</p>
            </div>
            <span class="rounded-full border border-[#e5d7c6] bg-[#fffaf2] px-4 py-2 text-sm font-medium"><?= count($profiles) ?> chi nhánh</span>
        </header>
        <?php if ($error !== ''): ?>
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                <p><?= e($error) ?></p>
                <a class="mt-2 inline-flex min-h-11 items-center rounded-lg px-2 underline underline-offset-4 hover:bg-red-100 focus-visible:outline-2 focus-visible:outline-offset-2" href="?edit=<?= $selectedId ?>">Tải lại thông tin</a>
            </div>
        <?php elseif (isset($_GET['saved'])): ?>
            <p role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-900">Đã lưu thông tin chi nhánh.</p>
        <?php endif ?>
        <?php if ($profiles): ?>
            <div class="grid min-w-0 items-start gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
                <nav class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1" aria-label="Danh sách chi nhánh">
                    <?php foreach ($profiles as $id => $profile): ?>
                        <a class="branch-profile-card" href="?edit=<?= $id ?>" <?= $id === $selectedId ? 'aria-current="true"' : '' ?>>
                            <span class="mb-4 inline-flex size-11 items-center justify-center rounded-xl bg-[#f4eade] text-[#79543e]" aria-hidden="true"><?= admin_icon('home') ?></span>
                            <span class="block text-xs font-medium tracking-wider text-[#8b7059]">CN<?= str_pad((string)$id, 2, '0', STR_PAD_LEFT) ?></span>
                            <strong class="mt-2 block break-words text-lg"><?= e($profile['name']) ?></strong>
                            <span class="mt-2 block break-words text-sm leading-6 text-[#786858]"><?= e($profile['address'] ?: 'Chưa có địa chỉ') ?></span>
                            <span class="mt-4 block text-sm font-medium text-[#79543e]"><?= $id === $selectedId ? 'Đang chỉnh sửa' : 'Chỉnh sửa thông tin →' ?></span>
                        </a>
                    <?php endforeach ?>
                </nav>
                <form method="post" class="min-w-0 rounded-2xl border border-[#e5d7c6] bg-white p-5 sm:p-7" data-branch-profile-form>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $selectedId ?>">
                    <input type="hidden" name="version" value="<?= e($values['version']) ?>">
                    <div class="mb-6 border-b border-[#eee4d8] pb-5">
                        <h2>Thông tin chi nhánh</h2>
                        <p class="mt-2 text-sm text-[#786858]">Mã CN<?= str_pad((string)$selectedId, 2, '0', STR_PAD_LEFT) ?> · Chỉ Super Admin được chỉnh sửa.</p>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-medium sm:col-span-2" for="branch-name">Tên chi nhánh <span class="sr-only">bắt buộc</span>
                            <input class="branch-profile-field" id="branch-name" name="name" value="<?= e($values['name']) ?>" minlength="2" maxlength="100" required autocomplete="organization">
                        </label>
                        <label class="grid gap-2 text-sm font-medium sm:col-span-2" for="branch-address">Địa chỉ
                            <textarea class="branch-profile-field min-h-24" id="branch-address" name="address" rows="2" maxlength="255" autocomplete="street-address"><?= e($values['address']) ?></textarea>
                        </label>
                        <label class="grid gap-2 text-sm font-medium" for="branch-phone">Số điện thoại
                            <input class="branch-profile-field" id="branch-phone" type="tel" name="phone" value="<?= e($values['phone']) ?>" maxlength="40" autocomplete="tel">
                        </label>
                        <label class="grid gap-2 text-sm font-medium" for="branch-email">Email liên hệ
                            <input class="branch-profile-field" id="branch-email" type="email" name="email" value="<?= e($values['email']) ?>" maxlength="254" autocomplete="email">
                        </label>
                        <label class="grid gap-2 text-sm font-medium sm:col-span-2" for="branch-map">Liên kết bản đồ
                            <input class="branch-profile-field" id="branch-map" type="url" name="map_url" value="<?= e($values['map_url']) ?>" maxlength="500" placeholder="https://maps.google.com/..." aria-describedby="branch-map-help">
                            <span class="text-xs font-normal text-[#786858]" id="branch-map-help">Để trống nếu chưa có liên kết bản đồ.</span>
                        </label>
                        <label class="grid gap-2 text-sm font-medium sm:col-span-2" for="branch-description">Giới thiệu
                            <textarea class="branch-profile-field min-h-28" id="branch-description" name="description" rows="4" maxlength="1000" aria-describedby="branch-description-help"><?= e($values['description']) ?></textarea>
                            <span class="text-xs font-normal text-[#786858]" id="branch-description-help">Hiển thị tại chân trang website của chi nhánh.</span>
                        </label>
                    </div>
                    <footer class="mt-7 flex flex-wrap items-center justify-between gap-4 border-t border-[#eee4d8] pt-5">
                        <p class="max-w-sm text-xs text-[#786858]">Tên và thông tin liên hệ sẽ được cập nhật tại phần chọn chi nhánh và cuối trang website.</p>
                        <button class="branch-profile-save" type="submit">Lưu thay đổi</button>
                    </footer>
                </form>
            </div>
        <?php endif ?>
    </section>
    <?php
}
