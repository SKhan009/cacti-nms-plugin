<?php

function nms_show_tab() {
	global $config;

	if (!api_user_realm_auth('nms.php')) {
		return;
	}

	$selected = in_array(get_current_page(), array('nms.php', 'fault_config.php', 'topology.php'), true) ? " class='selected'" : '';
	$url = html_escape($config['url_path'] . 'plugins/nms/nms.php');
	$icon = html_escape($config['url_path'] . 'plugins/nms/images/nms.svg');

	print "<a id='tab-nms'$selected href='$url'><img src='$icon' alt='NMS'></a>";
}

function nms_draw_navigation_text($nav) {
	if (in_array(get_current_page(), array('nms.php', 'fault_config.php', 'topology.php'), true)) {
		$nav['NMS'] = 'plugins/nms/nms.php';
		if (get_current_page() === 'topology.php') {
			$nav['Topology'] = 'plugins/nms/topology.php';
		} elseif (get_current_page() === 'fault_config.php') {
			$nav['Fault Configuration'] = 'plugins/nms/fault_config.php';
		} else {
			$nav['Fault Management'] = 'plugins/nms/nms.php';
		}
	}

	return $nav;
}
