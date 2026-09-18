<?php
/** Render the diagnostics page title, description and section navigation. */
?>
<div class="nms-heading">
	<div>
		<p class="nms-eyebrow">NMS / PRESETS</p>
		<h1>Protocol checks</h1>
		<p>Set safe on-demand tests and run them from the assigned Cacti collector.</p>
	</div>
</div>

<nav class="nms-preset-tabs" aria-label="Protocol checks sections">
	<a href="diagnostics.php?section=run" class="<?php print $section === "run" ? "active" : ""; ?>">Run a diagnostic</a>
	<a href="diagnostics.php?section=profiles" class="<?php print $section === "profiles"
 	? "active"
 	: ""; ?>">Diagnostic profiles</a>
</nav>

<?php if ($error && !$open_profile_modal) { ?>
	<p role="alert" class="nms-notice error"><?php print nms_h($error); ?></p>
<?php } elseif ($notice) { ?>
	<p role="status" class="nms-notice"><?php print nms_h($notice); ?></p>
<?php } ?>
