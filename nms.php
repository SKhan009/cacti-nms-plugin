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

$allowed_states = array('active', 'open', 'acknowledged', 'resolved', 'all');
$allowed_severities = array('all', 'critical', 'major', 'warning');

$state = isset_request_var('state') ? get_nfilter_request_var('state') : 'active';
$severity = isset_request_var('severity') ? get_nfilter_request_var('severity') : 'all';
$search = isset_request_var('search') ? trim(get_nfilter_request_var('search')) : '';

if (!in_array($state, $allowed_states, true)) $state = 'active';
if (!in_array($severity, $allowed_severities, true)) $severity = 'all';

$where = array("i.source_type = 'device'");
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
if ($search !== '') {
	$where[] = '(i.title LIKE ? OR i.message LIKE ? OR h.description LIKE ? OR h.hostname LIKE ?)';
	$term = '%' . $search . '%';
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
	$params[] = $term;
}

$incidents = db_fetch_assoc_prepared("SELECT i.*, h.description AS host_description,
		h.hostname AS host_hostname, ua.username AS acknowledged_by_name
	FROM plugin_nms_incidents AS i
	LEFT JOIN host AS h ON h.id = i.host_id
	LEFT JOIN user_auth AS ua ON ua.id = i.acknowledged_by
	WHERE " . implode(' AND ', $where) . "
	ORDER BY FIELD(i.status, 'open', 'acknowledged', 'resolved'),
		FIELD(i.severity, 'critical', 'major', 'warning'), i.first_seen DESC
	LIMIT 250", $params);

$counts = db_fetch_row("SELECT
	SUM(status IN ('open', 'acknowledged')) AS active_count,
	SUM(status IN ('open', 'acknowledged') AND severity = 'critical') AS critical_count,
	SUM(status = 'acknowledged') AS acknowledged_count,
	SUM(status = 'resolved' AND resolved_at >= CURDATE()) AS resolved_today
	FROM plugin_nms_incidents
	WHERE source_type = 'device'");

$device_total = (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted = '' AND disabled = ''");
$device_up = (int) db_fetch_cell("SELECT COUNT(*) FROM host WHERE deleted = '' AND disabled = '' AND status = " . HOST_UP);
$availability = $device_total > 0 ? round(($device_up / $device_total) * 100, 1) : 100;
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
	<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-v1.1.css?v=1.4.0'); ?>">
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
				<a class="nms-sidebar-link selected" href="#incident-queue"><span class="nms-sidebar-icon">!</span><span class="nms-sidebar-copy"><strong>Device faults</strong><small>Active device incidents</small></span></a>
			</div>
			<div class="nms-sidebar-status"><span></span><div class="nms-sidebar-copy"><strong>Live monitoring</strong><small>Reading Cacti devices</small></div></div>
		</aside>

<main class="nms-shell">
	<div class="nms-heading">
		<div>
			<p class="nms-eyebrow">NMS / Fault Management</p>
			<h1>Active incidents</h1>
			<p>Live device faults reported by Cacti.</p>
		</div>
		<div class="nms-health">
			<span class="nms-health-dot"></span>
			<div><strong><?php print nms_h($availability); ?>% device availability</strong><small><?php print $device_up; ?> of <?php print $device_total; ?> devices up</small></div>
		</div>
	</div>

	<div class="nms-summary-grid">
		<div class="nms-summary nms-summary-open"><span>Open device faults</span><strong><?php print (int) $counts['active_count']; ?></strong><small>Devices needing attention</small></div>
		<div class="nms-summary nms-summary-critical"><span>Critical</span><strong><?php print (int) $counts['critical_count']; ?></strong><small>Immediate attention required</small></div>
		<div class="nms-summary nms-summary-ack"><span>Acknowledged</span><strong><?php print (int) $counts['acknowledged_count']; ?></strong><small>Being handled by operators</small></div>
		<div class="nms-summary nms-summary-resolved"><span>Resolved today</span><strong><?php print (int) $counts['resolved_today']; ?></strong><small>Automatically or manually cleared</small></div>
	</div>

	<section class="nms-panel" id="incident-queue">
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
			<button type="submit">Apply filters</button>
		</form>

		<div class="nms-table-wrap">
			<table class="nms-table">
				<thead><tr><th>Severity &amp; incident</th><th>Device</th><th>First seen</th><th>Last seen</th><th>Status</th><th>Action</th></tr></thead>
				<tbody>
				<?php if (!count($incidents)) { ?>
					<tr><td colspan="6" class="nms-empty">No device faults match the current filters.</td></tr>
				<?php } ?>
				<?php foreach ($incidents as $incident) {
					$target = $incident['host_description'];
					if ($target === null || $target === '') $target = 'Cacti device';
				?>
				<tr>
					<td><div class="nms-incident"><i class="nms-severity <?php print nms_h($incident['severity']); ?>"></i><div><strong><?php print nms_h($incident['title']); ?></strong><small><?php print nms_h($incident['message']); ?></small></div></div></td>
					<td><strong><?php print nms_h($target); ?></strong><?php if ($incident['host_hostname']) { ?><small><?php print nms_h($incident['host_hostname']); ?></small><?php } ?></td>
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
