<?php
/** Pure validation and precedence tests; no Cacti/database needed. */
require_once(__DIR__ . '/../includes/topology_config.php');
function check_topology($ok) { if (!$ok) throw new RuntimeException('Topology validation assertion failed.'); }
foreach (array(-1, '1.5', '1e2', '', array(), '101') as $invalid) {
	try { nms_topology_integer($invalid, 1, 100, 'Rack units'); throw new LogicException('Invalid units accepted'); }
	catch (InvalidArgumentException $expected) {}
}
foreach (array(4, 5, 8, 100) as $count) check_topology(nms_topology_integer($count, 1, 100, 'Racks') === $count);
$profiles = array(array('category_id'=>1,'device_type'=>'','physical_ports'=>24), array('category_id'=>1,'device_type'=>'Router','physical_ports'=>8), array('category_id'=>2,'device_type'=>'UPS','physical_ports'=>0));
check_topology(nms_topology_physical_ports($profiles, 1, 'Router') === 8);
check_topology(nms_topology_physical_ports($profiles, 1, 'Switch') === 24);
check_topology(nms_topology_physical_ports($profiles, 2, 'UPS') === 0);
check_topology(nms_topology_physical_ports($profiles, 3, 'Switch') === null);
echo "PASS: integer bounds, dynamic rack counts, profile precedence and unknown capacity.\n";
