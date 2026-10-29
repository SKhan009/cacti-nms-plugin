<?php
require __DIR__.'/../../dashboard/widgets/services/lnms_service.php';
$config=['poller_id'=>1,'url_path'=>'/cacti/'];$saved='';$management=true;
$sites=[['id'=>7,'name'=>'Main Site','latitude'=>12.97,'longitude'=>77.59],['id'=>8,'name'=>'Remote Site','latitude'=>28.61,'longitude'=>77.21]];
$pollers=[['id'=>1,'name'=>'Main Poller','hostname'=>'10.1.0.10','disabled'=>'','last_update'=>date('Y-m-d H:i:s')],['id'=>2,'name'=>'Remote LNMS','hostname'=>'10.2.0.10','disabled'=>'','last_update'=>date('Y-m-d H:i:s',time()-3600)]];
function db_fetch_cell_prepared($sql,$args){return $GLOBALS['saved'];}
function db_fetch_assoc($sql){return str_contains($sql,'FROM poller')?$GLOBALS['pollers']:$GLOBALS['sites'];}
function icct_nms_inventory(){return [['id'=>55,'hostname'=>'ordinary-device','site_id'=>99]];}
function icct_nms_map_coordinates($lat,$lng){return $lat===null||$lng===null?null:[(float)$lat,(float)$lng];}
function read_config_option($key){return 300;}
function is_realm_allowed($realm){return $GLOBALS['management'];}
function icct_backend_require_management($realm){if(!$GLOBALS['management'])throw new RuntimeException('Denied');}
function icct_backend_category_execute($sql,$args){$GLOBALS['saved']=$args[1];}
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
$now=time();
check(icct_nms_lnms_poller_status(['disabled'=>'on'],300,$now)==='Disabled','Disabled native pollers are represented');
check(icct_nms_lnms_poller_status(['last_update'=>'0000-00-00 00:00:00'],300,$now)==='Unknown','Missing heartbeat is not reported online');
check(icct_nms_lnms_poller_status(['last_update'=>date('Y-m-d H:i:s',$now+120)],300,$now)==='Unknown','Future heartbeat does not fabricate availability');
$d=icct_nms_lnms_data();check(count($d['nodes'])===2&&$d['center']['id']===1,'Only configured pollers appear; this LNMS is the center');
check($d['nodes'][0]['coordinates']===null&&$d['nodes'][0]['site_id']===null,'No geolocation is guessed from IP or an unrelated device');
check($d['counts']['online']===1&&$d['counts']['offline']===1,'Heartbeat freshness determines native poller availability');
icct_nms_lnms_save_sites(['poller_sites'=>[1=>'7',2=>'8']]);$d=icct_nms_lnms_data();
check($d['center']['coordinates']===[12.97,77.59]&&$d['nodes'][1]['coordinates']===[28.61,77.21],'Native sites provide explicit LNMS coordinates');
check($d['nodes'][1]['collector_url']==='/cacti/pollers.php?action=edit&id=2','Collector links stay within the native Cacti path');
$before=$saved;
foreach([[99=>'7'],[1=>'999'],[1=>['7']]] as $bad){try{icct_nms_lnms_save_sites(['poller_sites'=>$bad]);throw new LogicException('Invalid assignment accepted');}catch(InvalidArgumentException $error){}}
check($saved===$before,'Unknown poller/site and malformed site selections cannot change mappings');
$management=false;$d=icct_nms_lnms_data();check($d['nodes'][0]['collector_url']===null,'Viewers receive no management collector link');
try{icct_nms_lnms_save_sites(['poller_sites'=>[1=>'0']]);throw new LogicException('Viewer changed locations');}catch(RuntimeException $error){check($error->getMessage()==='Denied','Site assignment requires management access');}
echo "LNMS poller source, heartbeat, location assignment and permissions passed.\n";
