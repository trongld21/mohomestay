<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
echo 'Nhap mat khau admin: ';
$password = trim((string)fgets(STDIN));
if (strlen($password) < 12) exit("Mat khau phai co it nhat 12 ky tu.\n");
echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
