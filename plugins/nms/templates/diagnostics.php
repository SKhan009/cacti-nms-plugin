<?php
/**
 * @file diagnostics.php
 * Assemble the protocol-check page from focused, maintainable view partials.
 * The controller prepares every variable consumed by these templates.
 */
?>
<main class="nms-shell nms-topology-config">
	<div class="nms-heading">
	<div>
		<p class="nms-eyebrow">NMS / PRESETS</p>
		<h1>Protocol checks</h1>
		<p>Set safe on-demand tests and run them from the assigned Cacti collector.</p>
	</div>
</div>

<form method="get" class="nms-canvas-node-filter"><input type="hidden" name="section" value="run"><label for="nmsDiagnosticNode">Node</label><select id="nmsDiagnosticNode" name="node_id"><option value="0">All devices</option><?php foreach(nms_nodes_list() as $node){ ?><option value="<?php print (int)$node['id']; ?>" <?php print (int)$node['id']===$node_id?'selected':''; ?>><?php print nms_h($node['name'].' · '.$node['site_name']); ?></option><?php } ?></select><button type="submit">Show</button><?php if($node_id){ ?><a href="devices.php?tab=nodes&amp;node_id=<?php print $node_id; ?>">Node details</a><?php } ?></form>
<nav class="nms-preset-tabs" aria-label="Protocol checks sections">
	<a href="diagnostics.php?section=run&amp;node_id=<?php print $node_id; ?>" class="<?php print $section === "run" ? "active" : ""; ?>">Run a diagnostic</a>
	<a href="diagnostics.php?section=profiles" class="<?php print $section === "profiles"
	? "active"
	: ""; ?>">Diagnostic profiles</a>
</nav>

<?php if ($error && !$open_profile_modal) { ?>
	<p role="alert" class="nms-notice error"><?php print nms_h($error); ?></p>
<?php } elseif ($notice) { ?>
	<p role="status" class="nms-notice"><?php print nms_h($notice); ?></p>
<?php } ?>

	<?php if ($section === "run") { ?>
		<?php require __DIR__ . "/diagnostics/run.php"; ?>
	<?php } else { ?>
		<?php require __DIR__ . "/diagnostics/profiles.php"; ?>
	<?php } ?>
</main>

<?php if ($section === "profiles") { ?>
	<?php require __DIR__ . "/diagnostics/profile_dialog.php"; ?>
<?php } ?>

<?php require __DIR__ . "/diagnostics/behavior.php"; ?>
