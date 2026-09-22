<?php

declare(strict_types=1);

$root=dirname(__DIR__);$failures=[];
function ui_check(bool $condition,string $message):void{global $failures;if(!$condition)$failures[]=$message;}
$views=(string)file_get_contents($root.'/src/views.php');$app=(string)file_get_contents($root.'/public/assets/app.js');
$ui=is_file($root.'/public/assets/ui.js')?(string)file_get_contents($root.'/public/assets/ui.js'):'';
$css=(string)file_get_contents($root.'/public/assets/app.css');
ui_check(str_contains($views,'href="/favicon.svg"'),'Moi trang phai link favicon');
ui_check(str_contains($views,'apple-touch-icon'),'Moi trang phai link apple touch icon');
ui_check(substr_count($views,'data-toast-region')===1,'Layout phai co dung mot toast region');
ui_check(substr_count($views,'data-dialog-root')===1,'Layout phai co dung mot dialog root');
ui_check(str_contains($ui,'window.LangUI'),'ui.js phai expose LangUI');
ui_check(str_contains($ui,'toast(')&&str_contains($ui,'confirm('),'LangUI phai co toast va confirm');
ui_check(str_contains($css,'prefers-reduced-motion'),'CSS phai ton trong reduced motion');
ui_check(!str_contains($app,'alert(')&&!str_contains($app,'confirm('),'App khong duoc dung alert/confirm native');
ui_check(is_file($root.'/public/favicon.svg')&&is_file($root.'/public/apple-touch-icon.svg'),'Phai co favicon assets');
if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}echo "Shared UI contract tests: OK\n";
