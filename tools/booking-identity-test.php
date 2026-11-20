<?php

declare(strict_types=1);
putenv('APP_KEY=identity-test-key-for-synthetic-fixtures-only');
putenv('BOOKING_IDENTITY_APP_KEY=');
putenv('BOOKING_IDENTITY_LEGACY_APP_KEY=');
require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/booking-identity-check.php';
function identity_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function identity_reject(callable $action, string $message): void
{
    try { $action(); } catch (BookingException) { return; }
    throw new RuntimeException($message);
}
$path = tempnam(sys_get_temp_dir(), 'mo-id-test-');
try {
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAUAAAADICAIAAAAWZq/8AAACX0lEQVR4nO3TQQ0AIBDAMMC/xfOCBz5kSatgn+2ZWUDT+R0AvDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIEhzMAQZmAIMzCEGRjCDAxhBoYwA0OYgSHMwBBmYAgzMIQZGMIMDGEGhjADQ5iBIczAEGZgCDMwhBkYwgwMYQaGMANDmIFhdV1yTAQnz76DwQAAAABJRU5ErkJggg=='));
    $upload = ['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'name' => 'untrusted.php'];
    $images = read_booking_identity_uploads(['identity_front' => $upload, 'identity_back' => $upload]);
    $pngChunk = static fn(string $type, string $data): string =>
        pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $portrait = "\x89PNG\r\n\x1a\n"
        . $pngChunk('IHDR', pack('NNCCCCC', 120, 200, 8, 2, 0, 0, 0))
        . $pngChunk('IDAT', gzcompress(str_repeat("\0", (1 + 120 * 3) * 200)))
        . $pngChunk('IEND', '');
    file_put_contents($path, $portrait);
    clearstatcache(true, $path);
    identity_expect(validate_booking_identity_file($upload)['bytes'] === $portrait, 'Accept a 200 by 120 upload rotated into portrait');
    file_put_contents($path, $images['front']['bytes']);
    clearstatcache(true, $path);
    identity_expect(read_booking_identity_uploads([], false) === [], 'Admin may omit both sides');
    identity_expect(array_keys(read_booking_identity_uploads(['identity_front' => $upload], false)) === ['front'], 'Admin may upload one side');
    identity_reject(fn() => read_booking_identity_uploads(['identity_back' => array_replace($upload, ['error' => UPLOAD_ERR_INI_SIZE])], false), 'Optional upload still rejects upload errors');
    identity_expect($images['front']['mime'] === 'image/png', 'Validate actual MIME instead of file name');
    identity_reject(fn() => read_booking_identity_uploads(['identity_front' => $upload]), 'Missing back must fail');
    identity_reject(fn() => validate_booking_identity_file(array_replace($upload, ['error' => UPLOAD_ERR_INI_SIZE])), 'Upload errors fail closed');
    $encrypted = encrypt_booking_identity($images['front']['bytes'], 'booking:front');
    identity_expect($encrypted !== $images['front']['bytes'], 'No plaintext storage');
    identity_expect(decrypt_booking_identity($encrypted, 'booking:front') === $images['front']['bytes'], 'Encrypted image round trip');
    $originalKey = getenv('APP_KEY');
    putenv('APP_KEY=rotated-identity-test-key-for-synthetic-fixtures-only');
    putenv('BOOKING_IDENTITY_LEGACY_APP_KEY=' . $originalKey);
    try {
        identity_expect(decrypt_booking_identity($encrypted, 'booking:front') === $images['front']['bytes'], 'Read existing images after key rotation');
        putenv('APP_KEY=short');
        identity_expect(decrypt_booking_identity($encrypted, 'booking:front') === $images['front']['bytes'], 'The legacy key can recover an image when the current key is unusable');
        identity_reject(fn() => encrypt_booking_identity($images['front']['bytes'], 'booking:front'), 'New images still require a valid current key');
        putenv('APP_KEY=rotated-identity-test-key-for-synthetic-fixtures-only');
        $newImage = encrypt_booking_identity($images['front']['bytes'], 'booking:front');
        putenv('BOOKING_IDENTITY_LEGACY_APP_KEY');
        identity_expect(decrypt_booking_identity($newImage, 'booking:front') === $images['front']['bytes'], 'New images use the current key');
        putenv('APP_KEY=short');
        try {
            decrypt_booking_identity($encrypted, 'booking:front');
            throw new LogicException('Missing keys should fail closed');
        } catch (BookingIdentityReadException $error) {
            identity_expect($error->getMessage() === 'missing_key', 'Missing keys are distinguishable in server logs');
        }
    } finally {
        putenv('APP_KEY=' . $originalKey);
        putenv('BOOKING_IDENTITY_LEGACY_APP_KEY');
    }
putenv('BOOKING_IDENTITY_APP_KEY=' . str_repeat('8', 64));
    try {
        $dedicatedImage = encrypt_booking_identity($images['front']['bytes'], 'booking:front');
        identity_expect(decrypt_booking_identity($encrypted, 'booking:front') === $images['front']['bytes'], 'Enabling the dedicated key preserves existing images');
        putenv('APP_KEY=another-application-key-for-tests');
        identity_expect(decrypt_booking_identity($dedicatedImage, 'booking:front') === $images['front']['bytes'], 'Rotating APP_KEY does not affect dedicated CCCD uploads');
        putenv('BOOKING_IDENTITY_APP_KEY=short');
        identity_reject(fn() => encrypt_booking_identity($images['front']['bytes'], 'booking:front'), 'An invalid dedicated key cannot silently fall back for writes');
    } finally {
        putenv('APP_KEY=' . $originalKey);
        putenv('BOOKING_IDENTITY_APP_KEY');
    }
    foreach ([$encrypted . 'tampered', $encrypted] as $i => $data) {
        try {
            decrypt_booking_identity($data, $i === 0 ? 'booking:front' : 'other-booking:front');
            throw new LogicException('Tampering or cross-booking substitution accepted');
        } catch (BookingIdentityReadException $error) {
            identity_expect($error->getMessage() === 'authentication_failed', 'Authentication failures are distinguishable from transient service errors');
        }
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE booking_identity_images(booking_id TEXT, side TEXT, mime_type TEXT, encrypted_image BLOB, PRIMARY KEY(booking_id,side))');
    $pdo->exec("CREATE TRIGGER damage_identity AFTER INSERT ON booking_identity_images WHEN NEW.side = 'back' BEGIN
        UPDATE booking_identity_images SET encrypted_image = zeroblob(length(NEW.encrypted_image)) WHERE booking_id = NEW.booking_id AND side = NEW.side;
    END");
    $pdo->beginTransaction();
    identity_reject(fn() => save_booking_identity($pdo, 'damaged', $images), 'Corruption of a stored image must be detected before the booking commits');
    $pdo->rollBack();
    $pdo->exec('DROP TRIGGER damage_identity');
    $pdo->beginTransaction();
    save_booking_identity($pdo, 'first', $images);
    $pdo->rollBack();
    identity_expect((int)$pdo->query('SELECT COUNT(*) FROM booking_identity_images')->fetchColumn() === 0, 'Images roll back with booking');
    $pdo->beginTransaction();
    save_booking_identity($pdo, 'first', $images);
    $pdo->commit();
    identity_expect((int)$pdo->query('SELECT COUNT(*) FROM booking_identity_images')->fetchColumn() === 2, 'Both sides persisted');
    $stored = $pdo->query("SELECT encrypted_image FROM booking_identity_images WHERE side='front'")->fetchColumn();
    identity_expect(decrypt_booking_identity($stored, 'first:front') === $images['front']['bytes'], 'Stored image remains readable');
    $pdo->exec("CREATE TABLE bookings(id TEXT, booking_code TEXT); INSERT INTO bookings VALUES ('first','MO-1234567890')");
    identity_expect(check_saved_booking_identity($pdo, 'MO-1234567890') === ['front' => 'readable', 'back' => 'readable'], 'CLI reports only status for a readable booking');
    $pdo->exec("UPDATE booking_identity_images SET encrypted_image='short' WHERE side='back'");
    identity_expect(check_saved_booking_identity($pdo, 'MO-1234567890') === ['front' => 'readable', 'back' => 'invalid_payload'], 'CLI identifies damaged stored data without disclosing it');
    identity_expect(check_saved_booking_identity($pdo, 'MO-0000000000') === ['booking' => 'not_found'], 'CLI identifies a missing booking');
    replace_unreadable_booking_identity($pdo, 'first', 'back', $images['back']);
    identity_expect(check_saved_booking_identity($pdo, 'MO-1234567890') === ['front' => 'readable', 'back' => 'readable'], 'A damaged side can be replaced');
    identity_expect($pdo->query("SELECT encrypted_image FROM booking_identity_images WHERE side='front'")->fetchColumn() === $stored, 'The other side is unchanged');
    identity_reject(fn() => replace_unreadable_booking_identity($pdo, 'first', 'back', $images['back']), 'A readable or already repaired side cannot be replaced');
    identity_reject(fn() => replace_unreadable_booking_identity($pdo, 'missing', 'back', $images['back']), 'A repair cannot create a missing record');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>');
    identity_reject(fn() => validate_booking_identity_file($upload), 'Active SVG uploads rejected');
    file_put_contents($path, str_repeat('x', 3 * 1024 * 1024 + 1));
    identity_reject(fn() => validate_booking_identity_file($upload), 'Oversized uploads rejected');
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    identity_reject(fn() => booking_form_body(), 'JSON cannot bypass identity upload requirement');
    identity_expect(admin_api_permission('/api/admin/bookings/' . str_repeat('a',32) . '/identity/front', 'GET') === 'bookings.identity', 'Identity access needs dedicated permission');
    identity_expect(admin_api_permission('/api/admin/bookings/' . str_repeat('a', 32) . '/identity/back', 'POST') === 'bookings.identity', 'Staff with identity viewing can repair a damaged side');
    identity_expect(admin_api_permission('/api/admin/bookings', 'GET') === 'bookings.view', 'Normal listing access preserved');
    $public = file_get_contents(APP_ROOT . '/src/api.php');
    $lookup = substr($public, strpos($public, 'function lookup_booking_api()'), strpos($public, 'function admin_bookings_api()') - strpos($public, 'function lookup_booking_api()'));
    identity_expect(!str_contains($lookup, 'encrypted_image') && !str_contains($lookup, 'identity_image_count'), 'Public lookup never returns private images');
    echo "Booking identity security tests: OK (synthetic images and SQLite)\n";
} finally {
    @unlink($path);
}
