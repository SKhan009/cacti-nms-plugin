<?php
/**
 * @file app_footer.php
 * Close the shared NMS page, bind sidebar collapse, and include versioned module, pagination, and tooltip scripts.
 */
?>	</div>
	<script>
	(/** Persist the shared sidebar state; only the hamburger can reopen a collapsed menu. */ function() {
		var button = document.getElementById('nmsSidebarToggle');
		if (!button) return;
		var key = 'nms.sidebar.collapsed';
		function sync(collapsed) {
			document.body.classList.toggle('nms-sidebar-collapsed', collapsed);
			button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			button.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
		}
		sync(window.localStorage.getItem(key) === '1');
		button.addEventListener('click', function() {
			document.body.classList.remove('nms-sidebar-menu-open');
			var collapsed = !document.body.classList.contains('nms-sidebar-collapsed');
			window.localStorage.setItem(key, collapsed ? '1' : '0');
			sync(collapsed);
		});
		document.querySelectorAll('.nms-template-menu > summary').forEach(function(menu) {
			menu.addEventListener('click', function(event) {
				if (document.body.classList.contains('nms-sidebar-collapsed')) event.preventDefault();
			});
		});
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
