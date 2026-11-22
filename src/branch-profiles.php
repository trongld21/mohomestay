<?php

declare(strict_types=1);

function default_branch_profiles(): array
{
    $empty = ['address' => '', 'phone' => '', 'email' => '', 'map_url' => '', 'description' => '', 'version' => 0];
    return [
        1 => ['id' => 1, 'name' => 'Chi nhánh 1', 'address' => '82 đường B18, KDC 91B, Ninh Kiều, Cần Thơ', 'phone' => '0357907153'] + $empty,
        2 => ['id' => 2, 'name' => 'Chi nhánh 2'] + $empty,
    ];
}

function load_branch_profiles(PDO $pdo): array
{
    $profiles = [];
    foreach ($pdo->query('SELECT id,name,address,phone,email,map_url,description,version FROM branches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        if (in_array($id, [1, 2], true)) {
            $profiles[$id] = $row;
        }
    }
    if (count($profiles) !== 2) {
        throw new RuntimeException('Branch profiles have not been initialized.');
    }
    return $profiles;
}

function branch_profiles(bool $refresh = false): array
{
    static $profiles;
    if ($profiles === null || $refresh) {
        try {
            $profiles = load_branch_profiles(db(1));
        } catch (Throwable) {
            // Read-only labels still work before migration or without a DB.
            // The management page loads strictly and never saves fallback rows.
            $profiles = default_branch_profiles();
        }
    }
    return $profiles;
}

function branch_profile(?int $id = null): array
{
    $id ??= branch_id();
    return branch_profiles()[$id] ?? throw new InvalidArgumentException('Chi nhánh không hợp lệ.');
}

function branch_profile_input(array $input): array
{
    $values = [];
    foreach (['name' => 100, 'address' => 255, 'phone' => 40, 'email' => 254, 'map_url' => 500, 'description' => 1000] as $key => $limit) {
        if (!is_string($input[$key] ?? '')) {
            throw new BookingException('Thông tin chi nhánh không hợp lệ.', 422);
        }
        $value = trim($input[$key] ?? '');
        if (mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new BookingException('Thông tin chi nhánh quá dài hoặc chứa ký tự không hợp lệ.', 422);
        }
        if (!in_array($key, ['address', 'description'], true) && preg_match('/[\r\n]/', $value)) {
            throw new BookingException('Tên và thông tin liên hệ phải nằm trên một dòng.', 422);
        }
        $values[$key] = $value;
    }
    if (mb_strlen($values['name']) < 2) {
        throw new BookingException('Tên chi nhánh cần từ 2 đến 100 ký tự.', 422);
    }
    if ($values['phone'] !== '' && !preg_match('/^\+?[0-9][0-9\s().-]{4,39}$/D', $values['phone'])) {
        throw new BookingException('Nhập số điện thoại hợp lệ hoặc để trống.', 422);
    }
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        throw new BookingException('Nhập email liên hệ hợp lệ hoặc để trống.', 422);
    }
    if ($values['map_url'] !== '') {
        $parts = parse_url($values['map_url']);
        if (!filter_var($values['map_url'], FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new BookingException('Liên kết bản đồ phải là địa chỉ HTTPS hợp lệ.', 422);
        }
    }
    return $values;
}

function update_branch_profile(PDO $pdo, array $input): void
{
    if (!in_array($input['id'] ?? null, [1, 2, '1', '2'], true)) {
        throw new BookingException('Không tìm thấy chi nhánh.', 404);
    }
    $version = $input['version'] ?? null;
    if ((!is_int($version) && !is_string($version)) || filter_var($version, FILTER_VALIDATE_INT) === false || (int)$version < 1) {
        throw new BookingException('Tải lại thông tin chi nhánh trước khi lưu.', 409);
    }
    $values = branch_profile_input($input);
    $query = $pdo->prepare('UPDATE branches SET name=?,address=?,phone=?,email=?,map_url=?,description=?,version=version+1 WHERE id=? AND version=?');
    $query->execute([...array_values($values), (int)$input['id'], (int)$version]);
    if ($query->rowCount() !== 1) {
        throw new BookingException('Thông tin đã được thay đổi ở nơi khác. Tải lại trang trước khi lưu.', 409);
    }
}

function save_branch_profile(array $input): void
{
    require_permission('accounts');
    update_branch_profile(db(1), $input);
    branch_profiles(true);
}
