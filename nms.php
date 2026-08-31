<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_setup_database();
nms_sync_all_faults(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	$id = get_filter_request_var('id');
	$user_id = isset($_SESSION[SESS_USER_ID]) ? (int) $_SESSION[SESS_USER_ID] : 0;

	if ($action === 'acknowledge' && $id > 0) {
		nms_acknowledge_incident($id, $user_id);
		header('Location: nms.php');
		exit;
	}
}

$allowed_states = array('active', 'open', 'acknowledged', 'resolved', 'all');
$allowed_severities = array('all', 'critical', 'major', 'warning');
$allowed_sources = array('all', 'device', 'poller', 'rrd', 'output');

$state = isset_request_var('state') ? get_nfilter_request_var('state') : 'active';
$severity = isset_request_var('severity') ? get_nfilter_request_var('severity') : 'all';
$source = isset_request_var('source') ? get_nfilter_request_var('source') : 'all';
$search = isset_request_var('search') ? trim(get_nfilter_request_var('search')) : '';

if (!in_array($state, $allowed_states, true)) $state = 'active';
if (!in_array($severity, $allowed_severities, true)) $severity = 'all';
if (!in_array($source, $allowed_sources, true)) $source = 'all';

$where = array('1=1');
$params = array();

if ($state === 'active') {
	$where[] = "i.status IN ('open', 'acknowledged')";
} elseif ($state !== 'all') {
	$where[] = 'i.status = ?';
	$params[] = $state;
}
if ($severity !== 'all') {
	$where[] = 'i.severity = ?';
	$params[] = $severity;
}
if ($source !== 'all') {
	$where[] = 'i.source_type = ?';
	$params[] = $source;
}
if ($search !== '') {
	$where[] = '(i.title LIKE ? OR i.message LIKE ? OR h.description LIKE ? OR p.name LIKE ?)';
	$term = '%' . $search . '%';
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
}

$incidents = db_fetch_assoc_prepared("SELECT i.*, h.description AS host_description,
		h.hostname AS host_hostname, p.name AS poller_name, ua.username AS acknowledged_by_name,
		dtd.name_cache AS data_source_name
	FROM plugin_nms_incidents AS i
	LEFT JOIN host AS h ON h.id = i.host_id
	LEFT JOIN poller AS p ON p.id = i.poller_id
	LEFT JOIN user_auth AS ua ON ua.id = i.acknowledged_by
	LEFT JOIN data_template_data AS dtd ON dtd.local_data_id = i.local_data_id
	WHERE " . implode(' AND ', $where) . "
	ORDER BY FIELD(i.status, 'open', 'acknowledged', 'resolved'),
		FIELD(i.severity, 'critical', 'major', 'warning'), i.first_seen DESC
	LIMIT 250", $params);

$counts = db_fetch_row("SELECT
	SUM(status IN ('open', 'acknowledged')) AS active_count,
	SUM(status IN ('open', 'acknowledged') AND severity = 'critical') AS critical_count,
	SUM(status = 'acknowledged') AS acknowledged_count,
	SUM(status = 'resolved' AND resolved_at >= CURDATE()) AS resolved_today
	FROM plugin_nms_incidents");

$device_total = (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted = '' AND disabled = ''");
$device_up = (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted = '' AND disabled = '' AND status = " . HOST_UP);
$availability = $device_total > 0 ? round(($device_up / $device_total) * 100, 1) : 100;
$last_sync = db_fetch_cell_prepared("SELECT updated_at FROM plugin_nms_meta WHERE meta_key = 'last_sync'", array());
$log_events = nms_recent_core_log_events(12);
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
	<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-v1.1.css?v=1.1.0'); ?>">
</head>
<body class="nms-standalone">
	<header class="nms-app-header">
		<a class="nms-brand" href="nms.php" aria-label="NMS fault dashboard">
			<span class="nms-brand-mark">N</span>
			<span><strong>NMS</strong><small>Network Management System</small></span>
		</a>
		<nav class="nms-primary-nav" aria-label="NMS modules">
			<a class="selected" href="nms.php">Faults</a>
		</nav>
		<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>">
			<span aria-hidden="true">&#8599;</span> Cacti Backend
		</a>
	</header>

<div class="nms-shell">
	<div class="nms-heading">
		<div>
			<p class="nms-eyebrow">NMS / Fault Management</p>
			<h1>Active incidents</h1>
			<p>Live faults from Cacti devices, collectors, RRD data sources, poller output, and core logs.</p>
		</div>
		<div class="nms-health">
			<span class="nms-health-dot"></span>
			<div><strong><?php print nms_h($availability); ?>% device availability</strong><small><?php print $device_up; ?> of <?php print $device_total; ?> devices up</small></div>
		</div>
	</div>

	<div class="nms-summary-grid">
		<div class="nms-summary nms-summary-open"><span>Open incidents</span><strong><?php print (int) $counts['active_count']; ?></strong><small>All unresolved fault sources</small></div>
		<div class="nms-summary nms-summary-critical"><span>Critical</span><strong><?php print (int) $counts['critical_count']; ?></strong><small>Immediate attention required</small></div>
		<div class="nms-summary nms-summary-ack"><span>Acknowledged</span><strong><?php print (int) $counts['acknowledged_count']; ?></strong><small>Being handled by operators</small></div>
		<div class="nms-summary nms-summary-resolved"><span>Resolved today</span><strong><?php print (int) $counts['resolved_today']; ?></strong><small>Automatically or manually cleared</small></div>
	</div>

	<section class="nms-panel">
		<div class="nms-panel-head">
			<div><h2>Incident queue</h2><p><?php print count($incidents); ?> matching incidents · Last synchronized <?php print $last_sync ? nms_h(nms_time_ago($last_sync)) : 'now'; ?></p></div>
			<div class="nms-state-links">
				<a class="<?php print $state === 'active' ? 'selected' : ''; ?>" href="?state=active">Active</a>
				<a class="<?php print $state === 'acknowledged' ? 'selected' : ''; ?>" href="?state=acknowledged">Acknowledged</a>
				<a class="<?php print $state === 'resolved' ? 'selected' : ''; ?>" href="?state=resolved">Resolved</a>
				<a class="<?php print $state === 'all' ? 'selected' : ''; ?>" href="?state=all">All</a>
			</div>
		</div>

		<form class="nms-toolbar" method="get" action="nms.php">
			<input type="hidden" name="state" value="<?php print nms_h($state); ?>">
			<label class="nms-search"><span>⌕</span><input type="search" name="search" value="<?php print nms_h($search); ?>" placeholder="Search incidents, devices, or collectors"></label>
			<select name="severity" aria-label="Severity">
				<option value="all">All severities</option>
				<option value="critical" <?php print $severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
				<option value="major" <?php print $severity === 'major' ? 'selected' : ''; ?>>Major</option>
				<option value="warning" <?php print $severity === 'warning' ? 'selected' : ''; ?>>Warning</option>
			</select>
			<select name="source" aria-label="Fault source">
				<option value="all">All sources</option>
				<option value="device" <?php print $source === 'device' ? 'selected' : ''; ?>>Devices</option>
				<option value="poller" <?php print $source === 'poller' ? 'selected' : ''; ?>>Collectors</option>
				<option value="rrd" <?php print $source === 'rrd' ? 'selected' : ''; ?>>RRD data</option>
				<option value="output" <?php print $source === 'output' ? 'selected' : ''; ?>>Poller output</option>
			</select>
			<button type="submit">Apply filters</button>
		</form>

		<div class="nms-table-wrap">
			<table class="nms-table">
				<thead><tr><th>Severity &amp; incident</th><th>Target</th><th>Source</th><th>First seen</th><th>Last seen</th><th>Status</th><th>Action</th></tr></thead>
				<tbody>
				<?php if (!count($incidents)) { ?>
					<tr><td colspan="7" class="nms-empty">No incidents match the current live filters.</td></tr>
				<?php } ?>
				<?php foreach ($incidents as $incident) {
					$target = $incident['host_description'];
					if ($target === null || $target === '') $target = $incident['poller_name'];
					if ($target === null || $target === '') $target = $incident['data_source_name'];
					if ($target === null || $target === '') $target = 'Cacti system';
				?>
				<tr>
					<td><div class="nms-incident"><i class="nms-severity <?php print nms_h($incident['severity']); ?>"></i><div><strong><?php print nms_h($incident['title']); ?></strong><small><?php print nms_h($incident['message']); ?></small></div></div></td>
					<td><strong><?php print nms_h($target); ?></strong><?php if ($incident['host_hostname']) { ?><small><?php print nms_h($incident['host_hostname']); ?></small><?php } ?></td>
					<td><span class="nms-source"><?php print nms_h(strtoupper($incident['source_type'])); ?></span></td>
					<td class="nms-nowrap" title="<?php print nms_h($incident['first_seen']); ?>"><?php print nms_h(nms_time_ago($incident['first_seen'])); ?></td>
					<td class="nms-nowrap" title="<?php print nms_h($incident['last_seen']); ?>"><?php print nms_h(nms_time_ago($incident['last_seen'])); ?></td>
					<td><span class="nms-state <?php print nms_h($incident['status']); ?>"><?php print nms_h($incident['status']); ?></span><?php if ($incident['acknowledged_by_name']) { ?><small>by <?php print nms_h($incident['acknowledged_by_name']); ?></small><?php } ?></td>
					<td>
					<?php if ($incident['status'] === 'open') { ?>
						<form method="post" action="nms.php" class="nms-inline-form">
							<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
							<input type="hidden" name="nms_action" value="acknowledge">
							<input type="hidden" name="id" value="<?php print (int) $incident['id']; ?>">
							<button type="submit" class="nms-ack">Acknowledge</button>
						</form>
					<?php } else { ?><span class="nms-muted">—</span><?php } ?>
					</td>
				</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="nms-panel nms-log-panel">
		<div class="nms-panel-head"><div><h2>System notices</h2><p>Simple explanations of recent Cacti messages</p></div><a class="nms-log-link" href="../../clog.php">Technical log</a></div>
		<div class="nms-log-list">
		<?php if (!count($log_events)) { ?><p class="nms-empty">No system problems were found.</p><?php } ?>
		<?php foreach ($log_events as $event) { ?>
			<div class="nms-log-entry <?php print nms_h($event['state']); ?>">
				<span class="<?php print nms_h($event['severity']); ?>"><?php print nms_h($event['state'] === 'resolved' ? 'FIXED' : strtoupper($event['severity'])); ?></span>
				<div class="nms-log-copy">
					<strong><?php print nms_h($event['title']); ?></strong>
					<p><?php print nms_h($event['detail']); ?></p>
					<small><?php print $event['time'] !== '' ? nms_h($event['time']) : 'Recent'; ?><?php if ($event['count'] > 1) { ?> · Repeated <?php print (int) $event['count']; ?> times<?php } ?></small>
				</div>
			</div>
		<?php } ?>
		</div>
	</section>
</div>
</body>
</html>
