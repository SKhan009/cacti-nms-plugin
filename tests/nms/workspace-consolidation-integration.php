<?php
/** Native dependency inventory and ACL; synthetic same-device evidence; temporary review/audit tables. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[2].'/authorization.php';
require_once $argv[2].'/consolidation_preflight.php';
$permissionSource=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/consolidation_permissions.php'));eval(substr($permissionSource,5));
require_once $argv[1].'/plugins/nms/includes/workspace/reviews.php';
require_once $argv[1].'/plugins/nms/includes/workspace/admission.php';
$s=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));eval(substr($s,5));qa_schema();
$s=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/consolidation.php'));$s=str_replace(['nms_topology_discovery(','nms_nd_device_identities(','nms_workspace_audit('],['qa_discovery(','qa_identities(','qa_audit('],$s);eval(substr($s,5));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$hosts=[];foreach(db_fetch_assoc("SELECT id,hostname,poller_id,site_id FROM host WHERE deleted='' ORDER BY id LIMIT 2") as $h)$hosts[(int)$h['id']]=$h;
$ids=array_keys($hosts);[$a,$b]=$ids;$identities=[$a=>['evidence'=>'synthetic1'],$b=>['evidence'=>'synthetic2']];$failAudit=false;$count=0;
function qa_discovery(...$args){global $hosts;return ['hosts'=>$hosts,'snapshots'=>[]];}
function qa_identities(...$args){global $identities;return $identities;}
function qa_audit(...$args){global $failAudit;if($failAudit)throw new RuntimeException('Injected audit failure');return nms_workspace_audit(...$args);}
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
function rejects($fn,$message){try{$fn();}catch(RuntimeException $e){check(true,$message);return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
$before=[db_fetch_assoc('SELECT id,hostname,disabled FROM host ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id')];
rejects(fn()=>nms_workspace_consolidation_plan($a,$b),'No plan without a current same-device decision');
$hash=nms_identity_review_hash($a,$b,$hosts,$identities);
nms_category_execute("INSERT INTO plugin_nms_identity_reviews VALUES (?,?,'same','',?,1,?,NOW())",[$a,$b,$hash,nms_current_user_id()]);
$plan=nms_workspace_consolidation_plan($a,$b);
check(count($plan['devices'])===2&&$plan['keep_id']===$a,'Native graph/data/reference inventory retains preferred direction');
check($plan['status']==='migration_review_required'&&count($plan['limitations'])===5,'Plan discloses unsupported migration and RRD limitations');
$input=['keep_id'=>$a,'other_id'=>$b,'revision'=>$plan['revision'],'note'=>'Synthetic acceptance review'];
$failAudit=true;rejects(fn()=>nms_workspace_consolidation_save($input),'Audit failure rejects saved review');$failAudit=false;
check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_consolidation_reviews')===0,'Failed audit rolls back consolidation review');
$id=nms_workspace_consolidation_save($input);
check($id>0&&(int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===2,'Saving records a review with audit events for both devices');
check(count(nms_workspace_consolidation_history($a))===1,'Both-device ACL history returns saved review');
$input['revision']=str_repeat('0',64);rejects(fn()=>nms_workspace_consolidation_save($input),'Changed review revision cannot be saved');
$identities[$a]['evidence']='changed';rejects(fn()=>nms_workspace_consolidation_plan($a,$b),'Changed identity invalidates previous same-device decision');
$after=[db_fetch_assoc('SELECT id,hostname,disabled FROM host ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM graph_local ORDER BY id'),db_fetch_assoc('SELECT id,host_id FROM data_local ORDER BY id')];
check($before===$after,'Review does not delete/disable devices or change graph/data ownership');
// Shadow only this connection's graph inventory, retaining native schema and rows.
$identities[$a]['evidence']='synthetic1';
$originalGraphs=db_fetch_assoc('SELECT * FROM graph_local');
$ddl=db_fetch_row('SHOW CREATE TABLE graph_local');$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values($ddl)[1]);
if(!db_execute($ddl))throw new RuntimeException('Graph fixture creation failed');
foreach($originalGraphs as $graph){$keys=array_keys($graph);nms_category_execute('INSERT INTO graph_local (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($graph));}
$graphId=(int)db_fetch_cell_prepared('SELECT id FROM graph_local WHERE host_id=? ORDER BY id LIMIT 1',[$a]);
if(!$graphId)throw new RuntimeException('Test requires a fixture host with a graph');
$baseline=nms_workspace_consolidation_plan($a,$b);
nms_category_execute('UPDATE graph_local SET snmp_index=? WHERE id=?',['qa-changed-index',$graphId]);
$changed=nms_workspace_consolidation_plan($a,$b);
check($baseline['revision']!==$changed['revision'],'Changed native graph dependency invalidates plan fingerprint');
$input['revision']=$baseline['revision'];rejects(fn()=>nms_workspace_consolidation_save($input),'Stale dependency assessment cannot be saved');
check(count(nms_workspace_consolidation_history($a))===1,'Rejected stale plan creates no new history record');
// A graph item can change its linked data source without changing ownership counts.
$originalItems=db_fetch_assoc('SELECT * FROM graph_templates_item');
$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',array_values(db_fetch_row('SHOW CREATE TABLE graph_templates_item'))[1]);
if(!db_execute($ddl))throw new RuntimeException('Graph item fixture creation failed');
foreach($originalItems as $item){$keys=array_keys($item);nms_category_execute('INSERT INTO graph_templates_item (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($item));}
$item=db_fetch_row_prepared('SELECT i.id,r.local_data_id FROM graph_templates_item i JOIN data_template_rrd r ON r.id=i.task_item_id WHERE i.local_graph_id=? ORDER BY i.id LIMIT 1',[$graphId]);
$replacement=db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE local_data_id>0 AND local_data_id<>? ORDER BY id LIMIT 1',[$item['local_data_id']]);
if(!$item||!$replacement)throw new RuntimeException('Distinct graph/data fixture links required');
$baseline=nms_workspace_consolidation_plan($b,$a);
nms_category_execute('UPDATE graph_templates_item SET task_item_id=? WHERE id=?',[$replacement,$item['id']]);
$changed=nms_workspace_consolidation_plan($b,$a);
check($baseline['dependency_edges_hash']!==$changed['dependency_edges_hash'],'Changed graph-to-data link changes dependency fingerprint');
check($baseline['revision']!==$changed['revision'],'Link change invalidates review even when native asset ownership is unchanged');
rejects(fn()=>nms_workspace_consolidation_save(['keep_id'=>$b,'other_id'=>$a,'revision'=>$baseline['revision'],'note'=>'stale link']),'Stale graph/data link cannot be confirmed');
echo "$count assertions passed; no core mutations or RRD migration.\n";
