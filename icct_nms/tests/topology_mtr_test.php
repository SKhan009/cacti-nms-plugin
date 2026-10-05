<?php
require __DIR__.'/../includes/topology_mtr_service.php';
function check($ok){if(!$ok)throw new RuntimeException('MTR check failed');}
$report="HOST: collector Loss% Snt Last Avg Best Wrst StDev\n 1.|-- 127.0.0.1 0.0% 4 0.0 0.2 0.0 0.3 0.1\n 2.|-- ??? 100.0% 4 0.0 0.0 0.0 0.0 0.0\n 3.|-- 2001:db8::1 25.0% 4 1.0 2.0 1.0 3.0 0.5\n";
$hops=icct_nms_mtr_hops($report);check(count($hops)===3);check($hops[0]['last_ms']===0.0);check($hops[1]['avg_ms']===null);check($hops[2]['address']==='2001:db8::1'&&$hops[2]['loss_percent']===25.0);
check(icct_nms_mtr_hops('mtr: permission denied')===[]);
check(icct_nms_mtr_hops(' 1.|-- 10.0.0.1 101% 4 1 1 1 1 1')===[]);
function icct_backend_require_device_access($id){if($id!==2)throw new RuntimeException('Denied');}
function icct_backend_current_user_id(){return 7;}
function icct_backend_diag_assignment($id,$tool){return ['hostname'=>'127.0.0.1','poller_id'=>1];}
function icct_backend_diag_signature($row){return 'current';}
function db_fetch_row_prepared($sql,$args){check($args===[2,7,1,$args[3],'current']);check(str_contains($sql,'user_id=?')&&str_contains($sql,'config_hash=?'));return $GLOBALS['job'];}
$GLOBALS['job']=['status'=>'complete','finished_at'=>'2026-10-04 12:00:00','result_json'=>json_encode(['output'=>$report,'target'=>'127.0.0.1','execution_host'=>'collector'])];
check(count(icct_nms_link_mtr(2))===2);
$GLOBALS['job']['result_json']=json_encode(['target'=>'old-target','output'=>$report]);check(icct_nms_link_mtr(2)===[]);
$GLOBALS['job']=null;check(icct_nms_link_mtr(2)===[]);
try {icct_nms_link_mtr(3);throw new Exception('Authorization missing');}catch(RuntimeException $e){check($e->getMessage()==='Denied');}
echo "Topology MTR tests passed\n";
