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
} elseif ($state === 'fault') {
	$where[] = 'h.status != ' . HOST_UP;
} elseif ($state === 'acknowledged') {
	$where[] = "i.status = 'acknowledged'";
}
if ($search !== '') {
	$where[] = '(h.description LIKE ? OR h.hostname LIKE ? OR h.snmp_sysName LIKE ? OR s.name LIKE ?)';
	$term = '%' . $search . '%';
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
}

$devices = db_fetch_assoc_prepared("SELECT h.*, s.name AS site_name,
		i.id AS incident_id, i.severity AS incident_severity, i.status AS incident_status,
		i.title AS incident_title, i.message AS incident_message,
		i.first_seen AS incident_first_seen, i.last_seen AS incident_last_seen,
		ua.username AS acknowledged_by_name
	FROM host AS h
	LEFT JOIN sites AS s ON s.id = h.site_id
	LEFT JOIN plugin_nms_incidents AS i ON i.host_id = h.id
		AND i.source_type = 'device' AND i.status IN ('open', 'acknowledged')
	LEFT JOIN user_auth AS ua ON ua.id = i.acknowledged_by
	WHERE " . implode(' AND ', $where) . "
	ORDER BY (h.status = " . HOST_UP . ") ASC, h.description ASC
	LIMIT 250", $params);

$counts = db_fetch_row("SELECT COUNT(*) AS total_count,
	SUM(h.status = " . HOST_UP . ") AS up_count,
	SUM(h.status != " . HOST_UP . ") AS fault_count,
	SUM(i.status = 'acknowledged') AS acknowledged_count
	FROM host AS h
	LEFT JOIN plugin_nms_incidents AS i ON i.host_id = h.id
		AND i.source_type = 'device' AND i.status IN ('open', 'acknowledged')
	WHERE h.deleted = '' AND h.disabled = ''");

$device_total = (int) $counts['total_count'];
$device_up = (int) $counts['up_count'];
$availability = $device_total > 0 ? round(($device_up / $device_total) * 100, 1) : 100;
$rrd_by_host = array();
$rrd_summary = array('total' => 0, 'fresh' => 0, 'stale' => 0, 'missing' => 0);
$monitored_host_ids = db_fetch_assoc("SELECT id FROM host WHERE deleted = '' AND disabled = ''");
foreach ($monitored_host_ids as $monitored_host) {
	$host_id = (int) $monitored_host['id'];
	$rrd_by_host[$host_id] = nms_device_rrd_reading($host_id);
	foreach ($rrd_summary as $key => $value) {
		$rrd_summary[$key] += $rrd_by_host[$host_id][$key];
	}
}
$last_sync = db_fetch_cell_prepared("SELECT updated_at FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title>NMS · Fault Management</title>
	<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-v1.1.css?v=1.5.0'); ?>">
</head>
<body class="nms-standalone">
	<header class="nms-app-header">
		<div class="nms-header-start">
			<button class="nms-sidebar-toggle" id="nmsSidebarToggle" type="button" aria-controls="nmsSidebar" aria-expanded="true" aria-label="Collapse sidebar"><span></span><span></span><span></span></button>
			<a class="nms-brand" href="nms.php" aria-label="NMS fault dashboard">
				<span class="nms-brand-mark">N</span>
				<span><strong>NMS</strong><small>Network Management System</small></span>
			</a>
		</div>
		<nav class="nms-primary-nav" aria-label="NMS modules">
			<a class="selected" href="nms.php">Faults</a>
		</nav>
		<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>">
			<span aria-hidden="true">&#8599;</span> Cacti Backend
		</a>
	</header>

	<div class="nms-app-layout">
		<aside class="nms-sidebar" id="nmsSidebar">
			<div class="nms-sidebar-section">
				<p class="nms-sidebar-label">Monitoring</p>
				<a class="nms-sidebar-link selected" href="#incident-queue"><span class="nms-sidebar-icon">●</span><span class="nms-sidebar-copy"><strong>Device readings</strong><small>All monitored devices</small></span></a>
			</div>
			<div class="nms-sidebar-status"><span></span><div class="nms-sidebar-copy"><strong>Live monitoring</strong><small>Reading Cacti devices</small></div></div>
		</aside>

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
		<div class="nms-summary nms-summary-critical"><span>Device faults</span><strong><?php print (int) $counts['fault_count']; ?></strong><small>Need attention</small></div>
		<div class="nms-summary <?php print ($rrd_summary['stale'] + $rrd_summary['missing']) > 0 ? 'nms-summary-ack' : 'nms-summary-resolved'; ?>"><span>Healthy RRD readings</span><strong><?php print (int) $rrd_summary['fresh']; ?>/<?php print (int) $rrd_summary['total']; ?></strong><small><?php print (int) $rrd_summary['stale']; ?> stale · <?php print (int) $rrd_summary['missing']; ?> missing</small></div>
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
				<thead><tr><th>Device</th><th>Address</th><th>Status</th><th>Availability</th><th>Poller response</th><th>Polls</th><th>RRD readings</th><th>Last device reading</th><th>Action</th></tr></thead>
				<tbody>
				<?php if (!count($devices)) { ?>
					<tr><td colspan="9" class="nms-empty">No devices match the current filters.</td></tr>
				<?php } ?>
				<?php foreach ($devices as $device) {
					$is_up = (int) $device['status'] === HOST_UP;
					$status_name = nms_host_status_name((int) $device['status']);
					$status_class = $is_up ? 'up' : strtolower($status_name);
					$severity_class = $is_up ? 'healthy' : ($device['incident_severity'] ?: 'critical');
					$detail = $is_up ? 'Device is responding normally' : trim((string) $device['status_last_error']);
					if ($detail === '') $detail = 'Cacti reports device state ' . $status_name;
					$rrd = isset($rrd_by_host[(int) $device['id']]) ? $rrd_by_host[(int) $device['id']] : nms_device_rrd_reading($device['id']);
				?>
				<tr>
					<td><div class="nms-incident"><i class="nms-severity <?php print nms_h($severity_class); ?>"></i><div><strong><?php print nms_h($device['description']); ?></strong><small><?php print nms_h($detail); ?></small></div></div></td>
					<td><strong><?php print nms_h($device['hostname']); ?></strong><?php if ($device['site_name']) { ?><small><?php print nms_h($device['site_name']); ?></small><?php } ?></td>
					<td><span class="nms-state <?php print nms_h($status_class); ?>"><?php print nms_h($status_name); ?></span><?php if ($device['acknowledged_by_name']) { ?><small>by <?php print nms_h($device['acknowledged_by_name']); ?></small><?php } ?></td>
					<td><strong><?php print nms_h(number_format((float) $device['availability'], 1)); ?>%</strong><small><?php print (int) $device['failed_polls']; ?> failed</small></td>
					<td class="nms-nowrap"><strong><?php print nms_h(number_format((float) $device['cur_time'], 2)); ?> ms</strong><small><?php print nms_h(number_format((float) $device['avg_time'], 2)); ?> ms average</small></td>
					<td><strong><?php print (int) $device['total_polls']; ?></strong><small>Total checks</small></td>
					<td class="nms-nowrap"><strong><?php print (int) $rrd['fresh']; ?> of <?php print (int) $rrd['total']; ?> fresh</strong><small><?php if ($rrd['latest'] > 0) { ?>Updated <?php print nms_h(nms_time_ago(date('Y-m-d H:i:s', $rrd['latest']))); ?><?php } else { ?>No RRD update<?php } ?><?php if ($rrd['stale'] > 0 || $rrd['missing'] > 0) { ?> · <?php print (int) $rrd['stale']; ?> stale, <?php print (int) $rrd['missing']; ?> missing<?php } ?></small></td>
					<td class="nms-nowrap" title="<?php print nms_h($device['last_updated']); ?>"><?php print nms_h(nms_time_ago($device['last_updated'])); ?></td>
					<td>
					<?php if ($device['incident_status'] === 'open') { ?>
						<form method="post" action="nms.php" class="nms-inline-form">
							<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
							<input type="hidden" name="nms_action" value="acknowledge">
							<input type="hidden" name="id" value="<?php print (int) $device['incident_id']; ?>">
							<button type="submit" class="nms-ack">Acknowledge</button>
						</form>
					<?php } elseif ($is_up) { ?><span class="nms-ok">Healthy</span><?php } else { ?><span class="nms-muted">—</span><?php } ?>
					</td>
				</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	</section>

</main>
	</div>
	<script>
	(function() {
		var button = document.getElementById('nmsSidebarToggle');
		if (!button) return;
		button.addEventListener('click', function() {
			var collapsed = document.body.classList.toggle('nms-sidebar-collapsed');
			button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			button.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
		});
	})();
	</script>
</body>
</html>
