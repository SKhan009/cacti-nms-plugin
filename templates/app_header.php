<?php
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
	<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-v1.1.css?v=1.8.0'); ?>">
	<?php if (!empty($nms_extra_css)) { ?><link rel="stylesheet" href="<?php print nms_h($nms_asset_base . $nms_extra_css); ?>"><?php } ?>
</head>
<body class="nms-standalone">
	<header class="nms-app-header">
		<div class="nms-header-start">
			<button class="nms-sidebar-toggle" id="nmsSidebarToggle" type="button" aria-controls="nmsSidebar" aria-expanded="true" aria-label="Collapse sidebar"><span></span><span></span><span></span></button>
			<a class="nms-brand" href="nms.php" aria-label="NMS dashboard"><span class="nms-brand-mark">N</span><span><strong>NMS</strong><small>Network Management System</small></span></a>
		</div>
		<nav class="nms-primary-nav" aria-label="NMS modules">
			<a class="<?php print $nms_active_module === 'faults' ? 'selected' : ''; ?>" href="nms.php">Faults</a>
			<a class="<?php print $nms_active_module === 'configuration' ? 'selected' : ''; ?>" href="fault_config.php">Fault Configuration</a>
			<a class="<?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>" href="topology.php">Topology</a>
		</nav>
		<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>"><span aria-hidden="true">&#8599;</span> Cacti Backend</a>
	</header>
	<div class="nms-app-layout">
		<aside class="nms-sidebar" id="nmsSidebar">
			<div class="nms-sidebar-section">
				<p class="nms-sidebar-label">Monitoring</p>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'faults' ? 'selected' : ''; ?>" href="nms.php"><span class="nms-sidebar-icon">●</span><span class="nms-sidebar-copy"><strong>Device readings</strong><small>Health from Cacti core</small></span></a>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'configuration' ? 'selected' : ''; ?>" href="fault_config.php"><span class="nms-sidebar-icon">⚙</span><span class="nms-sidebar-copy"><strong>Fault configuration</strong><small>Categories and severity</small></span></a>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>" href="topology.php"><span class="nms-sidebar-icon">⌘</span><span class="nms-sidebar-copy"><strong>Topology</strong><small>Configured device map</small></span></a>
			</div>
			<div class="nms-sidebar-status"><span></span><div class="nms-sidebar-copy"><strong>Live monitoring</strong><small>Reading Cacti devices</small></div></div>
		</aside>
