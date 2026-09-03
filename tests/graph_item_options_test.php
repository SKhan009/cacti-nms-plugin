<?php
/** No database writes: cover all native types, expansions and validation. */
require_once(__DIR__ . '/../includes/graph_item_options.php');
$consolidation_functions = array(1 => 'AVERAGE', 2 => 'MIN', 3 => 'MAX', 4 => 'LAST');
$struct_graph_item = array('graph_type_id' => array('default' => 4), 'color_id' => array('default' => 0),
	'alpha' => array('default' => 'FF'), 'gprint_id' => array('default' => 2),
	'text_format' => array('max_length' => 255), 'line_width' => array('max_length' => 5),
	'dashes' => array('max_length' => 40), 'dash_offset' => array('max_length' => 4), 'value' => array('max_length' => 255),
	'textalign' => array('default' => '', 'array' => array('' => 'None', 'left' => 'Left', 'right' => 'Right', 'center' => 'Center', 'justified' => 'Justified')));
$types = array(1=>'COMMENT',2=>'HRULE',3=>'VRULE',4=>'LINE1',5=>'LINE2',6=>'LINE3',7=>'AREA',8=>'AREA:STACK',9=>'GPRINT',10=>'LEGEND',11=>'GPRINT:LAST',12=>'GPRINT:MAX',13=>'GPRINT:MIN',14=>'GPRINT:AVERAGE',15=>'LEGEND_CAMM',20=>'LINE:STACK',30=>'TICK',40=>'TEXTALIGN');
/** Stop the standalone regression check on an unexpected item row. */
function expect_item($condition, $message) { if (!$condition) throw new RuntimeException($message); }
foreach ($types as $id => $type) {
	$rows = nms_graph_item_rows($types, 101, 'test_reading', array('graph_style'=>$type, 'item_value'=>$type==='VRULE'?'1700000000':'0.1', 'stack_source_id'=>102));
	$ids = array_column($rows, 'graph_type_id');
	if ($type==='LEGEND' || $type==='LEGEND_CAMM') {
		expect_item($ids === array_fill(0, $type==='LEGEND'?3:4, 9), $type . ' expansion');
		expect_item(array_column($rows, 'consolidation_function_id') === ($type==='LEGEND'?array(4,1,3):array(4,1,2,3)), $type . ' CF order');
	} else expect_item(in_array($id, $ids, true), $type . ' missing');
	if (strpos($type, ':STACK') !== false) expect_item($rows[0]['task_item_id']===102 && $rows[1]['task_item_id']===101, 'Stack order');
	if (in_array($type, array('COMMENT','HRULE','VRULE','TEXTALIGN'))) expect_item($rows[0]['task_item_id']===0, 'Annotation unexpectedly bound to source');
}
$rows = nms_graph_item_rows($types,101,'test',array('graph_style'=>'LINE3','show_average'=>false,'show_current'=>false,'show_maximum'=>false,'show_minimum'=>false,'shift'=>true,'shift_seconds'=>'60','dashes'=>'5,3','color_id'=>0));
expect_item(count($rows)===1 && $rows[0]['line_width']===3.0 && $rows[0]['color_id']===0 && $rows[0]['value']==='60', 'Plot options');
foreach (array(array('graph_style'=>'unknown'),array('graph_style'=>'AREA:STACK'),array('graph_style'=>'TICK','item_value'=>2),array('graph_style'=>'VRULE','item_value'=>'text'),array('graph_style'=>'LINE1','line_width'=>-1),array('graph_style'=>'LINE1','dashes'=>'bad'),array('graph_style'=>'TEXTALIGN','textalign'=>'invalid')) as $options) {
	try { nms_graph_item_rows($types,101,'test',$options); } catch (InvalidArgumentException $e) { continue; }
	throw new RuntimeException('Invalid item accepted: ' . json_encode($options));
}
// Prove IDs/defaults are taken from Cacti rather than assumed to be 1..4, preset 2 or rounded alpha.
$consolidation_functions = array(11 => 'AVERAGE', 12 => 'MIN', 13 => 'MAX', 14 => 'LAST');
$struct_graph_item['gprint_id']['default'] = 27;
$rows = nms_graph_item_rows($types, 101, 'test', array('graph_style' => 'LEGEND', 'alpha' => '7F'));
expect_item(array_column($rows, 'consolidation_function_id') === array(14, 11, 13), 'Native CF IDs ignored');
expect_item($rows[0]['gprint_id'] === 27 && $rows[0]['alpha'] === '7F', 'Native preset or alpha changed');
echo "All 18 graph item types, native defaults/IDs, stack/legend expansion and invalid-value checks passed.\n";
