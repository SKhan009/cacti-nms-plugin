<?php

require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/template.php');

function nms_template_clean_name($value, $maximum = 150) {
	$value = trim(preg_replace('/\s+/', ' ', strip_tags((string) $value)));
	if ($value === '') throw new InvalidArgumentException('A Cacti template name is required.');
	return substr($value, 0, $maximum);
}

function nms_template_host($name) {
	$name = nms_template_clean_name($name);
	$existing = (int) db_fetch_cell_prepared('SELECT id FROM host_template WHERE name = ?', array($name));
	if ($existing > 0) return $existing;
	$id = sql_save(array('id' => 0, 'hash' => get_hash_host_template(0), 'name' => $name), 'host_template');
	if (!$id) throw new RuntimeException('Cacti could not create the host template.');
	return (int) $id;
}

function nms_template_known_oid_label($oid) {
	$labels = array(
		'1.3.6.1.2.1.1.3.0' => 'System uptime',
		'1.3.6.1.2.1.1.5.0' => 'Device name'
	);
	return $labels[$oid] ?? '';
}

function nms_template_record_label($record) {
	$known_label = nms_template_known_oid_label((string) $record['oid']);
	if ($known_label !== '') return $known_label;

	$section = trim(preg_replace('/[_-]+/', ' ', (string) $record['section']));
	$section = trim(preg_replace('/\s+/', ' ', $section));
	if ($section === '') $section = 'SNMP reading';
	if ((int) ($record['reading_total'] ?? 1) > 1) {
		$section .= ' - Reading ' . max(1, (int) ($record['reading_index'] ?? 1));
	}
	return substr($section, 0, 150);
}

function nms_template_data_source_name($record) {
	$label = strtolower(nms_template_record_label($record));
	$label = trim(preg_replace('/[^a-z0-9]+/', '_', $label), '_');
	if ($label === '') $label = 'reading';
	$index = max(1, (int) ($record['reading_index'] ?? 1));
	$suffix = '_' . $index;
	return 'nms_' . substr($label, 0, 19 - 4 - strlen($suffix)) . $suffix;
}

function nms_template_pair($template_name, $record) {
	$base_data_template_id = (int) db_fetch_cell("SELECT id FROM data_template WHERE name = 'SNMP - Generic OID Template'");
	$base_graph_template_id = (int) db_fetch_cell("SELECT id FROM graph_templates WHERE name = 'SNMP - Generic OID Template'");
	if (!$base_data_template_id || !$base_graph_template_id) {
		throw new RuntimeException('Cacti Generic OID templates are not installed.');
	}

	$label = nms_template_record_label($record);
	$prefix = preg_match('/^NMS\b/i', $template_name) ? $template_name : 'NMS ' . $template_name;
	$object_name = substr($prefix . ' - ' . $label, 0, 190);
	$data_template_id = (int) api_duplicate_data_source(0, $base_data_template_id, $object_name);
	if (!$data_template_id) throw new RuntimeException('Cacti could not create data template for ' . $record['oid'] . '.');

	$data_template_data_id = (int) db_fetch_cell_prepared(
		'SELECT id FROM data_template_data WHERE data_template_id = ? AND local_data_id = 0', array($data_template_id)
	);
	$data_template_rrd_id = (int) db_fetch_cell_prepared(
		'SELECT id FROM data_template_rrd WHERE data_template_id = ? AND local_data_id = 0', array($data_template_id)
	);
	$oid_field_id = (int) db_fetch_cell("SELECT id FROM data_input_fields WHERE data_input_id = 1 AND data_name = 'oid'");
	if (!$data_template_data_id || !$data_template_rrd_id || !$oid_field_id) {
		throw new RuntimeException('The duplicated Cacti data template is incomplete.');
	}

	/* Cacti/RRDtool data-source types: 1 GAUGE, 2 COUNTER. */
	$data_source_type_id = in_array((int) $record['type'], array(65, 70), true) ? 2 : 1;
	db_execute_prepared('UPDATE data_template_data SET name = ? WHERE id = ?',
		array('|host_description| - ' . $label, $data_template_data_id));
	db_execute_prepared('UPDATE data_template_rrd SET data_source_name = ?, data_source_type_id = ? WHERE id = ?',
		array(nms_template_data_source_name($record), $data_source_type_id, $data_template_rrd_id));
	db_execute_prepared('UPDATE data_input_data SET t_value = ?, value = ?
		WHERE data_template_data_id = ? AND data_input_field_id = ?',
		array('', $record['oid'], $data_template_data_id, $oid_field_id));

	$graph_template_id = (int) api_duplicate_graph(0, $base_graph_template_id, $object_name, false);
	if (!$graph_template_id) throw new RuntimeException('Cacti could not create graph template for ' . $record['oid'] . '.');
	db_execute_prepared('UPDATE graph_templates_graph SET title = ?, vertical_label = ?
		WHERE graph_template_id = ? AND local_graph_id = 0',
		array('|host_description| - ' . $label, substr($label, 0, 20), $graph_template_id));
	db_execute_prepared('UPDATE graph_templates_item SET task_item_id = ?
		WHERE graph_template_id = ? AND local_graph_id = 0',
		array($data_template_rrd_id, $graph_template_id));

	return array('data_template_id' => $data_template_id, 'graph_template_id' => $graph_template_id);
}

function nms_template_import($original_name, $community, $template_name, $category_id, $content, $records, $user_id) {
	$category_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_device_categories WHERE id = ?', array($category_id));
	if (!$category_exists) throw new InvalidArgumentException('Select a valid device category.');
	$hash = hash('sha256', $content);
	if ((int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_snmprec_imports WHERE file_hash = ? OR community = ?', array($hash, $community))) {
		throw new InvalidArgumentException('This file or simulator community has already been imported.');
	}

	$graphable_count = 0;
	$section_totals = array();
	foreach ($records as $record) {
		if (!$record['graphable']) continue;
		$graphable_count++;
		$section_key = strtolower(trim((string) $record['section']));
		$section_totals[$section_key] = ($section_totals[$section_key] ?? 0) + 1;
	}
	if ($graphable_count === 0) throw new InvalidArgumentException('The file has no numeric readings that Cacti can graph.');
	if ($graphable_count > 64) throw new InvalidArgumentException('A single import can create at most 64 graphable readings.');

	$target_path = '';
	db_execute('START TRANSACTION');
	try {
		$host_template_id = nms_template_host($template_name);
		db_execute_prepared('INSERT INTO plugin_nms_category_templates (host_template_id, category_id, assigned_at)
			VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), assigned_at = NOW()',
			array($host_template_id, $category_id));
		db_execute_prepared('INSERT INTO plugin_nms_snmprec_imports
			(original_name, community, template_name, host_template_id, category_id, record_count,
			graphable_count, file_hash, deployed_path, uploaded_by, created_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', array(
			$original_name, $community, $template_name, $host_template_id, $category_id,
			count($records), $graphable_count, $hash, '', $user_id
		));
		$import_id = (int) db_fetch_cell('SELECT LAST_INSERT_ID()');

		$section_positions = array();
		foreach ($records as $record) {
			$data_template_id = 0;
			$graph_template_id = 0;
			if ($record['graphable']) {
				$section_key = strtolower(trim((string) $record['section']));
				$section_positions[$section_key] = ($section_positions[$section_key] ?? 0) + 1;
				$record['reading_index'] = $section_positions[$section_key];
				$record['reading_total'] = $section_totals[$section_key];
				$pair = nms_template_pair($template_name, $record);
				$data_template_id = $pair['data_template_id'];
				$graph_template_id = $pair['graph_template_id'];
				db_execute_prepared('REPLACE INTO host_template_graph (host_template_id, graph_template_id) VALUES (?, ?)',
					array($host_template_id, $graph_template_id));
			}
			db_execute_prepared('INSERT INTO plugin_nms_snmprec_oids
				(import_id, oid, tag, raw_value, section_name, graphable, data_template_id, graph_template_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array(
				$import_id, $record['oid'], $record['tag'], $record['value'], $record['section'],
				$record['graphable'] ? 'on' : '', $data_template_id, $graph_template_id
			));
		}

		$target_path = nms_snmprec_deploy($community, $content);
		db_execute_prepared('UPDATE plugin_nms_snmprec_imports SET deployed_path = ? WHERE id = ?',
			array($target_path, $import_id));
		db_execute('COMMIT');
		return array('import_id' => $import_id, 'host_template_id' => $host_template_id,
			'graphable_count' => $graphable_count, 'deployed_path' => $target_path);
	} catch (Throwable $exception) {
		db_execute('ROLLBACK');
		if ($target_path !== '' && is_file($target_path)) @unlink($target_path);
		throw $exception;
	}
}

function nms_template_upgrade_readable_names() {
	$migration_key = 'readable_template_names_v1';
	if ((string) db_fetch_cell_prepared('SELECT meta_value FROM plugin_nms_meta WHERE meta_key = ?', array($migration_key)) === 'done') return;

	$rows = db_fetch_assoc("SELECT o.id, o.import_id, o.oid, o.section_name AS section,
		o.data_template_id, o.graph_template_id, i.template_name
		FROM plugin_nms_snmprec_oids AS o
		INNER JOIN plugin_nms_snmprec_imports AS i ON i.id = o.import_id
		WHERE o.graphable = 'on' AND o.data_template_id > 0 AND o.graph_template_id > 0
		ORDER BY o.import_id, o.id");
	$totals = array();
	foreach ($rows as $row) {
		$key = (int) $row['import_id'] . ':' . strtolower(trim((string) $row['section']));
		$totals[$key] = ($totals[$key] ?? 0) + 1;
	}

	$positions = array();
	foreach ($rows as $row) {
		$key = (int) $row['import_id'] . ':' . strtolower(trim((string) $row['section']));
		$positions[$key] = ($positions[$key] ?? 0) + 1;
		$record = array(
			'oid' => $row['oid'], 'section' => $row['section'],
			'reading_index' => $positions[$key], 'reading_total' => $totals[$key]
		);
		$label = nms_template_record_label($record);
		$prefix = preg_match('/^NMS\b/i', $row['template_name']) ? $row['template_name'] : 'NMS ' . $row['template_name'];
		$object_name = substr($prefix . ' - ' . $label, 0, 190);
		$data_template_id = (int) $row['data_template_id'];
		$graph_template_id = (int) $row['graph_template_id'];

		db_execute_prepared('UPDATE data_template SET name = ? WHERE id = ?', array($object_name, $data_template_id));
		db_execute_prepared('UPDATE data_template_data SET name = ? WHERE data_template_id = ? AND local_data_id = 0',
			array('|host_description| - ' . $label, $data_template_id));
		db_execute_prepared('UPDATE data_template_rrd SET data_source_name = ? WHERE data_template_id = ? AND local_data_id = 0',
			array(nms_template_data_source_name($record), $data_template_id));
		db_execute_prepared('UPDATE graph_templates SET name = ? WHERE id = ?', array($object_name, $graph_template_id));
		db_execute_prepared('UPDATE graph_templates_graph SET title = ?, vertical_label = ? WHERE graph_template_id = ? AND local_graph_id = 0',
			array('|host_description| - ' . $label, substr($label, 0, 20), $graph_template_id));
	}

	db_execute_prepared("INSERT INTO plugin_nms_meta (meta_key, meta_value, updated_at) VALUES (?, 'done', NOW())
		ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = NOW()", array($migration_key));
}
