<?php

declare(strict_types=1);
putenv('APP_KEY=identity-test-key-for-synthetic-fixtures-only');
require dirname(__DIR__) . '/src/bootstrap.php';
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
    identity_expect($images['front']['mime'] === 'image/png', 'Validate actual MIME instead of file name');
    identity_reject(fn() => read_booking_identity_uploads(['identity_front' => $upload]), 'Missing back must fail');
    identity_reject(fn() => validate_booking_identity_file(array_replace($upload, ['error' => UPLOAD_ERR_INI_SIZE])), 'Upload errors fail closed');
    $encrypted = encrypt_booking_identity($images['front']['bytes'], 'booking:front');
    identity_expect($encrypted !== $images['front']['bytes'], 'No plaintext storage');
    identity_expect(decrypt_booking_identity($encrypted, 'booking:front') === $images['front']['bytes'], 'Encrypted image round trip');
    foreach ([$encrypted . 'tampered', $encrypted] as $i => $data) {
        try {
            decrypt_booking_identity($data, $i === 0 ? 'booking:front' : 'other-booking:front');
            throw new LogicException('Tampering or cross-booking substitution accepted');
        } catch (RuntimeException $error) {
            if ($error instanceof LogicException) throw $error;
        }
    }
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE booking_identity_images(booking_id TEXT, side TEXT, mime_type TEXT, encrypted_image BLOB, PRIMARY KEY(booking_id,side))');
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
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>');
    identity_reject(fn() => validate_booking_identity_file($upload), 'Active SVG uploads rejected');
    file_put_contents($path, str_repeat('x', 3 * 1024 * 1024 + 1));
    identity_reject(fn() => validate_booking_identity_file($upload), 'Oversized uploads rejected');
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    identity_reject(fn() => booking_form_body(), 'JSON cannot bypass identity upload requirement');
    identity_expect(admin_api_permission('/api/admin/bookings/' . str_repeat('a',32) . '/identity/front', 'GET') === 'bookings.identity', 'Identity access needs dedicated permission');
    identity_expect(admin_api_permission('/api/admin/bookings', 'GET') === 'bookings.view', 'Normal listing access preserved');
    $public = file_get_contents(APP_ROOT . '/src/api.php');
    $lookup = substr($public, strpos($public, 'function lookup_booking_api()'), strpos($public, 'function admin_bookings_api()') - strpos($public, 'function lookup_booking_api()'));
    identity_expect(!str_contains($lookup, 'encrypted_image') && !str_contains($lookup, 'identity_image_count'), 'Public lookup never returns private images');
    echo "Booking identity security tests: OK (synthetic images and SQLite)\n";
} finally {
    @unlink($path);
}
