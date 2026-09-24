<?php
/**
 * @file app_footer.php
 * Close the shared NMS page, load shared UI components and include versioned module, pagination, and tooltip scripts.
 */
?>	</div>

	<script src="<?php print nms_h(nms_asset_url("js/nms-ui.js")); ?>"></script>
	<script src="<?php print nms_h(nms_asset_url("js/nms-upload.js")); ?>"></script>
	<?php foreach (array_filter(array_map("trim", explode(",", (string) $nms_extra_js))) as $nms_script) { ?><script src="<?php print nms_h(
	nms_asset_url($nms_script),
); ?>"></script><?php } ?>
	<script src="<?php print nms_h(nms_asset_url("js/nms-pagination.js")); ?>"></script>
	<script src="<?php print nms_h(nms_asset_url("js/nms-tooltips.js")); ?>"></script>
</body>
</html>
