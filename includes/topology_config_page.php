<?php
/** Included by the authenticated topology controller after accessible-site validation. */
require_once(__DIR__ . '/topology_config.php');
$page_error = '';
$node_id = isset_request_var('node_id') ? get_filter_request_var('node_id') : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		nms_require_management();
		if (!$selected_site) throw new InvalidArgumentException('Select a Cacti site first.');
		$action = get_nfilter_request_var('nms_action');
		if ($action === 'save_ports') {
			nms_topology_port_save(get_filter_request_var('category_id'), get_nfilter_request_var('device_type'), get_nfilter_request_var('physical_ports'));
		} else {
			$input = array();
			foreach (array('node_id', 'rack_id', 'host_id', 'name', 'node_kind', 'rack_count', 'unit_count', 'start_unit', 'unit_height') as $field) {
				$input[$field] = isset_request_var($field) ? get_nfilter_request_var($field) : '';
			}
			$node_id = nms_topology_config_write($action, $site_id, $input);
		}
		$_SESSION['nms_topology_notice'] = $action === 'unplace_device' ? 'Rack assignment removed. The Cacti device was not deleted.' : 'Topology configuration saved successfully.';
		header('Location: topology.php?tab=' . rawurlencode($topology_tab) . '&site_id=' . $site_id . '&node_id=' . $node_id);
		exit;
	} catch (Throwable $error) { $page_error = $error->getMessage(); }
}
$notice = $_SESSION['nms_topology_notice'] ?? '';
unset($_SESSION['nms_topology_notice']);
$categories = nms_categories();
$profiles = db_fetch_assoc('SELECT p.*, c.name AS category_name FROM plugin_nms_port_profiles p INNER JOIN plugin_nms_categories c ON c.id = p.category_id ORDER BY c.name, p.device_type');
$nodes = db_fetch_assoc_prepared('SELECT n.*, COUNT(r.id) AS rack_count FROM plugin_nms_rack_nodes n LEFT JOIN plugin_nms_racks r ON r.node_id = n.id WHERE n.site_id = ? GROUP BY n.id ORDER BY n.name', array($site_id));
$node = null;
foreach ($nodes as $candidate) if ((int) $candidate['id'] === $node_id) $node = $candidate;
if ($node_id && !$node) { http_response_code(404); die('Select a node at this accessible Cacti site.'); }
if (!$node && $topology_tab === 'racks' && count($nodes)) { $node = $nodes[0]; $node_id = (int) $node['id']; }
$racks = $node ? db_fetch_assoc_prepared('SELECT * FROM plugin_nms_racks WHERE node_id = ? ORDER BY rack_number', array($node_id)) : array();
$visible = nms_visible_host_sql();
$devices = db_fetch_assoc_prepared("SELECT h.id, h.description, h.hostname, h.status, h.disabled, h.last_updated,
	ct.category_id, ct.device_type, c.name AS category_name
	FROM host h LEFT JOIN plugin_nms_device_classification ct ON ct.host_id = h.id
	LEFT JOIN plugin_nms_categories c ON c.id = ct.category_id
	WHERE h.deleted = '' AND h.site_id = ? AND $visible ORDER BY h.description", array($site_id));
$device_by_id = array();
foreach ($devices as $device) $device_by_id[(int) $device['id']] = $device;
// Preserve occupied units even if their device is now inaccessible, deleted, or moved to another site.
// Such slots are anonymous reservations, never a leak of hidden device identity or health.
$placements = $node ? db_fetch_assoc_prepared('SELECT d.* FROM plugin_nms_rack_devices d INNER JOIN plugin_nms_racks r ON r.id = d.rack_id WHERE r.node_id = ? ORDER BY d.start_unit DESC', array($node_id)) : array();
$base_url = 'topology.php?tab=' . rawurlencode($topology_tab) . '&site_id=' . $site_id . '&node_id=' . $node_id;
nms_prepare_page('topology', 'NMS · Topology configuration', 'css/nms-topology-config.css', 'js/nms-topology-config.js');
require(__DIR__ . '/../templates/app_header.php');
/** Output common authenticated POST fields without duplicating business data. */
function nms_topology_form_fields($action) {
	global $nms_csrf_token;
	print '<input type="hidden" name="__csrf_magic" value="' . nms_h($nms_csrf_token) . '"><input type="hidden" name="nms_action" value="' . nms_h($action) . '">';
}
require(__DIR__ . '/../templates/topology_config.php');
require(__DIR__ . '/../templates/app_footer.php');
