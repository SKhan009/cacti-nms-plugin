<?php
/** Run inside `unshare --net` on RHEL. No persistent writes or device reads. */
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap=getenv('NMS_CACTI_BOOTSTRAP');
if (!$bootstrap) throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP.');
require $bootstrap;
require_once $config['base_path'].'/plugins/nms/includes/mib_templates.php';
$_SESSION=['sess_user_id'=>1];
function check($ok,$message) { if(!$ok)throw new RuntimeException($message); echo "PASS: $message\n"; }
$category=(int)nms_categories()[0]['id'];
$dir=sys_get_temp_dir().'/nms-offline-qa-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$base="QA-OFFLINE-BASE DEFINITIONS ::= BEGIN\nIMPORTS enterprises FROM SNMPv2-SMI;\nqaRoot OBJECT IDENTIFIER ::= { enterprises 55555 }\nEND\n";
$main='QA-OFFLINE-MIB DEFINITIONS ::= BEGIN
IMPORTS OBJECT-TYPE, Integer32 FROM SNMPv2-SMI qaRoot FROM QA-OFFLINE-BASE;
qaValue OBJECT-TYPE
 SYNTAX Integer32
 MAX-ACCESS read-only
 STATUS current
 DESCRIPTION "Offline test only"
 ::= { qaRoot 1 }
END';
file_put_contents($dir.'/base.txt',$base);file_put_contents($dir.'/main.my',$main);
$upload=['name'=>['main.my'],'tmp_name'=>[$dir.'/main.my'],'error'=>[UPLOAD_ERR_OK]];
try {
 foreach ([UPLOAD_ERR_INI_SIZE=>'upload_max_filesize',UPLOAD_ERR_PARTIAL=>'interrupted',UPLOAD_ERR_NO_TMP_DIR=>'temporary directory',UPLOAD_ERR_CANT_WRITE=>'cannot write',UPLOAD_ERR_NO_FILE=>'Select a MIB'] as $error=>$expected) {
  $bad=$upload;$bad['error'][0]=$error;
  try {nms_mib_upload_path($bad,0);throw new LogicException('Upload error ignored');}
  catch(InvalidArgumentException $e){check(strpos($e->getMessage(),$expected)!==false,'Specific upload diagnostic: '.$expected);}
 }
 try {nms_mib_preview($upload,0,'Offline QA',$category);throw new LogicException('Missing dependency accepted');}
 catch(RuntimeException $e){check(strpos($e->getMessage(),'Missing MIB dependencies: QA-OFFLINE-BASE')!==false,'Missing imported module identified');}
 $upload['name'][]='base.txt';$upload['tmp_name'][]=$dir.'/base.txt';$upload['error'][]=UPLOAD_ERR_OK;
 $preview=nms_mib_preview($upload,0,'Offline QA',$category);
 check(count($preview['records'])===1 && $preview['records'][0]['oid']==='1.3.6.1.4.1.55555.1.0','Vendor .my file and uploaded dependency parse offline to exact scalar OID');
 check($preview['host_id']===0,'No device access needed for offline template preview');
} finally {unlink($dir.'/base.txt');unlink($dir.'/main.my');rmdir($dir);}
