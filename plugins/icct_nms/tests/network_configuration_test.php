<?php
if(!in_array('--integration',$argv,true)){echo "Run with --integration on a Cacti installation; test writes are rolled back.\n";exit;}
chdir(dirname(__DIR__,3));require 'include/global.php';$_SESSION['sess_user_id']=1;
require 'plugins/icct_nms/includes/bootstrap.php';icct_nms_backend();require 'plugins/icct_nms/includes/network_configuration_service.php';
$options=icct_nms_network_options();$input=icct_nms_network_defaults($options)+['network_id'=>0];$input['name']='ICCT network validation transaction';$input['subnet_range']='192.0.2.0/30';$input['enabled']=0;$input['ping_port']=22;$input['ping_timeout']=400;$input['ping_retries']=1;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($input,$message){try{icct_nms_network_save($input);}catch(InvalidArgumentException $e){echo $message." passed.\n";return;}throw new RuntimeException($message.' not rejected');}
db_execute('START TRANSACTION');try{
$id=icct_nms_network_save($input);$row=icct_nms_network_get($id);check($row['name']===$input['name']&&$row['subnet_range']===$input['subnet_range']&&(int)$row['total_ips']===2,'Native create/count failed');
$edit=$input;$edit['network_id']=$id;$edit['sched_type']=5;$edit['month']=[1,12];$edit['monthly_week']=[1,32];$edit['monthly_day']=[2,6];$edit['notification_enabled']=1;$edit['notification_email']='qa@example.invalid';icct_nms_network_save($edit);$row=icct_nms_network_get($id);check($row['month']==='1,12'&&$row['monthly_day']==='2,6'&&$row['notification_enabled']==='on','Update schedule failed');echo "Native network create/update, subnet count, schedule arrays and notifications passed.\n";
reject(array_replace($input,['subnet_range'=>'invalid-range']),'Invalid subnet');reject(array_replace($input,['sched_type'=>3]),'Weekly schedule required days');reject(array_replace($input,['poller_id'=>999999]),'Invalid collector');reject(array_replace($input,['ping_method'=>3,'ping_port'=>0]),'TCP port required');reject(array_replace($input,['notification_enabled'=>1]),'Notification recipient required');reject(array_replace($input,['start_at'=>'2026-02-30 12:00:00']),'Invalid calendar date');
unset($_SESSION['sess_user_id']);try{icct_nms_network_save($input);throw new RuntimeException('Permission failure');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'permission'),'Wrong permission failure');echo "Automation realm permission enforced.\n";}
}finally{db_execute('ROLLBACK');}echo "All test writes rolled back; no network scans started.\n";
