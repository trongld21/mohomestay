<?php

declare(strict_types=1);

final class BookingIdentityReadException extends RuntimeException {}

function booking_identity_key(?string $secret = null): string
{
    if ($secret === null) {
        $dedicatedKey = (string)config('booking_identity_key', '');
        if ($dedicatedKey !== '' && !preg_match('/^[a-f0-9]{64}$/iD', $dedicatedKey)) {
            throw new BookingException('Khóa bảo vệ ảnh CCCD chưa hợp lệ. Vui lòng liên hệ Mơ.', 503);
        }
        $secret = $dedicatedKey !== '' ? $dedicatedKey : (string)config('app_key', '');
    }
    if (strlen($secret) < 16) throw new BookingException('Chưa cấu hình khóa bảo vệ ảnh CCCD. Vui lòng liên hệ Mơ.', 503);
    return hash_hkdf('sha256', $secret, 32, 'mo-booking-identity-v1');
}

function encrypt_booking_identity(string $bytes, string $context): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($bytes, 'aes-256-gcm', booking_identity_key(), OPENSSL_RAW_DATA, $iv, $tag, $context);
    if ($cipher === false) throw new RuntimeException('Không mã hóa được ảnh CCCD.');
    return $iv . $tag . $cipher;
}

function decrypt_booking_identity(string $encrypted, string $context): string
{
    if (strlen($encrypted) < 29) throw new BookingIdentityReadException('invalid_payload');
    $cipher = substr($encrypted, 28);
    $iv = substr($encrypted, 0, 12);
    $tag = substr($encrypted, 12, 16);
    $dedicatedKey = (string)config('booking_identity_key', '');
    $keys = array_unique([
        preg_match('/^[a-f0-9]{64}$/iD', $dedicatedKey) ? $dedicatedKey : '',
        (string)config('app_key', ''),
        (string)env_value('BOOKING_IDENTITY_LEGACY_APP_KEY', ''),
    ]);
    $hasReadableKey = false;
    foreach ($keys as $key) {
        if (strlen($key) < 16) continue;
        $hasReadableKey = true;
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', booking_identity_key($key), OPENSSL_RAW_DATA, $iv, $tag, $context);
        if ($plain !== false) return $plain;
    }
    throw new BookingIdentityReadException($hasReadableKey ? 'authentication_failed' : 'missing_key');
}

function validate_booking_identity_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
        throw new BookingException('Vui lòng tải đủ ảnh CCCD mặt trước và mặt sau. Mỗi ảnh tối đa 3 MiB.', 422);
    }
    $path = $file['tmp_name'];
    if (!is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) {
        throw new BookingException('Ảnh CCCD tải lên không hợp lệ.', 422);
    }
    $size = filesize($path);
    if (!$size || $size > 3 * 1024 * 1024) throw new BookingException('Mỗi ảnh CCCD tối đa 3 MiB.', 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $dimensions = @getimagesize($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$dimensions
        || $dimensions[0] < 200 || $dimensions[1] < 120 || $dimensions[0] > 8000 || $dimensions[1] > 8000
        || $dimensions[0] * $dimensions[1] > 24000000) {
        throw new BookingException('CCCD cần là ảnh JPG, PNG hoặc WebP rõ nét, tối thiểu 200 × 120 px, tối đa 24 megapixel.', 422);
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('Không đọc được ảnh CCCD.');
    return ['mime' => $mime, 'bytes' => $bytes];
}

function read_booking_identity_uploads(array $files, bool $required = true): array
{
    $images = [];
    foreach (['front', 'back'] as $side) {
        if (!$required && ($files['identity_' . $side]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $images[$side] = validate_booking_identity_file($files['identity_' . $side] ?? []);
    }
    if ($images !== []) booking_identity_key();
    return $images;
}

function booking_form_body(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        throw new BookingException('Vui lòng tải đủ hai mặt CCCD trong biểu mẫu đặt phòng.', 422);
    }
    if (!$_POST) throw new BookingException('Tệp tải lên quá lớn hoặc biểu mẫu trống. Mỗi ảnh tối đa 3 MiB.', 422);
    $body = $_POST;
    $body['acceptTerms'] = filter_var($body['acceptTerms'] ?? false, FILTER_VALIDATE_BOOL);
    return $body;
}

function save_booking_identity(PDO $pdo, string $bookingId, array $images, bool $required = true): void
{
    if (($required && array_keys($images) !== ['front', 'back']) || array_diff(array_keys($images), ['front', 'back'])) throw new RuntimeException('Thiếu hai mặt CCCD.');
    if ($images === []) return;
    $insert = $pdo->prepare('INSERT INTO booking_identity_images(booking_id,side,mime_type,encrypted_image) VALUES(:booking,:side,:mime,:image)');
    foreach ($images as $side => $image) {
        write_booking_identity_image($pdo, $insert, $bookingId, $side, $image);
    }
}

function write_booking_identity_image(PDO $pdo, PDOStatement $statement, string $bookingId, string $side, array $image): void
{
    $encrypted = encrypt_booking_identity($image['bytes'], $bookingId . ':' . $side);
    $statement->bindValue('booking', $bookingId);
    $statement->bindValue('side', $side);
    $statement->bindValue('mime', $image['mime']);
    $statement->bindValue('image', $encrypted, PDO::PARAM_LOB);
    $statement->execute();

    $read = $pdo->prepare('SELECT encrypted_image FROM booking_identity_images WHERE booking_id=? AND side=?');
    $read->execute([$bookingId, $side]);
    if ($read->fetchColumn() !== $encrypted) {
        error_log(sprintf('Booking identity write verification failed booking=%s side=%s', $bookingId, $side));
        throw new BookingException('Chưa xác nhận được ảnh CCCD đã lưu. Vui lòng thử lại.', 503);
    }
}

function replace_unreadable_booking_identity(PDO $pdo, string $bookingId, string $side, array $image): void
{
    $pdo->beginTransaction();
    try {
        $read = $pdo->prepare('SELECT encrypted_image FROM booking_identity_images WHERE booking_id=? AND side=?' . room_operation_lock_suffix($pdo));
        $read->execute([$bookingId, $side]);
        $stored = $read->fetchColumn();
        $read->closeCursor();
        if ($stored === false) throw new BookingException('Đơn chưa có ảnh CCCD cho mặt này.', 404);

        $unreadable = false;
        try {
            decrypt_booking_identity($stored, $bookingId . ':' . $side);
        } catch (BookingIdentityReadException $error) {
            if ($error->getMessage() === 'missing_key') {
                throw new BookingException('Máy chủ chưa có khóa đọc ảnh CCCD hợp lệ.', 503);
            }
            $unreadable = true;
        }
        if (!$unreadable) {
            throw new BookingException('Ảnh hiện tại đọc được. Tải lại để xem ảnh.', 409);
        }

        $update = $pdo->prepare('UPDATE booking_identity_images SET mime_type=:mime, encrypted_image=:image WHERE booking_id=:booking AND side=:side');
        write_booking_identity_image($pdo, $update, $bookingId, $side, $image);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function admin_replace_booking_identity_image(string $bookingId, string $side): never
{
    require_permission('bookings.identity');
    require_csrf();
    $account = current_account();
    $image = validate_booking_identity_file($_FILES['identity_image'] ?? []);
    replace_unreadable_booking_identity(db(), $bookingId, $side, $image);
    error_log(sprintf('Booking identity replaced booking=%s side=%s actor=%s', $bookingId, $side, $account['id']));
    json_response(['ok' => true]);
}

function admin_booking_identity_image(string $bookingId, string $side): never
{
    require_admin();
    require_permission('bookings.identity');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $find = db()->prepare('SELECT mime_type,encrypted_image FROM booking_identity_images WHERE booking_id=? AND side=?');
    $find->execute([$bookingId, $side]);
    $row = $find->fetch();
    if (!$row) throw new BookingException('Đơn chưa có ảnh CCCD này.', 404);
    try {
        $bytes = decrypt_booking_identity($row['encrypted_image'], $bookingId . ':' . $side);
    } catch (BookingIdentityReadException $error) {
        error_log(sprintf('Booking identity read failed booking=%s side=%s reason=%s', $bookingId, $side, $error->getMessage()));
        if ($error->getMessage() === 'missing_key') {
            json_response(['error' => 'Máy chủ chưa có khóa đọc ảnh CCCD hợp lệ.'], 409);
        }
        json_response(['error' => 'Không đọc được ảnh CCCD đã lưu.', 'code' => 'identity_unreadable'], 409);
    }
    header('Content-Type: ' . $row['mime_type']);
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: inline; filename="identity-' . $side . '"');
    echo $bytes;
    exit;
}

function render_booking_identity_fields(bool $required = true): void
{
    ?>
    <fieldset data-identity-upload class="col-span-full grid gap-3 rounded-xl border border-stone-200 bg-stone-50 p-4 sm:grid-cols-2">
        <legend class="px-1 text-base font-semibold">CCCD người thuê nhận phòng <?= $required ? 'bắt buộc' : '(không bắt buộc)' ?></legend>
        <p class="text-sm text-stone-600 sm:col-span-2"><?= $required ? 'Tải đủ 2 mặt của cùng một CCCD, rõ chữ và đủ góc.' : 'Có thể bỏ trống hoặc tải từng mặt CCCD, rõ chữ và đủ góc.' ?> Ảnh được lưu bảo mật cùng đơn để Mơ quản lý lưu trú; chỉ nhân viên được phân quyền mới xem được. JPG, PNG hoặc WebP, tối đa 3 MiB mỗi ảnh.</p>
        <?php foreach (['front' => 'Mặt trước', 'back' => 'Mặt sau'] as $side => $label): ?>
            <div data-identity-side class="grid min-w-0 content-start gap-2">
                <label class="grid gap-2 text-sm font-medium"><?= e($label) ?> <?= $required ? '*' : '' ?>
                    <input type="file" name="identity_<?= e($side) ?>" accept="image/jpeg,image/png,image/webp" <?= $required ? 'required' : '' ?>
                        class="w-full min-w-0 truncate rounded-xl border border-solid border-[#d8c9b8] bg-[#fffdf9] p-1 text-sm text-[#514237]
                            file:mr-2 file:min-h-9 file:rounded-lg file:border-0 file:bg-[#eee4d6] file:px-3 file:text-sm file:font-semibold file:text-[#563722]
                            enabled:file:cursor-pointer enabled:hover:file:bg-[#e4d3bd] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#79543e]">
                </label>
                <div class="relative mx-auto w-fit max-w-full">
                    <img data-identity-preview hidden alt="Xem trước CCCD <?= e(mb_strtolower($label)) ?>" class="max-h-40 w-auto max-w-full rounded-lg align-top">
                    <button type="button" data-identity-remove hidden aria-label="Xóa ảnh <?= e(mb_strtolower($label)) ?> CCCD"
                        class="absolute -top-1 -right-1 size-11 rounded-full border-0 bg-transparent p-0 text-red-800 enabled:hover:text-red-950 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-red-800">
                        <span class="mx-auto grid size-8 place-items-center rounded-full border border-solid border-[#dfc6be] bg-white" aria-hidden="true">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <path d="m6 6 12 12M18 6 6 18" />
                            </svg>
                        </span>
                    </button>
                </div>
            </div>
        <?php endforeach ?>
        <p data-identity-upload-message role="status" class="text-sm text-red-800 sm:col-span-2"></p>
    </fieldset>
    <?php
}
