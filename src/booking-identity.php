<?php

declare(strict_types=1);

function booking_identity_key(): string
{
    $secret = (string)config('app_key', '');
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
    if (strlen($encrypted) < 29) throw new RuntimeException('Dữ liệu ảnh CCCD không hợp lệ.');
    $plain = openssl_decrypt(substr($encrypted, 28), 'aes-256-gcm', booking_identity_key(), OPENSSL_RAW_DATA,
        substr($encrypted, 0, 12), substr($encrypted, 12, 16), $context);
    if ($plain === false) throw new RuntimeException('Không giải mã được ảnh CCCD.');
    return $plain;
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
    $insert = $pdo->prepare('INSERT INTO booking_identity_images(booking_id,side,mime_type,encrypted_image) VALUES(?,?,?,?)');
    foreach ($images as $side => $image) {
        $insert->execute([$bookingId, $side, $image['mime'], encrypt_booking_identity($image['bytes'], $bookingId . ':' . $side)]);
    }
}

function admin_booking_identity_image(string $bookingId, string $side): never
{
    require_admin();
    require_permission('bookings.identity');
    $find = db()->prepare('SELECT mime_type,encrypted_image FROM booking_identity_images WHERE booking_id=? AND side=?');
    $find->execute([$bookingId, $side]);
    $row = $find->fetch();
    if (!$row) throw new BookingException('Đơn chưa có ảnh CCCD này.', 404);
    $bytes = decrypt_booking_identity($row['encrypted_image'], $bookingId . ':' . $side);
    header('Content-Type: ' . $row['mime_type']);
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
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
            <label class="grid content-start gap-2 text-sm font-medium"><?= e($label) ?> <?= $required ? '*' : '' ?>
                <input type="file" name="identity_<?= e($side) ?>" accept="image/jpeg,image/png,image/webp" <?= $required ? 'required' : '' ?> class="min-h-11 w-full rounded-lg border border-stone-300 bg-white p-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-800">
                <img data-identity-preview hidden alt="Xem trước CCCD <?= e(mb_strtolower($label)) ?>" class="max-h-40 w-full rounded-lg object-contain">
            </label>
        <?php endforeach ?>
        <p data-identity-upload-message role="status" class="text-sm text-red-800 sm:col-span-2"></p>
    </fieldset>
    <?php
}
