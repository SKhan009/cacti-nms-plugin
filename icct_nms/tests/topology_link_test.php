<?php
require __DIR__ . '/../dashboard/topology/services/topology_link_service.php';
function check($ok){if(!$ok)throw new RuntimeException('Link reading check failed');}
function icct_backend_nd_hosts(){return [['id'=>2,'enabled'=>1,'collection_enabled'=>1,'stale_seconds'=>600]];}
function icct_backend_nd_hash($host){return 'current';}
function db_fetch_row_prepared($sql,$args){return $GLOBALS['snapshot'];}
$ports=[7=>['name'=>'eth0','description'=>'Ethernet','high_speed_mbps'=>1000,'in_bps'=>0,'out_bps'=>1000000,'admin'=>1,'oper'=>1,'sample_seconds'=>300]];
check(icct_nms_link_port($ports,'eth0')===$ports[7]);
check(icct_nms_link_port($ports,'wrong')===null);
check(icct_nms_link_port($ports,'ignored',7)===$ports[7]);
check(icct_nms_link_port($ports+[8=>$ports[7]],'eth0')===null);
$GLOBALS['snapshot']=['status'=>'success','config_hash'=>'current','succeeded_at'=>date('Y-m-d H:i:s'),'data_json'=>json_encode(['interfaces'=>$ports])];
$r=icct_nms_link_reading(2,'eth0');check($r['capacity_bps']===1000000000.0&&$r['in_bps']===0.0&&$r['out_bps']===1000000.0);
$GLOBALS['snapshot']['succeeded_at']=date('Y-m-d H:i:s',time()-601);check(!icct_nms_link_reading(2,'eth0')['available']);
$GLOBALS['snapshot']['succeeded_at']=date('Y-m-d H:i:s');$GLOBALS['snapshot']['config_hash']='old';check(!icct_nms_link_reading(2,'eth0')['available']);
check(!icct_nms_link_reading(3,'eth0')['available']);
echo "Topology link tests passed\n";
