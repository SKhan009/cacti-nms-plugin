<?php
/** Standalone regression checks for core-owned choices, validation, rendering and POST collection. */
require_once(__DIR__ . '/../includes/core_form_options.php');
/** Fail visibly instead of depending on PHP's optional assertion settings. */
function expect_core($ok, $message) { if (!$ok) throw new RuntimeException($message); }
/** Supply database-owned presets without a running Cacti database. */
function db_fetch_assoc($sql) { return array(array('id' => 27, 'name' => 'Installed preset')); }
/** Escape generated HTML exactly as the plugin does. */
function nms_h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
/** Simulate Cacti's request presence check. */
function isset_request_var($name) { return isset($_POST[$name]); }
/** Simulate Cacti's unfiltered request reader. */
function get_nfilter_request_var($name) { return $_POST[$name] ?? ''; }

$struct_graph = array(
	'common' => array('method' => 'spacer', 'friendly_name' => 'Common'),
	'title' => array('method' => 'textbox', 'friendly_name' => 'Title', 'default' => '', 'max_length' => 255),
	'vertical_label' => array('method' => 'textbox', 'friendly_name' => 'Label', 'default' => '', 'max_length' => 255),
	'width' => array('method' => 'textbox', 'friendly_name' => 'Width', 'default' => '637', 'max_length' => 50),
	'height' => array('method' => 'textbox', 'friendly_name' => 'Height', 'default' => '213', 'max_length' => 50),
	'base_value' => array('method' => 'textbox', 'friendly_name' => 'Base', 'default' => '1000'),
	'image_format_id' => array('method' => 'drop_array', 'friendly_name' => 'Format', 'default' => '8', 'array' => array(8 => 'Installed format')),
	'auto_scale_opts' => array('method' => 'radio', 'friendly_name' => 'Scale', 'default' => '7', 'items' => array(array('radio_value' => 7, 'radio_caption' => 'Installed scale'))),
	'lower_limit' => array('method' => 'textbox', 'friendly_name' => 'Lower', 'default' => '0'),
	'upper_limit' => array('method' => 'textbox', 'friendly_name' => 'Upper', 'default' => '100'),
	'right_axis' => array('method' => 'textbox', 'friendly_name' => 'Right axis', 'default' => ''),
	'legend_position' => array('method' => 'drop_array', 'friendly_name' => 'Legend', 'none_value' => 'None', 'array' => array('north' => 'North')),
	'right_axis_format' => array('method' => 'drop_sql', 'friendly_name' => 'Preset', 'none_value' => 'None', 'default' => '', 'sql' => 'SELECT id, name FROM graph_templates_gprint'),
	'auto_scale' => array('method' => 'checkbox', 'friendly_name' => 'Auto scale', 'default' => 'on')
);
$fields_graph_template_template_edit = array('multiple' => array('method' => 'checkbox'), 'name' => array('method' => 'textbox'));
$values = nms_core_graph_values(array('title' => '|host_description|', 'vertical_label' => 'Temperature °C — long label', 'width' => '643', 'lower_limit' => 'U', 'upper_limit' => '-1.5e+4', 'right_axis' => '2.5:-1', 't_width' => 'on', 'id' => 99));
expect_core($values['width'] === '643' && $values['height'] === '213' && $values['image_format_id'] === '8', 'Native defaults or arbitrary width lost');
expect_core($values['t_width'] === '' && $values['t_height'] === '' && !isset($values['id']), 'NMS must not enable per-graph overrides');
expect_core($values['vertical_label'] === 'Temperature °C — long label', 'Unicode label was changed');
expect_core($values['legend_position'] === '0' && $values['right_axis_format'] === '0', 'Native None option missing');
foreach (array(array('image_format_id' => 3), array('width' => '10px'), array('right_axis_format' => 2), array('right_axis' => 'bad'), array('lower_limit' => '1bad'), array('title' => array('bad')), array('vertical_label' => str_repeat('x', 256))) as $invalid) {
	try { nms_core_graph_values($invalid + array('title' => 'Test')); } catch (InvalidArgumentException $e) { continue; }
	throw new RuntimeException('Invalid core option accepted: ' . json_encode($invalid));
}
$_POST = array('title' => 'Test', 'width' => '711', 't_width' => 'on', 'id' => '99');
$request = nms_core_graph_request();
expect_core($request['auto_scale'] === '' && $request['t_width'] === '' && !isset($request['id']), 'POST must ignore override flags');
ob_start();
foreach ($struct_graph as $name => $field) if ($field['method'] !== 'spacer') nms_core_render_field($name, $field, $values[$name], $values['t_' . $name] === 'on');
$html = ob_get_clean();
expect_core(strpos($html, 'Installed scale') !== false && strpos($html, 'Installed preset') !== false && strpos($html, 'value="643"') !== false, 'Core choices not rendered');
expect_core(strpos($html, 'name="t_') === false && strpos($html, 'Allow override') === false, 'Override UI must not render');
echo "Core choices, configured defaults, free sizes, Unicode, override exclusion, validation and rendering passed.\n";
