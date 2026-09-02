<?php
/**
 * @file fault_config.php
 * Fault Configuration page: select a Cacti Tree, map host templates, and create or update Tree-scoped fault rules.
 * Request handling and queries below prepare the forms; reusable rule validation lives in includes/functions.php.
 */

// Let Cacti authenticate the request before loading shared NMS rule and storage helpers.
require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_setup_database();

// Use the same comparison and severity definitions for validation and form options.
$comparison_labels = nms_fault_comparisons();
$allowed_severities = nms_fault_severities();
$tab = isset_request_var('tab') ? get_nfilter_request_var('tab') : 'rules';
if (!in_array($tab, array('rules', 'templates'), true)) $tab = 'rules';
// The legacy request name category_id now contains a Cacti graph_tree.id, not a plugin category ID.
$category_id = isset_request_var('category_id') ? get_filter_request_var('category_id') : 0;
$page_error = '';

// Process submitted changes before rendering HTML so successful actions can redirect safely.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');
	try {
		if ($action === 'delete_tree') {
			// The shared helper checks NMS ownership before removing the Tree and its rule mappings.
			$deleted_tree_id = nms_tree_delete(get_filter_request_var('category_id'));
			header('Location: fault_config.php?tab=templates&tree_deleted=' . $deleted_tree_id);
			exit;
		} elseif ($action === 'assign_template') {
			// All devices using this host template inherit the selected Tree's fault-rule scope.
			$category_id = get_filter_request_var('category_id');
			nms_assign_template_tree(get_filter_request_var('host_template_id'), $category_id);
			$tab = 'templates';
		} elseif ($action === 'add_rule' || $action === 'save_rule') {
			// ID zero creates a rule; a supplied ID updates one after shared Tree/parameter validation.
			$rule_id = $action === 'save_rule' ? get_filter_request_var('rule_id') : 0;
			$category_id = get_filter_request_var('category_id');
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

		// Re-evaluate device faults with the saved configuration, then redirect to prevent resubmission.
		nms_sync_device_faults();
		header('Location: fault_config.php?tab=' . rawurlencode($tab) .
			($category_id > 0 ? '&category_id=' . (int) $category_id : '') . '&saved=1');
		exit;
	} catch (Throwable $exception) {
		// Render the validation failure on this page instead of ending the request with an uncaught error.
		$page_error = $exception->getMessage();
	}
}

// Read categories from Cacti Trees; counts and ownership flags decorate the selector and delete controls.
$categories = db_fetch_assoc("SELECT c.*,
	EXISTS (SELECT 1 FROM plugin_nms_managed_objects AS mo WHERE mo.object_type = 'tree' AND mo.object_id = c.id) AS nms_deletable,
	(SELECT COUNT(*) FROM plugin_nms_category_templates AS ct WHERE ct.category_id = c.id) AS template_count,
	(SELECT COUNT(DISTINCT h.id) FROM plugin_nms_category_templates AS ct
		INNER JOIN host AS h ON h.host_template_id = ct.host_template_id
		WHERE ct.category_id = c.id AND h.deleted = '' AND h.disabled = '') AS device_count,
	(SELECT COUNT(*) FROM plugin_nms_fault_rules AS r WHERE r.category_id = c.id AND r.enabled = 'on') AS active_rule_count
	FROM graph_tree AS c ORDER BY c.sequence, c.name");

// With no explicit selection, prefer a Tree containing monitored devices, then the first available Tree.
if ($category_id <= 0 && count($categories)) {
	foreach ($categories as $category) {
		if ((int) $category['device_count'] > 0) { $category_id = (int) $category['id']; break; }
	}
	if ($category_id <= 0) $category_id = (int) $categories[0]['id'];
}

// Resolve the selected Tree against current records, including when a previous selection was deleted.
$selected_category = null;
foreach ($categories as $category) if ((int) $category['id'] === $category_id) $selected_category = $category;
if (!$selected_category && count($categories)) {
	$selected_category = $categories[0];
	$category_id = (int) $selected_category['id'];
}

// Show reusable core host templates together with their current NMS Tree mapping and enabled-device count.
$templates = db_fetch_assoc("SELECT ht.id, ht.name, ct.category_id,
	COUNT(DISTINCT CASE WHEN h.deleted = '' AND h.disabled = '' THEN h.id END) AS device_count
	FROM host_template AS ht
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = ht.id
	LEFT JOIN host AS h ON h.host_template_id = ht.id
	GROUP BY ht.id, ht.name, ct.category_id ORDER BY ht.name");

// Load only the selected Tree's saved rules; other Trees' thresholds remain independent.
$rules = $category_id > 0 ? db_fetch_assoc_prepared('SELECT * FROM plugin_nms_fault_rules
	WHERE category_id = ? ORDER BY sort_order, id', array($category_id)) : array();

// Discover actual core data-source and text-inventory parameters instead of inventing readings from imports.
$fresh_after = nms_parameter_fresh_after();
$parameters = nms_fault_parameter_catalog($category_id);

// Build page-wide counters; numeric sample counts require a fresh reading from an Up device.
$linked_devices = (int) db_fetch_cell("SELECT COUNT(DISTINCT h.id)
	FROM host AS h INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
	WHERE h.deleted = '' AND h.disabled = ''");
$enabled_rules = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_fault_rules WHERE enabled = 'on'");
$known_parameters = (int) db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_nms_device_parameters AS p
	INNER JOIN host AS h ON h.id = p.host_id AND h.deleted = '' AND h.disabled = '' AND h.status = " . HOST_UP . "
	WHERE p.last_seen >= ?", array($fresh_after));
$known_parameters += (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_device_inventory AS di
	INNER JOIN host AS h ON h.id = di.host_id AND h.deleted = '' AND h.disabled = ''");
// Set shared navigation, asset URLs, and CSRF form tokens before rendering the configuration forms.
nms_prepare_page('configuration', 'NMS · Fault Configuration', 'css/nms-fault-config.css');
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell nms-config-shell">
	<div class="nms-heading nms-config-heading">
		<div><p class="nms-eyebrow">NMS / Fault Configuration</p><h1>Device fault rules</h1><p>Use actual Cacti device parameters and apply severity by Cacti Tree category.</p></div>
		<?php if (isset_request_var('saved')) { ?><div class="nms-saved">Saved and checked against live device values</div><?php } ?>
	</div>
	<?php if (isset_request_var('tree_deleted')) { ?><div class="nms-form-message success"><strong>Cacti Tree deleted</strong><span>The NMS-managed tree and its NMS mappings were removed.</span></div><?php } ?>
	<?php if ($page_error !== '') { ?><div class="nms-config-error"><strong>Could not save configuration</strong><span><?php print nms_h($page_error); ?></span></div><?php } ?>
		<div class="nms-config-stats"><span><strong><?php print count($categories); ?></strong> Cacti Trees</span><span><strong><?php print count($templates); ?></strong> templates mapped</span><span><strong><?php print $linked_devices; ?></strong> linked devices</span><span><strong><?php print $enabled_rules; ?></strong> active rules</span><span><strong><?php print $known_parameters; ?></strong> current monitored parameters</span></div>
	<nav class="nms-config-tabs" aria-label="Fault configuration sections"><a class="<?php print $tab === 'rules' ? 'selected' : ''; ?>" href="?tab=rules&amp;category_id=<?php print $category_id; ?>">Fault values and severity</a><a class="<?php print $tab === 'templates' ? 'selected' : ''; ?>" href="?tab=templates">Cacti template mapping</a></nav>

	<?php if ($tab === 'rules') { ?>
	<section class="nms-panel nms-config-panel">
		<div class="nms-config-bar"><div><h2>Fault values and severity</h2><p>A rule can use a number such as temperature &gt; 80, or text such as interface state does not equal up.</p></div><form method="get" action="fault_config.php"><input type="hidden" name="tab" value="rules"><label>Device category (Cacti Tree)<select name="category_id" onchange="this.form.submit()"><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $category['id'] === $category_id ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?> (<?php print (int) $category['device_count']; ?>)</option><?php } ?></select></label></form></div>
		<div class="nms-rule-note"><strong><?php print nms_h($selected_category ? $selected_category['name'] : 'Category'); ?></strong><span><?php print count($parameters); ?> parameters available from <?php print (int) ($selected_category ? $selected_category['device_count'] : 0); ?> linked devices. Cacti data sources cover sensors and ports; live inventory includes serial-number status.</span></div>
		<form class="nms-add-rule" method="post" action="fault_config.php">
			<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="add_rule"><input type="hidden" name="category_id" value="<?php print $category_id; ?>">
			<div class="nms-add-rule-title"><strong>Add fault rule</strong><small>Only parameters attached to real Cacti devices are listed.</small></div>
			<label>Device parameter<select name="parameter_key" required><?php foreach ($parameters as $parameter) { ?><option value="<?php print nms_h($parameter['parameter_key']); ?>"><?php print nms_h($parameter['template_name'] . ' · ' . $parameter['parameter_name'] . ' · ' . (int) $parameter['device_count'] . ' device(s)'); ?></option><?php } ?><option value="core:status">Cacti device state</option></select></label>
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
	<section class="nms-panel nms-config-panel"><div class="nms-config-bar"><div><h2>Cacti template mapping</h2><p>Device categories come directly from Cacti Graph Trees. A mapping applies that tree's fault rules to every device using the selected host template.</p></div><button class="nms-category-popup-button" type="button" popovertarget="nmsCategorySummary">Tree summary</button></div><div id="nmsCategorySummary" class="nms-category-popup" popover><div class="nms-popup-head"><div><strong>Cacti Tree category summary</strong><small>Only NMS-managed trees have a delete control; Cacti core trees stay protected.</small></div><button type="button" popovertarget="nmsCategorySummary" popovertargetaction="hide" aria-label="Close category summary">×</button></div><div class="nms-popup-categories"><?php foreach ($categories as $category) { ?><div><span><strong><?php print nms_h($category['name']); ?></strong><small><?php print (int) $category['template_count']; ?> templates · <?php print (int) $category['device_count']; ?> devices</small></span><?php if (!empty($category['nms_deletable'])) { ?><form method="post" action="fault_config.php?tab=templates" onsubmit="return confirm('Delete this NMS-managed Cacti Tree and remove its NMS mappings?');"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="delete_tree"><input type="hidden" name="category_id" value="<?php print (int) $category['id']; ?>"><button class="nms-delete-x" type="submit" aria-label="Delete <?php print nms_h($category['name']); ?>" title="Delete NMS-managed Cacti Tree">×</button></form><?php } ?></div><?php } ?></div></div><div class="nms-table-wrap"><table class="nms-table nms-config-table"><thead><tr><th>Cacti host template</th><th>ID</th><th>Linked devices</th><th>Device category (Cacti Tree)</th><th></th></tr></thead><tbody>
	<?php foreach ($templates as $template) { $mapping_form_id = 'nms-map-' . (int) $template['id']; ?><tr><td><strong><?php print nms_h($template['name']); ?></strong></td><td><?php print (int) $template['id']; ?></td><td><?php print (int) $template['device_count']; ?></td><td><form id="<?php print $mapping_form_id; ?>" method="post" action="fault_config.php"><input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>"><input type="hidden" name="nms_action" value="assign_template"><input type="hidden" name="host_template_id" value="<?php print (int) $template['id']; ?>"><select name="category_id" aria-label="Category for <?php print nms_h($template['name']); ?>"><?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $template['category_id'] === (int) $category['id'] ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?></select></form></td><td><button class="nms-save-button" type="submit" form="<?php print $mapping_form_id; ?>">Save</button></td></tr><?php } ?>
	</tbody></table></div></section>
	<?php } ?>
</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
