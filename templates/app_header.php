<?php
/**
 * @file app_header.php
 * Shared NMS document header, navigation, and sidebar.
 * Controllers supply page metadata, active-module selection, asset URLs, and CSRF tokens before including this view.
 */
if (!isset($nms_active_module)) $nms_active_module = 'faults';
if (!isset($nms_page_title)) $nms_page_title = 'NMS';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php print nms_h($nms_page_title); ?></title>
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-v1.1.css')); ?>">
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-tooltips.css')); ?>">
	<link rel="stylesheet" href="<?php print nms_h(nms_asset_url('css/nms-snmpsim.css')); ?>">
	<?php if (!empty($nms_extra_css)) { ?><link rel="stylesheet" href="<?php print nms_h(nms_asset_url($nms_extra_css)); ?>"><?php } ?>
</head>
<body class="nms-standalone">
<?php require(__DIR__ . '/navigation.php'); ?>
