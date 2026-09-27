<?php
/** CLI-only QA storage test. Temporary tables shadow production names on this connection. */
if (PHP_SAPI !== 'cli') exit(1);
$bootstrap = getenv('NMS_CACTI_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) throw new RuntimeException('Set NMS_CACTI_BOOTSTRAP to the QA Cacti include/global.php.');
// Component tests load the staged NMS source explicitly. Cacti's process-local
// install guard suppresses automatic plugin hooks, avoiding a second, live copy.
// It does not change plugin_config or disable NMS for other PHP processes.
// Native hook dispatch must be verified separately against the deployed plugin.
define('IN_CACTI_INSTALL', true);
require $bootstrap;
// Retain real Cacti page-realm authorization without running config_insert hooks.
api_plugin_load_realms();
if (function_exists('nms_require_management')) throw new RuntimeException('Live NMS was loaded before the staged test source.');
require_once __DIR__.'/../../plugins/nms/includes/functions.php';
require __DIR__.'/../../plugins/nms/includes/configuration/service.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
require_once $config['base_path'].'/lib/auth.php';
$_SESSION = ['sess_user_id'=>1];
// Create only temporary tables by extracting this migration's literal DDL.
$source=file_get_contents(__DIR__.'/../../plugins/nms/includes/configuration/service.php');
preg_match_all('/nms_category_execute\("(CREATE TABLE IF NOT EXISTS .*?)"\);/s',$source,$matches);
check(count($matches[1])===7,'Expected all serial schema definitions');
foreach($matches[1] as $ddl) nms_category_execute(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$ddl));
$input=['id'=>0,'revision'=>0,'name'=>'QA serial fixture','description'=>'Temporary only','manufacturer'=>'Test',
    'model'=>'Fixture','protocol'=>'modbus_rtu','baud_rate'=>9600,'data_bits'=>8,'parity'=>'even',
    'stop_bits'=>1,'flow_control'=>'none','timeout_ms'=>1000,'retries'=>1];
$id=nms_serial_profile_save($input);
$first=nms_serial_profile_get($id);
check((int)$first['revision']===1,'Initial revision');
$settings=$first['settings_json'];
nms_category_execute("INSERT INTO plugin_nms_serial_connections (name,poller_id,transport,endpoint,endpoint_key,profile_id,profile_revision,settings_json,updated_by,updated_at) VALUES ('Temporary fixture',1,'direct','/dev/qa-fixture',?, ?,1,?,1,NOW())",[hash('sha256','fixture'),$id,$settings]);
$edit=array_replace($input,['id'=>$id,'revision'=>1,'baud_rate'=>19200]);
nms_serial_profile_save($edit);
check((int)nms_serial_profile_get($id)['revision']===2,'Revision not advanced');
check(db_fetch_cell('SELECT settings_json FROM plugin_nms_serial_connections')===$settings,'Preset edit changed connection settings');
try { nms_serial_profile_save($edit); throw new LogicException('Stale revision accepted'); } catch (RuntimeException $e) { if($e instanceof LogicException) throw $e; }
check((int)nms_serial_profile_get($id)['revision']===2,'Rejected edit mutated state');
try { nms_serial_profile_save($input); throw new LogicException('Duplicate accepted'); } catch (InvalidArgumentException $e) {}
$_SESSION = ['sess_user_id'=>0];
try { nms_serial_profile_save(array_replace($edit,['revision'=>2])); throw new LogicException('Permission bypass'); } catch (RuntimeException $e) { if($e instanceof LogicException) throw $e; }
$_SESSION = ['sess_user_id'=>1];
check(count(nms_serial_profiles())===1,'Rollback leaked an extra profile');
echo "PASS: temporary-table create/edit, stale revision, duplicate name, denied write and unchanged connection snapshot\n";
$hosts=db_fetch_assoc("SELECT id,poller_id FROM host WHERE deleted='' ORDER BY id LIMIT 2");
check(count($hosts)===2,'QA requires two existing devices for read-only identity/ACL checks');
$create=['name'=>'Temporary connection','transport'=>'direct','endpoint'=>'/dev/ttyUSB97','poller_id'=>$hosts[0]['poller_id'],'profile_id'=>$id,'profile_revision'=>2];
$connection=nms_serial_connection_create($create);
try { nms_serial_connection_create($create); throw new LogicException('Duplicate endpoint accepted'); } catch(InvalidArgumentException $e) {}
$assign=['connection_id'=>$connection,'assignment_revision'=>0,'device_address'=>1];
nms_serial_assign($hosts[0]['id'],$assign);
check((int)nms_serial_assignment($hosts[0]['id'])['device_address']===1,'Assignment not stored');
try { nms_serial_assign($hosts[0]['id'],$assign); throw new LogicException('Stale assignment accepted'); } catch(RuntimeException $e) {}
if ((int)$hosts[0]['poller_id']===(int)$hosts[1]['poller_id']) {
    try { nms_serial_assign($hosts[1]['id'],$assign); throw new LogicException('Duplicate bus address accepted'); } catch(InvalidArgumentException $e) {}
    nms_serial_assign($hosts[1]['id'],array_replace($assign,['device_address'=>2]));
}
$preview=nms_serial_refresh_preview($connection);
check(count($preview['members'])>=1,'Affected members absent from preview');
nms_serial_profile_save(array_replace($edit,['revision'=>2,'baud_rate'=>38400]));
try { nms_serial_refresh_apply($connection,$preview['fingerprint']); throw new LogicException('Changed profile applied without new preview'); } catch(RuntimeException $e) {}
$preview=nms_serial_refresh_preview($connection);
nms_serial_refresh_apply($connection,$preview['fingerprint']);
check(nms_serial_connection_get($connection)['settings']['baud_rate']===38400,'Explicit refresh failed');
try { nms_serial_refresh_apply($connection,$preview['fingerprint']); throw new LogicException('Stale preview replayed'); } catch(RuntimeException $e) {}
echo "PASS: affected-member preview, stale profile rejection, explicit refresh and replay rejection\n";
nms_category_execute('UPDATE plugin_nms_serial_connections SET poller_id=poller_id+10000 WHERE id=?',[$connection]);
try { nms_serial_assign($hosts[0]['id'],array_replace($assign,['assignment_revision'=>1])); throw new LogicException('Collector mismatch accepted'); } catch(InvalidArgumentException $e) {}
nms_serial_assign($hosts[0]['id'],['connection_id'=>0,'assignment_revision'=>1]);
check(!nms_serial_assignment($hosts[0]['id']),'Unassign failed');
echo "PASS: temporary connection ownership, unique bus addresses, stale assignment, collector mismatch and unassign; native hosts unchanged\n";
require __DIR__.'/../../plugins/nms/includes/configuration/jobs.php';
$model=['id'=>0,'revision'=>0,'name'=>'Temporary Modbus equipment','manufacturer'=>'Test','model'=>'Fixture','manual_reference'=>'Simulator fixture, not a manufacturer map','protocol'=>'modbus_rtu','fields_json'=>json_encode([['key'=>'limit','label'=>'Fixture limit','offset'=>0,'function'=>3,'type'=>'uint16','min'=>0,'max'=>100,'writable'=>true]])];
$model_id=nms_equipment_profile_save($model);
nms_category_execute('UPDATE plugin_nms_serial_connections SET poller_id=? WHERE id=?',[$hosts[0]['poller_id'],$connection]);
nms_serial_assign($hosts[0]['id'],array_replace($assign,['assignment_revision'=>0]));
nms_equipment_assign($hosts[0]['id'],['profile_id'=>$model_id,'revision'=>0]);
$read=nms_config_job_enqueue($hosts[0]['id'],'limit');
try { nms_config_job_enqueue($hosts[0]['id'],'limit'); throw new LogicException('Overlapping job accepted'); } catch(RuntimeException $e) {}
nms_category_execute("UPDATE plugin_nms_config_jobs SET status='complete',finished_at=NOW(),result_json=? WHERE id=?",[json_encode(['value'=>17]),$read]);
$write=nms_config_job_enqueue($hosts[0]['id'],'limit','write','23',$read);
check(db_fetch_cell_prepared('SELECT status FROM plugin_nms_config_jobs WHERE id=?',[$read])==='consumed','Read preview was not consumed');
check(db_fetch_cell_prepared('SELECT before_json FROM plugin_nms_config_jobs WHERE id=?',[$write])==='17','Before value not retained');
nms_category_execute("UPDATE plugin_nms_config_jobs SET status='failed',finished_at=NOW() WHERE id=?",[$write]);
try { nms_config_job_enqueue($hosts[0]['id'],'limit','write','24',$read); throw new LogicException('Consumed preview reused'); } catch(RuntimeException $e) {}
try { nms_equipment_profile_save(array_replace($model,['id'=>$model_id,'revision'=>1])); throw new LogicException('Assigned model edited'); } catch(RuntimeException $e) {}
echo "PASS: equipment assignment, read admission, overlap rejection, write evidence and single-use preview; no equipment I/O\n";

if ((int)$hosts[0]['poller_id']===(int)$hosts[1]['poller_id']) {
    $other_model=nms_equipment_profile_save(array_replace($model,['name'=>'Other bus model','manufacturer'=>'Other manufacturer','model'=>'Second model']));
    nms_equipment_assign($hosts[1]['id'],['profile_id'=>$other_model,'revision'=>0]);
    check((int)db_fetch_cell_prepared('SELECT profile_id FROM plugin_nms_config_devices WHERE host_id=?',[$hosts[1]['id']])===$other_model,'Mixed models on shared RTU bus were rejected');
    nms_equipment_assign($hosts[1]['id'],['profile_id'=>0,'revision'=>1]);
    echo "PASS: independent equipment models on one compatible shared bus\n";
}
