<?php
/** CLI-only render fixture: no database, network or device writes. */
if(PHP_SAPI!=='cli') exit(1);
function nms_h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function nms_poller_interval(){return 300;}
function nms_appearance_read(){return ['types'=>[]];}
function db_fetch_assoc($sql){return [];}
$_SERVER['REQUEST_METHOD']='GET';
$host_id=0; $error=''; $association_error=''; $nms_csrf_token='fixture-only';
$values=['description'=>'','hostname'=>'','host_template_id'=>0,'site_id'=>0,'poller_id'=>1,'location'=>'','short_name'=>'','manual_serial_number'=>'','node_id'=>0,'external_id'=>'','disabled'=>'','equipment_category_id'=>0,'device_type'=>'','device_threads'=>1,'notes'=>'','connection_id'=>0,'device_address'=>1,'transport'=>'direct','equipment_profile_id'=>0,'interval_seconds'=>300,'assignment_revision'=>0,'equipment_revision'=>0];
$host_templates=[['id'=>7,'name'=>'Installed serial template']];
$sites=[['id'=>2,'name'=>'Bengaluru']];$pollers=[['id'=>1,'name'=>'Main collector'],['id'=>2,'name'=>'Remote collector']];
$categories=[];$node_options=[['id'=>9,'name'=>'ICCT 1','site_name'=>'Bengaluru','site_id'=>2]];
$fields_host_edit=['device_threads'=>['array'=>[1=>'1',2=>'2']]];
$settings=['protocol'=>'modbus_rtu','baud_rate'=>9600,'data_bits'=>8,'parity'=>'even','stop_bits'=>1,'flow_control'=>'none','timeout_ms'=>1000,'retries'=>1];
$profiles=[['id'=>3,'name'=>'Meter 9600 8E1','revision'=>2,'settings_json'=>json_encode($settings)]];
$wizard_connections=[['id'=>5,'name'=>'Shared gateway','endpoint'=>'[192.0.2.10]:4001','poller_id'=>2,'settings'=>array_replace($settings,['baud_rate'=>19200])]];
$equipment_choices=[['id'=>4,'name'=>'Meter readings','manufacturer'=>'Example','model'=>'Fixture','manual_reference'=>'Fixture only','fields_json'=>json_encode([['label'=>'Voltage','key'=>'voltage','offset'=>0,'function'=>3,'type'=>'uint16','unit'=>'V','min'=>0,'max'=>500]])]];
$source_profiles=[['id'=>1,'name'=>'Installed 5 minute profile','step'=>300,'heartbeat'=>600]];
$graph_choices=[['id'=>1,'name'=>'Installed graph']];$query_choices=[['id'=>2,'name'=>'Installed script query']];$associated_graphs=[];$associated_queries=[];$reindex_types=[0=>'None'];
print '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="plugins/nms/css/nms-devices.css"><link rel="stylesheet" href="plugins/nms/css/nms-snmp-form.css"><link rel="stylesheet" href="plugins/nms/css/nms-nodes.css"><link rel="stylesheet" href="plugins/nms/css/nms-serial-wizard.css"><style>body{font:14px system-ui;background:#eef2f3;color:#213039;margin:24px}main{margin:auto}a{color:#236344}</style></head><body>';
require __DIR__.'/../../plugins/nms/templates/devices/serial_wizard.php';
print '<script src="plugins/nms/js/nms-serial-wizard.js"></script></body></html>';
