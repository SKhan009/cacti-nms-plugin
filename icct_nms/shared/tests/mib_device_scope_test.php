<?php
require __DIR__ . '/../../protocols/snmp/mibs/services/mib_device_core_service.php';
function icct_mib_graph_groups($records) { return $records; }
function db_fetch_assoc($sql) {
    if (str_contains($sql, 'SELECT id,name')) return [['id'=>11,'name'=>'MIB graph'],['id'=>12,'name'=>'Unrelated graph']];
    if (str_contains($sql, 'data_template_rrd')) return [['graph_template_id'=>11,'data_template_id'=>21],['graph_template_id'=>12,'data_template_id'=>22]];
    return [];
}
$bundle=['rows'=>[['graph_template_id'=>11]]];
$records=[['name'=>'Prepared OID graph','records'=>[['symbol'=>'TEST::value']]]];
$options=icct_mib_device_graph_options($bundle,[]);
if (array_keys($options)!==['core:11']) throw new Exception('Unrelated graph exposed');
if ($options['core:11']['sources']!==['core:21']) throw new Exception('Wrong source count');
try { icct_mib_device_graph_selection($bundle,$records,['core:12']); throw new Exception('Unrelated graph accepted'); }
catch (InvalidArgumentException $e) {}
if (icct_mib_device_graph_selection($bundle,[],['core:11'])['core_graphs']!==[11]) throw new Exception('MIB native graph rejected');
echo "MIB-only device graph scope and native identity validation passed.\n";

if (array_keys(icct_mib_device_graph_options($bundle,$records)) !== ['Prepared OID graph']) throw new Exception('Saved graphs duplicated the prepared batch');
