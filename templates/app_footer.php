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
	<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-typography.css?v=1.9.19'); ?>">
	<?php if (!empty($nms_extra_js)) { ?><script src="<?php print nms_h($nms_asset_base . $nms_extra_js); ?>"></script><?php } ?>
	<script src="<?php print nms_h($nms_asset_base . 'js/nms-pagination.js?v=1.9.17'); ?>"></script>
	<script src="<?php print nms_h($nms_asset_base . 'js/nms-tooltips.js?v=1.9.18'); ?>"></script>
</body>
</html>
