<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_setup_database();

function nms_fault_parameter_exists($category_id, $parameter_key) {
	if ($parameter_key === 'core:status') return true;
	if (strpos($parameter_key, 'dtrr:') !== 0) return false;
	$template_item_id = (int) substr($parameter_key, 5);
	return (int) db_fetch_cell_prepared("SELECT COUNT(*)
		FROM plugin_nms_category_templates AS ct
		INNER JOIN host AS h ON h.host_template_id = ct.host_template_id AND h.deleted = '' AND h.disabled = ''
		INNER JOIN poller_item AS pi ON pi.host_id = h.id
		INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
		WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id = ?",
		array((int) $category_id, $template_item_id)) > 0;
}

$allowed_comparisons = array(
	'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal',
	'equals', 'not_equals', 'contains', 'not_contains', 'is_unknown', 'is_not_unknown'
);
$allowed_severities = array('critical', 'major', 'warning');
$tab = isset_request_var('tab') ? get_nfilter_request_var('tab') : 'rules';
if (!in_array($tab, array('rules', 'templates'), true)) $tab = 'rules';
$category_id = isset_request_var('category_id') ? get_filter_request_var('category_id') : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');

	if ($action === 'assign_template') {
		$template_id = get_filter_request_var('host_template_id');
		$category_id = get_filter_request_var('category_id');
		$template_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', array($template_id));
		$category_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_tree WHERE id = ?', array($category_id));
		if ($template_exists && $category_exists) {
			db_execute_prepared('INSERT INTO plugin_nms_category_templates (host_template_id, category_id, assigned_at)
				VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), assigned_at = NOW()',
				array($template_id, $category_id));
		}
		$tab = 'templates';
	} elseif ($action === 'add_rule' || $action === 'save_rule') {
		$rule_id = $action === 'save_rule' ? get_filter_request_var('rule_id') : 0;
		$category_id = get_filter_request_var('category_id');
		$name = trim(get_nfilter_request_var('name'));
		$parameter_key = get_nfilter_request_var('parameter_key');
		$comparison = get_nfilter_request_var('comparison');
		$threshold_value = trim(get_nfilter_request_var('threshold_value'));
		$unit = substr(trim(get_nfilter_request_var('unit')), 0, 24);
		$severity = get_nfilter_request_var('severity');
		$enabled = isset_request_var('enabled') ? 'on' : '';
		$metric = $parameter_key === 'core:status' ? 'core_status' : 'parameter';
		$numeric_comparisons = array('greater_than', 'greater_or_equal', 'less_than', 'less_or_equal');
		$valid_threshold = in_array($comparison, array('is_unknown', 'is_not_unknown'), true) ||
			(in_array($comparison, $numeric_comparisons, true) ? is_numeric($threshold_value) : $threshold_value !== '');
		$valid_parameter = nms_fault_parameter_exists($category_id, $parameter_key);
		if (!$valid_parameter && $action === 'save_rule') {
			$valid_parameter = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_fault_rules
				WHERE id = ? AND category_id = ? AND parameter_key = ?', array($rule_id, $category_id, $parameter_key)) > 0;
		}

		if ($name !== '' && in_array($comparison, $allowed_comparisons, true) &&
			in_array($severity, $allowed_severities, true) && $valid_threshold &&
			$valid_parameter) {
			if ($action === 'add_rule') {
				$sort_order = (int) db_fetch_cell_prepared('SELECT COALESCE(MAX(sort_order), 0) + 10
					FROM plugin_nms_fault_rules WHERE category_id = ?', array($category_id));
				db_execute_prepared('INSERT INTO plugin_nms_fault_rules
					(category_id, name, metric, parameter_key, comparison, threshold, threshold_value, unit,
					severity, enabled, sort_order, created_at, updated_at)
					VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW(), NOW())', array(
						$category_id, $name, $metric, $parameter_key, $comparison, $threshold_value,
						$unit, $severity, 'on', $sort_order
					));
			} else {
				db_execute_prepared('UPDATE plugin_nms_fault_rules SET name = ?, comparison = ?,
					threshold_value = ?, unit = ?, severity = ?, enabled = ?, updated_at = NOW()
					WHERE id = ? AND category_id = ?', array(
						$name, $comparison, $threshold_value, $unit, $severity, $enabled, $rule_id, $category_id
					));
			}
		}
		$tab = 'rules';
	}

	nms_sync_device_faults();
	header('Location: fault_config.php?tab=' . rawurlencode($tab) .
		($category_id > 0 ? '&category_id=' . (int) $category_id : '') . '&saved=1');
	exit;
}

$categories = db_fetch_assoc("SELECT c.*,
	(SELECT COUNT(*) FROM plugin_nms_category_templates AS ct WHERE ct.category_id = c.id) AS template_count,
	(SELECT COUNT(DISTINCT h.id) FROM plugin_nms_category_templates AS ct
		INNER JOIN host AS h ON h.host_template_id = ct.host_template_id
		WHERE ct.category_id = c.id AND h.deleted = '' AND h.disabled = '') AS device_count,
	(SELECT COUNT(*) FROM plugin_nms_fault_rules AS r WHERE r.category_id = c.id AND r.enabled = 'on') AS active_rule_count
	FROM graph_tree AS c ORDER BY c.sequence, c.name");

if ($category_id <= 0 && count($categories)) {
	foreach ($categories as $category) {
		if ((int) $category['device_count'] > 0) { $category_id = (int) $category['id']; break; }
	}
	if ($category_id <= 0) $category_id = (int) $categories[0]['id'];
}

$selected_category = null;
foreach ($categories as $category) if ((int) $category['id'] === $category_id) $selected_category = $category;
if (!$selected_category && count($categories)) {
	$selected_category = $categories[0];
	$category_id = (int) $selected_category['id'];
}

$templates = db_fetch_assoc("SELECT ht.id, ht.name, ct.category_id,
	COUNT(DISTINCT CASE WHEN h.deleted = '' AND h.disabled = '' THEN h.id END) AS device_count
	FROM host_template AS ht
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = ht.id
	LEFT JOIN host AS h ON h.host_template_id = ht.id
	GROUP BY ht.id, ht.name, ct.category_id ORDER BY ht.name");

$rules = $category_id > 0 ? db_fetch_assoc_prepared('SELECT * FROM plugin_nms_fault_rules
	WHERE category_id = ? ORDER BY sort_order, id', array($category_id)) : array();

$parameters = $category_id > 0 ? db_fetch_assoc_prepared("SELECT
	CONCAT('dtrr:', dtr.local_data_template_rrd_id) AS parameter_key,
	COALESCE(NULLIF(dt.name, ''), CONCAT('Cacti data template ', dtr.data_template_id)) AS template_name,
	dtr.data_source_name AS parameter_name,
	COUNT(DISTINCT h.id) AS device_count,
	GROUP_CONCAT(DISTINCT NULLIF(p.raw_value, '') ORDER BY p.last_seen DESC SEPARATOR ', ') AS latest_values
	FROM plugin_nms_category_templates AS ct
	INNER JOIN host AS h ON h.host_template_id = ct.host_template_id AND h.deleted = '' AND h.disabled = ''
	INNER JOIN poller_item AS pi ON pi.host_id = h.id
	INNER JOIN data_template_rrd AS dtr ON dtr.local_data_id = pi.local_data_id
	LEFT JOIN data_template AS dt ON dt.id = dtr.data_template_id
	LEFT JOIN plugin_nms_device_parameters AS p ON p.host_id = h.id
		AND p.local_data_id = dtr.local_data_id
		AND p.parameter_key = CONCAT('dtrr:', dtr.local_data_template_rrd_id)
	WHERE ct.category_id = ? AND dtr.local_data_template_rrd_id > 0
	GROUP BY dtr.local_data_template_rrd_id, dt.name, dtr.data_source_name
	ORDER BY dt.name, dtr.data_source_name", array($category_id)) : array();

$comparison_labels = array(
	'greater_than' => 'Greater than (>)', 'greater_or_equal' => 'Greater than or equal (≥)',
	'less_than' => 'Less than (<)', 'less_or_equal' => 'Less than or equal (≤)',
	'equals' => 'Equals', 'not_equals' => 'Does not equal', 'contains' => 'Contains text',
	'not_contains' => 'Does not contain text', 'is_unknown' => 'Is unknown or empty',
	'is_not_unknown' => 'Has a valid value'
);

$linked_devices = (int) db_fetch_cell("SELECT COUNT(DISTINCT h.id)
	FROM host AS h INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
	WHERE h.deleted = '' AND h.disabled = ''");
$enabled_rules = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_fault_rules WHERE enabled = 'on'");
$known_parameters = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_device_parameters AS p
	INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = '' AND h.disabled = ''");
$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
$nms_active_module = 'configuration';
$nms_page_title = 'NMS · Fault Configuration';
$nms_extra_css = 'css/nms-fault-config.css?v=2.1.0';
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell nms-config-shell">
	<div class="nms-heading nms-config-heading">
		<div><p class="nms-eyebrow">NMS / Fault Configuration</p><h1>Device fault rules</h1><p>Use actual Cacti device parameters and apply severity by Cacti Tree category.</p></div>
		<?php if (isset_request_var('saved')) { ?><div class="nms-saved">Saved and checked against live device values</div><?php } ?>
	</div>
	<div class="nms-config-stats"><span><strong><?php print count($categories); ?></strong> Cacti Trees</span><span><strong><?php print count($templates); ?></strong> templates mapped</span><span><strong><?php print $linked_devices; ?></strong> linked devices</span><span><strong><?php print $enabled_rules; ?></strong> active rules</span><span><strong><?php print $known_parameters; ?></strong> latest parameter readings</span></div>
	<nav class="nms-config-tabs" aria-label="Fault configuration sections"><a class="<?php print $tab === 'rules' ? 'selected' : ''; ?>" href="?tab=rules&amp;category_id=<?php print $category_id; ?>">Fault values and severity</a><a class="<?php print $tab === 'templates' ? 'selected' : ''; ?>" href="?tab=templates">Cacti template mapping</a></nav>

	<?php if ($tab === 'rules') { ?>
	<section class="nms-panel nms-config-panel">
		<div class="nms-config-bar"><div><h2>Fault values and severity</h2><p>A rule can use a number such as temperature &gt; 80, or text such as interface state does not equal up.</p></div><form method="get" action="fault_config.php"><input type="hidden" name="tab" value="rules"><label>Device category (Cacti Tree)<select name="category_id" onchange="this.form.submit()"><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $category['id'] === $category_id ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?> (<?php print (int) $category['device_count']; ?>)</option><?php } ?></select></label></form></div>
		<div class="nms-rule-note"><strong><?php print nms_h($selected_category ? $selected_category['name'] : 'Category'); ?></strong><span><?php print count($parameters); ?> actual parameters available from <?php print (int) ($selected_category ? $selected_category['device_count'] : 0); ?> linked devices. Temperature and other sensors appear automatically after Cacti collects them.</span></div>
		<form class="nms-add-rule" method="post" action="fault_config.php">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="add_rule"><input type="hidden" name="category_id" value="<?php print $category_id; ?>">
			<div class="nms-add-rule-title"><strong>Add fault rule</strong><small>Only parameters attached to real Cacti devices are listed.</small></div>
			<label>Device parameter<select name="parameter_key" required><?php foreach ($parameters as $parameter) { ?><option value="<?php print nms_h($parameter['parameter_key']); ?>"><?php print nms_h($parameter['template_name'] . ' · ' . $parameter['parameter_name']); ?></option><?php } ?><option value="core:status">Cacti device state</option></select></label>
			<label>Rule name<input type="text" name="name" maxlength="150" placeholder="Temperature too high" required></label>
			<label>Condition<select name="comparison" required><?php foreach ($comparison_labels as $value => $label) { ?><option value="<?php print $value; ?>"><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label>Fault value<input type="text" name="threshold_value" maxlength="191" placeholder="80 or down"></label><label>Unit<input type="text" name="unit" maxlength="24" placeholder="°C, %, MB"></label>
			<label>Severity<select name="severity"><?php foreach ($allowed_severities as $severity) { ?><option value="<?php print $severity; ?>"><?php print ucfirst($severity); ?></option><?php } ?></select></label><button class="nms-save-button" type="submit">Add rule</button>
		</form>
		<div class="nms-existing-rules"><div class="nms-rule-table-head"><span>Rule / parameter</span><span>Condition</span><span>Fault value</span><span>Severity</span><span>State</span><span></span></div>
		<?php foreach ($rules as $rule) { $parameter_label = $rule['parameter_key'] === 'core:status' ? 'Cacti device state' : $rule['parameter_key']; foreach ($parameters as $parameter) if ($parameter['parameter_key'] === $rule['parameter_key']) $parameter_label = $parameter['template_name'] . ' · ' . $parameter['parameter_name']; ?>
			<form class="nms-compact-rule" method="post" action="fault_config.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_rule"><input type="hidden" name="rule_id" value="<?php print (int) $rule['id']; ?>"><input type="hidden" name="category_id" value="<?php print $category_id; ?>"><input type="hidden" name="parameter_key" value="<?php print nms_h($rule['parameter_key']); ?>"><div><input aria-label="Rule name" type="text" name="name" maxlength="150" value="<?php print nms_h($rule['name']); ?>" required><small><?php print nms_h($parameter_label); ?></small></div><select aria-label="Condition" name="comparison"><?php foreach ($comparison_labels as $value => $label) { ?><option value="<?php print $value; ?>" <?php print $rule['comparison'] === $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option><?php } ?></select><div class="nms-threshold-pair"><input aria-label="Fault value" type="text" name="threshold_value" maxlength="191" value="<?php print nms_h($rule['threshold_value']); ?>"><input aria-label="Unit" type="text" name="unit" maxlength="24" value="<?php print nms_h($rule['unit']); ?>" placeholder="unit"></div><select aria-label="Severity" name="severity"><?php foreach ($allowed_severities as $severity) { ?><option value="<?php print $severity; ?>" <?php print $rule['severity'] === $severity ? 'selected' : ''; ?>><?php print ucfirst($severity); ?></option><?php } ?></select><label class="nms-enabled"><input type="checkbox" name="enabled" value="on" <?php print $rule['enabled'] === 'on' ? 'checked' : ''; ?>><span>Enabled</span></label><button class="nms-save-button" type="submit">Save</button></form>
		<?php } ?></div>
	</section>
	<?php } else { ?>
	<section class="nms-panel nms-config-panel"><div class="nms-config-bar"><div><h2>Cacti template mapping</h2><p>Device categories come directly from Cacti Graph Trees. A mapping applies that tree's fault rules to every device using the selected host template.</p></div><button class="nms-category-popup-button" type="button" popovertarget="nmsCategorySummary">Tree summary</button></div><div id="nmsCategorySummary" class="nms-category-popup" popover><div class="nms-popup-head"><div><strong>Cacti Tree category summary</strong><small>Current Cacti templates and linked devices</small></div><button type="button" popovertarget="nmsCategorySummary" popovertargetaction="hide" aria-label="Close category summary">×</button></div><div class="nms-popup-categories"><?php foreach ($categories as $category) { ?><div><strong><?php print nms_h($category['name']); ?></strong><span><?php print (int) $category['template_count']; ?> templates · <?php print (int) $category['device_count']; ?> devices</span></div><?php } ?></div></div><div class="nms-table-wrap"><table class="nms-table nms-config-table"><thead><tr><th>Cacti host template</th><th>ID</th><th>Linked devices</th><th>Device category (Cacti Tree)</th><th></th></tr></thead><tbody>
	<?php foreach ($templates as $template) { $mapping_form_id = 'nms-map-' . (int) $template['id']; ?><tr><td><strong><?php print nms_h($template['name']); ?></strong></td><td><?php print (int) $template['id']; ?></td><td><?php print (int) $template['device_count']; ?></td><td><form id="<?php print $mapping_form_id; ?>" method="post" action="fault_config.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="assign_template"><input type="hidden" name="host_template_id" value="<?php print (int) $template['id']; ?>"><select name="category_id" aria-label="Category for <?php print nms_h($template['name']); ?>"><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $template['category_id'] === (int) $category['id'] ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?></select></form></td><td><button class="nms-save-button" type="submit" form="<?php print $mapping_form_id; ?>">Save</button></td></tr><?php } ?>
	</tbody></table></div></section>
	<?php } ?>
</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
