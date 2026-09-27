<?php
/** Node bulk admission tests; all device and membership changes are temporary. */
require __DIR__.'/serial-profiles-integration.php';
require __DIR__.'/../../plugins/nms/includes/configuration/node.php';
$native=db_fetch_assoc_prepared('SELECT * FROM host WHERE id IN (?,?)',[$hosts[0]['id'],$hosts[1]['id']]);
$ddl=db_fetch_row('SHOW CREATE TABLE host'); nms_category_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table']));
$site=(int)db_fetch_cell('SELECT id FROM sites ORDER BY id LIMIT 1'); check($site>0,'Need an existing QA site');
foreach($native as $row) { $row['site_id']=$site; $row['poller_id']=$hosts[0]['poller_id']; nms_category_execute('INSERT INTO host (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row)); }
foreach(['plugin_nms_nodes','plugin_nms_node_devices'] as $table) { $ddl=db_fetch_row('SHOW CREATE TABLE '.$table); nms_category_execute(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl['Create Table'])); }
nms_category_execute("INSERT INTO plugin_nms_nodes(id,site_id,name,code,updated_by,updated_at) VALUES (1,?,'Temporary node','TEMP',1,NOW())",[$site]);
foreach($hosts as $host) nms_category_execute('INSERT INTO plugin_nms_node_devices(host_id,node_id,updated_by,updated_at) VALUES (?,1,1,NOW())',[$host['id']]);
nms_equipment_assign($hosts[1]['id'],['profile_id'=>$model_id,'revision'=>0]);
$ids=array_column($hosts,'id');
$reads=nms_config_node_reads(1,$ids,'limit');
foreach($reads as $id=>$read) {
    check($read['status']==='queued','Read did not queue for each member');
    nms_category_execute("UPDATE plugin_nms_config_jobs SET status='complete',result_json=?,finished_at=NOW() WHERE id=?",[json_encode(['value'=>17]),$read['job_id']]);
}
$preview=nms_config_node_preview(1,$ids,'limit',23);
check(count($preview['rows'])===2,'Preview omitted a selected member');
try { nms_config_node_preview(1,$ids,'limit',101); throw new LogicException('Out-of-range batch accepted'); } catch(InvalidArgumentException $e) {}
// Remove just the second member after review. First can queue; the removed device must fail independently.
nms_category_execute('DELETE FROM plugin_nms_node_devices WHERE host_id=?',[$ids[1]]);
$result=nms_config_node_apply($preview);
check($result[$ids[0]]['status']==='queued' && $result[$ids[1]]['status']==='failed','Partial batch did not preserve per-device results');
$count=(int)db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_config_jobs WHERE operation='write'");
nms_config_node_apply($preview);
check((int)db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_config_jobs WHERE operation='write'")===$count,'Replayed batch created another write');
try { nms_config_node_selection(1,[$ids[1]]); throw new LogicException('Nonmember accepted'); } catch(InvalidArgumentException $e) {}
$health=nms_node_health([['disabled'=>'','last_updated'=>date('Y-m-d H:i:s'),'status'=>3,'serial_monitoring'=>['status'=>'Unavailable']]],time()-600);
check($health['state']==='Unknown','No-ping native state falsely counted serial equipment as Up');
$_SESSION=['sess_user_id'=>0];
try { nms_config_node_selection(1,[$ids[0]]); throw new LogicException('Unauthorised batch accepted'); } catch(RuntimeException $e) {}
echo "PASS: node selection, per-device reads and previews, range validation, membership recheck, independent results, replay rejection and permissions; no equipment writes\n";
