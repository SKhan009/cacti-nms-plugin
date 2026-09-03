<?php
/**
 * @file fault_config.php
 * Fault Configuration page: manage equipment categories, suggest template defaults, and create category-scoped fault rules.
 * Request handling and queries below prepare the forms; reusable rule validation lives in includes/functions.php.
 */

// Let Cacti authenticate the request before loading shared NMS rule and storage helpers.
require(__DIR__ . '/../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_require_database();

// Use the same comparison and severity definitions for validation and form options.
$comparison_labels = nms_fault_comparisons();
$allowed_severities = nms_fault_severities();
$tab = isset_request_var('tab') ? get_nfilter_request_var('tab') : 'rules';
if (!in_array($tab, array('rules', 'templates', 'groups'), true)) $tab = 'rules';
// Use a new request namespace so bookmarked tree IDs cannot select unrelated categories.
$category_id = isset_request_var('equipment_category_id') ? get_filter_request_var('equipment_category_id') : 0;
$page_error = '';

// Process submitted changes before rendering HTML so successful actions can redirect safely.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	try {
		nms_require_management();
		if ($action === 'save_group') {
			nms_group_save(get_filter_request_var('group_id'), get_nfilter_request_var('name'), get_nfilter_request_var('description'));
			$tab = 'groups';
		} elseif ($action === 'save_category') {
			$category_id = nms_category_save(get_filter_request_var('equipment_category_id'),
				get_nfilter_request_var('name'), get_nfilter_request_var('description'));
			$tab = 'templates';
		} elseif ($action === 'assign_template') {
			$category_id = get_filter_request_var('equipment_category_id');
			nms_assign_template_category(get_filter_request_var('host_template_id'), $category_id);
			$tab = 'templates';
		} elseif ($action === 'save_rule_scope') {
			$scope_input = array();
			foreach (array_keys(nms_rule_scope_defaults()) as $field) {
				if (!isset_request_var($field)) throw new InvalidArgumentException('Incomplete applicability form. Reload the current rule editor before saving.');
				$scope_input[$field] = get_nfilter_request_var($field);
			}
			nms_rule_scope_save($category_id, get_filter_request_var('rule_id'), $scope_input);
			$tab = 'rules';
		} elseif ($action === 'add_rule' || $action === 'save_rule') {
			// ID zero creates a rule; a supplied ID updates one after category/parameter validation.
			$rule_id = $action === 'save_rule' ? get_filter_request_var('rule_id') : 0;
			$category_id = get_filter_request_var('equipment_category_id');
			nms_fault_rule_save($category_id, $rule_id, array(
				'name' => get_nfilter_request_var('name'),
				'parameter_key' => get_nfilter_request_var('parameter_key'),
				'comparison' => get_nfilter_request_var('comparison'),
				'threshold_value' => get_nfilter_request_var('threshold_value'),
				'unit' => get_nfilter_request_var('unit'),
				'severity' => get_nfilter_request_var('severity'),
				'enabled' => $action === 'add_rule' || isset_request_var('enabled')
			));
			$tab = 'rules';
		} else {
			throw new InvalidArgumentException('Unsupported fault configuration action.');
		}

		// Cacti's next post-poll hook evaluates saved policy; web requests do not reconcile all devices.
		header('Location: fault_config.php?tab=' . rawurlencode($tab) .
			($category_id > 0 ? '&equipment_category_id=' . (int) $category_id : '') . '&saved=1');
		exit;
	} catch (Throwable $exception) {
		// Render the validation failure on this page instead of ending the request with an uncaught error.
		$page_error = $exception->getMessage();
	}
}

// Equipment categories are independent; template mappings are defaults, not device membership.
$visible_hosts = nms_visible_host_sql();
$categories = db_fetch_assoc("SELECT c.*,
	(SELECT COUNT(*) FROM plugin_nms_category_templates AS ct WHERE ct.category_id = c.id) AS template_count,
	(SELECT COUNT(DISTINCT h.id) FROM plugin_nms_device_classification AS ct
		INNER JOIN host AS h ON h.id = ct.host_id
		WHERE ct.category_id = c.id AND h.deleted = '' AND h.disabled = '' AND $visible_hosts) AS device_count,
	(SELECT COUNT(*) FROM plugin_nms_fault_rules AS r WHERE r.category_id = c.id AND r.enabled = 'on') AS active_rule_count
	FROM plugin_nms_categories AS c ORDER BY c.sort_order, c.name, c.id");

// With no selection, start at a category with devices.
if ($category_id <= 0 && count($categories)) {
	foreach ($categories as $category) {
		if ((int) $category['device_count'] > 0) { $category_id = (int) $category['id']; break; }
	}
	if ($category_id <= 0) $category_id = (int) $categories[0]['id'];
}

// Invalid explicit IDs must not silently select a different rule scope.
$selected_category = null;
foreach ($categories as $category) if ((int) $category['id'] === $category_id) $selected_category = $category;
if (!$selected_category && $category_id > 0) {
	$page_error = 'The selected equipment category does not exist. Select a current category.';
	$category_id = 0;
}
if (isset_request_var('category_id')) {
	$page_error = 'This link uses an old Cacti tree category ID. Select an equipment category below.';
}

// Native template classes remain core-owned; NMS adds a category suggestion.
$templates = db_fetch_assoc("SELECT ht.id, ht.name, ht.class, ct.category_id,
	COUNT(DISTINCT CASE WHEN h.deleted = '' AND h.disabled = '' THEN h.id END) AS device_count
	FROM host_template AS ht
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = ht.id
	LEFT JOIN host AS h ON h.host_template_id = ht.id AND $visible_hosts
	GROUP BY ht.id, ht.name, ht.class, ct.category_id ORDER BY ht.name");

// Load only the selected equipment category's saved rules.
$rules = $category_id > 0 ? db_fetch_assoc_prepared('SELECT * FROM plugin_nms_fault_rules
	WHERE category_id = ? ORDER BY sort_order, id', array($category_id)) : array();

// Discover actual core data-source and text-inventory parameters instead of inventing readings from imports.
$fresh_after = nms_parameter_fresh_after();
$parameters = nms_fault_parameter_catalog($category_id);

// Applicability options are native records, never a copied protocol/model catalogue.
$scope_devices = $tab === 'rules' ? db_fetch_assoc_prepared("SELECT h.id, h.description AS name FROM host AS h
	INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	WHERE ct.category_id = ? AND h.deleted = '' AND $visible_hosts ORDER BY h.description", array($category_id)) : array();
$scope_inputs = $tab === 'rules' ? db_fetch_assoc('SELECT id, name FROM data_input ORDER BY name') : array();
$scope_queries = $tab === 'rules' ? db_fetch_assoc('SELECT id, name FROM snmp_query ORDER BY name') : array();
$scope_model_ids = $tab === 'rules' ? db_fetch_assoc_prepared("SELECT DISTINCT h.snmp_sysObjectID FROM host AS h
	INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	WHERE ct.category_id = ? AND h.deleted = '' AND h.snmp_sysObjectID != '' AND $visible_hosts
	ORDER BY h.snmp_sysObjectID", array($category_id)) : array();
$scope_sources = $tab === 'rules' ? db_fetch_assoc_prepared("SELECT dl.id,
	CONCAT(h.description, ' · ', dtd.name_cache, ' · data source ', dl.id) AS name
	FROM data_local AS dl INNER JOIN host AS h ON h.id = dl.host_id
	INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	INNER JOIN data_template_data AS dtd ON dtd.local_data_id = dl.id
	WHERE ct.category_id = ? AND h.deleted = '' AND $visible_hosts ORDER BY h.description, dl.id", array($category_id)) : array();

// Build page-wide counters; numeric sample counts require a fresh reading from an Up device.
$linked_devices = (int) db_fetch_cell("SELECT COUNT(DISTINCT h.id)
	FROM host AS h INNER JOIN plugin_nms_device_classification AS ct ON ct.host_id = h.id
	WHERE h.deleted = '' AND h.disabled = '' AND $visible_hosts");
$enabled_rules = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_fault_rules WHERE enabled = 'on'");
$known_parameters = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_device_parameters AS p
	INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = '' AND h.disabled = '' AND h.status = " . HOST_UP . "
	WHERE p.last_seen BETWEEN ? AND ? AND h.last_updated BETWEEN ? AND ? AND $visible_hosts
	AND LOWER(TRIM(p.raw_value)) NOT IN ('', 'u', 'unknown', 'nan', 'null')", array($fresh_after, nms_now(), $fresh_after, nms_now()));
$known_parameters += (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_device_inventory AS di
	INNER JOIN host AS h ON h.id = di.host_id AND h.deleted = '' AND h.disabled = '' AND $visible_hosts
	WHERE h.status = " . HOST_UP . " AND h.last_updated BETWEEN ? AND ?
	AND di.status IN ('ok', 'changed') AND di.last_success BETWEEN ? AND ?
	AND di.last_attempt BETWEEN ? AND ? AND TRIM(di.observed_value) != ''",
	array($fresh_after, nms_now(), $fresh_after, nms_now(), $fresh_after, nms_now()));
// Set shared navigation, asset URLs, and CSRF form tokens before rendering the configuration forms.
nms_prepare_page('configuration', 'NMS · Fault Configuration', 'css/nms-fault-config.css');
$operational_groups = $tab === 'groups' ? nms_groups() : array();
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell nms-config-shell">
	<div class="nms-heading nms-config-heading">
		<div><p class="nms-eyebrow">NMS / Fault Configuration</p><h1>Device fault rules</h1><p>Use actual Cacti device parameters and apply severity by each device’s equipment category.</p></div>
		<?php if (isset_request_var('saved')) { ?><div class="nms-saved">Configuration saved; evaluated after the next Cacti poll</div><?php } ?>
	</div>
	<?php if ($page_error !== '') { ?><div class="nms-config-error"><strong>Could not save configuration</strong><span><?php print nms_h($page_error); ?></span></div><?php } ?>
		<div class="nms-config-stats"><span><strong><?php print count($categories); ?></strong> equipment categories</span><span><strong><?php print count($templates); ?></strong> available templates</span><span><strong><?php print $linked_devices; ?></strong> linked devices</span><span><strong><?php print $enabled_rules; ?></strong> active rules</span><span><strong><?php print $known_parameters; ?></strong> current monitored parameters</span></div>
	<nav class="nms-config-tabs" aria-label="Fault configuration sections"><a class="<?php print $tab === 'rules' ? 'selected' : ''; ?>" href="?tab=rules&amp;equipment_category_id=<?php print $category_id; ?>">Fault values and severity</a><a class="<?php print $tab === 'templates' ? 'selected' : ''; ?>" href="?tab=templates">Categories and template defaults</a><a class="<?php print $tab === 'groups' ? 'selected' : ''; ?>" href="?tab=groups">Operational groups</a></nav>

	<?php if ($tab === 'rules') { ?>
	<section class="nms-panel nms-config-panel">
		<div class="nms-config-bar"><div><h2>Fault values and severity</h2><p>A rule can use a number such as temperature &gt; 80, or text such as interface state does not equal up.</p></div><form method="get" action="fault_config.php"><input type="hidden" name="tab" value="rules"><label>Device category<select name="equipment_category_id" onchange="this.form.submit()"><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $category['id'] === $category_id ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?></select></label></form></div>
		<div class="nms-rule-note"><strong><?php print nms_h($selected_category ? $selected_category['name'] : 'Category'); ?></strong><span><?php print count($parameters); ?> parameters available from <?php print (int) ($selected_category ? $selected_category['device_count'] : 0); ?> linked devices. Cacti data sources cover sensors and ports; live inventory includes serial-number status.</span></div>
		<form class="nms-add-rule" method="post" action="fault_config.php">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="add_rule"><input type="hidden" name="equipment_category_id" value="<?php print $category_id; ?>">
			<div class="nms-add-rule-title"><strong>Add fault rule</strong><small>Only parameters attached to real Cacti devices are listed.</small></div>
			<label>Device parameter<select name="parameter_key" required><?php foreach ($parameters as $parameter) { ?><option value="<?php print nms_h($parameter['parameter_key']); ?>"><?php print nms_h($parameter['template_name'] . ' · ' . $parameter['parameter_name'] . ' · ' . (int) $parameter['device_count'] . ' device(s)'); ?></option><?php } ?><option value="core:status">Cacti device state</option></select></label>
			<label>Rule name<input type="text" name="name" maxlength="150" placeholder="Temperature too high" required></label>
			<label>Condition<select name="comparison" required><?php foreach ($comparison_labels as $value => $label) { ?><option value="<?php print $value; ?>"><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label>Fault value<input type="text" name="threshold_value" maxlength="191" placeholder="80 or down"></label><label>Unit<input type="text" name="unit" maxlength="24" placeholder="°C, %, MB"></label>
			<label>Severity<select name="severity"><?php foreach ($allowed_severities as $severity) { ?><option value="<?php print $severity; ?>"><?php print ucfirst($severity); ?></option><?php } ?></select></label><button class="nms-save-button" type="submit">Add rule</button>
		</form>
		<div class="nms-existing-rules"><div class="nms-rule-table-head"><span>Rule / parameter</span><span>Condition</span><span>Fault value</span><span>Severity</span><span>State</span><span></span></div>
		<?php foreach ($rules as $rule) { $parameter_label = $rule['parameter_key'] === 'core:status' ? 'Cacti device state' : $rule['parameter_key']; foreach ($parameters as $parameter) if ($parameter['parameter_key'] === $rule['parameter_key']) $parameter_label = $parameter['template_name'] . ' · ' . $parameter['parameter_name']; ?>
			<form class="nms-compact-rule" method="post" action="fault_config.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_rule"><input type="hidden" name="rule_id" value="<?php print (int) $rule['id']; ?>"><input type="hidden" name="equipment_category_id" value="<?php print $category_id; ?>"><input type="hidden" name="parameter_key" value="<?php print nms_h($rule['parameter_key']); ?>"><div><input aria-label="Rule name" type="text" name="name" maxlength="150" value="<?php print nms_h($rule['name']); ?>" required><small><?php print nms_h($parameter_label); ?></small></div><select aria-label="Condition" name="comparison"><?php foreach ($comparison_labels as $value => $label) { ?><option value="<?php print $value; ?>" <?php print $rule['comparison'] === $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option><?php } ?></select><div class="nms-threshold-pair"><input aria-label="Fault value" type="text" name="threshold_value" maxlength="191" value="<?php print nms_h($rule['threshold_value']); ?>"><input aria-label="Unit" type="text" name="unit" maxlength="24" value="<?php print nms_h($rule['unit']); ?>" placeholder="unit"></div><select aria-label="Severity" name="severity"><?php foreach ($allowed_severities as $severity) { ?><option value="<?php print $severity; ?>" <?php print $rule['severity'] === $severity ? 'selected' : ''; ?>><?php print ucfirst($severity); ?></option><?php } ?></select><label class="nms-enabled"><input type="checkbox" name="enabled" value="on" <?php print $rule['enabled'] === 'on' ? 'checked' : ''; ?>><span>Enabled</span></label><button class="nms-save-button" type="submit">Save</button></form>
			<?php require(__DIR__ . '/templates/fault_rule_scope.php'); ?>
		<?php } ?></div>
	</section>
	<?php } elseif ($tab === 'templates') { ?>
	<section class="nms-panel nms-config-panel">
		<div class="nms-config-bar"><div><h2>Equipment categories</h2><p>Independent of Cacti trees, sites and templates. Existing devices keep their saved category when template defaults change.</p></div></div>
		<form class="nms-add-rule" method="post">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_category"><input type="hidden" name="equipment_category_id" value="0">
			<label>New category name<input name="name" maxlength="150" required></label><label>Description<input name="description" maxlength="512"></label><button class="nms-save-button">Add category</button>
		</form>
		<?php foreach ($categories as $category) { ?>
		<form class="nms-add-rule" method="post">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_category"><input type="hidden" name="equipment_category_id" value="<?php print (int) $category['id']; ?>">
			<label>Category #<?php print (int) $category['id']; ?><input name="name" maxlength="150" required value="<?php print nms_h($category['name']); ?>"></label>
			<label>Description<input name="description" maxlength="512" value="<?php print nms_h($category['description']); ?>"></label>
			<span><?php print (int) $category['device_count']; ?> devices · <?php print (int) $category['active_rule_count']; ?> active rules</span><button class="nms-save-button">Save category</button>
		</form>
		<?php } ?>
		<div class="nms-config-bar"><div><h2>Template category suggestions</h2><p>A default is offered when adding a device. It does not reclassify existing devices or change Cacti's native template class.</p></div></div>
		<div class="nms-table-wrap"><table class="nms-table nms-config-table"><thead><tr><th>Cacti host template</th><th>Native class / ID</th><th>Linked devices</th><th>Suggested equipment category</th><th></th></tr></thead><tbody>
	<?php foreach ($templates as $template) { $mapping_form_id = 'nms-map-' . (int) $template['id']; ?><tr><td><strong><?php print nms_h($template['name']); ?></strong></td><td><?php print nms_h($device_classes[$template['class']] ?? $template['class']); ?> · <?php print (int) $template['id']; ?></td><td><?php print (int) $template['device_count']; ?></td><td><form id="<?php print $mapping_form_id; ?>" method="post" action="fault_config.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="assign_template"><input type="hidden" name="host_template_id" value="<?php print (int) $template['id']; ?>"><select name="equipment_category_id" aria-label="Category for <?php print nms_h($template['name']); ?>"><option value="">Select a category</option><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $template['category_id'] === (int) $category['id'] ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?></select></form></td><td><button class="nms-save-button" type="submit" form="<?php print $mapping_form_id; ?>">Save</button></td></tr><?php } ?>
	</tbody></table></div></section>
	<?php } else { ?>
	<section class="nms-panel nms-config-panel"><div class="nms-config-bar"><div><h2>Operational groups</h2><p>Create labels such as LAN, WAN or Power infrastructure for existing rule-scope integrations. Groups do not change a device category or Cacti template.</p></div></div>
		<?php foreach (array_merge(array(array('id' => 0, 'name' => '', 'description' => '')), $operational_groups) as $group) { ?>
		<form method="post" class="nms-add-rule"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="save_group"><input type="hidden" name="group_id" value="<?php print (int) $group['id']; ?>">
			<label><?php print $group['id'] ? 'Group name' : 'New group'; ?><input name="name" maxlength="150" required value="<?php print nms_h($group['name']); ?>"></label><label>Description<input name="description" maxlength="512" value="<?php print nms_h($group['description']); ?>"></label><button class="nms-save-button"><?php print $group['id'] ? 'Save group' : 'Add group'; ?></button>
		</form><?php } ?>
	</section>
	<?php } ?>
</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
