<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');
require_once($config['base_path'] . '/plugins/nms/includes/snmprec.php');
require_once($config['base_path'] . '/plugins/nms/includes/template_manager.php');
require_once($config['base_path'] . '/plugins/nms/includes/device_manager.php');

nms_setup_database();

$allowed_tabs = array('inventory', 'add', 'edit', 'import');
$tab = isset_request_var('tab') ? get_nfilter_request_var('tab') : 'inventory';
if (!in_array($tab, $allowed_tabs, true)) $tab = 'inventory';
$page_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	try {
		if ($action === 'change_data_query') {
			$device_id = (int) get_filter_request_var('id');
			$query_id = nms_device_change_data_query($device_id, get_filter_request_var('snmp_query_id'), get_filter_request_var('reindex_method'));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&data_query_changed=' . $query_id . '#data-queries');
			exit;
		}

		if ($action === 'reload_data_query') {
			$device_id = (int) get_filter_request_var('id');
			$query_id = nms_device_reload_data_query($device_id, get_filter_request_var('snmp_query_id'));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&data_query_reloaded=' . $query_id . '#data-queries');
			exit;
		}

		if ($action === 'remove_data_query') {
			$device_id = (int) get_filter_request_var('id');
			$query_id = nms_device_remove_data_query($device_id, get_filter_request_var('snmp_query_id'));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&data_query_removed=' . $query_id . '#data-queries');
			exit;
		}

		if ($action === 'add_graph_template') {
			$device_id = (int) get_filter_request_var('id');
			$template_id = nms_device_add_graph_template($device_id, get_filter_request_var('graph_template_id'));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&graph_template_added=' . $template_id . '#graph-templates');
			exit;
		}

		if ($action === 'add_data_query') {
			$device_id = (int) get_filter_request_var('id');
			$query_id = nms_device_add_data_query($device_id, get_filter_request_var('snmp_query_id'), get_filter_request_var('reindex_method'));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&data_query_added=' . $query_id . '#data-queries');
			exit;
		}

		if ($action === 'add_device') {
			$device_id = nms_device_create(array(
				'description' => get_nfilter_request_var('description'),
				'hostname' => get_nfilter_request_var('hostname'),
				'host_template_id' => get_filter_request_var('host_template_id'),
				'site_id' => get_filter_request_var('site_id'),
				'poller_id' => get_filter_request_var('poller_id'),
				'snmp_version' => get_filter_request_var('snmp_version'),
				'snmp_community' => get_nfilter_request_var('snmp_community'),
				'snmp_port' => get_filter_request_var('snmp_port'),
				'snmp_timeout' => get_filter_request_var('snmp_timeout'),
				'snmp_username' => get_nfilter_request_var('snmp_username'),
				'snmp_password' => get_nfilter_request_var('snmp_password'),
				'snmp_auth_protocol' => get_nfilter_request_var('snmp_auth_protocol'),
				'snmp_priv_passphrase' => get_nfilter_request_var('snmp_priv_passphrase'),
				'snmp_priv_protocol' => get_nfilter_request_var('snmp_priv_protocol'),
				'snmp_context' => get_nfilter_request_var('snmp_context'),
				'snmp_engine_id' => get_nfilter_request_var('snmp_engine_id'),
				'availability_method' => get_filter_request_var('availability_method'),
				'ping_method' => get_filter_request_var('ping_method'),
				'ping_port' => get_filter_request_var('ping_port'),
				'ping_timeout' => get_filter_request_var('ping_timeout'),
				'ping_retries' => get_filter_request_var('ping_retries'),
				'max_oids' => get_filter_request_var('max_oids'),
				'device_threads' => get_filter_request_var('device_threads'),
				'notes' => get_nfilter_request_var('notes'),
				'location' => get_nfilter_request_var('location'),
				'external_id' => get_nfilter_request_var('external_id'),
				'proxy' => isset_request_var('proxy'),
				'disabled' => isset_request_var('disabled')
			));
			header('Location: devices.php?tab=inventory&device_created=' . $device_id);
			 exit;
		}

		if ($action === 'update_device') {
			$device_id = nms_device_update(get_filter_request_var('id'), array(
				'description' => get_nfilter_request_var('description'),
				'hostname' => get_nfilter_request_var('hostname'),
				'host_template_id' => get_filter_request_var('host_template_id'),
				'site_id' => get_filter_request_var('site_id'),
				'poller_id' => get_filter_request_var('poller_id'),
				'snmp_version' => get_filter_request_var('snmp_version'),
				'snmp_community' => get_nfilter_request_var('snmp_community'),
				'snmp_port' => get_filter_request_var('snmp_port'),
				'snmp_timeout' => get_filter_request_var('snmp_timeout'),
				'snmp_username' => get_nfilter_request_var('snmp_username'),
				'snmp_password' => get_nfilter_request_var('snmp_password'),
				'snmp_auth_protocol' => get_nfilter_request_var('snmp_auth_protocol'),
				'snmp_priv_passphrase' => get_nfilter_request_var('snmp_priv_passphrase'),
				'snmp_priv_protocol' => get_nfilter_request_var('snmp_priv_protocol'),
				'snmp_context' => get_nfilter_request_var('snmp_context'),
				'snmp_engine_id' => get_nfilter_request_var('snmp_engine_id'),
				'availability_method' => get_filter_request_var('availability_method'),
				'ping_method' => get_filter_request_var('ping_method'),
				'ping_port' => get_filter_request_var('ping_port'),
				'ping_timeout' => get_filter_request_var('ping_timeout'),
				'ping_retries' => get_filter_request_var('ping_retries'),
				'max_oids' => get_filter_request_var('max_oids'),
				'device_threads' => get_filter_request_var('device_threads'),
				'notes' => get_nfilter_request_var('notes'),
				'location' => get_nfilter_request_var('location'),
				'external_id' => get_nfilter_request_var('external_id'),
				'proxy' => isset_request_var('proxy'),
				'disabled' => isset_request_var('disabled')
			));
			header('Location: devices.php?tab=edit&id=' . $device_id . '&device_updated=1');
			exit;
		}

		if ($action === 'import_snmprec') {
			if (!isset($_FILES['snmprec_file']) || $_FILES['snmprec_file']['error'] !== UPLOAD_ERR_OK) {
				throw new InvalidArgumentException('Select a readable .snmprec file.');
			}
			$original_name = basename((string) $_FILES['snmprec_file']['name']);
			if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'snmprec') {
				throw new InvalidArgumentException('Only .snmprec files can be imported.');
			}
			$content = file_get_contents($_FILES['snmprec_file']['tmp_name']);
			if ($content === false) throw new RuntimeException('NMS could not read the uploaded file.');
			$records = nms_snmprec_parse($content);
			$community = nms_snmprec_community(get_nfilter_request_var('community'));
			$result = nms_template_import($original_name, $community,
				nms_template_clean_name(get_nfilter_request_var('template_name')),
				get_filter_request_var('category_id'), $content, $records,
				isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0);
			header('Location: devices.php?tab=import&imported=' . (int) $result['import_id']);
			exit;
		}
	} catch (Throwable $exception) {
		$page_error = $exception->getMessage();
		$tab = $action === 'import_snmprec' ? 'import' : (in_array($action, array('update_device', 'add_graph_template', 'add_data_query', 'change_data_query', 'reload_data_query', 'remove_data_query'), true) ? 'edit' : 'add');
	}
}

$devices = db_fetch_assoc("SELECT h.id, h.description, h.hostname, h.status, h.disabled, h.availability,
	h.cur_time, h.avg_time, h.total_polls, h.failed_polls, h.status_last_error, h.last_updated,
	h.snmp_version, h.snmp_port, h.snmp_sysName, h.snmp_sysLocation, h.snmp_sysDescr,
	ht.name AS template_name, s.name AS site_name, p.name AS poller_name,
	c.name AS category_name,
	(SELECT COUNT(*) FROM graph_local AS gl WHERE gl.host_id = h.id) AS graph_count,
	(SELECT COUNT(*) FROM data_local AS dl WHERE dl.host_id = h.id) AS data_source_count,
	(SELECT COUNT(*) FROM poller_item AS pi WHERE pi.host_id = h.id) AS poller_item_count
	FROM host AS h
	LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
	LEFT JOIN sites AS s ON s.id = h.site_id
	LEFT JOIN poller AS p ON p.id = h.poller_id
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
	LEFT JOIN plugin_nms_device_categories AS c ON c.id = ct.category_id
	WHERE h.deleted = '' ORDER BY h.description");

$host_templates = db_fetch_assoc('SELECT id, name FROM host_template ORDER BY name');
$sites = db_fetch_assoc('SELECT id, name FROM sites ORDER BY name');
$pollers = db_fetch_assoc('SELECT id, name FROM poller ORDER BY id');
$categories = db_fetch_assoc('SELECT id, name FROM plugin_nms_device_categories ORDER BY sort_order, name');
$imports = db_fetch_assoc("SELECT i.*, c.name AS category_name, ht.name AS host_template_name,
	u.username AS uploaded_by_name FROM plugin_nms_snmprec_imports AS i
	LEFT JOIN plugin_nms_device_categories AS c ON c.id = i.category_id
	LEFT JOIN host_template AS ht ON ht.id = i.host_template_id
	LEFT JOIN user_auth AS u ON u.id = i.uploaded_by ORDER BY i.id DESC LIMIT 50");

$cacti_device_defaults = array(
	'snmp_version' => (int) read_config_option('snmp_version'),
	'snmp_community' => (string) read_config_option('snmp_community'),
	'snmp_port' => (int) read_config_option('snmp_port'),
	'snmp_timeout' => (int) read_config_option('snmp_timeout'),
	'snmp_username' => (string) read_config_option('snmp_username'),
	'snmp_password' => (string) read_config_option('snmp_password'),
	'snmp_auth_protocol' => (string) read_config_option('snmp_auth_protocol'),
	'snmp_priv_protocol' => (string) read_config_option('snmp_priv_protocol'),
	'snmp_priv_passphrase' => (string) read_config_option('snmp_priv_passphrase'),
	'availability_method' => (int) read_config_option('availability_method'),
	'ping_method' => (int) read_config_option('ping_method'),
	'ping_port' => (int) read_config_option('ping_port'),
	'ping_timeout' => (int) read_config_option('ping_timeout'),
	'ping_retries' => (int) read_config_option('ping_retries'),
	'max_oids' => (int) read_config_option('max_get_size'),
	'device_threads' => (int) read_config_option('device_threads')
);

$edit_device = array();
$device_graph_templates = array();
$device_data_queries = array();
$available_graph_templates = array();
$available_data_queries = array();
if ($tab === 'edit') {
	$edit_device_id = isset_request_var('id') ? (int) get_filter_request_var('id') : 0;
	$edit_device = db_fetch_row_prepared("SELECT h.*, ht.name AS template_name, p.name AS poller_name,
		s.name AS site_name, (SELECT COUNT(*) FROM graph_local WHERE host_id = h.id) AS graph_count,
		(SELECT COUNT(*) FROM data_local WHERE host_id = h.id) AS data_source_count,
		(SELECT COUNT(*) FROM poller_item WHERE host_id = h.id) AS poller_item_count
		FROM host AS h LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
		LEFT JOIN poller AS p ON p.id = h.poller_id LEFT JOIN sites AS s ON s.id = h.site_id
		WHERE h.id = ? AND h.deleted = ''", array($edit_device_id));
	if (!$edit_device) {
		$page_error = 'The selected Cacti device was not found.';
		$tab = 'inventory';
	} else {
		$device_graph_templates = db_fetch_assoc_prepared("SELECT gt.id, gt.name,
			MAX(gl.id) AS graph_local_id, COUNT(DISTINCT gl.id) AS graph_count
			FROM host_graph AS hg INNER JOIN graph_templates AS gt ON gt.id = hg.graph_template_id
			LEFT JOIN graph_local AS gl ON gl.graph_template_id = gt.id AND gl.host_id = hg.host_id
			WHERE hg.host_id = ? GROUP BY gt.id, gt.name ORDER BY gt.name", array($edit_device_id));
		$device_data_queries = db_fetch_assoc_prepared("SELECT sq.id, sq.name, hsq.reindex_method,
			COUNT(hsc.snmp_index) AS item_count, COUNT(DISTINCT hsc.snmp_index) AS row_count
			FROM host_snmp_query AS hsq INNER JOIN snmp_query AS sq ON sq.id = hsq.snmp_query_id
			LEFT JOIN host_snmp_cache AS hsc ON hsc.host_id = hsq.host_id AND hsc.snmp_query_id = hsq.snmp_query_id
			WHERE hsq.host_id = ? GROUP BY sq.id, sq.name, hsq.reindex_method ORDER BY sq.name", array($edit_device_id));
		$available_graph_templates = db_fetch_assoc_prepared("SELECT DISTINCT gt.id, gt.name
			FROM graph_templates AS gt LEFT JOIN snmp_query_graph AS sqg ON sqg.graph_template_id = gt.id
			INNER JOIN graph_templates_item AS gti ON gti.graph_template_id = gt.id
			INNER JOIN data_template_rrd AS dtr ON gti.task_item_id = dtr.id
			INNER JOIN data_template_data AS dtd ON dtd.data_template_id = dtr.data_template_id
			WHERE sqg.name IS NULL AND gti.local_graph_id = 0 AND dtr.local_data_id = 0
			AND gt.id NOT IN (SELECT graph_template_id FROM host_graph WHERE host_id = ?)
			ORDER BY gt.name", array($edit_device_id));
		$data_query_filter = (int) $edit_device['snmp_version'] === 0 ? ' AND sq.data_input_id != 2' : '';
		$available_data_queries = db_fetch_assoc_prepared("SELECT sq.id, sq.name FROM snmp_query AS sq
			WHERE sq.id NOT IN (SELECT snmp_query_id FROM host_snmp_query WHERE host_id = ?)$data_query_filter
			ORDER BY sq.name", array($edit_device_id));
	}
}

$device_counts = db_fetch_row("SELECT COUNT(*) AS total,
	SUM(disabled = '') AS enabled, SUM(status = " . HOST_UP . " AND disabled = '') AS up,
	SUM(status = " . HOST_DOWN . " AND disabled = '') AS down FROM host WHERE deleted = ''");
$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
$nms_active_module = 'devices';
$nms_page_title = 'NMS · Device Management';
$nms_extra_css = 'css/nms-devices.css?v=1.1.0';
$nms_extra_js = 'js/nms-devices.js?v=1.1.0';
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell nms-devices-shell">
	<div class="nms-heading">
		<div><p class="nms-eyebrow">NMS / Device Management</p><h1>Devices and SNMP records</h1><p>Manage real Cacti devices and build templates from validated SNMPSim records.</p></div>
		<div class="nms-health"><span class="nms-health-dot"></span><div><strong><?php print (int) $device_counts['up']; ?> devices up</strong><small><?php print (int) $device_counts['total']; ?> total in Cacti</small></div></div>
	</div>

	<?php if ($page_error !== '') { ?><div class="nms-form-message error"><strong>Could not complete the request</strong><span><?php print nms_h($page_error); ?></span></div><?php } ?>
	<?php if (isset_request_var('device_created')) { ?><div class="nms-form-message success"><strong>Device created</strong><span>Cacti device <?php print (int) get_filter_request_var('device_created'); ?> is ready for polling and graph selection.</span></div><?php } ?>
	<?php if (isset_request_var('device_updated')) { ?><div class="nms-form-message success"><strong>Device updated</strong><span>The live Cacti device settings were saved successfully.</span></div><?php } ?>
	<?php if (isset_request_var('graph_template_added')) { ?><div class="nms-form-message success"><strong>Graph template added</strong><span>The Cacti graph-template association is now active for this device.</span></div><?php } ?>
	<?php if (isset_request_var('data_query_added')) { ?><div class="nms-form-message success"><strong>Data query added</strong><span>The Cacti data query is now associated with this device.</span></div><?php } ?>
	<?php if (isset_request_var('data_query_changed')) { ?><div class="nms-form-message success"><strong>Re-index method updated</strong><span>The Cacti data-query setting was saved.</span></div><?php } ?>
	<?php if (isset_request_var('data_query_reloaded')) { ?><div class="nms-form-message success"><strong>Data query reloaded</strong><span>Cacti refreshed the indexed data for this device.</span></div><?php } ?>
	<?php if (isset_request_var('data_query_removed')) { ?><div class="nms-form-message success"><strong>Data query removed</strong><span>The association and its indexed cache were removed from this device.</span></div><?php } ?>
	<?php if (isset_request_var('imported')) { ?><div class="nms-form-message success"><strong>SNMP record imported</strong><span>The simulator file and Cacti templates were created successfully.</span></div><?php } ?>

	<div class="nms-page-tabs" role="tablist" aria-label="Device management views">
		<a class="<?php print $tab === 'inventory' ? 'selected' : ''; ?>" href="?tab=inventory">Device dashboard</a>
		<a class="<?php print $tab === 'add' ? 'selected' : ''; ?>" href="?tab=add">Add device</a>
		<?php if ($tab === 'edit') { ?><a class="selected" href="?tab=edit&id=<?php print (int) $edit_device['id']; ?>">Edit device</a><?php } ?>
		<a class="<?php print $tab === 'import' ? 'selected' : ''; ?>" href="?tab=import">Upload SNMP record</a>
	</div>

	<?php require($config['base_path'] . '/plugins/nms/templates/devices/' . $tab . '.php'); ?>
</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
