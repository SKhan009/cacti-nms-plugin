<?php
require __DIR__ . '/../../protocols/shared/services/protocol_preset_service.php';
$meta=['protocol_defaults'=>json_encode(['ssh'=>['port'=>22,'connect_timeout'=>10,'username'=>'operator','monitoring'=>'1'],'lldp'=>['interval_seconds'=>300,'stale_seconds'=>900,'refresh_seconds'=>30]])];
function db_fetch_cell_prepared($sql,$args){return $GLOBALS['meta'][$args[0]] ?? false;}
function icct_backend_category_execute($sql,$args){$GLOBALS['meta'][$args[0]]=$args[1];}
$defaults=icct_nms_protocol_presets();
icct_nms_protocol_record_overrides(11,'ssh',['port'=>'2222','connect_timeout'=>'10','username'=>'operator','monitoring'=>'1','secret'=>'never-in-metadata']);
icct_nms_protocol_record_overrides(12,'ssh',['port'=>'22','connect_timeout'=>'10','username'=>'operator','monitoring'=>'1']);
if(icct_nms_protocol_device_overrides(11)['ssh']!==['port'] || icct_nms_protocol_device_overrides(12)['ssh']!==[])throw new RuntimeException('Device overrides leaked or inherited fields pinned');
if(str_contains(json_encode($meta),'never-in-metadata') || icct_nms_protocol_presets()!==$defaults)throw new RuntimeException('Secrets stored or preset mutated');
$defaults['ssh']['connect_timeout']=20;$defaults['ssh']['port']=23;$meta['protocol_defaults']=json_encode($defaults);
if(icct_nms_protocol_device_overrides(11)['ssh']!==['port'] || icct_nms_protocol_device_overrides(12)['ssh']!==[])throw new RuntimeException('Preset change erased overrides');
icct_nms_protocol_record_overrides(11,'ssh',['port'=>'23','connect_timeout'=>'20','username'=>'operator','monitoring'=>'1']);
if(icct_nms_protocol_device_overrides(11)['ssh']!==[])throw new RuntimeException('Returning to preset did not restore inheritance');
icct_nms_protocol_record_overrides(11,'discovery',['discovery_protocol'=>'lldp','interval_seconds'=>'300','stale_seconds'=>'1000','refresh_seconds'=>'30']);
if(icct_nms_protocol_device_overrides(11)['lldp']!==['stale_seconds'] || icct_nms_protocol_device_overrides(12)['lldp']!==null)throw new RuntimeException('Discovery or legacy state failed');
echo "Field-level preset inheritance, device isolation, reset to defaults and secret exclusion passed.\n";
