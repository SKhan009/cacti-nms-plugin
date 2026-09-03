<?php
/** Standalone MariaDB integration QA in a uniquely named throwaway database, never Cacti. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-isolated') die("Pass --run-isolated with local socket database-create permission.\n");
$qa_db = 'nms_rack_qa_' . bin2hex(random_bytes(6));
$pdo = new PDO('mysql:unix_socket=/var/lib/mysql/mysql.sock;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
function db_fetch_cell($sql) { return $GLOBALS['pdo']->query($sql)->fetchColumn(); }
function qa_stmt($sql, $params) { $s = $GLOBALS['pdo']->prepare($sql); $s->execute($params); return $s; }
function db_fetch_cell_prepared($sql, $params) { return qa_stmt($sql,$params)->fetchColumn(); }
function db_fetch_row_prepared($sql, $params) { return qa_stmt($sql,$params)->fetch(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql, $params = array()) { qa_stmt($sql,$params); return true; }
function db_execute($sql) { $GLOBALS['pdo']->exec($sql); return true; }
function nms_current_user_id() { return 1; }
function nms_require_device_access($id) { if (!in_array($id,array(1,2),true)) throw new RuntimeException('Denied device'); }
function qa_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function qa_reject($fn) { try { $fn(); throw new LogicException('Invalid action accepted'); } catch (InvalidArgumentException $expected) {} }
require_once(__DIR__ . '/../includes/topology_config.php');
try {
	$pdo->exec('CREATE DATABASE `' . $qa_db . '` CHARACTER SET utf8mb4');
	$pdo->exec('USE `' . $qa_db . '`');
	$pdo->exec("CREATE TABLE host (id INT PRIMARY KEY, site_id INT, deleted CHAR(2)); INSERT INTO host VALUES (1,1,''),(2,1,'')");
	$pdo->exec("CREATE TABLE plugin_nms_categories (id INT PRIMARY KEY); INSERT INTO plugin_nms_categories VALUES (1)");
	nms_topology_config_schema();
	nms_topology_config_schema();
	nms_topology_port_save(1, 'Switch', 24);
	nms_topology_port_save(1, 'Switch', 48);
	qa_check((int)db_fetch_cell('SELECT physical_ports FROM plugin_nms_port_profiles') === 48, 'Profile update');
	$data = array('node_id'=>0,'name'=>'Test vehicle','node_kind'=>'vehicle','rack_count'=>4,'unit_count'=>12);
	$id = nms_topology_config_write('save_node',1,$data); $data['node_id']=$id;
	foreach(array(4,8,5) as $count) { $data['rack_count']=$count; nms_topology_config_write('save_node',1,$data); qa_check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_racks') === $count,'Rack count'); }
	$rack = (int)db_fetch_cell('SELECT id FROM plugin_nms_racks WHERE rack_number=5');
	$placement = array('rack_id'=>$rack,'host_id'=>1,'start_unit'=>10,'unit_height'=>3);
	nms_topology_config_write('place_device',1,$placement);
	$placement['host_id']=2;
	qa_reject(function() use($placement) { nms_topology_config_write('place_device',1,$placement); });
	$placement['start_unit']=12;
	qa_reject(function() use($placement) { nms_topology_config_write('place_device',1,$placement); });
	$data['rack_count']=4;
	qa_reject(function() use($data) { nms_topology_config_write('save_node',1,$data); });
	qa_reject(function() use($rack) { nms_topology_config_write('save_rack',1,array('rack_id'=>$rack,'name'=>'small','unit_count'=>11)); });
	qa_reject(function() use($rack) { nms_topology_config_write('save_rack',2,array('rack_id'=>$rack,'name'=>'wrong site','unit_count'=>18)); });
	nms_topology_config_write('save_rack',1,array('rack_id'=>$rack,'name'=>'18U rack','unit_count'=>18));
	$placement['start_unit']=13;
	nms_topology_config_write('place_device',1,$placement);
	qa_check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_rack_devices')===2,'Adjacent slots');
	$placement['host_id']=1; $placement['start_unit']=1;
	nms_topology_config_write('place_device',1,$placement);
	qa_check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_rack_devices')===2,'Move duplicates device');
	nms_topology_config_write('unplace_device',1,$placement);
	qa_check((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_nms_rack_devices')===1,'Unassign');
	qa_check((int)db_fetch_cell('SELECT COUNT(*) FROM host')===2,'Core fixture changed');
	echo "PASS: repeatable DDL, profile updates, 4/8/5 racks, occupancy, boundaries, adjacency, site checks, moves, unassign and transaction recovery.\n";
} finally {
	// Exact generated QA identifier only; never drop a configured database or accept a user-supplied name.
	if (!preg_match('/^nms_rack_qa_[a-f0-9]{12}$/D',$qa_db)) throw new RuntimeException('Invalid QA database');
	$pdo->exec('DROP DATABASE IF EXISTS `' . $qa_db . '`');
}
echo "Temporary QA database removed; Cacti database untouched.\n";
