<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';
require_once APP_ROOT.'/src/uploads.php';

$failures=[];
function upload_check(bool $condition,string $message):void{global $failures;if(!$condition)$failures[]=$message;}
function upload_throws(callable $callback,string $message):void{global $failures;try{$callback();$failures[]=$message;}catch(BookingException){}}

$tmp=tempnam(sys_get_temp_dir(),'lang-upload-');
file_put_contents($tmp,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q=='));
$valid=validate_room_upload(['error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp),'name'=>'room.jpg'],$tmp);
upload_check($valid['extension']==='jpg'&&$valid['mime']==='image/jpeg','JPEG that phai duoc chap nhan');
upload_throws(fn()=>validate_room_upload(['error'=>UPLOAD_ERR_OK,'size'=>9*1024*1024,'name'=>'large.jpg'],$tmp),'File qua 8 MiB phai bi tu choi');
$fake=tempnam(sys_get_temp_dir(),'lang-fake-');file_put_contents($fake,'<?php echo 1;');
upload_throws(fn()=>validate_room_upload(['error'=>UPLOAD_ERR_OK,'size'=>filesize($fake),'name'=>'fake.jpg'],$fake),'File gia anh phai bi tu choi');
$path=managed_upload_path('room_01','jpg');
upload_check((bool)preg_match('#^/uploads/rooms/room_01/[a-f0-9]{32}\.jpg$#',$path),'Ten upload phai ngau nhien va an toan');
upload_check(is_managed_room_upload($path,'room_01'),'Path upload hop le phai duoc quan ly');
upload_check(!is_managed_room_upload('/uploads/rooms/../deploy-hook.php','room_01'),'Path traversal phai bi tu choi');
upload_check(!is_managed_room_upload('/images/pink.jpg','room_01'),'Khong duoc xoa anh ngoai upload root');
upload_throws(fn()=>assert_room_image_limit(12),'Phong du 12 anh phai tu choi upload tiep');
$api=(string)file_get_contents(APP_ROOT.'/src/api.php');
foreach(['create_room_api','update_room_api','delete_room_api','upload_room_image_api','update_room_image_api','delete_room_image_api'] as $handler){
    upload_check((bool)preg_match('/function '.preg_quote($handler,'/').'\([^}]+require_admin\(\);require_csrf\(\);/s',$api),$handler.' phai bat buoc admin va CSRF');
}
upload_check(str_contains((string)file_get_contents(APP_ROOT.'/src/room-repository.php'),'room_deletion_allowed'),'Xoa phong phai dung chinh sach 409 khi da co booking');
@unlink($tmp);@unlink($fake);

if($failures){fwrite(STDERR,implode(PHP_EOL,$failures).PHP_EOL);exit(1);}echo "Upload security tests: OK\n";
