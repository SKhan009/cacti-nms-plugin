<?php
/**
 * Shared NMS navigation used by standalone pages and native template editors.
 *
 * Keep links here aligned with plugin realm checks. Hidden modules remain
 * registered for backwards compatibility but are not rendered in the UI.
 */
?>
<header class="nms-app-header">
	<div class="nms-header-start">
		<button class="nms-sidebar-toggle" id="nmsSidebarToggle" type="button"
			aria-controls="nmsSidebar" aria-expanded="true" aria-label="Collapse sidebar"
			data-nms-no-tooltip>
			<span></span><span></span><span></span>
		</button>
	</div>
	<nav class="nms-primary-nav" aria-label="NMS modules">
		<a class="<?php print $nms_active_module === "topology" ? "selected" : ""; ?>"
			href="<?php print nms_h(nms_plugin_url("topology.php")); ?>"
			data-nms-tip="Arrange live Cacti devices on a site topology map.">Topology</a>
		<a class="<?php print $nms_active_module === "devices" && ($tab ?? "") !== "import" ? "selected" : ""; ?>"
			href="<?php print nms_h(nms_plugin_url("devices.php")); ?>"
			data-nms-tip="Add, edit, import, and graph real Cacti devices.">Devices</a>
	</nav>
	<a class="nms-backend-button" href="<?php print nms_h($nms_backend_url); ?>"
		data-nms-tip="Open the full Cacti administration console.">
		<span aria-hidden="true">&#8599;</span> Cacti Backend
	</a>
</header>

<div class="nms-app-layout">
	<aside class="nms-sidebar" id="nmsSidebar" data-nms-no-tooltip>
		<div class="nms-sidebar-section">
			<p class="nms-sidebar-label">Monitoring</p>
			<details class="nms-template-menu" <?php if ($nms_active_module === "topology") {
   	print "open";
   } ?>>
				<summary aria-label="Topology" class="nms-sidebar-link <?php print $nms_active_module === "topology"
    	? "selected"
    	: ""; ?>">
					<span class="nms-sidebar-icon">⌘</span>
					<span class="nms-sidebar-copy"><strong>Topology</strong></span>
					<span class="nms-submenu-arrow" aria-hidden="true">⌄</span>
				</summary>
				<nav class="nms-template-subnav" aria-label="Topology sections">
					<?php foreach (
     	["discovered" => "Topology view", "appearance" => "Device appearance", "inventory" => "Discovery inventory"]
     	as $key => $label
     ) { ?>
						<a href="<?php print nms_h(nms_plugin_url("topology.php?tab=" . $key)); ?>"
							<?php if ($nms_active_module === "topology" && ($topology_tab ?? "map") === $key) {
       	print 'aria-current="page"';
       } ?>>
							<?php print $label; ?>
						</a>
					<?php } ?>
				</nav>
			</details>

			<a class="nms-sidebar-link <?php print $nms_active_module === "devices" && ($tab ?? "") !== "import"
   	? "selected"
   	: ""; ?>"
				href="<?php print nms_h(nms_plugin_url("devices.php")); ?>">
				<span class="nms-sidebar-icon">＋</span>
				<span class="nms-sidebar-copy"><strong>Device management</strong></span>
			</a>

			<?php if (api_user_realm_auth("devices.php") && is_realm_allowed(3)) { ?>
				<?php $nms_repo_open = $nms_active_module === "repository"; ?>
				<details class="nms-template-menu" <?php if ($nms_repo_open) {
    	print "open";
    } ?>>
					<summary class="nms-sidebar-link <?php if ($nms_repo_open) {
     	print "selected";
     } ?>">
						<span class="nms-sidebar-icon" aria-hidden="true">▤</span>
						<span class="nms-sidebar-copy"><strong>File repository</strong></span>
						<span class="nms-submenu-arrow" aria-hidden="true">⌄</span>
					</summary>
					<nav class="nms-template-subnav" aria-label="Uploaded files">
						<a href="<?php print nms_h(nms_plugin_url("file_repository.php?kind=mib")); ?>"
							<?php if ($nms_repo_open && ($_GET["kind"] ?? "") === "mib") {
       	print 'aria-current="page"';
       } ?>>MIB files</a>
						<a href="<?php print nms_h(nms_plugin_url("file_repository.php?kind=snmprec")); ?>"
							<?php if ($nms_repo_open && ($_GET["kind"] ?? "") !== "mib") {
       	print 'aria-current="page"';
       } ?>>SNMP recordings</a>
					</nav>
				</details>
			<?php } ?>

			<?php if (api_user_realm_auth("discovery_presets.php")) { ?>
				<?php $nms_presets_open = in_array($nms_active_module, ["presets", "diagnostics"], true); ?>
				<details class="nms-template-menu" <?php if ($nms_presets_open) {
    	print "open";
    } ?>>
					<summary aria-label="Presets" class="nms-sidebar-link <?php print $nms_presets_open ? "selected" : ""; ?>">
						<span class="nms-sidebar-icon" aria-hidden="true">⚙</span>
						<span class="nms-sidebar-copy"><strong>Presets</strong></span>
						<span class="nms-submenu-arrow" aria-hidden="true">⌄</span>
					</summary>
					<nav class="nms-template-subnav" aria-label="Protocol presets">
						<a href="<?php print nms_h(nms_plugin_url("discovery_presets.php")); ?>"
							<?php if (get_current_page() === "discovery_presets.php") {
       	print 'aria-current="page"';
       } ?>>Connection discovery</a>
						<a href="<?php print nms_h(nms_plugin_url("diagnostics.php")); ?>"
							<?php if (get_current_page() === "diagnostics.php") {
       	print 'aria-current="page"';
       } ?>>Protocol checks</a>
					</nav>
				</details>
			<?php } ?>
		</div>

		<div class="nms-sidebar-status">
			<span></span>
			<div class="nms-sidebar-copy">
				<strong>Live monitoring</strong>
				<small>Reading Cacti devices</small>
			</div>
		</div>
	</aside>
