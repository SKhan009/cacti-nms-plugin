<?php
/**
 * @file diagnostics.php
 * Assemble the protocol-check page from focused, maintainable view partials.
 * The controller prepares every variable consumed by these templates.
 */
?>
<main class="nms-shell nms-topology-config">
	<?php require __DIR__ . "/diagnostics/heading.php"; ?>

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
