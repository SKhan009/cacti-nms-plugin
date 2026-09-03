<?php
/**
 * @file nms.php
 * Device readings dashboard: combine enabled Cacti hosts with active incidents, poller samples, and text inventory.
 * Acknowledgement and filtering are handled here; fault evaluation and freshness rules live in shared helpers.
 */

require(__DIR__ . '/../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

// Require a completed lifecycle upgrade; page views never apply schema migrations.
nms_require_database();
// Cacti's post-poll hook owns incident evaluation; a read-only visit only displays stored evidence.
$incident_sources_sql = nms_monitored_incident_sources_sql();
$active_statuses_sql = nms_active_incident_statuses_sql();
$severity_order_sql = nms_severity_order_sql('ii.severity');

// Acknowledgement changes incident workflow state, not the device's actual fault condition.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	$id = get_filter_request_var('id');
	$user_id = nms_current_user_id();

	if ($action === 'acknowledge' && $id > 0) {
		try {
			nms_require_management();
			$incident_host = db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_incidents WHERE id = ?', array($id));
			nms_require_device_access($incident_host);
		} catch (RuntimeException $error) {
			http_response_code(403);
			die(nms_h($error->getMessage()));
		}
		nms_acknowledge_incident($id, $user_id);
		header('Location: nms.php');
		exit;
	}
}

// Whitelist the view filter; bind search text as query parameters rather than interpolating it into SQL.
$allowed_states = array('all', 'up', 'fault', 'acknowledged');

$state = isset_request_var('state') ? get_nfilter_request_var('state') : 'all';
$search = isset_request_var('search') ? trim(get_nfilter_request_var('search')) : '';

if (!in_array($state, $allowed_states, true)) $state = 'all';

$visible_hosts = nms_visible_host_sql();
$where = array("h.deleted = ''", "h.disabled = ''", $visible_hosts);
$params = array();

if ($state === 'up') {
	$where[] = 'h.status = ' . HOST_UP;
	$where[] = 'h.last_updated BETWEEN ? AND ?';
	$params[] = nms_parameter_fresh_after();
	$params[] = nms_now();
	$where[] = 'i.id IS NULL';
} elseif ($state === 'fault') {
	$where[] = 'i.id IS NOT NULL';
} elseif ($state === 'acknowledged') {
	$where[] = "EXISTS (SELECT 1 FROM plugin_nms_incidents AS ia WHERE ia.host_id = h.id AND ia.source_type IN ($incident_sources_sql) AND ia.status = 'acknowledged')";
}
if ($search !== '') {
	$where[] = '(h.description LIKE ? OR h.hostname LIKE ? OR h.snmp_sysName LIKE ? OR s.name LIKE ? OR ht.name LIKE ? OR c.name LIKE ?)';
	$term = '%' . $search . '%';
	for ($index = 0; $index < 6; $index++) $params[] = $term;
}

// Keep every matching enabled core device, attaching one prioritized active incident for its summary row.
$devices = db_fetch_assoc_prepared("SELECT h.*, s.name AS site_name,
	ht.name AS template_name, c.name AS category_name,
	(SELECT COUNT(*) FROM plugin_nms_fault_rules AS r WHERE r.category_id = c.id AND r.enabled = 'on') AS configured_rule_count,
	i.id AS incident_id, i.severity AS incident_severity, i.status AS incident_status,
	i.title AS incident_title, i.message AS incident_message,
	i.first_seen AS incident_first_seen, i.last_seen AS incident_last_seen,
	ua.username AS acknowledged_by_name,
	(SELECT COUNT(*) FROM plugin_nms_incidents AS ic WHERE ic.host_id = h.id
		AND ic.source_type IN ($incident_sources_sql) AND ic.status IN ($active_statuses_sql)) AS active_fault_count
	FROM host AS h
	LEFT JOIN sites AS s ON s.id = h.site_id
	LEFT JOIN host_template AS ht ON ht.id = h.host_template_id
	LEFT JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	LEFT JOIN plugin_nms_categories AS c ON c.id = ct.category_id
	LEFT JOIN plugin_nms_incidents AS i ON i.id = (SELECT ii.id FROM plugin_nms_incidents AS ii
		WHERE ii.host_id = h.id AND ii.source_type IN ($incident_sources_sql) AND ii.status IN ($active_statuses_sql)
		ORDER BY FIELD(ii.status, $active_statuses_sql), $severity_order_sql, ii.id LIMIT 1)
	LEFT JOIN user_auth AS ua ON ua.id = i.acknowledged_by
	WHERE " . implode(' AND ', $where) . "
	ORDER BY (i.id IS NULL) ASC, h.description ASC", $params);

// Summary cards describe all enabled devices, independently of the current table search/filter.
$counts = db_fetch_row_prepared("SELECT COUNT(*) AS total_count,
	SUM(h.status = " . HOST_UP . " AND h.last_updated BETWEEN ? AND ?) AS up_count,
	SUM(EXISTS (SELECT 1 FROM plugin_nms_incidents AS i WHERE i.host_id = h.id AND i.source_type IN ($incident_sources_sql) AND i.status IN ($active_statuses_sql))) AS fault_count,
	SUM(EXISTS (SELECT 1 FROM plugin_nms_incidents AS i WHERE i.host_id = h.id AND i.source_type IN ($incident_sources_sql) AND i.status = 'acknowledged')) AS acknowledged_count
	FROM host AS h
	WHERE h.deleted = '' AND h.disabled = '' AND $visible_hosts", array(nms_parameter_fresh_after(), nms_now()));

$device_total = (int) $counts['total_count'];
$device_up = (int) $counts['up_count'];
$availability = $device_total > 0 ? round(($device_up / $device_total) * 100, 1) . '% currently up' : 'No enabled devices';
// Group retained poller samples by host; rendering still checks freshness before presenting them as current.
$parameters_by_host = array();
$parameter_rows = db_fetch_assoc("SELECT p.host_id, p.parameter_name, p.display_name, p.raw_value, p.last_seen,
	h.status AS host_status, h.last_updated AS host_last_updated
	FROM plugin_nms_device_parameters AS p
	INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = '' AND h.disabled = ''
	WHERE $visible_hosts
	ORDER BY p.host_id, p.last_seen DESC, p.display_name");
foreach ($parameter_rows as $parameter) {
	$host_id = (int) $parameter['host_id'];
	if (!isset($parameters_by_host[$host_id])) $parameters_by_host[$host_id] = array();
	$parameters_by_host[$host_id][] = $parameter;
}
// Text identity such as serial numbers is stored separately because RRD data sources hold numeric readings.
$inventory_by_host = array();
$inventory_rows = db_fetch_assoc("SELECT di.* FROM plugin_nms_device_inventory AS di
	INNER JOIN host AS h ON h.id = di.host_id AND h.deleted = '' AND h.disabled = ''
	WHERE $visible_hosts
	ORDER BY di.host_id, di.display_name");
foreach ($inventory_rows as $inventory) {
	$inventory_by_host[(int) $inventory['host_id']][$inventory['inventory_key']] = $inventory;
}
// Count only usable current values, not stale samples or readings from unavailable hosts.
$parameter_count = 0;
foreach ($parameter_rows as $parameter) if (nms_parameter_has_current_value($parameter)) $parameter_count++;
$last_sync = db_fetch_cell_prepared("SELECT updated_at FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
nms_prepare_page('faults', 'NMS · Fault Management');
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
			<div><strong><?php print nms_h($availability); ?></strong><small><?php print $device_up; ?> of <?php print $device_total; ?> devices with a current Up state</small></div>
		</div>
	</div>

	<div class="nms-summary-grid">
		<div class="nms-summary nms-summary-total"><span>Total devices</span><strong><?php print (int) $counts['total_count']; ?></strong><small>Enabled monitoring targets</small></div>
		<div class="nms-summary nms-summary-resolved"><span>Devices up</span><strong><?php print (int) $counts['up_count']; ?></strong><small>Responding normally</small></div>
		<div class="nms-summary nms-summary-critical"><span>Devices with faults</span><strong><?php print (int) $counts['fault_count']; ?></strong><small>Based on configured category rules</small></div>
		<div class="nms-summary nms-summary-total"><span>Current device readings</span><strong><?php print $parameter_count; ?></strong><small>Fresh values returned by the Cacti poller</small></div>
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

		<div class="nms-table-wrap nms-fit-table-wrap">
			<table class="nms-table nms-device-status-table">
				<thead><tr><th>Device</th><th>Address</th><th>Fault status</th><th>Availability</th><th>Latest device parameters</th><th>Last device reading</th><th>Action</th></tr></thead>
				<tbody>
				<?php if (!count($devices)) { ?>
					<tr><td colspan="7" class="nms-empty">No devices match the current filters.</td></tr>
				<?php } ?>
				<?php foreach ($devices as $device) {
					$is_up = nms_device_status_name($device) === 'Up';
					$has_fault = (int) $device['active_fault_count'] > 0;
					$status_name = nms_device_status_name($device);
					$core_fresh = nms_parameter_is_fresh($device['last_updated']);
					$status_label = $has_fault ? $device['incident_status'] : (!$core_fresh ? 'Stale' : (!$is_up ? 'Unavailable' : ((int) $device['configured_rule_count'] > 0 ? 'No active faults' : 'Unconfigured')));
					$status_class = $has_fault ? $device['incident_status'] : ($is_up && $core_fresh ? 'up' : 'unknown');
					$severity_class = $has_fault ? $device['incident_severity'] : ($is_up && $core_fresh ? 'healthy' : 'warning');
					$detail = $has_fault ? trim((string) $device['incident_message']) : ((int) $device['configured_rule_count'] === 0 ? 'No enabled equipment-category rules. Cacti collection state is shown separately.' : 'No active rule incident; missing or stale measurements are not proof of recovery.');
					if ($detail === '') $detail = 'Cacti reports device state ' . $status_name;
					$device_parameters = isset($parameters_by_host[(int) $device['id']]) ? $parameters_by_host[(int) $device['id']] : array();
					$fresh_parameters = array();
					foreach ($device_parameters as $parameter) if ($is_up && nms_parameter_has_current_value($parameter)) $fresh_parameters[] = $parameter;
					$device_inventory = isset($inventory_by_host[(int) $device['id']]) ? $inventory_by_host[(int) $device['id']] : array();
				?>
				<tr>
					<td><div class="nms-incident"><i class="nms-severity <?php print nms_h($severity_class); ?>"></i><div><strong><?php print nms_h($device['description']); ?></strong><small><?php print nms_h(($device['category_name'] ?: 'Unmapped') . ' · ' . ($device['template_name'] ?: 'No template')); ?></small><small><?php print nms_h($detail); ?></small></div></div></td>
					<td><strong><?php print nms_h($device['hostname']); ?></strong><?php if ($device['site_name']) { ?><small><?php print nms_h($device['site_name']); ?></small><?php } ?></td>
					<td><span class="nms-state <?php print nms_h($status_class); ?>"><?php print nms_h($status_label); ?></span><small>Cacti device: <?php print nms_h($status_name); ?></small><?php if ($has_fault && (int) $device['active_fault_count'] > 1) { ?><small><?php print (int) $device['active_fault_count']; ?> active faults</small><?php } ?><?php if ($device['acknowledged_by_name']) { ?><small>by <?php print nms_h($device['acknowledged_by_name']); ?></small><?php } ?></td>
					<td><strong><?php print nms_h(number_format((float) $device['availability'], 1)); ?>%</strong><small><?php print (int) $device['failed_polls']; ?> failed</small></td>
					<td><strong><?php print count($fresh_parameters); ?> current readings</strong><?php if (count($fresh_parameters) && $is_up) { foreach (array_slice($fresh_parameters, 0, 2) as $parameter) { ?><small title="<?php print nms_h($parameter['display_name']); ?>"><?php print nms_h($parameter['parameter_name']); ?>: <?php print nms_h($parameter['raw_value']); ?></small><?php } } elseif (!$is_up) { ?><small>No current value — Cacti reports <?php print nms_h($status_name); ?></small><?php } elseif (count($device_parameters)) { ?><small>No current value — last Cacti readings are stale</small><?php } else { ?><small>Waiting for the next Cacti poll</small><?php } ?><?php if (isset($device_inventory['serial_number'])) { $serial = $device_inventory['serial_number']; $serial['status'] = nms_serial_observation_state($serial['status'], $serial['last_success'], $device['status'], $device['disabled'], $device['last_updated']); if ($serial['status'] === 'failed') { ?><small>Serial: live SNMP read failed</small><?php } elseif (!in_array($serial['status'], array('ok', 'changed'), true)) { ?><small>Serial: <?php print nms_h($serial['status']); ?> — no current value</small><?php } else { ?><small>Serial: <?php print nms_h($serial['observed_value']); ?><?php print $serial['status'] === 'changed' ? ' (changed)' : ''; ?></small><?php } } ?></td>
					<td class="nms-nowrap" title="<?php print nms_h($device['last_updated']); ?>"><?php print nms_h(nms_time_ago($device['last_updated'])); ?></td>
					<td>
					<?php if ($device['incident_status'] === 'open') { ?>
						<form method="post" action="nms.php" class="nms-inline-form">
							<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
							<input type="hidden" name="nms_action" value="acknowledge">
							<input type="hidden" name="id" value="<?php print (int) $device['incident_id']; ?>">
							<button type="submit" class="nms-ack">Acknowledge</button>
						</form>
					<?php } elseif (!$has_fault) { ?><span class="nms-muted"><?php print nms_h($status_label); ?></span><?php } else { ?><span class="nms-muted">Acknowledged</span><?php } ?>
					</td>
				</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	</section>

</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
