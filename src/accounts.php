<?php

declare(strict_types=1);

function account_permissions(): array
{
    return [
        'bookings' => 'Tổng quan & đặt phòng',
        'rooms' => 'Quản lý phòng',
        'door-codes' => 'Quản lý mã mở cửa',
    ];
}

function current_account(): ?array
{
    if (($_SESSION['admin_until'] ?? 0) < time()) {
        return null;
    }
    if (!isset($_SESSION['account_id'])) {
        return ['id' => 'admin', 'name' => 'Admin', 'email' => (string)config('admin_email'), 'root' => true];
    }
    $stmt = db()->prepare('SELECT id, name, email, is_active, permissions, session_version FROM accounts WHERE id = ?');
    $stmt->execute([$_SESSION['account_id']]);
    $account = $stmt->fetch();
    if (!$account || !$account['is_active'] || (int)$account['session_version'] !== (int)($_SESSION['account_version'] ?? 0)) {
        return null;
    }
    $account['root'] = false;
    return $account;
}

function can_use(string $permission): bool
{
    $account = current_account();
    return $account !== null && ($account['root'] || in_array($permission, json_decode($account['permissions'], true) ?: [], true));
}

function require_permission(string $permission): void
{
    require_admin();
    if (!can_use($permission)) {
        json_response(['error' => 'Tài khoản chưa được cấp quyền sử dụng chức năng này.'], 403);
    }
}

function account_login(string $email, string $password): bool
{
    $root = $email === strtolower((string)config('admin_email'));
    if ($root) {
        if (!password_verify($password, (string)config('admin_password_hash'))) {
            return false;
        }
        $account = null;
    } else {
        $stmt = db()->prepare('SELECT * FROM accounts WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            return false;
        }
    }
    session_regenerate_id(true);
    $_SESSION = ['admin_until' => time() + 8 * 3600];
    if ($account) {
        $_SESSION['account_id'] = $account['id'];
        $_SESSION['account_version'] = (int)$account['session_version'];
    }
    return true;
}

function account_home(): string
{
    foreach (account_permissions() as $key => $label) {
        if (can_use($key)) {
            return $key === 'door-codes' ? '/admin/door-codes' : '/admin#' . $key;
        }
    }
    return can_use('accounts') ? '/admin/accounts' : '/admin/no-access';
}

function save_account(array $data, bool $permissionsOnly): void
{
    require_permission('accounts');
    $id = (string)($data['id'] ?? '');
    if ($id !== '' || $permissionsOnly) {
        $stmt = db()->prepare('SELECT id FROM accounts WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new BookingException('Không tìm thấy tài khoản.', 404);
        }
    }
    if ($permissionsOnly) {
        $permissions = $data['permissions'] ?? [];
        if (!is_array($permissions) || array_diff($permissions, array_keys(account_permissions()))) {
            throw new BookingException('Danh sách quyền không hợp lệ.', 422);
        }
        $stmt = db()->prepare('UPDATE accounts SET permissions = ? WHERE id = ?');
        $stmt->execute([json_encode(array_values(array_unique($permissions))), $id]);
        return;
    }
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 100 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new BookingException('Nhập tên và email hợp lệ.', 422);
    }
    if ($email === strtolower((string)config('admin_email'))) {
        throw new BookingException('Email này dành riêng cho Admin.', 422);
    }
    if (($id === '' || $password !== '') && (strlen($password) < 12 || strlen($password) > 72)) {
        throw new BookingException('Mật khẩu cần từ 12 đến 72 byte.', 422);
    }
    $active = isset($data['is_active']) ? 1 : 0;
    try {
        if ($id === '') {
            $stmt = db()->prepare('INSERT INTO accounts (id, name, email, password_hash, is_active, permissions) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([uuid(), $name, $email, password_hash($password, PASSWORD_DEFAULT), $active, '[]']);
        } else {
            $sql = 'UPDATE accounts SET name = ?, email = ?, is_active = ?, session_version = session_version + 1';
            $values = [$name, $email, $active];
            if ($password !== '') {
                $sql .= ', password_hash = ?';
                $values[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $values[] = $id;
            $stmt = db()->prepare($sql . ' WHERE id = ?');
            $stmt->execute($values);
        }
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            throw new BookingException('Email đã được sử dụng.', 422);
        }
        throw $error;
    }
}
