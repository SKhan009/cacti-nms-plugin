<?php
/** Native SQL pending-write checks with connection-local tables, including a synthetic archive name. */
if(PHP_SAPI!=='cli'||empty($argv[1])||empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require $argv[2].'/consolidation_writes.php';
function nms_workspace_admission_rows($sql,$params=[]){if(strpos($sql,'information_schema.TABLES')===false){$rows=db_execute_prepared($sql,$params,true,false,'Row',false,'db_fetch_assoc_return');if(!is_array($rows))throw new RuntimeException('Fixture query failed');return $rows;}return array_map(static fn($name)=>['name'=>$name],['poller_output','poller_output_boost','poller_output_boost_arch_qa','poller_output_boost_local_data_ids','poller_output_realtime','poller_output_unrelated']);}
foreach(['poller_output','poller_output_boost','poller_output_boost_arch_qa','poller_output_boost_local_data_ids','poller_output_realtime'] as $name)if(!db_execute('CREATE TEMPORARY TABLE '.$name.' (local_data_id INT)'))throw new RuntimeException('Temporary queue failed');
if(!db_execute('CREATE TEMPORARY TABLE poller_time(id INT,poller_id INT,start_time DATETIME,end_time DATETIME)')||!db_execute('CREATE TEMPORARY TABLE processes(id INT,tasktype VARCHAR(20),started DATETIME,timeout INT)'))throw new RuntimeException('Temporary process tables failed');
function reject_write($message){try{nms_workspace_consolidation_pending_writes([51],1);}catch(RuntimeException $e){echo "PASS: $message\n";return;}throw new RuntimeException('Unexpected acceptance: '.$message);}
nms_workspace_consolidation_pending_writes([51],1);echo "PASS: drained native queues accepted\n";
foreach(['poller_output','poller_output_boost','poller_output_boost_arch_qa','poller_output_boost_local_data_ids','poller_output_realtime'] as $name){
 db_execute('INSERT INTO '.$name.' VALUES (51)');reject_write($name.' pending update rejected');db_execute('DELETE FROM '.$name);
}
db_execute('INSERT INTO poller_output_boost VALUES (52)');nms_workspace_consolidation_pending_writes([51],1);echo "PASS: unrelated data-source update does not block\n";
db_execute("INSERT INTO processes VALUES (1,'boost',NOW(),300)");reject_write('active Boost writer rejected');db_execute('DELETE FROM processes');
db_execute("INSERT INTO poller_time VALUES (1,1,NOW(),'0000-00-00 00:00:00')");reject_write('active native poller rejected');
echo "9 native SQL checks passed; table discovery is controlled, persistent queues unchanged.\n";
