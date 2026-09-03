<?php
/** Group, relationship and capability contracts; uses no live devices or database. */
require_once(__DIR__ . '/../includes/groups.php');
require_once(__DIR__ . '/../includes/relationships.php');
require_once(__DIR__ . '/../includes/capabilities.php');
define('HOST_UP', 3);
$_SESSION = array('sess_user_id' => 7);
$writes = array();
$interface_name = 'Gi0/1';

/** Fail visibly without PHP assertion configuration. */
function expect_foundation($value, $message) { if (!$value) throw new RuntimeException($message); }
/** Record only plugin-owned SQL writes. */
function db_execute_prepared($sql, $params = array()) { global $writes; $writes[] = array($sql, $params); return true; }
/** Record transaction rollbacks. */
function db_execute($sql) { return db_execute_prepared($sql); }
/** Stand in for native Cacti ACLs, rejecting device 99. */
function is_device_allowed($id) { return in_array($id, array(1, 2, 3), true); }
/** Use configured Cacti collection cadence. */
function read_config_option($name) { return 300; }
/** Supply scalar host/group existence and native item counts. */
function db_fetch_cell_prepared($sql, $params) {
	if (strpos($sql, 'FROM poller_item') !== false) return 2;
	return in_array((int) $params[0], array(1, 2, 3, 5), true) ? 1 : 0;
}
/** An installed optional plugin is not necessarily integrated into NMS. */
function db_fetch_assoc($sql) { return array(array('directory' => 'syslog', 'status' => 1)); }
/** Supply native input methods, timestamped readings and a real cached interface identity. */
function db_fetch_assoc_prepared($sql, $params) {
	global $interface_name;
	if (strpos($sql, 'FROM host_snmp_cache') !== false) {
		return $params[1] === 4 && $params[2] === '9' ? array(array('field_name' => 'ifName', 'field_value' => $interface_name)) : array();
	}
	if (strpos($sql, 'FROM data_local') !== false) return array(array('id' => 10, 'name' => 'Native SNMP query', 'type_id' => 3));
	return array(array('raw_value' => '20', 'last_seen' => date('Y-m-d H:i:s')),
		array('raw_value' => 'U', 'last_seen' => date('Y-m-d H:i:s')));
}
/** Return a manual edge for the archive contract. */
function db_fetch_row_prepared($sql, $params) { return array('provenance' => 'manual', 'source_host_id' => 1, 'target_host_id' => 2); }

nms_group_membership_save(1, array('2', '5', '2'));
expect_foundation(count($writes) === 5, 'Membership replacement did not deduplicate IDs or use a transaction');
expect_foundation($writes[1][1] === array(1), 'Group write not limited to the selected device');
expect_foundation($writes[2][1] === array(2, 1, 7) && $writes[3][1] === array(5, 1, 7), 'Many-to-many memberships changed');
foreach (array(array('999'), array('-1'), '2') as $bad) {
	try { nms_group_membership_save(1, $bad); throw new LogicException('Invalid group accepted'); }
	catch (InvalidArgumentException $expected) {}
}
$writes = array();
nms_relationship_save('1:4:9', '2:0:', 'network');
nms_relationship_save('1:4:9', '3:0:', 'network');
expect_foundation(count($writes) === 2, 'Multiple connections not saved');
expect_foundation($writes[0][1][0] !== $writes[1][1][0], 'Connections to different endpoints collided');
expect_foundation($writes[0][1][2] === 4 && $writes[0][1][3] === '9' && $writes[0][1][4] === 'ifName:Gi0/1', 'Query/index/stable label identity lost');
expect_foundation(strpos($writes[0][0], "'manual'") !== false && strpos($writes[0][0], 'last_seen') === false, 'Manual record claimed discovery evidence');
expect_foundation(strpos($writes[0][0], 'plugin_nms_topology') === false, 'Connectivity coupled to layout');
foreach (array(array('1:0:161', '2:0:', 'network'), array('1:4:999', '2:0:', 'network'),
	array('1:0:', '1:4:9', 'network'), array('1:0:', '2:0:', 'invented')) as $bad) {
	try { nms_relationship_save(...$bad); throw new LogicException('Invalid relationship accepted'); }
	catch (InvalidArgumentException $expected) {}
}
try { nms_relationship_save('99:0:', '2:0:', 'network'); throw new LogicException('Unauthorized endpoint accepted'); }
catch (RuntimeException $expected) {}
nms_relationship_archive(8);
expect_foundation(strpos(end($writes)[0], 'archived_at = NOW()') !== false, 'Archive hard-deleted an edge');

$host = array('id' => 1, 'status' => HOST_UP, 'disabled' => '', 'last_updated' => date('Y-m-d H:i:s'));
$snapshot = nms_device_capabilities($host);
expect_foundation($snapshot['methods'][0]['name'] === 'Native SNMP query', 'Data input method copied from a static catalogue');
$states = array_column($snapshot['capabilities'], 'state', 'capability');
expect_foundation($states['Device availability'] === 'healthy', 'Fresh native availability not recognized');
expect_foundation($states['Syslog ingestion'] === 'integration pending', 'Installed plugin falsely marked collecting');
expect_foundation($states['Threshold integration'] === 'not installed', 'Absent Thold integration invented');
expect_foundation($states['Usage accounting / NetFlow / IPFIX'] === 'unavailable', 'Accounting falsely presented as implemented');
$host['last_updated'] = date('Y-m-d H:i:s', time() - 1800);
$states = array_column(nms_device_capabilities($host)['capabilities'], 'state', 'capability');
expect_foundation($states['Configured device/interface/component measurements'] === 'stale', 'Fresh sample hid stale device availability');
$host['disabled'] = 'on';
expect_foundation(nms_device_capabilities($host)['capabilities'][0]['state'] === 'disabled', 'Disabled monitoring presented as healthy');
echo "Foundation contracts passed: many-to-many groups, independent edges, interface identity, ACLs, recoverable archive and evidence-based capability states.\n";
