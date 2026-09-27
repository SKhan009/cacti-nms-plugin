<?php
/** Reuse native temporary queue fixture; simulate file/native transport at explicit worker boundaries. */
require __DIR__.'/workspace-consolidation-jobs.php';
$s=file_get_contents($argv[2].'/consolidation_worker.php');
$start=strpos($s,'function nms_workspace_consolidation_transition(');$end=strpos($s,'function nms_workspace_consolidation_worker_context(',$start);
$worker=substr($s,$start,$end-$start).substr($s,strpos($s,'function nms_workspace_consolidation_poll('));
$worker=str_replace(['nms_workspace_consolidation_worker_context(','nms_workspace_consolidation_rrd_manifest(','nms_workspace_consolidation_native_graph(','nms_workspace_consolidation_native_data(','nms_workspace_consolidation_verify_transfer('],['qa_context(','qa_rrds(','qa_graph(','qa_data(','qa_verify('],$worker);eval($worker);
$mode='';$nativeCalls=0;$contextCalls=0;$config['rra_path']='/unused-controlled-fixture';
function qa_context($job,$collector){global $mode,$contextCalls;$contextCalls++;if($mode==='context'||($mode==='changed_after_rrd'&&$contextCalls===2))throw new RuntimeException('Context rejected');return ['devices'=>[[],['data_sources'=>[]]],'transfer_preflight'=>['graph_ids'=>[],'data_ids'=>[20]]];}
function qa_rrds($rows,$root){global $mode;if($mode==='disabled_after_proof')db_execute("UPDATE plugin_config SET status=4 WHERE directory='nms'");if($mode==='rrd')throw new RuntimeException('RRD rejected');return [];}
function qa_graph($id,$keep){global $nativeCalls;$nativeCalls++;}
function qa_data($ids,$keep){global $nativeCalls,$mode;$nativeCalls++;if($mode==='partial')throw new RuntimeException('Native data update failed after side effect');}
function qa_verify($job,$plan,$proof,$root,$items){global $mode;if($mode==='postcondition')throw new RuntimeException('Verification rejected');return ['device_records_retained'=>true];}
$owner=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
foreach(['success','context','rrd','changed_after_rrd','disabled_after_proof','partial','postcondition','abandoned_verifying','abandoned_applying','expired','cancelled'] as $mode) {
 db_execute("UPDATE plugin_config SET status=1 WHERE directory='nms'");
 nms_category_execute('DELETE FROM plugin_nms_consolidation_jobs',[]);$nativeCalls=$contextCalls=0;
 $state=$mode==='abandoned_verifying'?'verifying':($mode==='abandoned_applying'?'applying':($mode==='cancelled'?'cancelled':'queued'));
 nms_category_execute('INSERT INTO plugin_nms_consolidation_jobs(keep_id,other_id,user_id,poller_id,revision,plan_json,status,progress_json,result_json,requested_at) VALUES (?,?,?,1,?,\'{}\',?,\'{}\',\'{}\',?)',[$keep,$other,$owner,str_repeat('a',64),$state,date('Y-m-d H:i:s',time()-($mode==='expired'?600:0))]);
 $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');nms_workspace_consolidation_poll(1);
 $actual=db_fetch_cell_prepared('SELECT status FROM plugin_nms_consolidation_jobs WHERE id=?',[$id]);
 $expected=$mode==='success'?'complete':(in_array($mode,['partial','postcondition','abandoned_applying','disabled_after_proof'],true)?'review_required':($mode==='cancelled'?'cancelled':'failed'));
 check($actual===$expected,'Worker state '.$mode.' becomes '.$expected);
 check($nativeCalls===(in_array($mode,['success','partial','postcondition'],true)?1:0),'No unintended native replay for '.$mode);
}
check((int)db_fetch_cell_prepared('SELECT IS_FREE_LOCK(?)',['nms_consolidation_worker_1'])===1,'Collector worker lock released');
foreach([$keep,$other] as $host)check((int)db_fetch_cell_prepared('SELECT IS_FREE_LOCK(?)',['nms_management_ip_'.$host])===1,'Shared host mutation lock released');
echo "Worker lifecycle verified with native temporary SQL and simulated file/native operations.\n";
