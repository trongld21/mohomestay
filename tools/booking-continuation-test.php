<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/booking.php';
require dirname(__DIR__) . '/src/payment.php';
require dirname(__DIR__) . '/src/rooms.php';
require dirname(__DIR__) . '/src/manual-bookings.php';
require dirname(__DIR__) . '/src/room-operations.php';
require dirname(__DIR__) . '/src/room-inventory.php';
require dirname(__DIR__) . '/src/availability.php';

function uuid(): string { return bin2hex(random_bytes(16)); }
function current_account(): array { return ['id' => 'staff', 'root' => false]; }
function queue_staff_notification(PDO $pdo, array $booking, string $event, ?array $charge = null): void {}
final class RoomRepository
{
    public function __construct(private PDO $pdo) {}
    public function findById(string $id): ?array
    {
        global $room;
        return $id === $room['id'] ? $room : null;
    }
}
function continuation_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function continuation_reject(callable $operation): void
{
    try {
        $operation();
        throw new RuntimeException('Invalid continuation accepted.');
    } catch (BookingException $error) {
        continuation_check($error->httpStatus === 409, 'Conflict must return 409.');
    }
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE rooms (id TEXT PRIMARY KEY,name TEXT,is_active INTEGER,status TEXT);
CREATE TABLE bookings (id TEXT PRIMARY KEY,booking_code TEXT,room_id TEXT,check_in TEXT,check_out TEXT,stay_package TEXT,package_id TEXT,
package_name_snapshot TEXT,package_mode_snapshot TEXT,package_duration_snapshot INTEGER,package_check_in_snapshot TEXT,package_check_out_snapshot TEXT,
package_price_snapshot INTEGER,access_token TEXT,total_price INTEGER,number_of_guests INTEGER,guest_name TEXT,guest_email TEXT,guest_phone TEXT,
guest_note TEXT,booking_source TEXT,manual_request_id TEXT UNIQUE,status TEXT,payment_status TEXT,payment_method TEXT,is_test INTEGER DEFAULT 0,
hold_expires_at TEXT,continued_from_booking_id TEXT,continued_to_booking_id TEXT);
CREATE TABLE booking_checkout_charges (booking_id TEXT PRIMARY KEY,actual_checkout_at TEXT);
CREATE TABLE room_availability (id TEXT,room_id TEXT,date TEXT,available INTEGER);
CREATE TABLE room_quote_holds (id TEXT,room_id TEXT,starts_at TEXT,ends_at TEXT,released_at TEXT,expires_at TEXT);
CREATE TABLE room_emergency_locks (id TEXT,room_id TEXT,booking_id TEXT,starts_at TEXT,ended_at TEXT,ended_by TEXT);
INSERT INTO rooms VALUES ('r','Room',1,'ACTIVE');");
$room = ['id' => 'r', 'name' => 'Room', 'status' => 'ACTIVE', 'maxGuests' => 2, 'packages' => [
    ['id' => 'night', 'name' => 'Qua đêm', 'enabled' => true, 'mode' => 'FIXED_TIME', 'checkInTime' => '21:00', 'checkOutTime' => '09:00', 'price' => 287000],
    ['id' => 'three', 'name' => '3h', 'enabled' => true, 'mode' => 'DURATION', 'durationMinutes' => 180, 'price' => 159000],
    ['id' => 'wrong', 'name' => 'Wrong', 'enabled' => true, 'mode' => 'FIXED_TIME', 'checkInTime' => '22:00', 'checkOutTime' => '09:00', 'price' => 287000],
]];
$day = new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Ho_Chi_Minh'));
$parentId = str_repeat('a', 32);
$start = mysql_datetime($day->setTime(18, 0));
$boundary = mysql_datetime($day->setTime(21, 0));
$end = mysql_datetime($day->modify('+1 day')->setTime(9, 0));
$pdo->prepare("INSERT INTO bookings (id,booking_code,room_id,check_in,check_out,status,payment_status,guest_name,guest_phone,guest_email,number_of_guests) VALUES (?,'MO-PARENT','r',?,?,'CONFIRMED','PAID','Guest','0901234567','guest@example.test',2)")
    ->execute([$parentId, $start, $boundary]);
continuation_reject(fn() => assert_room_inventory($pdo, 'r', $boundary, $end));
$context = booking_continuation_context($pdo, $parentId);
continuation_check(array_column($context['packages'], 'id') === ['night', 'three'], 'Only packages beginning exactly at previous checkout are options.');
continuation_reject(fn() => create_booking_continuation($pdo, $parentId, ['packageId' => 'wrong', 'requestId' => uuid()]));
$input = ['packageId' => 'night', 'requestId' => uuid(), 'guestPhone' => '0999999999'];
$child = create_booking_continuation($pdo, $parentId, $input);
continuation_check($child['check_in'] === $boundary && $child['check_out'] === $end, 'Night must begin at 21:00 and end at 09:00.');
continuation_check($child['guest_phone'] === '0901234567', 'Guest identity must come from the original booking.');
continuation_check($child['continued_from_booking_id'] === $parentId && (int)$child['total_price'] === 287000, 'New package has its own linked booking and price.');
$retry = create_booking_continuation($pdo, $parentId, $input);
continuation_check($retry['id'] === $child['id'], 'Retry must not create another package.');
continuation_reject(fn() => create_booking_continuation($pdo, $parentId, ['packageId' => 'night', 'requestId' => uuid()]));
continuation_reject(fn() => assert_room_inventory($pdo, 'r', $boundary, $end));
$inventory = availability_snapshot($pdo, [$room], $day->format('Y-m-d'), 2);
$cleaning = array_values(array_filter($inventory['rooms'][0]['intervals'], static fn($interval) => $interval['status'] === 'CLEANING'));
continuation_check(count($cleaning) === 1 && strtotime($cleaning[0]['start']) === strtotime($end . ' UTC'), 'Only final checkout has the cleaning hour.');
$parent = $pdo->query("SELECT * FROM bookings WHERE id='" . $parentId . "'")->fetch();
$state = booking_lifecycle($parent, strtotime($boundary . ' UTC') + 60);
continuation_check($state['status'] === 'CHECKED_OUT' && !$state['isOverdue'] && $state['continuingStay'], 'Finished first package must not become overdue while guest stays in the next package.');
continuation_reject(fn() => update_booking_lifecycle($pdo, $parentId, 'CANCELLED'));
update_booking_lifecycle($pdo, $child['id'], 'CANCELLED');
$inventory = availability_snapshot($pdo, [$room], $day->format('Y-m-d'), 2);
$cleaning = array_values(array_filter($inventory['rooms'][0]['intervals'], static fn($interval) => $interval['status'] === 'CLEANING'));
continuation_check(count($cleaning) === 1 && strtotime($cleaning[0]['start']) === strtotime($boundary . ' UTC'), 'Cancelling continuation restores cleaning after the parent.');
$pdo->prepare("INSERT INTO bookings (id,booking_code,room_id,check_in,check_out,status,guest_phone) VALUES (?,'MO-OTHER','r',?,?,'CONFIRMED','0987654321')")
    ->execute([uuid(), mysql_datetime($day->modify('+1 day')->setTime(8, 0)), mysql_datetime($day->modify('+1 day')->setTime(12, 0))]);
continuation_reject(fn() => create_booking_continuation($pdo, $parentId, ['packageId' => 'night', 'requestId' => uuid()]));
echo "Continuous booking identity, adjacency, conflict, chain cleaning, cancellation and retries: OK\n";
