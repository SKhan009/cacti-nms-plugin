<?php

function nms_show_tab() {
	global $config;

	if (!api_user_realm_auth('nms.php')) {
		return;
	}

	$selected = get_current_page() === 'nms.php' ? " class='selected'" : '';
	$url = html_escape($config['url_path'] . 'plugins/nms/nms.php');
	$icon = html_escape($config['url_path'] . 'plugins/nms/images/nms.svg');

	print "<a id='tab-nms'$selected href='$url'><img src='$icon' alt='NMS'></a>";
}

function nms_draw_navigation_text($nav) {
	if (get_current_page() === 'nms.php') {
		$nav['NMS'] = 'plugins/nms/nms.php';
		$nav['Fault Management'] = 'plugins/nms/nms.php';
	}

	return $nav;
}
