<?php
require __DIR__ . '/../src/revenue-integration.php';
function check($v,$m) { if (!$v) throw new RuntimeException($m); }
check(!mo_revenue_authorized('', ''), 'Empty key denied');
check(!mo_revenue_authorized(str_repeat('a',32), str_repeat('b',32)), 'Wrong key denied');
check(mo_revenue_authorized(str_repeat('a',32), str_repeat('a',32)), 'Right key accepted');
$p = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$p->exec("CREATE TABLE rooms(id TEXT,name TEXT);
INSERT INTO rooms VALUES('r','Phòng Mơ');
CREATE TABLE bookings(id TEXT,booking_code TEXT,room_id TEXT,total_price REAL,status TEXT,payment_status TEXT,is_test INTEGER,check_in TEXT);
INSERT INTO bookings VALUES('b','MO-1','r',1000,'CHECKED_OUT','PAID',0,'2026-10-24 20:00:00'),('test','MO-2','r',999,'CONFIRMED','PAID',1,'2026-10-24 20:00:00'),('cancel','MO-3','r',999,'CANCELLED','PAID',0,'2026-10-24 20:00:00');
CREATE TABLE payments(booking_id TEXT,amount REAL,status TEXT,paid_at TEXT,payment_method TEXT);
INSERT INTO payments VALUES('b',1000,'PAID','2026-10-24 17:00:00','cash'),('test',999,'PAID','2026-10-24 18:00:00','cash'),('cancel',999,'PAID','2026-10-24 18:00:00','cash');
CREATE TABLE booking_checkout_charges(booking_id TEXT,amount REAL,payment_status TEXT,paid_at TEXT);
INSERT INTO booking_checkout_charges VALUES('b',100,'PAID','2026-10-24 18:00:00');
CREATE TABLE retail_sales(sale_code TEXT,total REAL,status TEXT,paid_at TEXT,room_name_snapshot TEXT,completion_method TEXT);
INSERT INTO retail_sales VALUES('BH1',200,'PAID','2026-10-25 16:59:59','Phòng Mơ','MANUAL_TRANSFER'),('BH2',999,'PAID','2026-10-25 17:00:00','','PAYOS');
CREATE TABLE expense_vouchers(voucher_code TEXT,amount REAL,spent_at TEXT,include_cashflow INTEGER,category TEXT);
INSERT INTO expense_vouchers VALUES('PC1',50,'2026-10-24 17:00:00',1,'Vật tư'),('PC2',999,'2026-10-24 18:00:00',0,'Khác');");
$r=mo_revenue_report($p,'2026-10-25','2026-10-25');
check($r['totals']['room']===1000.0, 'Room excludes test and cancelled, uses paid UTC date');
check($r['totals']['retail']===200.0 && $r['totals']['surcharge']===100.0, 'Retail and checkout');
check($r['totals']['collected']===1300.0 && $r['totals']['expense']===50.0,'Total without double counting');
check($r['days'][0]['date']==='2026-10-25', 'Vietnam date');
check(count($r['days'][0]['transactions'])===4, 'All receipt and expense details');
check($r['totals']['cash']===1000.0 && $r['totals']['transfer']===300.0, 'Payment split');
echo "Mo revenue: PASS\n";
