<?php
require __DIR__.'/../includes/rack_preset_service.php';
$profileKey='0123456789abcdef';$calls=[];$capacity=24;
function icct_backend_require_management($realm) { if ($realm!==3) throw new RuntimeException('Wrong management realm'); }
function db_fetch_cell($query) { return 'fixture'; }
function db_fetch_cell_prepared($query,$parameters) {
    if (str_contains($query,'meta_value')) return json_encode([$GLOBALS['profileKey']=>['name'=>'Demo','rack_count'=>2,'unit_count'=>$GLOBALS['capacity']]]);
    if (str_contains($query,'GET_LOCK') || str_contains($query,'RELEASE_LOCK') || str_contains($query,'FROM sites')) return 1;
    if (str_contains($query,'FROM plugin_icct_nms_racks')) return 21;
    return 0;
}
function db_fetch_assoc($query) { return []; }
function db_fetch_assoc_prepared($query,$parameters) { return [['id'=>21,'rack_number'=>1],['id'=>22,'rack_number'=>2]]; }

function icct_backend_topology_integer($value,$min,$max,$label) { $v=filter_var($value,FILTER_VALIDATE_INT);if ($v===false || $v<$min || $v>$max) throw new InvalidArgumentException($label);return $v; }
function icct_backend_category_execute($query,$parameters=[]) { $GLOBALS['calls'][]=[$query,$parameters]; }
function icct_backend_current_user_id(){return 1;}
function icct_backend_topology_config_apply($action,$site,$input) { $GLOBALS['calls'][]=[$action,$site,$input];return 12; }
$choices=icct_nms_device_rack_choices();
if (array_column($choices,'name')!==['Demo','Demo'] || $choices[0]['unit_count']!==24) throw new RuntimeException('Preset choices or units missing.');
if (icct_nms_resolve_preset_rack('preset:'.$profileKey.':1',6,'24:1')!==21) throw new RuntimeException('Preset resolution failed.');
icct_nms_resolve_preset_rack('preset:'.$profileKey.':1',6,'1:1');
foreach (['preset:'.$profileKey.':3'=>'1:1','preset:'.$profileKey.':1'=>'25:1'] as $choice=>$position) {
    $calls=[];
    try { icct_nms_resolve_preset_rack($choice,6,$position);throw new LogicException('Invalid capacity accepted.'); } catch (InvalidArgumentException $expected) {}
    if (count($calls)!==2 || $calls[1][0]!=='ROLLBACK') throw new RuntimeException('Invalid selection created racks.');
}
echo "Preset rack choices, selected-site creation, reuse and capacity checks passed.\n";
