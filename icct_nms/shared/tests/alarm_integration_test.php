<?php
/** Native database integration; connection-local temporary tables never alter saved alarms. */
if(PHP_SAPI!=='cli')exit(1);
try {
require '/var/www/html/cacti/include/cli_check.php';api_plugin_load_realms();require_once $config['base_path'].'/lib/auth.php';
if(session_status()===PHP_SESSION_NONE)session_start();$_SESSION=['sess_user_id'=>1];
require_once __DIR__.'/../services/bootstrap.php';require_once __DIR__.'/../../graphs/services/graph_service.php';require_once __DIR__.'/../../inventory/services/inventory.php';icct_nms_backend();
foreach(['plugin_icct_nms_alarms','plugin_icct_nms_alarm_history'] as $table){if(!db_execute('CREATE TEMPORARY TABLE qa_alarm_copy LIKE '.$table)||!db_execute('CREATE TEMPORARY TABLE '.$table.' LIKE qa_alarm_copy')||!db_execute('DROP TEMPORARY TABLE qa_alarm_copy'))throw new RuntimeException('Temporary isolation failed');}
function assertAlarm($value,$message){if(!$value)throw new RuntimeException($message);}
$host=db_fetch_row_prepared("SELECT * FROM host WHERE deleted='' ORDER BY id LIMIT 1",[]);if(!$host)throw new RuntimeException('One native host is needed for permission checks');
$rule=['name'=>'Isolated QA alarm','description'=>'Saved description','corrective_action'=>'Saved fix','source'=>'snmp','oid'=>'1.3.6.1.2.1.1.3.0'];$o=['state'=>'Major','rule'=>0,'graph'=>'QA reading','graph_id'=>0,'sample_time'=>time()];$key=icct_nms_alarm_key($host,$rule,$o);
$get=static fn()=>db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_alarms WHERE alarm_key=?',[$key]);
icct_nms_alarm_record($host,$rule,$o);icct_nms_alarm_record($host,$rule,$o);$r=$get();assertAlarm((int)$r['occurrences']===1&&$r['description']==='Saved description'&&$r['fix']==='Saved fix','Duplicate collection or text synchronization failed');
$raised=json_decode(db_fetch_cell_prepared("SELECT snapshot_json FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Raised' ORDER BY id LIMIT 1",[$key]),true);assertAlarm($raised['description']==='Saved description'&&$raised['fix']==='Saved fix','Initial event snapshot missing');
$rule['description']='Updated description';$rule['corrective_action']='Updated fix';icct_nms_alarm_record($host,$rule,$o);
$raisedAgain=json_decode(db_fetch_cell_prepared("SELECT snapshot_json FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Raised' ORDER BY id LIMIT 1",[$key]),true);assertAlarm($raisedAgain===$raised,'New text overwrote historical content');
$act=static function($action)use($get,$key){icct_nms_alarm_action(['alarm_key'=>$key,'alarm_action'=>$action,'revision'=>$get()['revision'],'note'=>'QA note']);};
try{$act('escalate');throw new LogicException('Escalation allowed before acknowledgement');}catch(RuntimeException $e){}
$act('ack');$detail=icct_nms_alarm_detail($key);assertAlarm($detail['acknowledgement']['note']==='QA note'&&$detail['alarm']['device_name']===$host['description'],'Full detail lost linked device or acknowledgement remarks');$act('escalate');$detail=icct_nms_alarm_detail($key);assertAlarm(count($detail['escalations'])===1&&$detail['escalations'][0]['note']==='QA note','Escalation tab lost its saved record');
$ackSnapshot=json_decode(db_fetch_cell_prepared("SELECT snapshot_json FROM plugin_icct_nms_alarm_history WHERE alarm_key=? AND event='Acknowledged' ORDER BY id DESC LIMIT 1",[$key]),true);assertAlarm((int)$ackSnapshot['acknowledged']===1&&$ackSnapshot['description']==='Updated description','Acknowledgement snapshot lost state or content');
try{icct_nms_alarm_detail('invalid');throw new LogicException('Invalid detail key accepted');}catch(InvalidArgumentException $e){}
db_execute_prepared('UPDATE plugin_icct_nms_alarms SET graph_id=2147483647 WHERE alarm_key=?',[$key]);
try{icct_nms_alarm_detail($key);throw new LogicException('Detail exposed a forbidden graph');}catch(RuntimeException $e){}
db_execute_prepared('UPDATE plugin_icct_nms_alarms SET graph_id=0 WHERE alarm_key=?',[$key]);
$act('ticket_close');$act('ticket_reopen');$r=$get();assertAlarm($r['acknowledged']&&$r['escalated']&&$r['ticket_state']==='Open','Workflow save failed');
try{icct_nms_alarm_action(['alarm_key'=>$key,'alarm_action'=>'close','revision'=>0]);throw new LogicException('Stale revision accepted');}catch(RuntimeException $e){}
$act('close');icct_nms_alarm_record($host,$rule,$o);assertAlarm($get()['state']==='Closed','Poller reopened a manually closed alarm');
icct_nms_alarm_record($host,$rule,array_replace($o,['state'=>'Normal']));assertAlarm($get()['state']==='Resolved','Recovery failed');
icct_nms_alarm_record($host,$rule,$o);$r=$get();assertAlarm((int)$r['occurrences']===2&&!$r['acknowledged']&&!$r['escalated'],'Recurrence must reset workflow and increment only once');
icct_nms_alarm_record($host,$rule,array_replace($o,['state'=>'Unknown']));assertAlarm($get()['state']==='Active','Missing data cleared an active alarm');
try{icct_nms_alarm_require_access(['host_id'=>16777215,'graph_id'=>0]);throw new LogicException('Foreign device allowed');}catch(RuntimeException $e){}
try{icct_nms_alarm_require_access(['host_id'=>$host['id'],'graph_id'=>2147483647]);throw new LogicException('Foreign graph allowed');}catch(RuntimeException $e){}
assertAlarm((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_icct_nms_alarm_history')>=8,'Workflow history missing');
icct_nms_collect_alarms();assertAlarm($get()['state']==='Disabled','A removed or replaced rule must not leave an active orphan alarm');
echo "PASS: native isolated alarm writes, durable workflow, optimistic concurrency, recovery, recurrence, history and device/graph authorization.\n";

}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
