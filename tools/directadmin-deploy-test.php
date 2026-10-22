<?php
function deployment_expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$root = $argv[1] ?? dirname(__DIR__);
deployment_expect(is_file($root . '/deploy-activate.php'), 'Missing DirectAdmin cron activator');
require $root . '/deploy-activate.php';
$sha = str_repeat('a', 40);
$key = str_repeat('k', 40);
$marker = deployment_marker($sha, 123, str_repeat('b',64), $key);
deployment_expect(deployment_marker_valid($marker, $key), 'Valid marker accepted');
foreach ([['sha'=>str_repeat('c',40)],['run_id'=>124],['digest'=>str_repeat('c',64)],['signature'=>'bad']] as $changes) {
    deployment_expect(!deployment_marker_valid(array_replace($marker,$changes),$key), 'Reject tampered release marker');
}
deployment_expect(!deployment_marker_valid($marker, str_repeat('z',40)), 'Reject wrong signing key');
$dir = sys_get_temp_dir().'/mo-da-test-'.bin2hex(random_bytes(6));
mkdir($dir.'/public_html',0700,true);
mkdir($dir.'/.deploy-incoming',0700);
$zip = new ZipArchive();
$archive = $dir.'/.deploy-incoming/release-'.$sha.'-123.zip';
$zip->open($archive,ZipArchive::CREATE);
$zip->addFromString('release/public/index.php','<?php echo 1;');
$zip->addFromString('release/src/bootstrap.php','<?php function demo_boot() {}');
$zip->addFromString('release/public/assets/tailwind.css','body{}');
$zip->close();
$marker = deployment_marker($sha,123,hash_file('sha256',$archive),$key);
$markerPath=$dir.'/.deploy-incoming/ready-'.$sha.'-123.json';
file_put_contents($markerPath,json_encode($marker));
file_put_contents($dir.'/.env','preserve');
deployment_expect(deployment_activate($dir,$key,static function():void {}) === 'success','Activate complete signed package');
deployment_expect(!file_exists($markerPath),'Consume success marker');
deployment_expect(file_get_contents($dir.'/.env')==='preserve','Preserve app secrets');
$status=json_decode(file_get_contents($dir.'/public_html/deploy-status.json'),true);
deployment_expect($status['release']===$sha && $status['run_id']===123 && $status['status']==='success','Publish exact activated run status');
file_put_contents($markerPath,json_encode($marker));
deployment_expect(deployment_activate($dir,$key,static function():void { throw new LogicException('must not migrate twice'); })==='unchanged','Repeated marker cannot run migrations twice');
file_put_contents($dir.'/.deploy-incoming/ready-'.str_repeat('c',40).'-124.json',json_encode(deployment_marker(str_repeat('c',40),124,str_repeat('f',64),$key)));
deployment_expect(deployment_activate($dir,$key,static function():void {})==='failed','Missing archive cannot activate');
file_put_contents($markerPath,json_encode($marker));
file_put_contents($dir.'/.deploy-incoming/ready-'.str_repeat('d',40).'-122.json',json_encode(deployment_marker(str_repeat('d',40),122,str_repeat('f',64),$key)));
deployment_expect(deployment_activate($dir,$key,static function():void { throw new LogicException('old run'); })==='unchanged','Older run cannot overwrite active release');
require $root . '/scripts/deploy-directadmin.php';
require $root . '/scripts/build-directadmin-package.php';
deployment_expect(da_api_success('error=0&text=uploaded'), 'Accept explicit panel success');
foreach (['error=1&text=denied', '{"error":true}', '<html>Login required</html>', '{}'] as $body) {
    deployment_expect(!da_api_success($body), 'Fail closed on panel error or ambiguous response');
}
mkdir($dir . '/source/src',0700,true);
mkdir($dir . '/source/public/assets',0700,true);
mkdir($dir . '/source/public/uploads',0700,true);
mkdir($dir . '/source/database/migrations',0700,true);
file_put_contents($dir . '/source/src/bootstrap.php','<?php function source_boot() {}');
file_put_contents($dir . '/source/public/index.php','<?php echo 1;');
file_put_contents($dir . '/source/public/assets/tailwind.css','body{}');
file_put_contents($dir . '/source/.env','secret-never-package');
file_put_contents($dir . '/source/public/uploads/photo.jpg','private-photo');
[$package,$ready] = deployment_build_package($dir . '/source',$dir . '/package',$sha,200,$key);
$runtime = agent_read_archive($package);
deployment_expect(count($runtime) === 3, 'Package excludes environment and uploaded photos');
$requests=[];
da_upload_release('https://panel.example.test:2222','test-user','test-key','/domains/mohomestay.vn/.deploy-incoming',$package,$ready,
    static function($url,$user,$password,$form) use (&$requests): void { $requests[]=$form['file1']->getPostFilename(); });
deployment_expect($requests === ['release-'.$sha.'-200.zip','ready-'.$sha.'-200.json'], 'Two requests only; activation marker must be uploaded last');
$calls=0;
try {
    da_upload_release('https://panel.example.test:2222','test-user','test-key','/domains/mohomestay.vn/.deploy-incoming',$package,$ready,
        static function() use (&$calls): void { $calls++; throw new RuntimeException('Upload failed'); });
} catch (RuntimeException) {}
deployment_expect($calls===1,'Failed archive upload must not send activation marker');
$broken=$dir.'/persistence-failure';
mkdir($broken.'/public_html',0700,true);
mkdir($broken.'/.deploy-incoming/last-success.json',0700,true);
copy($package,$broken.'/.deploy-incoming/release-'.$sha.'-201.zip');
file_put_contents($broken.'/.deploy-incoming/ready-'.$sha.'-201.json',json_encode(deployment_marker($sha,201,hash_file('sha256',$package),$key)));
$result=@deployment_activate($broken,$key,static function():void {});
$brokenStatus=json_decode(file_get_contents($broken.'/public_html/deploy-status.json'),true);
deployment_expect($result==='failed' && $brokenStatus['status']==='failed','Persistence failure must never publish success');
echo "DirectAdmin activation and packaging tests: OK\n";
