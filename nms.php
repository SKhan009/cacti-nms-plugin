<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_setup_database();
nms_sync_all_faults(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	$id = get_filter_request_var('id');
	$user_id = isset($_SESSION['sess_user_id']) ? (int) $_SESSION['sess_user_id'] : 0;

	if ($action === 'acknowledge' && $id > 0) {
		nms_acknowledge_incident($id, $user_id);
		header('Location: nms.php');
		exit;
	}
}

$allowed_states = array('all', 'up', 'fault', 'acknowledged');

$state = isset_request_var('state') ? get_nfilter_request_var('state') : 'all';
$search = isset_request_var('search') ? trim(get_nfilter_request_var('search')) : '';

if (!in_array($state, $allowed_states, true)) $state = 'all';

$where = array("h.deleted = ''", "h.disabled = ''");
$params = array();

if ($state === 'up') {
	$where[] = 'h.status = ' . HOST_UP;
	$where[] = 'i.id IS NULL';
} elseif ($state === 'fault') {
	$where[] = 'i.id IS NOT NULL';
} elseif ($state === 'acknowledged') {
	$where[] = "EXISTS (SELECT 1 FROM plugin_nms_incidents AS ia WHERE ia.host_id = h.id AND ia.source_type = 'device' AND ia.status = 'acknowledged')";
}
if ($search !== '') {
	$where[] = '(h.description LIKE ? OR h.hostname LIKE ? OR h.snmp_sysName LIKE ? OR s.name LIKE ? OR ht.name LIKE ? OR c.name LIKE ?)';
	$term = '%' . $search . '%';
	for ($index = 0; $index < 6; $index++) $params[] = $term;
}

$devices = db_fetch_assoc_prepared("SELECT h.*, s.name AS site_name,
	ht.name AS template_name, c.name AS category_name,
	i.id AS incident_id, i.severity AS incident_severity, i.status AS incident_status,
	i.title AS incident_title, i.message AS incident_message,
	i.first_seen AS incident_first_seen, i.last_seen AS incident_last_seen,
	ua.username AS acknowledged_by_name,
	(SELECT COUNT(*) FROM plugin_nms_incidents AS ic WHERE ic.host_id = h.id
		AND ic.source_type = 'device' AND ic.status IN ('open', 'acknowledged')) AS active_fault_count
	FROM host AS h
	LEFT JOIN sites AS s ON s.id = h.site_id
	LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
	LEFT JOIN plugin_nms_device_categories AS c ON c.id = ct.category_id
	LEFT JOIN plugin_nms_incidents AS i ON i.id = (SELECT ii.id FROM plugin_nms_incidents AS ii
		WHERE ii.host_id = h.id AND ii.source_type = 'device' AND ii.status IN ('open', 'acknowledged')
		ORDER BY FIELD(ii.status, 'open', 'acknowledged'), FIELD(ii.severity, 'critical', 'major', 'warning'), ii.id LIMIT 1)
	LEFT JOIN user_auth AS ua ON ua.id = i.acknowledged_by
	WHERE " . implode(' AND ', $where) . "
	ORDER BY (i.id IS NULL) ASC, h.description ASC", $params);

$counts = db_fetch_row("SELECT COUNT(*) AS total_count,
	SUM(h.status = " . HOST_UP . ") AS up_count,
	SUM(EXISTS (SELECT 1 FROM plugin_nms_incidents AS i WHERE i.host_id = h.id AND i.source_type = 'device' AND i.status IN ('open', 'acknowledged'))) AS fault_count,
	SUM(EXISTS (SELECT 1 FROM plugin_nms_incidents AS i WHERE i.host_id = h.id AND i.source_type = 'device' AND i.status = 'acknowledged')) AS acknowledged_count
	FROM host AS h
	WHERE h.deleted = '' AND h.disabled = ''");

$device_total = (int) $counts['total_count'];
$device_up = (int) $counts['up_count'];
$availability = $device_total > 0 ? round(($device_up / $device_total) * 100, 1) : 100;
$parameters_by_host = array();
$parameter_rows = db_fetch_assoc("SELECT p.host_id, p.parameter_name, p.display_name, p.raw_value, p.last_seen
	FROM plugin_nms_device_parameters AS p
	INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = '' AND h.disabled = ''
	ORDER BY p.host_id, p.last_seen DESC, p.display_name");
foreach ($parameter_rows as $parameter) {
	$host_id = (int) $parameter['host_id'];
	if (!isset($parameters_by_host[$host_id])) $parameters_by_host[$host_id] = array();
	$parameters_by_host[$host_id][] = $parameter;
}
$parameter_count = count($parameter_rows);
$last_sync = db_fetch_cell_prepared("SELECT updated_at FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
$nms_active_module = 'faults';
$nms_page_title = 'NMS · Fault Management';
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell">
	<div class="nms-heading">
		<div>
			<p class="nms-eyebrow">NMS / Fault Management</p>
			<h1>Device readings</h1>
			<p>Live health readings for every enabled Cacti device.</p>
		</div>
		<div class="nms-health">
			<span class="nms-health-dot"></span>
			<div><strong><?php print nms_h($availability); ?>% device availability</strong><small><?php print $device_up; ?> of <?php print $device_total; ?> devices up</small></div>
		</div>
	</div>

	<div class="nms-summary-grid">
		<div class="nms-summary nms-summary-total"><span>Total devices</span><strong><?php print (int) $counts['total_count']; ?></strong><small>Enabled monitoring targets</small></div>
		<div class="nms-summary nms-summary-resolved"><span>Devices up</span><strong><?php print (int) $counts['up_count']; ?></strong><small>Responding normally</small></div>
		<div class="nms-summary nms-summary-critical"><span>Devices with faults</span><strong><?php print (int) $counts['fault_count']; ?></strong><small>Based on configured category rules</small></div>
		<div class="nms-summary nms-summary-total"><span>Device parameters</span><strong><?php print $parameter_count; ?></strong><small>Latest actual values captured from Cacti</small></div>
	</div>

	<section class="nms-panel" id="incident-queue">
		<div class="nms-panel-head">
			<div><h2>Device status</h2><p><?php print count($devices); ?> matching devices · Last synchronized <?php print $last_sync ? nms_h(nms_time_ago($last_sync)) : 'now'; ?></p></div>
			<div class="nms-state-links">
				<a class="<?php print $state === 'all' ? 'selected' : ''; ?>" href="?state=all">All</a>
				<a class="<?php print $state === 'up' ? 'selected' : ''; ?>" href="?state=up">Up</a>
				<a class="<?php print $state === 'fault' ? 'selected' : ''; ?>" href="?state=fault">Faults</a>
				<a class="<?php print $state === 'acknowledged' ? 'selected' : ''; ?>" href="?state=acknowledged">Acknowledged</a>
			</div>
		</div>

		<form class="nms-toolbar" method="get" action="nms.php">
			<input type="hidden" name="state" value="<?php print nms_h($state); ?>">
			<label class="nms-search"><span>⌕</span><input type="search" name="search" value="<?php print nms_h($search); ?>" placeholder="Search device name, address, or site"></label>
			<button type="submit">Apply filters</button>
		</form>

		<div class="nms-table-wrap">
			<table class="nms-table">
				<thead><tr><th>Device</th><th>Address</th><th>Fault status</th><th>Availability</th><th>Latest device parameters</th><th>Last device reading</th><th>Action</th></tr></thead>
				<tbody>
				<?php if (!count($devices)) { ?>
					<tr><td colspan="7" class="nms-empty">No devices match the current filters.</td></tr>
				<?php } ?>
				<?php foreach ($devices as $device) {
					$is_up = (int) $device['status'] === HOST_UP;
					$has_fault = (int) $device['active_fault_count'] > 0;
					$status_name = nms_host_status_name((int) $device['status']);
					$status_class = $has_fault ? $device['incident_status'] : 'up';
					$severity_class = $has_fault ? ($device['incident_severity'] ?: 'warning') : 'healthy';
					$detail = $has_fault ? trim((string) $device['incident_message']) : ($is_up ? 'All enabled fault rules are within their limits' : trim((string) $device['status_last_error']));
					if ($detail === '') $detail = 'Cacti reports device state ' . $status_name;
					$device_parameters = isset($parameters_by_host[(int) $device['id']]) ? $parameters_by_host[(int) $device['id']] : array();
				?>
				<tr>
					<td><div class="nms-incident"><i class="nms-severity <?php print nms_h($severity_class); ?>"></i><div><strong><?php print nms_h($device['description']); ?></strong><small><?php print nms_h(($device['category_name'] ?: 'Unmapped') . ' · ' . ($device['template_name'] ?: 'No template')); ?></small><small><?php print nms_h($detail); ?></small></div></div></td>
					<td><strong><?php print nms_h($device['hostname']); ?></strong><?php if ($device['site_name']) { ?><small><?php print nms_h($device['site_name']); ?></small><?php } ?></td>
					<td><span class="nms-state <?php print nms_h($status_class); ?>"><?php print $has_fault ? nms_h($device['incident_status']) : 'Healthy'; ?></span><small>Cacti device: <?php print nms_h($status_name); ?></small><?php if ($has_fault && (int) $device['active_fault_count'] > 1) { ?><small><?php print (int) $device['active_fault_count']; ?> active faults</small><?php } ?><?php if ($device['acknowledged_by_name']) { ?><small>by <?php print nms_h($device['acknowledged_by_name']); ?></small><?php } ?></td>
					<td><strong><?php print nms_h(number_format((float) $device['availability'], 1)); ?>%</strong><small><?php print (int) $device['failed_polls']; ?> failed</small></td>
					<td><strong><?php print count($device_parameters); ?> readings</strong><?php if (count($device_parameters)) { foreach (array_slice($device_parameters, 0, 2) as $parameter) { ?><small title="<?php print nms_h($parameter['display_name']); ?>"><?php print nms_h($parameter['parameter_name']); ?>: <?php print nms_h($parameter['raw_value']); ?></small><?php } } else { ?><small>Waiting for the next Cacti collection</small><?php } ?></td>
					<td class="nms-nowrap" title="<?php print nms_h($device['last_updated']); ?>"><?php print nms_h(nms_time_ago($device['last_updated'])); ?></td>
					<td>
					<?php if ($device['incident_status'] === 'open') { ?>
						<form method="post" action="nms.php" class="nms-inline-form">
							<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
							<input type="hidden" name="nms_action" value="acknowledge">
							<input type="hidden" name="id" value="<?php print (int) $device['incident_id']; ?>">
							<button type="submit" class="nms-ack">Acknowledge</button>
						</form>
					<?php } elseif (!$has_fault) { ?><span class="nms-ok">Healthy</span><?php } else { ?><span class="nms-muted">Acknowledged</span><?php } ?>
					</td>
				</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	</section>

</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
