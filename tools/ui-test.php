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
foreach(['data-admin-tab="bookings"','data-admin-tab="rooms"','data-add-room','data-room-editor','data-room-form','data-image-input','data-tag-input','data-package-template','data-dirty-indicator'] as $contract){
    ui_check(str_contains($views,$contract),'Admin UI thieu contract '.$contract);
}
ui_check(str_contains($views,'/assets/admin.js'),'Admin dashboard phai tai admin.js');
foreach(['data-public-room-grid','room-tag-chips','coming-soon','data-flex-package','data-room-gallery'] as $contract){
    ui_check(str_contains($views,$contract),'Public UI thieu contract '.$contract);
}
ui_check(str_contains($views,'/assets/rooms.js'),'Public pages phai tai rooms.js');
ui_check(str_contains((string)file_get_contents($root.'/public/index.php'),'find_room'),'Route chi tiet phai tim phong dong theo slug');
ui_check(!str_contains($app,'const roomData='),'App khong duoc hard-code ba phong');
$adminJs=is_file($root.'/public/assets/admin.js')?(string)file_get_contents($root.'/public/assets/admin.js'):'';
ui_check(str_contains($adminJs,'form.elements.id.value')&&str_contains($adminJs,'form.elements.name.focus'),'Room editor phai truy cap field id/name qua form.elements');
ui_check(str_contains($views,'<option value="<?=e($r[\'id\'])?>"><?=e($r[\'name\'])?></option>'),'Homepage phai escape room option do admin quan ly');
if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}echo "Shared UI contract tests: OK\n";
