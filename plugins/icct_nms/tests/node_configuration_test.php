<?php
require __DIR__.'/../includes/node_configuration_service.php';
$nodes=[['id'=>1,'name'=>'Node A','site_id'=>5],['id'=>2,'name'=>'Node B','site_id'=>6]];
$devices=[['id'=>10,'site_id'=>5,'rack_id'=>3],['id'=>11,'site_id'=>5,'rack_id'=>0],['id'=>12,'site_id'=>6,'rack_id'=>0],['id'=>13,'site_id'=>5,'rack_id'=>0]];
$result=icct_nms_node_configuration_groups($nodes,$devices,[10=>2,11=>1,12=>1,13=>99],[3=>1]);
if (array_column($result['groups'][1]['devices'],'id')!==[10,11] || $result['groups'][2]['devices']!==[] || array_column($result['unassigned'],'id')!==[12,13]) throw new RuntimeException('Rack precedence, site validation or stale-node handling failed.');
function icct_backend_require_management($realm) { if ($realm!==3) throw new RuntimeException('Wrong realm'); }
function icct_nms_id($value) { return (int)$value; }
function icct_backend_topology_integer($value,$min,$max,$label) { return (int)$value; }
function icct_backend_require_device_access($id) { if (empty($GLOBALS['allowDevice'])) throw new RuntimeException('Device access denied.'); }
try { icct_nms_assign_node_device(['device_id'=>10,'node_id'=>1]); throw new LogicException('Unauthorized assignment allowed'); }
catch (RuntimeException $error) { if ($error->getMessage()!=='Device access denied.') throw $error; }
$allowDevice=true; $fixtureSite=5; $fixtureRack=false; $fixturePeripheral=false;$fixtureNode=0; $writes=[];
function db_fetch_cell($query) { return 'fixture'; }
function db_fetch_cell_prepared($query,$parameters) {
 if(str_contains($query,'meta_value FROM plugin_icct_nms_meta'))return $GLOBALS['fixturePeripheral']?9:0;
 if(str_contains($query,'SELECT r.node_id'))return 0;
 if(str_contains($query,'SELECT n.id'))return $GLOBALS['fixtureNode'];
 return 1;
}
function db_fetch_row_prepared($query,$parameters) {
    if (str_contains($query,'FROM host')) return ['id'=>$parameters[0],'site_id'=>$GLOBALS['hostSites'][$parameters[0]] ?? 5];
    if (str_contains($query,'rack_devices')) return $GLOBALS['fixtureRack'] ? ['node_id'=>1] : [];
    return ['id'=>1,'site_id'=>$GLOBALS['fixtureSite']];
}
function icct_backend_category_execute($query,$parameters=[]) { $GLOBALS['writes'][]=[$query,$parameters]; }
icct_nms_assign_node_device(['device_id'=>10,'node_id'=>1]);
if ($writes[1][1]!==['device_node_id_10','1'] || $writes[2][0]!=='COMMIT') throw new RuntimeException('Device-specific membership was not saved.');
foreach (['site','rack','peripheral','node'] as $case) {
    $writes=[]; $fixtureSite=$case==='site'?6:5; $fixtureRack=$case==='rack';$fixturePeripheral=$case==='peripheral';$fixtureNode=$case==='node'?2:0;
    try { icct_nms_assign_node_device(['device_id'=>10,'node_id'=>1]); throw new LogicException('Invalid assignment accepted.'); }
    catch (InvalidArgumentException $expected) {}
    if (count($writes)!==2 || $writes[1][0]!=='ROLLBACK') throw new RuntimeException('Invalid assignment changed membership.');
}
echo "Node grouping, device access, per-device save and site/rack validation passed.\n";

$fixtureSite=5;$fixtureRack=false;$fixturePeripheral=false;$fixtureNode=0;$writes=[];
icct_nms_assign_node_devices(['device_ids'=>[10,11],'node_id'=>1]);
$inserts=array_values(array_filter($writes,fn($write)=>str_contains($write[0],'INSERT INTO')));
if(count($inserts)!==2||$inserts[0][1]!==['device_node_id_10','1']||$inserts[1][1]!==['device_node_id_11','1']||end($writes)[0]!=='COMMIT')throw new LogicException('Bulk membership save failed');
$writes=[];$hostSites=[11=>6];
try {icct_nms_assign_node_devices(['device_ids'=>[10,11],'node_id'=>1]);throw new LogicException('Mixed-site bulk accepted');}catch(InvalidArgumentException $expected){}
if(end($writes)[0]!=='ROLLBACK'||in_array('COMMIT',array_column($writes,0),true))throw new LogicException('Bulk failure was committed');
$writes=[];
try {icct_nms_assign_node_devices(['device_ids'=>[10,10],'node_id'=>1]);throw new LogicException('Duplicate bulk accepted');}catch(InvalidArgumentException $expected){}
if($writes)throw new LogicException('Duplicate bulk changed membership');
echo "Bulk assignments, duplicate rejection and mixed-site rollback passed.\n";
