<?php
/** Actual worker/control flow with in-memory persistence and simulated transport. */
$source=file_get_contents(__DIR__.'/../../plugins/nms/includes/diagnostics_queue.php');
$start=strpos($source,'function nms_diag_execution_context(');$end=strpos($source,'/** In the isolated worker only', $start);
$source=preg_replace('/^\s*require_once .*;$/m','',substr($source,$start,$end-$start));eval($source);
$config=[];$mode='';$calls=0;$stored=null;$released=0;$authorized=true;$enabled=true;$collectorEnabled=true;$revision='current';
$job=['id'=>1,'host_id'=>2,'poller_id'=>1,'user_id'=>1,'tool'=>'ping','config_hash'=>'current'];
function nms_diag_authorize_job($job){global $authorized;if(!$authorized)throw new RuntimeException('Permission revoked');}
function nms_diag_assignment($id,$tool){global $revision;return ['poller_id'=>1,'revision'=>$revision];}
function nms_diag_signature($row){return $row['revision'];}
function nms_inventory_collector_id(){return 1;}
function db_fetch_cell_prepared($sql,$args){global $enabled,$collectorEnabled,$released;if(str_contains($sql,'GET_LOCK'))return 1;if(str_contains($sql,'RELEASE_LOCK')){$released++;return 1;}if(str_contains($sql,'plugin_config'))return $enabled?1:4;if(str_contains($sql,'FROM poller'))return $collectorEnabled?1:0;return null;}
function db_fetch_row_prepared($sql,$args){global $job;return $job;}
function nms_category_execute($sql,$args){global $stored;if(str_contains($sql,'SET status=?'))$stored=['status'=>$args[0],'result'=>json_decode($args[1],true)];}
function nms_diag_execute($row,$tool){global $mode,$calls,$authorized,$enabled,$collectorEnabled,$revision;$calls++;switch($mode){case 'permission':$authorized=false;break;case 'plugin':$enabled=false;break;case 'collector':$collectorEnabled=false;break;case 'configuration':$revision='changed';break;}return ['exit'=>0,'output'=>'PRIVATE TRANSPORT RESULT','target'=>'127.0.0.1','tool'=>'ping'];}
$count=0;
function check($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);$count++;echo "PASS: $message\n";}
foreach(['permission','plugin','collector','configuration','unchanged'] as $mode) {
 foreach(['before','during'] as $phase) {
  $authorized=$enabled=$collectorEnabled=true;$revision='current';$calls=0;$stored=null;$released=0;
  if($phase==='before') {if($mode==='permission')$authorized=false;if($mode==='plugin')$enabled=false;if($mode==='collector')$collectorEnabled=false;if($mode==='configuration')$revision='changed';}
  nms_diag_poll();
  if($mode==='unchanged')check($stored['status']==='complete'&&$calls===1&&$stored['result']['output']==='PRIVATE TRANSPORT RESULT','Unchanged context accepts authorized result '.$phase);
  else check($stored['status']==='failed'&&$calls===($phase==='before'?0:1)&&!str_contains(json_encode($stored),'PRIVATE TRANSPORT RESULT'),'Reject '.$mode.' change '.$phase.' transport without publishing result');
  check($released===1,'Worker releases lock '.$mode.' '.$phase);
 }
}
echo "$count worker assertions passed; transport and persistence simulated.\n";
