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
			document.body.classList.remove('nms-sidebar-menu-open');
			var collapsed = document.body.classList.toggle('nms-sidebar-collapsed');
			button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			button.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
		});
		document.querySelectorAll('.nms-template-menu > summary').forEach(function(menu) { menu.addEventListener('click', function(event) {
			if (document.body.classList.contains('nms-sidebar-collapsed') || (innerWidth <= 700 && !document.body.classList.contains('nms-sidebar-menu-open'))) {
				event.preventDefault();
				document.body.classList.remove('nms-sidebar-collapsed');
				if (innerWidth <= 700) document.body.classList.add('nms-sidebar-menu-open');
				menu.parentElement.open = true;
				button.setAttribute('aria-expanded', 'true');
				button.setAttribute('aria-label', 'Collapse sidebar');
			}
		}); });
	})();
	</script>
	<script src="<?php print nms_h(nms_asset_url("js/nms-upload.js")); ?>"></script>
	<?php foreach (array_filter(array_map("trim", explode(",", (string) $nms_extra_js))) as $nms_script) { ?><script src="<?php print nms_h(
	nms_asset_url($nms_script),
); ?>"></script><?php } ?>
	<script src="<?php print nms_h(nms_asset_url("js/nms-pagination.js")); ?>"></script>
	<script src="<?php print nms_h(nms_asset_url("js/nms-tooltips.js")); ?>"></script>
</body>
</html>
