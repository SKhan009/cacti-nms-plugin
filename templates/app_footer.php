<?php
/**
 * @file app_footer.php
 * Close the shared NMS page, bind sidebar collapse, and include versioned module, pagination, and tooltip scripts.
 */
?>	</div>
	<script>
	(/** Bind the shared sidebar toggle when the current page contains its button. */ function() {
		var button = document.getElementById('nmsSidebarToggle');
		if (!button) return;
		button.addEventListener('click', /** Toggle sidebar collapse and synchronize the button's accessible state and label. */ function() {
			var collapsed = document.body.classList.toggle('nms-sidebar-collapsed');
			button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			button.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
		});
	})();
	</script>
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-typography.css')); ?>">
	<?php if (!empty($nms_extra_js)) { ?><script src="<?php print nms_h(nms_asset_url($nms_extra_js)); ?>"></script><?php } ?>
	<script src="<?php print nms_h(nms_asset_url('js/nms-pagination.js')); ?>"></script>
	<script src="<?php print nms_h(nms_asset_url('js/nms-tooltips.js')); ?>"></script>
</body>
</html>
