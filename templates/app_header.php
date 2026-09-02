<?php
/**
 * @file app_header.php
 * Shared NMS document header, navigation, and sidebar.
 * Controllers supply page metadata, active-module selection, asset URLs, and CSRF tokens before including this view.
 */
if (!isset($nms_active_module)) $nms_active_module = 'faults';
if (!isset($nms_page_title)) $nms_page_title = 'NMS';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php print nms_h($nms_page_title); ?></title>
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-v1.1.css')); ?>">
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-tooltips.css')); ?>">
	<?php if (!empty($nms_extra_css)) { ?><link rel="stylesheet" href="<?php print nms_h(nms_asset_url($nms_extra_css)); ?>"><?php } ?>
</head>
<body class="nms-standalone">
	<header class="nms-app-header">
		<div class="nms-header-start">
			<button class="nms-sidebar-toggle" id="nmsSidebarToggle" type="button" aria-controls="nmsSidebar" aria-expanded="true" aria-label="Collapse sidebar" data-nms-tip="Open or collapse the NMS navigation sidebar."><span></span><span></span><span></span></button>
		</div>
		<nav class="nms-primary-nav" aria-label="NMS modules">
			<a class="<?php print $nms_active_module === 'faults' ? 'selected' : ''; ?>" href="nms.php" data-nms-tip="View current device health and faults evaluated from live Cacti data.">Faults</a>
			<a class="<?php print $nms_active_module === 'devices' ? 'selected' : ''; ?>" href="devices.php" data-nms-tip="Add, edit, import, and graph real Cacti devices.">Devices</a>
			<a class="<?php print $nms_active_module === 'configuration' ? 'selected' : ''; ?>" href="fault_config.php" data-nms-tip="Configure categories, device parameters, thresholds, and fault severity.">Fault Configuration</a>
			<a class="<?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>" href="topology.php" data-nms-tip="Arrange live Cacti devices on a site topology map.">Topology</a>
		</nav>
		<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>" data-nms-tip="Open the full Cacti administration console."><span aria-hidden="true">&#8599;</span> Cacti Backend</a>
	</header>
	<div class="nms-app-layout">
		<aside class="nms-sidebar" id="nmsSidebar">
			<div class="nms-sidebar-section">
				<p class="nms-sidebar-label">Monitoring</p>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'faults' ? 'selected' : ''; ?>" href="nms.php" data-nms-tip="Device health and active fault readings evaluated from Cacti core."><span class="nms-sidebar-icon">●</span><span class="nms-sidebar-copy"><strong>Device readings</strong><small>Health from Cacti core</small></span></a>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'devices' ? 'selected' : ''; ?>" href="devices.php" data-nms-tip="Device inventory, creation, editing, graphs, data queries, and SNMP record import."><span class="nms-sidebar-icon">＋</span><span class="nms-sidebar-copy"><strong>Device management</strong><small>Add devices and SNMP records</small></span></a>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'configuration' ? 'selected' : ''; ?>" href="fault_config.php" data-nms-tip="Map Cacti host templates to Cacti Tree categories and configure fault values and severity."><span class="nms-sidebar-icon">⚙</span><span class="nms-sidebar-copy"><strong>Fault configuration</strong><small>Cacti Trees and severity</small></span></a>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>" href="topology.php" data-nms-tip="Interactive topology built from enabled devices in each Cacti site."><span class="nms-sidebar-icon">⌘</span><span class="nms-sidebar-copy"><strong>Topology</strong><small>Configured device map</small></span></a>
			</div>
			<div class="nms-sidebar-status" data-nms-tip="NMS is reading device state and collection information directly from Cacti."><span></span><div class="nms-sidebar-copy"><strong>Live monitoring</strong><small>Reading Cacti devices</small></div></div>
		</aside>
