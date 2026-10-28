<?php

require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE bookings (id TEXT PRIMARY KEY,payment_status TEXT,status TEXT,is_test INTEGER,check_out TEXT,
    payment_provision_checked_at TEXT,confirmation_email_status TEXT,confirmation_email_attempts INTEGER,created_at TEXT);
CREATE TABLE booking_access_codes (booking_id TEXT,sync_status TEXT);");
$insert = $pdo->prepare("INSERT INTO bookings VALUES (?,'PAID','CONFIRMED',0,?,NULL,'PENDING',0,?)");
for ($i=0; $i<35; $i++) $insert->execute([(string)$i, gmdate('Y-m-d H:i:s',time()+86400), gmdate('Y-m-d H:i:s')]);
$seen = [];
$provision = function (string $id) use (&$seen): void { $seen[]=$id; };
retry_paid_booking_provisioning($pdo, $provision, false);
retry_paid_booking_provisioning($pdo, $provision, false);
if (count($seen)!==35 || count(array_unique($seen))!==35) {
    throw new RuntimeException('More than 30 pending emails must rotate without TTLock, even when no attempt can succeed.');
}
$pdo->exec("UPDATE bookings SET confirmation_email_status='SENT',payment_provision_checked_at=NULL");
$seen=[];
retry_paid_booking_provisioning($pdo, $provision, false);
if ($seen!==[]) throw new RuntimeException('Completed mail with TTLock disabled must leave the queue.');
echo "Persistent provisioning fairness without TTLock: OK\n";
