<?php

function render_accounts(bool $permissionsOnly, string $error): void
{
    $accounts = db()->query('SELECT id, name, email, is_active, permissions FROM accounts ORDER BY created_at, id')->fetchAll();
    $field = 'mt-2 min-h-11 w-full rounded-lg border border-stone-300 bg-white px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800';
    $button = 'min-h-11 rounded-lg bg-amber-900 px-5 py-3 font-semibold text-white enabled:hover:bg-amber-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800';
    ?>
    <div class="mx-auto max-w-6xl space-y-6 p-4 text-stone-800 sm:p-8">
        <header>
            <h1 class="text-2xl font-semibold sm:text-3xl"><?= $permissionsOnly ? 'Phân quyền tài khoản' : 'Quản lý tài khoản' ?></h1>
            <p class="mt-3 text-stone-600">Admin có toàn quyền. Tài khoản nhân viên chỉ được sử dụng chức năng đã được cấp.</p>
        </header>
        <?php if ($error !== ''): ?>
            <p role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-red-800"><?= e($error) ?></p>
        <?php elseif (isset($_GET['saved'])): ?>
            <p role="status" class="rounded-xl border border-green-300 bg-green-50 p-4 text-green-900">Đã lưu thay đổi.</p>
        <?php endif ?>
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-lg font-semibold">Admin · Toàn quyền</h2>
            <p class="mt-2 break-words"><?= e(config('admin_email')) ?></p>
            <p class="mt-2 text-sm">Tài khoản gốc được cấu hình trong môi trường máy chủ và luôn có tất cả quyền.</p>
        </section>
        <?php if (!$permissionsOnly): ?>
            <section class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-6">
                <h2 class="text-xl font-semibold">Thêm tài khoản</h2>
                <p class="mt-2 text-sm text-stone-600">Tài khoản mới chưa có quyền. Vào Phân quyền để chọn chức năng sau khi tạo.</p>
                <?php render_account_form([], $field, $button) ?>
            </section>
        <?php endif ?>
        <div class="grid gap-5 lg:grid-cols-2">
            <?php foreach ($accounts as $account): ?>
                <section class="min-w-0 rounded-2xl border border-stone-200 bg-white p-5 sm:p-6">
                    <h2 class="break-words text-xl font-semibold"><?= e($account['name']) ?></h2>
                    <p class="mt-2 break-words text-sm text-stone-600"><?= e($account['email']) ?> · <?= $account['is_active'] ? 'Đang hoạt động' : 'Đã khóa' ?></p>
                    <?php if ($permissionsOnly): ?>
                        <form method="post" class="mt-4 space-y-4">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= e($account['id']) ?>">
                            <p class="text-sm text-stone-600">Chọn từng thao tác được phép. Quyền thao tác tự kèm quyền xem trang tương ứng. Sửa thông tin phòng và quản lý ảnh là hai quyền riêng.</p>
                            <?php $grants = expanded_account_permissions(json_decode($account['permissions'], true) ?: []); ?>
                            <?php foreach (account_permission_groups() as $group => $actions): ?>
                                <fieldset class="space-y-2 rounded-xl border border-stone-200 p-3">
                                    <legend class="px-2 font-semibold"><?= e(account_permissions()[$group]) ?></legend>
                                    <?php foreach ($actions as $key => $label): ?>
                                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 hover:bg-amber-50">
                                            <input class="h-5 w-5 shrink-0 accent-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $grants, true) ? 'checked' : '' ?>>
                                            <span><?= e($label) ?></span>
                                        </label>
                                    <?php endforeach ?>
                                </fieldset>
                            <?php endforeach ?>
                            <button class="<?= $button ?>" type="submit">Lưu quyền</button>
                        </form>
                    <?php else: ?>
                        <?php render_account_form($account, $field, $button) ?>
                    <?php endif ?>
                </section>
            <?php endforeach ?>
        </div>
        <?php if (!$accounts): ?><p class="rounded-xl bg-white p-5 text-stone-600">Chưa có tài khoản nhân viên.</p><?php endif ?>
    </div>
    <?php
}

function render_account_form(array $account, string $field, string $button): void
{
    $suffix = $account['id'] ?? 'new';
    ?>
    <form method="post" class="mt-4 space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= e($account['id'] ?? '') ?>">
        <div>
            <label for="name-<?= e($suffix) ?>">Tên hiển thị</label>
            <input id="name-<?= e($suffix) ?>" class="<?= $field ?>" name="name" maxlength="100" value="<?= e($account['name'] ?? '') ?>" required autocomplete="off">
        </div>
        <div>
            <label for="email-<?= e($suffix) ?>">Email đăng nhập</label>
            <input id="email-<?= e($suffix) ?>" class="<?= $field ?>" type="email" name="email" maxlength="254" value="<?= e($account['email'] ?? '') ?>" required autocomplete="off">
        </div>
        <div>
            <label for="password-<?= e($suffix) ?>"><?= $account ? 'Mật khẩu mới (để trống nếu giữ nguyên)' : 'Mật khẩu' ?></label>
            <input id="password-<?= e($suffix) ?>" class="<?= $field ?>" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" <?= $account ? '' : 'required' ?>>
            <p class="mt-2 text-sm text-stone-600">Tối thiểu 12 ký tự; tối đa 72 byte với ký tự có dấu.</p>
        </div>
        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 hover:bg-amber-50">
            <input class="h-5 w-5 accent-amber-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800" type="checkbox" name="is_active" <?= !$account || $account['is_active'] ? 'checked' : '' ?>>
            <span>Cho phép đăng nhập</span>
        </label>
        <button class="<?= $button ?>" type="submit"><?= $account ? 'Lưu tài khoản' : 'Tạo tài khoản' ?></button>
    </form>
    <?php
}
