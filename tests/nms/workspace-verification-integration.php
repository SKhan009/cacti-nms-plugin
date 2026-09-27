<?php
/** Real transactions and lifecycle, simulated advertisement and network responses. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and staged workspace');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery.php';
require_once $argv[1].'/plugins/nms/includes/workspace/audit.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($argv[2].'/schema.php'));
eval(substr($schema,5));qa_schema();
$source=file_get_contents($argv[2].'/verification.php');
$source=substr($source,strpos($source,'function nms_workspace_verification_hash'));
$source=str_replace('nms_workspace_verification_context(', 'qa_context(', $source);eval($source);
$worker=preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/verification_worker.php'));
$worker=str_replace(['nms_workspace_verification_context(','nms_nd_network_probe(','nms_nd_collect_identity('],['qa_context(','qa_ping(','qa_identity('],$worker);eval(substr($worker,5));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$host=db_fetch_row('SELECT * FROM host WHERE id=2');
$candidate=['id'=>str_repeat('a',64),'target'=>'192.0.2.20','poller_id'=>1,'site_id'=>$host['site_id'],'observed_at'=>'2026-09-21 00:00:00'];
$probeCalls=0;$changed=false;
function qa_context($reporter,$id){global $host,$candidate,$changed;if($changed)throw new RuntimeException('Simulated changed evidence');return [$candidate,$host];}
function qa_ping(...$args){global $probeCalls;$probeCalls++;return ['reachable'=>true];}
function qa_identity(...$args){return ['uptime'=>123,'own_addresses'=>[],'interfaces'=>[]];}
function verify($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "PASS: $msg\n";}
$id=nms_workspace_verification_enqueue(2,$candidate['id']);
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===1,'Enqueue and audit commit together');
nms_workspace_verification_poll(1);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_candidate_checks WHERE id=?',[$id])==='complete' && $probeCalls===1,'Collector executes one candidate and stores terminal result');
$id=nms_workspace_verification_enqueue(2,$candidate['id']);nms_workspace_verification_cancel($id);nms_workspace_verification_poll(1);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_candidate_checks WHERE id=?',[$id])==='cancelled' && $probeCalls===1,'Queued cancellation prevents probes');
$id=nms_workspace_verification_enqueue(2,$candidate['id']);$changed=true;nms_workspace_verification_poll(1);$changed=false;
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_candidate_checks WHERE id=?',[$id])==='failed' && $probeCalls===1,'Changed evidence fails before network work');
$id=nms_workspace_verification_enqueue(2,$candidate['id']);db_execute_prepared("UPDATE plugin_nms_candidate_checks SET status='running' WHERE id=?",[$id]);nms_workspace_verification_poll(1);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_candidate_checks WHERE id=?',[$id])==='failed' && $probeCalls===1,'Interrupted execution is not replayed');
$id=nms_workspace_verification_enqueue(2,$candidate['id']);db_execute_prepared('UPDATE plugin_nms_candidate_checks SET requested_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?',[$id]);nms_workspace_verification_poll(1);
verify(db_fetch_cell_prepared('SELECT status FROM plugin_nms_candidate_checks WHERE id=?',[$id])==='failed' && $probeCalls===1,'Expired queued verification sends no probes');
echo "6 assertions passed; real SQL, simulated observations and probes.\n";
