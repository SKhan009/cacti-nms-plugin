<?php

function nms_topology_sites() {
	return db_fetch_assoc("SELECT s.id, s.name, COUNT(h.id) AS device_count
		FROM sites AS s
		LEFT JOIN host AS h ON h.site_id = s.id AND h.deleted = '' AND h.disabled = ''
		GROUP BY s.id, s.name
		HAVING COUNT(h.id) > 0
		ORDER BY s.name");
}

function nms_topology_devices($site_id) {
	return db_fetch_assoc_prepared("SELECT
			h.id, h.description, h.hostname, h.status, h.availability, h.cur_time,
			h.last_updated, h.site_id, h.poller_id, h.snmp_sysName, h.snmp_sysDescr,
			h.snmp_version, h.host_template_id, p.name AS poller_name,
			COALESCE(g.graph_count, 0) AS graph_count,
			COALESCE(i.interface_count, 0) AS interface_count,
			l.parent_host_id, l.parent_snmp_index, l.pos_x, l.pos_y, l.locked,
			CASE WHEN l.host_id IS NULL THEN 0 ELSE 1 END AS is_mapped
		FROM host AS h
		LEFT JOIN poller AS p ON p.id = h.poller_id
		LEFT JOIN plugin_nms_topology AS l ON l.host_id = h.id
		LEFT JOIN (
			SELECT host_id, COUNT(*) AS graph_count FROM graph_local GROUP BY host_id
		) AS g ON g.host_id = h.id
		LEFT JOIN (
			SELECT host_id, COUNT(DISTINCT snmp_index) AS interface_count
			FROM host_snmp_cache
			WHERE field_name IN ('ifName', 'ifDescr')
			GROUP BY host_id
		) AS i ON i.host_id = h.id
		WHERE h.deleted = '' AND h.disabled = '' AND h.site_id = ?
		ORDER BY (l.locked = 'on') DESC, h.description", array((int) $site_id));
}

function nms_topology_device_belongs_to_site($host_id, $site_id) {
	return (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM host
		WHERE id = ? AND site_id = ? AND deleted = '' AND disabled = ''", array((int) $host_id, (int) $site_id)) === 1;
}

function nms_topology_set_root($host_id, $site_id, $user_id) {
	if (!nms_topology_device_belongs_to_site($host_id, $site_id)) return false;

	db_execute_prepared("UPDATE plugin_nms_topology SET locked = '', updated_by = ?, updated_at = ?
		WHERE site_id = ? AND locked = 'on'", array((int) $user_id, nms_now(), (int) $site_id));
	db_execute_prepared("INSERT INTO plugin_nms_topology
		(host_id, site_id, parent_host_id, pos_x, pos_y, locked, updated_by, updated_at)
		VALUES (?, ?, 0, 50, 18, 'on', ?, ?)
		ON DUPLICATE KEY UPDATE site_id = VALUES(site_id), parent_host_id = 0,
			pos_x = 50, pos_y = 18, locked = 'on', updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
		array((int) $host_id, (int) $site_id, (int) $user_id, nms_now()));
	return true;
}

function nms_topology_interfaces($site_id) {
	$rows = db_fetch_assoc_prepared("SELECT c.host_id, c.snmp_index,
		MAX(CASE WHEN c.field_name = 'ifName' THEN c.field_value ELSE '' END) AS if_name,
		MAX(CASE WHEN c.field_name = 'ifDescr' THEN c.field_value ELSE '' END) AS if_description,
		MAX(CASE WHEN c.field_name = 'ifAlias' THEN c.field_value ELSE '' END) AS if_alias
		FROM host_snmp_cache AS c
		INNER JOIN host AS h ON h.id = c.host_id AND h.site_id = ? AND h.deleted = '' AND h.disabled = ''
		WHERE c.field_name IN ('ifName', 'ifDescr', 'ifAlias')
		GROUP BY c.host_id, c.snmp_index
		ORDER BY c.host_id, c.snmp_index", array((int) $site_id));
	$result = array();
	foreach ($rows as $row) {
		$host_id = (int) $row['host_id'];
		if (!isset($result[$host_id])) $result[$host_id] = array();
		$label = trim($row['if_name']) !== '' ? $row['if_name'] : $row['if_description'];
		if (trim($row['if_alias']) !== '') $label .= ' · ' . $row['if_alias'];
		if ($label === '') $label = 'Interface index ' . $row['snmp_index'];
		$result[$host_id][] = array('index' => (string) $row['snmp_index'], 'label' => $label);
	}
	return $result;
}

function nms_topology_save_position($host_id, $site_id, $parent_host_id, $parent_snmp_index, $x, $y, $user_id) {
	if (!nms_topology_device_belongs_to_site($host_id, $site_id)) return false;
	if ($parent_host_id > 0 && !nms_topology_device_belongs_to_site($parent_host_id, $site_id)) return false;

	$x = max(8, min(92, (float) $x));
	$y = max(10, min(90, (float) $y));
	db_execute_prepared("INSERT INTO plugin_nms_topology
		(host_id, site_id, parent_host_id, parent_snmp_index, pos_x, pos_y, locked, updated_by, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, '', ?, ?)
		ON DUPLICATE KEY UPDATE site_id = VALUES(site_id), parent_host_id = VALUES(parent_host_id), parent_snmp_index = VALUES(parent_snmp_index),
			pos_x = VALUES(pos_x), pos_y = VALUES(pos_y), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
		array((int) $host_id, (int) $site_id, (int) $parent_host_id, (string) $parent_snmp_index, $x, $y, (int) $user_id, nms_now()));
	return true;
}

function nms_topology_remove_device($host_id, $site_id) {
	$locked = db_fetch_cell_prepared("SELECT locked FROM plugin_nms_topology WHERE host_id = ? AND site_id = ?", array((int) $host_id, (int) $site_id));
	if ($locked === 'on') return false;
	db_execute_prepared('DELETE FROM plugin_nms_topology WHERE host_id = ? AND site_id = ?', array((int) $host_id, (int) $site_id));
	return true;
}

function nms_topology_json_devices($devices, $interfaces, $url_path) {
	$result = array();
	foreach ($devices as $device) {
		$result[] = array(
			'id' => (int) $device['id'],
			'name' => (string) $device['description'],
			'hostname' => (string) $device['hostname'],
			'sys_name' => (string) $device['snmp_sysName'],
			'sys_description' => (string) $device['snmp_sysDescr'],
			'status' => nms_host_status_name((int) $device['status']),
			'availability' => round((float) $device['availability'], 1),
			'response_ms' => round((float) $device['cur_time'], 2),
			'last_updated' => (string) $device['last_updated'],
			'poller' => (string) $device['poller_name'],
			'snmp_version' => (int) $device['snmp_version'],
			'graphs' => (int) $device['graph_count'],
			'interfaces' => (int) $device['interface_count'],
			'mapped' => (bool) $device['is_mapped'],
			'locked' => $device['locked'] === 'on',
			'parent_id' => (int) $device['parent_host_id'],
			'parent_snmp_index' => (string) $device['parent_snmp_index'],
			'interface_options' => isset($interfaces[(int) $device['id']]) ? $interfaces[(int) $device['id']] : array(),
			'x' => $device['pos_x'] === null ? null : (float) $device['pos_x'],
			'y' => $device['pos_y'] === null ? null : (float) $device['pos_y'],
			'graphs_url' => $url_path . 'graph_view.php?action=preview&host_id=' . (int) $device['id'],
			'device_url' => $url_path . 'host.php?action=edit&id=' . (int) $device['id']
		);
	}
	return $result;
}
