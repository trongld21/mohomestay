<?php

declare(strict_types=1);

function render_permission_manager(array $accounts, string $error): void
{
    $groups = account_permission_groups();
    $labels = account_permissions();
    $requested = (string)($_POST['id'] ?? $_GET['account'] ?? '');
    $selected = $accounts[0] ?? null;
    $data = [];
    foreach ($accounts as $account) {
        $grants = expanded_account_permissions(json_decode($account['permissions'], true) ?: []);
        $data[] = [
            'id' => $account['id'],
            'name' => $account['name'],
            'email' => $account['email'],
            'active' => (bool)$account['is_active'],
            'grants' => $grants,
        ];
        if ($account['id'] === $requested) {
            $selected = $account;
        }
    }
    $selectedGrants = $selected ? expanded_account_permissions(json_decode($selected['permissions'], true) ?: []) : [];
    if ($error !== '' && $selected && $selected['id'] === ($_POST['id'] ?? '') && is_array($_POST['permissions'] ?? [])) {
        $selectedGrants = array_values(array_intersect($_POST['permissions'] ?? [], array_keys(account_permission_labels())));
    }
    ?>
    <section class="mx-auto grid w-full max-w-7xl gap-6 text-[#3e322b]" data-permission-manager>
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-[#dfd3c4] pb-5">
            <div>
                <h1 class="text-2xl font-semibold sm:text-3xl">Phân quyền tài khoản</h1>
                <p class="mt-2 max-w-2xl text-sm text-[#70645b]">Chọn tài khoản và cấp đúng thao tác nhân viên cần sử dụng.</p>
            </div>
            <a class="permission-button" href="/admin/accounts">Quản lý tài khoản</a>
        </header>
        <?php if ($error !== ''): ?>
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><?= e($error) ?></p>
        <?php elseif (isset($_GET['saved'])): ?>
            <p role="status" class="rounded-xl border border-green-200 bg-green-50 p-4 text-green-900">Đã lưu quyền truy cập.</p>
        <?php endif ?>
        <aside class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-[#dfd3c4] bg-[#f3eee6] p-4 text-sm">
            <div>
                <h2 class="font-semibold">Super Admin · Toàn quyền</h2>
                <p class="mt-1 break-all text-[#70645b]"><?= e(config('admin_email')) ?></p>
            </div>
            <p class="max-w-xl text-[#70645b]">Tài khoản gốc luôn có toàn quyền. Nhân sự, tính lương, chấm công và lượt vào / ra hiện chỉ dành cho Super Admin.</p>
        </aside>
        <?php if (!$selected): ?>
            <div class="rounded-xl border border-dashed border-[#dfd3c4] px-6 py-12 text-center">
                <h2 class="text-xl font-semibold">Chưa có tài khoản nhân viên</h2>
                <p class="mt-2 text-sm text-[#70645b]">Tạo tài khoản trước, sau đó chọn những chức năng nhân viên được phép sử dụng.</p>
                <a class="permission-button mt-5" href="/admin/accounts">Tạo tài khoản</a>
            </div>
        <?php else: ?>
            <div class="grid min-w-0 gap-5 lg:grid-cols-[280px_minmax(0,1fr)]">
                <aside class="min-w-0 self-start rounded-xl border border-[#dfd3c4] bg-[#fffdf9] p-4">
                    <h2 class="font-semibold">Tài khoản nhân viên <span class="text-sm font-normal text-[#70645b]">(<?= count($accounts) ?>)</span></h2>
                    <label class="mt-4 block text-sm">Tìm tài khoản
                        <input class="permission-field mt-2" type="search" placeholder="Tên hoặc email" data-account-search>
                    </label>
                    <nav class="mt-3 grid max-h-80 gap-1 overflow-auto lg:max-h-[60dvh]" aria-label="Chọn tài khoản phân quyền">
                        <?php foreach ($data as $account): ?>
                            <a class="permission-account" href="?account=<?= e(urlencode($account['id'])) ?>" data-select-account="<?= e($account['id']) ?>" <?= $account['id'] === $selected['id'] ? 'aria-current="true"' : '' ?>>
                                <span class="block break-words font-semibold"><?= e($account['name']) ?></span>
                                <span class="mt-1 block break-all text-xs text-[#70645b]"><?= e($account['email']) ?></span>
                                <span class="mt-2 block text-xs text-[#70645b]"><?= $account['active'] ? 'Đang hoạt động' : 'Đã khóa đăng nhập' ?> · <?= count($account['grants']) ?> quyền</span>
                            </a>
                        <?php endforeach ?>
                    </nav>
                    <p class="mt-4 text-sm text-[#70645b]" data-no-accounts hidden>Không tìm thấy tài khoản phù hợp.</p>
                </aside>
                <form method="post" class="min-w-0 rounded-xl border border-[#dfd3c4] bg-[#fffdf9]" data-permission-form>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= e($selected['id']) ?>">
                    <header class="grid gap-4 border-b border-[#dfd3c4] p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words text-xl font-semibold" data-selected-name><?= e($selected['name']) ?></h2>
                                <p class="mt-1 break-all text-sm text-[#70645b]" data-selected-email><?= e($selected['email']) ?></p>
                            </div>
                            <span class="rounded-md bg-[#f3eee6] px-3 py-2 text-xs font-medium" data-selected-status><?= $selected['is_active'] ? 'Đang hoạt động' : 'Đã khóa đăng nhập' ?></span>
                        </div>
                        <p class="text-sm text-[#70645b]">Quyền thao tác tự kèm quyền xem trang tương ứng. Bỏ quyền xem sẽ bỏ các thao tác cần quyền đó.</p>
                        <label class="block text-sm">Tìm chức năng
                            <input class="permission-field mt-2" type="search" placeholder="Ví dụ: đặt phòng, thu tiền, ảnh phòng…" data-permission-search>
                        </label>
                    </header>
                    <div class="divide-y divide-[#dfd3c4]" data-permission-groups>
                        <?php foreach ($groups as $group => $actions): ?>
                            <fieldset class="m-0 min-w-0 border-0 p-5 sm:p-6" data-permission-group="<?= e($group) ?>" aria-labelledby="permission-group-<?= e($group) ?>">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                    <h3 class="font-semibold" id="permission-group-<?= e($group) ?>"><?= e($labels[$group]) ?> <span class="text-sm font-normal text-[#70645b]" data-group-count></span></h3>
                                    <div class="flex gap-2" data-group-actions hidden>
                                        <button class="permission-button permission-button-small" type="button" data-group-select>Chọn nhóm</button>
                                        <button class="permission-button permission-button-small" type="button" data-group-clear>Bỏ nhóm</button>
                                    </div>
                                </div>
                                <div class="grid gap-1 xl:grid-cols-2">
                                    <?php foreach ($actions as $key => $label): ?>
                                        <label class="permission-choice" data-permission-choice>
                                            <input class="h-5 w-5 shrink-0 accent-[#70432c] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#70432c]" type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $selectedGrants, true) ? 'checked' : '' ?>>
                                            <span class="text-sm leading-6"><?= e($label) ?></span>
                                        </label>
                                    <?php endforeach ?>
                                </div>
                            </fieldset>
                        <?php endforeach ?>
                    </div>
                    <p class="p-6 text-sm text-[#70645b]" data-no-permissions hidden>Không có chức năng phù hợp. Thử từ khóa khác.</p>
                    <footer class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t border-[#dfd3c4] bg-[#fffdf9] p-4 sm:px-6">
                        <div class="text-sm" aria-live="polite">
                            <span data-permission-total><?= count($selectedGrants) ?></span> quyền được chọn
                            <span class="mt-1 block text-xs text-[#70645b]" data-permission-dirty>Chưa có thay đổi.</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button class="permission-button" type="button" data-permission-reset hidden>Hoàn tác</button>
                            <button class="permission-button permission-button-primary" type="submit" data-permission-save>Lưu quyền</button>
                        </div>
                    </footer>
                </form>
            </div>
            <script type="application/json" data-permission-accounts><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
            <script defer src="<?= e(asset_url('/assets/permissions.js')) ?>"></script>
        <?php endif ?>
    </section>
    <?php
}
