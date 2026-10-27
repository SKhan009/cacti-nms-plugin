<?php
require __DIR__.'/../../protocols/snmp/mibs/services/mib_usage_service.php';
function icct_mib_objects($bundle){return $bundle['objects'];}
function icct_mib_existing_source_indexes($objects){return [0=>11,1=>12];}
function icct_backend_current_user_id(){return 1;}
function is_device_allowed($id,$user){return $id!==9;}
function db_fetch_assoc($sql){
 if(str_contains($sql,'FROM host h'))return [['id'=>2,'description'=>'Device A','host_template_id'=>0],['id'=>3,'description'=>'Device B','host_template_id'=>0],['id'=>4,'description'=>'Unrelated','host_template_id'=>0],['id'=>9,'description'=>'Restricted','host_template_id'=>99]];
 if(str_contains($sql,'AS oid'))return [['host_id'=>2,'oid'=>'.1.3.6.1.2.1.2.1.7'],['host_id'=>3,'oid'=>'1.3.6.1.2.1.1.1.0'],['host_id'=>4,'oid'=>'1.3.6.1.2.1.2.10.7'],['host_id'=>9,'oid'=>'1.3.6.1.2.1.1.1.0']];
 return [['host_id'=>2,'graph_template_id'=>22]];
}
$bundle=['id'=>'file','objects'=>[['numeric'=>true,'table'=>false,'base_oid'=>'1.3.6.1.2.1.1.1'],['numeric'=>true,'table'=>true,'base_oid'=>'1.3.6.1.2.1.2.1']],'rows'=>[['graph_template_id'=>22]],'host_template_id'=>99];
$usage=icct_mib_repository_usage([$bundle])['file'];
if($usage['data_sources']!==2||array_column($usage['devices'],'id')!==[2,3])throw new RuntimeException('Usage count, exact OID boundary, duplicate device or device authorization failed.');
if(icct_mib_repository_usage([])!==[])throw new RuntimeException('Empty upload list invented usage.');
echo "PASS: native usage counts, GET/indexed OID matching, unrelated/restricted devices excluded and duplicate device associations merged.\n";
