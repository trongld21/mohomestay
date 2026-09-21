<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
echo 'Nhap mat khau admin: ';
$password = trim((string)fgets(STDIN));
echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
