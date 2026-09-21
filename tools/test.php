<?php
require dirname(__DIR__) . '/src/bootstrap.php';

$failures = [];
function check(bool $condition, string $message): void { global $failures; if (!$condition) $failures[] = $message; }

[$start, $end] = stay_window('2027-01-15', '14:00', '3h');
check($end->getTimestamp() - $start->getTimestamp() === 10800, 'Goi 3h phai dung 3 gio');
[$start6, $end6] = stay_window('2027-01-15', '23:00', '6h');
check($end6->getTimestamp() - $start6->getTimestamp() === 21600, 'Goi 6h phai qua nua dem dung');

try { stay_window('2027-02-30', '14:00', '3h'); $failures[] = 'Ngay khong ton tai phai bi tu choi'; } catch (BookingException) {}
try { stay_window('2027-01-15', '14:00', 'overnight'); $failures[] = 'Qua dem chua cau hinh phai bi tu choi'; } catch (BookingException) {}

$expected = hash_hmac('sha256', 'a=1&b=2', (string)config('payos.checksum_key'));
check(payos_signature(['b'=>2,'a'=>1]) === $expected, 'Chu ky payOS phai sap xep key');
check(payos_verify(['a'=>1,'b'=>2], $expected), 'Xac minh chu ky payOS hop le');
check(!payos_verify(['a'=>2,'b'=>2], $expected), 'Phai tu choi chu ky sai');

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL); exit(1); }
echo "Unit tests: OK\n";
