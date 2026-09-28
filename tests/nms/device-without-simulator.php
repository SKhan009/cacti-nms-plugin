<?php
/** QA-only native create/edit; removes its randomly named disabled fixture in finally. */
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap=getenv('NMS_CACTI_BOOTSTRAP');
if (!$bootstrap) throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP.');
require $bootstrap;
require_once $config['base_path'].'/include/global_form.php';
require_once $config['base_path'].'/plugins/nms/includes/device_manager.php';
require_once $config['base_path'].'/plugins/nms/includes/snmpsim.php';
$_SESSION=['sess_user_id'=>1];
// Override only this CLI process. Do not alter the server's simulator configuration.
unset($config['nms_snmpsim']);
$config['nms_snmpsim_config']='/nonexistent/nms-optional-simulator-qa.json';
try { nms_snmpsim_config(); throw new LogicException('Simulator unexpectedly available'); }
catch(RuntimeException $e) { if($e instanceof LogicException)throw $e; }
$name='NMS no simulator QA '.bin2hex(random_bytes(6));
$input=['description'=>$name,'hostname'=>'192.0.2.254','host_template_id'=>0,'site_id'=>nms_single_topology_site() ?: 0,
'poller_id'=>1,'snmp_version'=>2,'snmp_community'=>'qa-only','snmp_port'=>161,'snmp_timeout'=>500,
'snmp_username'=>'','snmp_password'=>'','snmp_auth_protocol'=>'[None]','snmp_priv_protocol'=>'[None]',
'snmp_priv_passphrase'=>'','snmp_context'=>'','snmp_engine_id'=>'','availability_method'=>0,
'ping_method'=>2,'ping_port'=>0,'ping_timeout'=>400,'ping_retries'=>1,'max_oids'=>10,'device_threads'=>1,
'notes'=>'','location'=>'','external_id'=>'','disabled'=>true,'proxy'=>false,'equipment_category_id'=>0,
'device_type'=>'QA fixture','manual_serial_number'=>'','snmpsim_import_id'=>0];
try {
 $id=nms_device_create($input);
 if (!$id || db_fetch_cell_prepared('SELECT description FROM host WHERE id=?',[$id])!==$name)throw new RuntimeException('Create failed');
 $input['notes']='Edited without simulator configuration';
 nms_device_update($id,$input);
 if(db_fetch_cell_prepared('SELECT notes FROM host WHERE id=?',[$id])!==$input['notes'])throw new RuntimeException('Edit failed');
 echo "PASS: native SNMP device create/edit with unavailable simulator configuration\n";
 $input['snmpsim_import_id']=1;
 try {nms_device_create(array_replace($input,['description'=>$name.' simulator']));throw new LogicException('Explicit simulator bypassed configuration');}
 catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
 echo "PASS: explicit simulator creation still requires its configuration\n";
} finally {
 $id=(int)db_fetch_cell_prepared('SELECT id FROM host WHERE description=?',[$name]);
 if($id){api_device_remove($id);nms_managed_object_forget('device',$id);}
}
