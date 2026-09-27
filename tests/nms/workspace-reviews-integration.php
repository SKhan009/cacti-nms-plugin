<?php
/** Real SQL/ACL paths, temporary review/audit tables, synthetic identity observations. */
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and staged includes');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery_identity.php';
$dir=$argv[2].'/workspace';
require_once $dir.'/networks.php';
$schema=str_replace(['function nms_workspace_schema(', 'CREATE TABLE IF NOT EXISTS'],['function qa_workspace_schema(', 'CREATE TEMPORARY TABLE'],file_get_contents($dir.'/schema.php'));
eval(substr($schema,5));qa_workspace_schema();
$source=str_replace(['__DIR__','nms_topology_discovery(','nms_workspace_audit('],[var_export($dir,true),'qa_discovery(','qa_audit('],file_get_contents($dir.'/reviews.php'));
eval(substr($source,5));
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$hosts=[];foreach(db_fetch_assoc("SELECT id,hostname,poller_id,site_id FROM host WHERE deleted='' ORDER BY id LIMIT 2") as $h)$hosts[(int)$h['id']]=$h;
if(count($hosts)!==2)throw new RuntimeException('Two QA hosts required; none created by this test');
function qa_discovery($site){global $hosts;return ['hosts'=>$hosts,'snapshots'=>[]];}
$failAudit=false;$auditCalls=0;
function qa_audit($action,$detail,$id){global $failAudit,$auditCalls;if($failAudit && ++$auditCalls===2)throw new RuntimeException('Injected second audit failure');nms_workspace_audit($action,$detail,$id);}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
function reject($fn,$message){try{$fn();}catch(RuntimeException $e){verify(true,$message);return;}throw new RuntimeException('Unexpected success: '.$message);}
[$a,$b]=array_keys($hosts);
$before=db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id');
$identities=nms_nd_device_identities($hosts,[]);
$input=['host_a'=>$a,'host_b'=>$b,'decision'=>'same','note'=>'QA temporary review','revision'=>0,'evidence_hash'=>nms_identity_review_hash($a,$b,$hosts,$identities)];
nms_identity_review_save($input);
$row=db_fetch_row('SELECT * FROM plugin_nms_identity_reviews');
verify($row['decision']==='same' && (int)$row['revision']===1,'Decision stored once for the canonical pair');
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===2,'Each visible device receives its audit event');
reject(fn()=>nms_identity_review_save($input),'A second form with the old revision cannot overwrite the decision');
$edit=array_replace($input,['revision'=>1,'host_a'=>$b,'host_b'=>$a,'decision'=>'separate']);
nms_identity_review_save($edit);
verify((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_identity_reviews')===1 && db_fetch_cell('SELECT decision FROM plugin_nms_identity_reviews')==='separate','Reverse-side review updates the same record');
$edit=array_replace($input,['revision'=>2,'decision'=>'unresolved']);
$failAudit=true;$auditCalls=0;
reject(fn()=>nms_identity_review_save($edit),'Audit failure rejects the change');
verify(db_fetch_cell('SELECT decision FROM plugin_nms_identity_reviews')==='separate' && (int)db_fetch_cell('SELECT revision FROM plugin_nms_identity_reviews')===2 && (int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_workspace_audit')===4,'Decision and first audit write roll back together when second audit fails');
$failAudit=false;
$hosts[$a]['hostname']='192.0.2.254';
reject(fn()=>nms_identity_review_save($edit),'Changed management address invalidates submitted evidence');
verify($before===db_fetch_assoc('SELECT id,host_id,graph_template_id FROM graph_local ORDER BY id'),'Review decisions preserve native graph associations');
verify(count(nms_identity_reviews())===1,'Saved review can be read through both-device ACL query');
echo "9 assertions passed; real SQL and ACL, temporary storage, synthetic observations.\n";
