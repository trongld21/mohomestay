<?php

declare(strict_types=1);

function validate_room_upload(array $file,string $tmpPath):array
{
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK)throw new BookingException('Tải ảnh lên không thành công.',422);
    $size=(int)($file['size']??0);
    if($size<1||$size>8*1024*1024)throw new BookingException('Mỗi ảnh phải nhỏ hơn 8 MiB.',422);
    if(!is_file($tmpPath))throw new BookingException('Không tìm thấy tệp ảnh tạm.',422);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmpPath)?:'';
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($extensions[$mime]))throw new BookingException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WebP.',422);
    $dimensions=@getimagesize($tmpPath);
    if(!$dimensions||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>8000||$dimensions[1]>8000)throw new BookingException('Kích thước ảnh không hợp lệ hoặc vượt quá 8.000 px.',422);
    return ['extension'=>$extensions[$mime],'mime'=>$mime,'size'=>$size,'width'=>(int)$dimensions[0],'height'=>(int)$dimensions[1]];
}

function managed_upload_path(string $roomId,string $extension):string
{
    if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/',$roomId)||!in_array($extension,['jpg','png','webp'],true))throw new BookingException('Đường dẫn ảnh không hợp lệ.',422);
    return '/uploads/rooms/'.$roomId.'/'.bin2hex(random_bytes(16)).'.'.$extension;
}

function is_managed_room_upload(string $path,?string $roomId=null):bool
{
    if(str_contains($path,'..')||str_contains($path,"\0"))return false;
    $room=$roomId?preg_quote($roomId,'#'):'[a-zA-Z0-9_-]{1,64}';
    return (bool)preg_match('#^/uploads/rooms/'.$room.'/[a-f0-9]{32}\.(?:jpg|png|webp)$#',$path);
}

function assert_room_image_limit(int $count):void
{
    if($count>=12)throw new BookingException('Mỗi phòng được lưu tối đa 12 ảnh.',409);
}

function room_upload_disk_path(string $publicPath):string
{
    if(!is_managed_room_upload($publicPath))throw new BookingException('Đường dẫn ảnh không được quản lý.',422);
    return APP_ROOT.'/public'.str_replace('/',DIRECTORY_SEPARATOR,$publicPath);
}

function store_room_upload(array $file,string $roomId):array
{
    $tmp=(string)($file['tmp_name']??'');
    if(PHP_SAPI!=='cli'&&!is_uploaded_file($tmp))throw new BookingException('Tệp tải lên không hợp lệ.',422);
    $metadata=validate_room_upload($file,$tmp);$path=managed_upload_path($roomId,$metadata['extension']);$disk=room_upload_disk_path($path);
    $directory=dirname($disk);if(!is_dir($directory)&&!mkdir($directory,0755,true)&&!is_dir($directory))throw new RuntimeException('Không thể tạo thư mục ảnh.');
    $moved=PHP_SAPI==='cli'?copy($tmp,$disk):move_uploaded_file($tmp,$disk);
    if(!$moved)throw new RuntimeException('Không thể lưu ảnh.');
    return $metadata+['path'=>$path,'diskPath'=>$disk];
}

function delete_managed_upload(string $path,string $roomId):void
{
    if(!is_managed_room_upload($path,$roomId))return;
    $disk=room_upload_disk_path($path);if(is_file($disk))@unlink($disk);
}
