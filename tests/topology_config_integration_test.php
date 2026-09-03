<?php
/** Explicit VM QA. All plugin fixture writes are rolled back; no core records are changed. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run') die("Pass --run for rollback-only rack storage QA.\n");
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/database.php');
nms_require_database();
function rack_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function rack_reject($action, $site, $data) {
	try { nms_topology_config_apply($action, $site, $data); throw new LogicException('Expected rejection: ' . $action); }
	catch (InvalidArgumentException $expected) {}
}
$site = (int) db_fetch_cell("SELECT site_id FROM host WHERE deleted = '' AND site_id > 0 LIMIT 1");
if (!$site) die("SKIP: a Cacti site with a device is required.\n");
nms_category_execute('START TRANSACTION');
try {
	$data = array('node_id'=>0,'name'=>'QA rollback rack ' . bin2hex(random_bytes(6)), 'node_kind'=>'vehicle','rack_count'=>4,'unit_count'=>12);
	$node = nms_topology_config_apply('save_node', $site, $data);
	$data['node_id'] = $node;
	foreach (array(4,8,5) as $count) {
		$data['rack_count'] = $count;
		nms_topology_config_apply('save_node', $site, $data);
		rack_check((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_racks WHERE node_id = ?', array($node)) === $count, 'Dynamic rack count failed');
	}
	$rack = (int) db_fetch_cell_prepared('SELECT id FROM plugin_nms_racks WHERE node_id = ? AND rack_number = 5', array($node));
	// Reserved fixture slot exercises occupancy without touching a real device's placement.
	nms_category_execute('INSERT INTO plugin_nms_rack_devices (host_id,rack_id,start_unit,unit_height,updated_by,updated_at) VALUES (16777215,?,10,3,0,NOW())', array($rack));
	$data['rack_count'] = 4;
	rack_reject('save_node', $site, $data);
	rack_reject('save_rack', $site, array('rack_id'=>$rack,'name'=>'Too small','unit_count'=>11));
	nms_topology_config_apply('save_rack', $site, array('rack_id'=>$rack,'name'=>'Custom capacity','unit_count'=>18));
	rack_check((int) db_fetch_cell_prepared('SELECT unit_count FROM plugin_nms_racks WHERE id = ?', array($rack)) === 18, 'Per-rack capacity failed');
	rack_reject('save_rack', $site + 999999, array('rack_id'=>$rack,'name'=>'Wrong site','unit_count'=>18));
	echo "PASS: 4/8/5 dynamic racks, capacity edits, occupied-rack protection and site validation.\n";
} finally {
	db_execute('ROLLBACK');
}
rack_check(!(int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_rack_nodes WHERE id = ?', array($node)), 'Fixture rollback failed');
echo "PASS: QA fixture rolled back; no core devices changed.\n";
