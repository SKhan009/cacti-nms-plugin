<?php
require __DIR__.'/../includes/fcaps_service.php';
$fixture=sys_get_temp_dir().'/icct-fault-test-'.getmypid();mkdir($fixture.'/lib',0700,true);file_put_contents($fixture.'/lib/rrd.php','<?php');file_put_contents($fixture.'/lib/snmp.php','<?php');$config=['base_path'=>$fixture];$now=time();$writes=[];
function icct_backend_inventory_collector_id(){return 1;}
function read_config_option($key){return 300;}
function db_fetch_assoc_prepared($sql,$args){if(str_contains($sql,'SELECT h.*'))return [['id'=>5]];if($args[1]!==5)throw new Exception('Wrong host');return [['graph_id'=>100,'title_cache'=>'CPU','local_data_id'=>90,'data_source_name'=>'cpu']];}
function db_fetch_cell_prepared($sql,$args){return json_encode([['template_id'=>1,'metric_id'=>2,'minimum'=>0,'maximum'=>80,'severity'=>'Warning','enabled'=>true]]);}
function icct_backend_category_execute($sql,$args){global $writes;$writes[]=$args;}
function rrdtool_function_fetch(...$args){global $now;return ['data_source_names'=>['cpu'],'values'=>[0=>[$now-300=>'90',$now=>'U',$now+300=>'95']]];}
icct_backend_collect_faults();$status=json_decode($writes[0][1],true);
if($status['results'][0]['state']!=='Unknown' || $status['results'][0]['value']!==null)throw new Exception('Unknown latest measurement substituted with old or future value');
if($writes[0][0]!=='fault_status_5')throw new Exception('Cross-host observation write');
echo "Collector host isolation and unknown latest/future measurement handling passed.\n";

unlink($fixture.'/lib/rrd.php');unlink($fixture.'/lib/snmp.php');rmdir($fixture.'/lib');rmdir($fixture);
