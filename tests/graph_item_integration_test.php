<?php
/** Explicit VM QA: creates uniquely named templates and removes only those test objects. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run') die("CLI only; pass --run to create and clean up temporary QA templates.\n");
require_once(__DIR__ . '/../../../include/cli_check.php');
require_once(__DIR__ . '/../includes/functions.php');
require_once(__DIR__ . '/../includes/database.php');
require_once(__DIR__ . '/../includes/template_manager.php');
require_once(__DIR__ . '/../includes/graph_template_manager.php');
$source_id = (int) db_fetch_cell('SELECT id FROM data_template_rrd WHERE local_data_id = 0 ORDER BY id LIMIT 1');
if (!$source_id) throw new RuntimeException('No reusable data source available.');
$stack_source_id = (int) db_fetch_cell_prepared('SELECT id FROM data_template_rrd WHERE local_data_id = 0 AND id <> ? ORDER BY id LIMIT 1', array($source_id));
if (!$stack_source_id) throw new RuntimeException('A second reusable source is required for stack QA.');
$run = 'NMS QA Item Types ' . bin2hex(random_bytes(6));
foreach ($graph_item_types as $type_id => $type) {
	$id = 0;
	try {
		$options = array('graph_style'=>$type, 'item_value'=>$type==='VRULE'?'1700000000':'0.1', 'stack_source_id'=>$stack_source_id,
			'width'=>643,'height'=>217,'base_value'=>1000, 'text_format'=>'QA',
			'right_axis'=>'2:0','right_axis_label'=>'QA secondary axis','unit_exponent_value'=>'-3',
			'alt_y_grid'=>'on','dynamic_labels'=>'on','t_width'=>'on','multiple'=>'on','test_source'=>'on');
		$id = nms_graph_template_create($source_id, $run . ' ' . $type, 'Value', $options);
		// Independently check advanced settings and override flags in the native backend tables.
		$graph = db_fetch_row_prepared('SELECT * FROM graph_templates_graph WHERE graph_template_id = ? AND local_graph_id = 0', array($id));
		if ($graph['t_width'] !== '') throw new RuntimeException('NMS enabled an override flag.');
		foreach (array('width','height','right_axis','right_axis_label','unit_exponent_value','alt_y_grid','dynamic_labels') as $key) {
			if ((string) $graph[$key] !== (string) $options[$key]) throw new RuntimeException('Native graph setting mismatch: ' . $key);
		}
		$parent = db_fetch_row_prepared('SELECT multiple, test_source FROM graph_templates WHERE id = ?', array($id));
		if ($parent['multiple'] !== 'on' || $parent['test_source'] !== 'on') throw new RuntimeException('Template flags not saved.');
		$actual = db_fetch_assoc_prepared('SELECT graph_type_id,task_item_id,consolidation_function_id,sequence FROM graph_templates_item WHERE graph_template_id = ? ORDER BY sequence', array($id));
		$expected = nms_graph_item_rows($graph_item_types, $source_id, 'QA', $options);
		if (count($actual)!==count($expected)) throw new RuntimeException('Wrong item count for ' . $type);
		foreach ($actual as $index => $row) {
			foreach (array('graph_type_id','task_item_id','consolidation_function_id') as $column) {
				if ((int)$row[$column] !== (int)$expected[$index][$column]) throw new RuntimeException('Wrong ' . $column . ' for ' . $type);
			}
		}
		$mapped = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_template_input_defs d INNER JOIN graph_templates_item i ON i.id=d.graph_template_item_id WHERE i.graph_template_id=?', array($id));
		$expected_mapped = count(array_filter($expected, function ($row) { return $row['task_item_id'] > 0; }));
		if ($mapped !== $expected_mapped) throw new RuntimeException('Wrong input associations for ' . $type);
		echo $type . ': native save and input associations passed' . PHP_EOL;
	} finally {
		if ($id) {
			nms_graph_template_delete($id);
			if ((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_templates WHERE id=?', array($id))) throw new RuntimeException('QA cleanup failed for template ' . $id);
		}
	}
}
echo "All native item types saved correctly; temporary QA templates removed.\n";
