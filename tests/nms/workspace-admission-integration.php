<?php
if(PHP_SAPI!=='cli' || empty($argv[1]) || empty($argv[2]))exit('Supply Cacti root and staged workspace directory');
require $argv[1].'/include/global.php';
require_once $argv[1].'/plugins/nms/includes/functions.php';
require_once $argv[1].'/plugins/nms/includes/discovery.php';
require_once $argv[1].'/plugins/nms/includes/discovery_identity.php';
eval(substr(preg_replace('/^require_once .*;$/m','',file_get_contents($argv[2].'/candidates.php')),5));
$source=preg_replace('/^\s*require_once .*;$/m','',file_get_contents($argv[2].'/admission.php'));
$source=str_replace(['FROM host h','JOIN host h'],['FROM qa_admission_host h','JOIN qa_admission_host h'],$source);
eval(substr($source,5));
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$_SESSION['sess_user_id']=(int)db_fetch_cell("SELECT id FROM user_auth WHERE username='admin' AND enabled='on'");
$host=db_fetch_row("SELECT * FROM host WHERE id=2");
if(!$host)throw new RuntimeException('QA host 2 required');
$ddl=db_fetch_row('SHOW CREATE TABLE host');
if(!db_execute(str_replace('CREATE TABLE `host`','CREATE TEMPORARY TABLE `qa_admission_host`',$ddl['Create Table'])))throw new RuntimeException('Could not isolate host table');
$host['hostname']='192.0.2.200';$host['disabled']='on';
$cols=implode(',',array_map(fn($k)=>'`'.$k.'`',array_keys($host)));
if(!db_execute_prepared('INSERT INTO qa_admission_host ('.$cols.') VALUES ('.implode(',',array_fill(0,count($host),'?')).')',array_values($host)))throw new RuntimeException('Could not insert temporary host');
$c=['reporter_id'=>2,'target'=>'192.0.2.200','poller_id'=>$host['poller_id'],'site_id'=>$host['site_id'],'snmp_context'=>$host['snmp_context']];
$r=nms_workspace_admission_check($c);
verify($r['blocked'] && $r['matches'][0]['host_id']===2,'Real inventory query blocks a disabled existing host');
$c['target']='192.0.2.201';verify(!nms_workspace_admission_check($c)['blocked'],'Unmatched address remains provisionally clear');
$failed=false;try{nms_workspace_admission_rows('SELECT nms_intentionally_missing_column FROM host');}catch(RuntimeException $e){$failed=true;}
verify($failed,'Failed inventory query cannot masquerade as an empty inventory');
echo "3 real SQL assertions passed; host changes were session-local.\n";
