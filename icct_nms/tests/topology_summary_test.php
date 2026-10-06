<?php
require __DIR__ . '/../dashboard/topology/services/topology_summary_service.php';
foreach ([
 ['Core - Switching Capacity','Gbps',['Switching Capacity (Tbps)',0.001]],
 ['Core - Forwarding Rate','Mpps',['Forwarding Rate (Bpps)',0.001]],
 ['Hardware Redundancy','watts',['Hardware Redundancy (W)',1]],
 ['TCAM Capacity','entries',['Table Scale (TCAM / FIB)',1]],
 ['CPU Utilization','percent',null],
 ['Switching Capacity','percent',null],
 ['Forwarding Rate','bps',null],
 ['Hardware Redundancy','percent',null],
] as [$name,$units,$expected]) {
 if (icct_nms_topology_capacity_spec($name,$units)!==$expected)throw new LogicException('Incorrect metric or unit mapping: '.$name);
}
function icct_nms_device_graphs($id){return [];}
if(array_filter(icct_nms_topology_capacity(['id'=>1])))throw new LogicException('Invented values without authorized graphs');
echo "Capacity metric names, unit conversion and missing readings passed.\n";
