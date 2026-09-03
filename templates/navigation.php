<?php /** Shared NMS navigation for standalone pages and native template editors. */ ?>
	<header class="nms-app-header">
		<div class="nms-header-start">
			<button class="nms-sidebar-toggle" id="nmsSidebarToggle" type="button" aria-controls="nmsSidebar" aria-expanded="true" aria-label="Collapse sidebar" data-nms-no-tooltip><span></span><span></span><span></span></button>
		</div>
		<nav class="nms-primary-nav" aria-label="NMS modules">
			<a class="<?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>" href="topology.php" data-nms-tip="Arrange live Cacti devices on a site topology map.">Topology</a>
			<a class="<?php print $nms_active_module === 'devices' ? 'selected' : ''; ?>" href="devices.php" data-nms-tip="Add, edit, import, and graph real Cacti devices.">Devices</a>
			<a class="<?php print $nms_active_module === 'graphs' ? 'selected' : ''; ?>" href="graphs.php" data-nms-tip="Browse permitted Cacti graphs using the core graph-tree hierarchy.">Graphs</a>
		</nav>
		<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>" data-nms-tip="Open the full Cacti administration console."><span aria-hidden="true">&#8599;</span> Cacti Backend</a>
	</header>
	<div class="nms-app-layout">
		<aside class="nms-sidebar" id="nmsSidebar" data-nms-no-tooltip>
			<div class="nms-sidebar-section">
				<p class="nms-sidebar-label">Monitoring</p>
				<details class="nms-template-menu" <?php if ($nms_active_module === 'topology') print 'open'; ?>>
				<summary aria-label="Topology" class="nms-sidebar-link <?php print $nms_active_module === 'topology' ? 'selected' : ''; ?>"><span class="nms-sidebar-icon">⌘</span><span class="nms-sidebar-copy"><strong>Topology</strong></span><span class="nms-submenu-arrow" aria-hidden="true">⌄</span></summary>
				<nav class="nms-template-subnav" aria-label="Topology sections">
				<?php foreach (array('map' => 'Network topology', 'racks' => 'Rack topology', 'configuration' => 'Topology configuration') as $key => $label) { ?>
				<a href="topology.php?tab=<?php print $key; ?>" <?php if ($nms_active_module === 'topology' && ($topology_tab ?? 'map') === $key) print 'aria-current="page"'; ?>><?php print $label; ?></a>
				<?php } ?></nav></details>
				<details class="nms-template-menu" <?php if (in_array($nms_active_module, array('faults', 'configuration', 'capabilities'), true)) print 'open'; ?>>
				<summary aria-label="Faults" class="nms-sidebar-link <?php print in_array($nms_active_module, array('faults', 'configuration', 'capabilities'), true) ? 'selected' : ''; ?>"><span class="nms-sidebar-icon">●</span><span class="nms-sidebar-copy"><strong>Faults</strong></span><span class="nms-submenu-arrow" aria-hidden="true">⌄</span></summary>
				<nav class="nms-template-subnav" aria-label="Fault sections">
					<a href="nms.php" <?php if ($nms_active_module === 'faults') print 'aria-current="page"'; ?>>Device readings</a>
					<a href="fault_config.php" <?php if ($nms_active_module === 'configuration') print 'aria-current="page"'; ?>>Fault configuration</a>
					<a href="capabilities.php" <?php if ($nms_active_module === 'capabilities') print 'aria-current="page"'; ?>>FCAPS capabilities</a>
				</nav></details>
				<a class="nms-sidebar-link <?php print $nms_active_module === 'devices' ? 'selected' : ''; ?>" href="devices.php"><span class="nms-sidebar-icon">＋</span><span class="nms-sidebar-copy"><strong>Device management</strong></span></a>
				<details class="nms-template-menu" <?php if ($nms_active_module === 'templates') print 'open'; ?>>
				<summary aria-label="Templates" class="nms-sidebar-link <?php print $nms_active_module === 'templates' ? 'selected' : ''; ?>"><span class="nms-sidebar-icon" aria-hidden="true">▤</span><span class="nms-sidebar-copy"><strong>Templates</strong></span><span class="nms-submenu-arrow" aria-hidden="true">⌄</span></summary>
				<?php require_once(__DIR__ . '/../includes/template_workspace.php'); ?>
				<nav class="nms-template-subnav" aria-label="Template sections">
					<?php foreach (nms_template_sections() as $key => $template_section) { ?><a href="templates.php?section=<?php print nms_h($key); ?>" <?php if (($nms_template_section ?? '') === $key) print 'aria-current="page"'; ?>><?php print nms_h($template_section['label']); ?></a><?php } ?>
				</nav>
				</details>
			</div>
			<div class="nms-sidebar-status"><span></span><div class="nms-sidebar-copy"><strong>Live monitoring</strong><small>Reading Cacti devices</small></div></div>
		</aside>
