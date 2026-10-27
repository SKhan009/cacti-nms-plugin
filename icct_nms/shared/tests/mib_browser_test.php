<?php
require __DIR__.'/../../protocols/snmp/mibs/services/mib_browser_service.php';
function db_fetch_assoc($sql){return $sql==='SELECT id FROM data_template'?[['id'=>11]]:($GLOBALS['nativeSources']??[]);}
function icct_mib_list(){return [];}
function icct_mib_objects($bundle){return $bundle['objects'];}
function icct_mib_file($bundle,$index){return $bundle['files'][$index]['content'];}
$object=['symbol'=>'TEST-MIB::reading','kind'=>'OBJECT-TYPE','base_oid'=>'1.3.6.1.2.1.1.1','oid'=>'1.3.6.1.2.1.1.1.0','syntax'=>'INTEGER','access'=>'read-only','description'=>'A device reading.','table'=>false];
$bundle=['id'=>'fixture','name'=>'Fixture','objects'=>[$object,array_replace($object,['symbol'=>'TEST-MIB::other','base_oid'=>'1.3.6.1.2.1.2.1','oid'=>'1.3.6.1.2.1.2.1.0'])],
    'files'=>[['module'=>'TEST-MIB','name'=>'test.mib','content'=>"reading OBJECT-TYPE\nSYNTAX INTEGER\nMAX-ACCESS read-only\nSTATUS current\nDESCRIPTION \"A device reading.\"\nDEFVAL { 3 }\n::= { system 1 }\n"]],
    'rows'=>[['symbol'=>'TEST-MIB::reading','data_template_id'=>11],['symbol'=>'TEST-MIB::other','data_template_id'=>99]]];
$data=icct_mib_browser_data([$bundle]);
if(count($data['records'])!==2||$data['warnings'])throw new RuntimeException('Repository records lost');
if(!$data['records'][0]['created']||$data['records'][1]['created'])throw new RuntimeException('Deleted/nonexistent Cacti template shown as existing');
if($data['records'][0]['status']!=='current'||$data['records'][0]['defval']!=='3'||$data['records'][1]['status']!=='')throw new RuntimeException('Source metadata wrong or fabricated');
$ids=[];$walk=function($node)use(&$walk,&$ids){foreach($node['records'] as $id)$ids[]=$id;foreach($node['children'] as $child)$walk($child);};$walk($data['tree']);sort($ids);
if($ids!==[0,1])throw new RuntimeException('Tree lost OID records');
if(icct_mib_browser_tree([])['children'])throw new RuntimeException('Empty repository invented objects');
$unsafe=icct_mib_browser_tree([['name'=>'Invalid','base_oid'=>'<script>']]);
if($unsafe['children'])throw new RuntimeException('Invalid OID accepted into tree');
echo "PASS: numeric OID tree, source metadata, empty repository and native Cacti data-source existence.\n";
$indexed=$bundle;$indexed['objects']=[array_replace($object,['symbol'=>'TEST-MIB::entry','base_oid'=>'1.3.6.1.2.1.2.2.1']),array_replace($object,['symbol'=>'TEST-MIB::counter','base_oid'=>'1.3.6.1.2.1.2.2.1.10'])];
$indexed['files'][0]['content']="entry OBJECT-TYPE\nSYNTAX Entry\nSTATUS current\nINDEX { ifIndex }\n::= { table 1 }\ncounter OBJECT-TYPE\nSYNTAX Counter32\nSTATUS current\n::= { entry 10 }\n";
$indexedData=icct_mib_browser_data([$indexed]);
if($indexedData['records'][1]['indexes']!=='ifIndex')throw new RuntimeException('Table column did not inherit the MIB row index');
echo "PASS: table column inherits its actual MIB INDEX declaration.\n";

$preview=['records'=>[array_replace($object,['numeric'=>true]),array_replace($object,['numeric'=>true,'table'=>true,'base_oid'=>'1.3.6.1.2.1.2.1']),array_replace($object,['numeric'=>false])],'files'=>[['module'=>'TEST-MIB']],'parse_error'=>''];
$nativeSources=[['data_template_id'=>11,'oid'=>'.1.3.6.1.2.1.1.1.0'],['data_template_id'=>11,'oid'=>'1.3.6.1.2.1.2.10.5']];
$summary=icct_mib_upload_summary($preview);
if($summary['object_count']!==3||$summary['supported_count']!==2||$summary['existing_count']!==1||!$summary['can_configure'])throw new RuntimeException('Upload summary counts or OID boundary match failed');
$nativeSources[]=['data_template_id'=>11,'oid'=>'1.3.6.1.2.1.2.1.7'];
if(icct_mib_upload_summary($preview)['can_configure'])throw new RuntimeException('All-existing MIB offered duplicate automatic creation');
$preview['parse_error']='Missing dependency';if(icct_mib_upload_summary($preview)['can_configure'])throw new RuntimeException('Unresolved MIB offered automatic configuration');
echo "PASS: actual upload counts, existing native OIDs, boundary-safe instance matching and no duplicate automatic selection.\n";
$nativeSources=[];
$tableBundle=$bundle;$tableBundle['objects']=[array_replace($object,['symbol'=>'TEST-MIB::table','base_oid'=>'1.3.6.1.2.1.2','numeric'=>false]),array_replace($object,['symbol'=>'TEST-MIB::counter','base_oid'=>'1.3.6.1.2.1.2.1','oid'=>'1.3.6.1.2.1.2.1.0','numeric'=>true]),array_replace($object,['symbol'=>'TEST-MIB::otherCounter','base_oid'=>'1.3.6.1.2.1.2.2','oid'=>'1.3.6.1.2.1.2.2.0','numeric'=>true])];$tableBundle['rows']=[];
$state=icct_mib_browser_data([$tableBundle])['records'];
if(!$state[0]['can_create']||$state[0]['source_count']!==2||icct_mib_creation_targets($tableBundle['objects'],0)!==[1,2])throw new RuntimeException('Table Create did not select supported child OIDs.');
$nativeSources=[['data_template_id'=>11,'oid'=>'1.3.6.1.2.1.2.1.0']];$state=icct_mib_browser_data([$tableBundle])['records'];
if($state[0]['source_state']!=='partial'||!$state[0]['can_create']||$state[1]['source_state']!=='exists'||$state[1]['can_create'])throw new RuntimeException('Partial/existing status or Create eligibility is wrong.');
$nativeSources[]=['data_template_id'=>11,'oid'=>'1.3.6.1.2.1.2.2.0'];$state=icct_mib_browser_data([$tableBundle])['records'];
if($state[0]['source_state']!=='exists'||$state[0]['can_create'])throw new RuntimeException('All-created table still offers duplicate creation.');
echo "PASS: container Create, child selection, partial and fully created native source states.\n";
