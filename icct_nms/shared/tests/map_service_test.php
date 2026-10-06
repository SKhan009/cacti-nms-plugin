<?php
require __DIR__ . '/../../inventory/services/inventory.php';
require __DIR__ . '/../../dashboard/map/services/map_service.php';
function icct_backend_require_device_access($id){if($id===9)throw new RuntimeException('Your Cacti account cannot access this device.');}
function icct_backend_device_status_name($row){return 'Up';}
function icct_backend_ports_view($host){return $GLOBALS['mapTestPorts'] ?? ['fresh'=>false,'collected'=>0,'ports'=>[]];}
function icct_nms_device_types(){return [];}
function icct_nms_device_shape($category,$type,$types){return 'rectangle';}
function icct_nms_device_graphs($id){return [];}
function icct_backend_parameter_is_fresh($time){return false;}
function icct_backend_config_connection_status($id){return null;}
function icct_backend_current_user_id(){return 1;}
function icct_nms_fault_rules($id){return [];}
function icct_nms_fault_observations($host){return $GLOBALS['mapTestFaults'] ?? [];}
function read_config_option($key){return 300;}
function db_fetch_assoc($sql){return [
 ['id'=>7,'description'=>'Allowed','hostname'=>'192.0.2.7','site_id'=>2,'status'=>3,'disabled'=>'','category_id'=>0,'device_type'=>'','manual_serial_number'=>'','last_updated'=>'','poller_id'=>1],
 ['id'=>8,'description'=>'Unlocated','hostname'=>'192.0.2.8','site_id'=>3,'status'=>3,'disabled'=>'','category_id'=>0,'device_type'=>'','manual_serial_number'=>'','last_updated'=>'','poller_id'=>1],
 ['id'=>9,'description'=>'Denied','hostname'=>'192.0.2.9','site_id'=>2,'status'=>3,'disabled'=>'']];}
function db_fetch_row_prepared($sql,$args){
 if(str_contains($sql,'FROM sites'))return ['name'=>'Site '.$args[0],'latitude'=>$args[0]===2?12.5:0,'longitude'=>$args[0]===2?77.5:0,'address1'=>'','city'=>'','state'=>'','country'=>''];
 if(str_contains($sql,'FROM host'))return ['snmp_sysDescr'=>'System','total_polls'=>0];
 if(str_contains($sql,'diagnostic_jobs')){if($args[1]!==1)throw new Exception('Diagnostic owner filter missing');return $GLOBALS['mapTestJob'] ?? [];}
 throw new Exception('Unexpected row query');
}
function db_fetch_cell_prepared($sql,$args){return '';}
foreach([[0,0],[91,1],[0,181],[null,null],['bad',20],[INF,2]] as $point)if(icct_nms_map_coordinates(...$point)!==null)throw new Exception('Invalid coordinate accepted');
if(icct_nms_map_coordinates(0,10)!==[0.0,10.0])throw new Exception('Equator rejected');
$data=icct_nms_map_data();
if(count($data['sites'])!==1 || $data['sites'][0]['devices'][0]['id']!==7 || count($data['unlocated'])!==1 || $data['counts']['total']!==2)throw new Exception('ACL grouping/location/count failed');
if($data['sites'][0]['devices'][0]['packet_loss']!==null)throw new Exception('Missing loss invented');
$params=icct_nms_map_wms_parameters(['bbox'=>'0,0,100,100','url'=>'https://evil.invalid','width'=>1024,'layers'=>'other'],'nms:countries');
if($params['layers']!=='nms:countries' || $params['width']!==1024 || isset($params['url']))throw new Exception('Untrusted WMS input used');
foreach(['','0,0,0,0','1,1,0,0','nan,0,10,10','0,0,999999999,10'] as $bbox){try{icct_nms_map_wms_parameters(['bbox'=>$bbox],'world');}catch(InvalidArgumentException $e){continue;}throw new Exception('Invalid bounds accepted');}
try{icct_nms_map_wms_parameters(['bbox'=>'0,0,100,100','width'=>10000],'world');throw new Exception('Oversized image accepted');}catch(InvalidArgumentException $e){}
$m=icct_nms_map_metrics(['ssCpuIdle'=>92,'mem_total'=>100,'mem_free'=>25]);
if($m['cpu']!==8.0 || $m['memory']!==75.0 || $m['packet_loss']!==null)throw new Exception('Metric conversion failed');
$m=icct_nms_map_metrics(['load_1min'=>1.5,'cpu_percent'=>500,'mem_total'=>0]);
if($m['cpu']!==null || $m['memory']!==null)throw new Exception('Invalid metric accepted');
echo "PASS: authorized site grouping/counts, missing locations, bounded WMS and measured percentage conversion\n";

function icct_backend_diag_assignment($id,$tool){return ['id'=>$id];}
function icct_backend_diag_signature($row){return str_repeat('a',64);}
$host=['id'=>7,'hostname'=>'192.0.2.7','poller_id'=>1,'disabled'=>''];
$mapTestJob=['poller_id'=>1,'config_hash'=>str_repeat('a',64),'finished_at'=>date('Y-m-d H:i:s'),'result_json'=>json_encode(['target'=>'192.0.2.7','output'=>"4 packets transmitted, 3 received, 25% packet loss\nrtt min/avg/max/mdev = 1.000/2.000/3.000/0.500 ms"])];
$m=icct_nms_map_measurement($host);
if($m['packet_loss']!==25.0 || $m['latency_ms']!==2.0 || isset($m['output']))throw new Exception('Private measured summary failed');
$mapTestJob['finished_at']=date('Y-m-d H:i:s',time()-901);
if(icct_nms_map_measurement($host)['packet_loss']!==null)throw new Exception('Stale diagnostic used');
$mapTestJob['finished_at']=date('Y-m-d H:i:s');$mapTestJob['config_hash']=str_repeat('b',64);
if(icct_nms_map_measurement($host)['packet_loss']!==null)throw new Exception('Changed device diagnostic used');
$mapTestJob['config_hash']=str_repeat('a',64);$mapTestJob['poller_id']=2;
if(icct_nms_map_measurement($host)['packet_loss']!==null)throw new Exception('Other collector diagnostic used');
$mapTestJob['poller_id']=1;
if(icct_nms_map_measurement(array_replace($host,['disabled'=>'on']))['packet_loss']!==null)throw new Exception('Disabled device diagnostic used');
echo "PASS: private measured ping summary, stale/configuration/collector/disabled rejection\n";

function is_realm_allowed($realm){return true;}
function icct_backend_diag_selected_labels($id){return $id===7?["ping"=>"Ping ICMP"]:[];}
if($data["sites"][0]["devices"][0]["diagnostics"]!==["ping"=>"Ping ICMP"])throw new Exception("Map diagnostic selection mismatch");
echo "PASS: map diagnostics use saved per-device selections\n";

require __DIR__ . '/../../dashboard/widgets/services/dashboard_service.php';
// Port and metric alarms share the dashboard/map severity feed; clear/unknown stay out.
$GLOBALS['mapTestPorts']=['fresh'=>true,'collected'=>100,'ports'=>[['index'=>2,'name'=>'eth0','admin'=>1,'oper'=>2,'status'=>'Available (link down)']]];
$GLOBALS['mapTestFaults']=[['rule'=>'port:2','state'=>'Major','graph'=>'eth0 link status','value'=>2,'sample_time'=>100],['rule'=>0,'state'=>'Critical','graph'=>'CPU','value'=>95,'sample_time'=>100]];
$device=icct_nms_map_data()['sites'][0]['devices'][0];
if($device['fault_counts']['Major']!==1||$device['fault_counts']['Critical']!==1||$device['ports']['items'][0]['alarm_state']!=='Major'||array_column($device['fault_alarms'],'source')!==['Port','Fault'])throw new Exception('Combined severity feed failed');
$widgets=icct_nms_dashboard_readings(['unlocated'=>[$device],'sites'=>[]]);
if($widgets['total']!==2||$widgets['severity']['Major']!==1||$widgets['severity']['Critical']!==1||count($widgets['recent'])!==2||count($widgets['frequent'])!==2||$widgets['problematic']['devices'][0]['counts']['Major']!==1||$widgets['ports'][0]['ports']['items'][0]['alarm_state']!=='Major')throw new Exception('Dashboard widgets disagree on port/fault severity');
foreach(['Normal','Unknown'] as $state){
 $GLOBALS['mapTestFaults'][0]['state']=$state;
 $device=icct_nms_map_data()['sites'][0]['devices'][0];
 $widgets=icct_nms_dashboard_readings(['unlocated'=>[$device],'sites'=>[]]);
 if($widgets['total']!==1||$widgets['severity']['Major']!==0||count($widgets['recent'])!==1)throw new Exception('Dashboard retained cleared/stale port alarm');
 if($device['fault_counts']['Major']!==0||count($device['fault_alarms'])!==1||$device['ports']['items'][0]['alarm_state']!==$state)throw new Exception('Cleared or stale port counted as active');
}
echo "Port and fault severity feeds, clearing and stale observations passed\n";
