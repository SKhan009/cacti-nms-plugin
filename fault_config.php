<?php

require('../../include/auth.php');
require_once($config['base_path'] . '/plugins/nms/includes/functions.php');
require_once($config['base_path'] . '/plugins/nms/includes/database.php');

nms_setup_database();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset_request_var('nms_action')) {
	$action = get_nfilter_request_var('nms_action');

	if ($action === 'assign_template') {
		$template_id = get_filter_request_var('host_template_id');
		$category_id = get_filter_request_var('category_id');
		$template_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM host_template WHERE id = ?', array($template_id));
		$category_exists = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_device_categories WHERE id = ?', array($category_id));

		if ($template_exists && $category_exists) {
			db_execute_prepared('INSERT INTO plugin_nms_category_templates (host_template_id, category_id, assigned_at)
				VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), assigned_at = NOW()',
				array($template_id, $category_id));
		}
	} elseif ($action === 'save_rule') {
		$rule_id = get_filter_request_var('rule_id');
		$name = trim(get_nfilter_request_var('name'));
		$threshold = (float) get_nfilter_request_var('threshold');
		$severity = get_nfilter_request_var('severity');
		$enabled = isset_request_var('enabled') ? 'on' : '';

		if (!in_array($severity, array('critical', 'major', 'warning'), true)) {
			$severity = 'warning';
		}
		if ($name !== '' && $threshold >= 0) {
			db_execute_prepared('UPDATE plugin_nms_fault_rules
				SET name = ?, threshold = ?, severity = ?, enabled = ?, updated_at = NOW()
				WHERE id = ?', array($name, $threshold, $severity, $enabled, $rule_id));
		}
	}

	/* Re-evaluate immediately so the dashboard reflects the saved configuration. */
	nms_sync_device_faults();
	header('Location: fault_config.php?saved=1');
	exit;
}

$categories = db_fetch_assoc("SELECT c.*,
	(SELECT COUNT(*) FROM plugin_nms_category_templates AS ct WHERE ct.category_id = c.id) AS template_count,
	(SELECT COUNT(DISTINCT h.id) FROM plugin_nms_category_templates AS ct
		INNER JOIN host AS h ON h.host_template_id = ct.host_template_id
		WHERE ct.category_id = c.id AND h.deleted = '' AND h.disabled = '') AS device_count,
	(SELECT COUNT(*) FROM plugin_nms_fault_rules AS r WHERE r.category_id = c.id AND r.enabled = 'on') AS active_rule_count
	FROM plugin_nms_device_categories AS c
	ORDER BY c.sort_order, c.name");

$templates = db_fetch_assoc("SELECT ht.id, ht.name, ct.category_id,
	COUNT(DISTINCT CASE WHEN h.deleted = '' AND h.disabled = '' THEN h.id END) AS device_count
	FROM host_template AS ht
	LEFT JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = ht.id
	LEFT JOIN host AS h ON h.host_template_id = ht.id
	GROUP BY ht.id, ht.name, ct.category_id
	ORDER BY ht.name");

$rules = db_fetch_assoc('SELECT r.*, c.name AS category_name
	FROM plugin_nms_fault_rules AS r
	INNER JOIN plugin_nms_device_categories AS c ON c.id = r.category_id
	ORDER BY c.sort_order, r.sort_order, r.id');
$rules_by_category = array();
foreach ($rules as $rule) {
	$rules_by_category[(int) $rule['category_id']][] = $rule;
}

$metric_labels = array(
	'status_not_up' => array('Device state', 'Device must be Up', ''),
	'availability_below' => array('Availability', 'Create a fault below this value', '%'),
	'response_above' => array('Poller response', 'Create a fault above this value', 'ms'),
	'rrd_stale_minutes' => array('RRD freshness', 'Create a fault when data is older than', 'minutes'),
	'rrd_missing_count' => array('Missing RRD files', 'Create a fault at this count', 'files')
);

$linked_devices = (int) db_fetch_cell("SELECT COUNT(DISTINCT h.id)
	FROM host AS h INNER JOIN plugin_nms_category_templates AS ct ON ct.host_template_id = h.host_template_id
	WHERE h.deleted = '' AND h.disabled = ''");
$enabled_rules = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_nms_fault_rules WHERE enabled = 'on'");
$nms_csrf_token = csrf_get_tokens();
$nms_asset_base = $config['url_path'] . 'plugins/nms/';
$nms_backend_url = $config['url_path'] . 'index.php';
$nms_active_module = 'configuration';
$nms_page_title = 'NMS · Fault Configuration';
$nms_extra_css = 'css/nms-fault-config.css?v=1.0.0';
require($config['base_path'] . '/plugins/nms/templates/app_header.php');
?>
<main class="nms-shell">
	<div class="nms-heading nms-config-heading">
		<div>
			<p class="nms-eyebrow">NMS / Fault Configuration</p>
			<h1>Device categories and fault rules</h1>
			<p>Map Cacti templates to a category, then choose which live readings create a fault and its severity.</p>
		</div>
		<?php if (isset_request_var('saved')) { ?><div class="nms-saved">Configuration saved and devices checked</div><?php } ?>
	</div>

	<div class="nms-summary-grid">
		<div class="nms-summary nms-summary-total"><span>Device categories</span><strong><?php print count($categories); ?></strong><small>Based on Figure 5.2</small></div>
		<div class="nms-summary nms-summary-resolved"><span>Cacti templates mapped</span><strong><?php print count($templates); ?></strong><small>Every existing host template</small></div>
		<div class="nms-summary nms-summary-total"><span>Linked devices</span><strong><?php print $linked_devices; ?></strong><small>Actual enabled Cacti devices</small></div>
		<div class="nms-summary nms-summary-ack"><span>Active rules</span><strong><?php print $enabled_rules; ?></strong><small>Across all categories</small></div>
	</div>

	<section class="nms-panel">
		<div class="nms-panel-head"><div><h2>Category overview</h2><p>Categories organize the templates and rules; device readings still come directly from Cacti.</p></div></div>
		<div class="nms-category-grid">
		<?php foreach ($categories as $category) { ?>
			<div class="nms-category-card">
				<strong><?php print nms_h($category['name']); ?></strong>
				<p><?php print nms_h($category['description']); ?></p>
				<div><span><?php print (int) $category['template_count']; ?> templates</span><span><?php print (int) $category['device_count']; ?> devices</span><span><?php print (int) $category['active_rule_count']; ?> rules</span></div>
			</div>
		<?php } ?>
		</div>
	</section>

	<section class="nms-panel">
		<div class="nms-panel-head"><div><h2>Cacti template mapping</h2><p>Changing a category affects every current and future device that uses that Cacti host template.</p></div></div>
		<div class="nms-table-wrap">
			<table class="nms-table nms-config-table">
				<thead><tr><th>Cacti host template</th><th>Template ID</th><th>Linked devices</th><th>Fault category</th><th>Action</th></tr></thead>
				<tbody>
				<?php foreach ($templates as $template) { $mapping_form_id = 'nms-map-' . (int) $template['id']; ?>
				<tr>
					<td><strong><?php print nms_h($template['name']); ?></strong></td>
					<td><?php print (int) $template['id']; ?></td>
					<td><strong><?php print (int) $template['device_count']; ?></strong><small>enabled devices</small></td>
					<td>
						<form id="<?php print $mapping_form_id; ?>" method="post" action="fault_config.php">
						<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
						<input type="hidden" name="nms_action" value="assign_template">
						<input type="hidden" name="host_template_id" value="<?php print (int) $template['id']; ?>">
						<select name="category_id" aria-label="Category for <?php print nms_h($template['name']); ?>">
						<?php foreach ($categories as $category) { ?><option value="<?php print (int) $category['id']; ?>" <?php print (int) $template['category_id'] === (int) $category['id'] ? 'selected' : ''; ?>><?php print nms_h($category['name']); ?></option><?php } ?>
						</select>
						</form>
					</td>
					<td><button class="nms-save-button" type="submit" form="<?php print $mapping_form_id; ?>">Save mapping</button></td>
				</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="nms-panel">
		<div class="nms-panel-head"><div><h2>Fault values and severity</h2><p>Rules are applied to the live Cacti devices in each category. More than one rule can create a fault for the same device.</p></div></div>
		<div class="nms-rule-groups">
		<?php foreach ($categories as $category) { $category_rules = isset($rules_by_category[(int) $category['id']]) ? $rules_by_category[(int) $category['id']] : array(); ?>
			<details class="nms-rule-group" <?php print (int) $category['device_count'] > 0 ? 'open' : ''; ?>>
				<summary><span><strong><?php print nms_h($category['name']); ?></strong><small><?php print (int) $category['device_count']; ?> linked devices · <?php print count($category_rules); ?> available rules</small></span><span>Configure</span></summary>
				<div class="nms-rule-list">
				<?php foreach ($category_rules as $rule) { $metric = isset($metric_labels[$rule['metric']]) ? $metric_labels[$rule['metric']] : array($rule['metric'], 'Configured threshold', ''); ?>
					<form class="nms-rule-row" method="post" action="fault_config.php">
						<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
						<input type="hidden" name="nms_action" value="save_rule">
						<input type="hidden" name="rule_id" value="<?php print (int) $rule['id']; ?>">
						<div><label>Rule name</label><input type="text" name="name" maxlength="150" value="<?php print nms_h($rule['name']); ?>" required></div>
						<div class="nms-metric"><label>Live Cacti reading</label><strong><?php print nms_h($metric[0]); ?></strong><small><?php print nms_h($metric[1]); ?></small></div>
						<div><label>Fault value</label><div class="nms-value-input"><input type="number" name="threshold" min="0" step="0.001" value="<?php print nms_h(rtrim(rtrim($rule['threshold'], '0'), '.')); ?>" <?php print $rule['metric'] === 'status_not_up' ? 'readonly' : ''; ?>><span><?php print nms_h($metric[2]); ?></span></div></div>
						<div><label>Severity</label><select name="severity"><?php foreach (array('critical', 'major', 'warning') as $severity) { ?><option value="<?php print $severity; ?>" <?php print $rule['severity'] === $severity ? 'selected' : ''; ?>><?php print ucfirst($severity); ?></option><?php } ?></select></div>
						<label class="nms-enabled"><input type="checkbox" name="enabled" value="on" <?php print $rule['enabled'] === 'on' ? 'checked' : ''; ?>><span>Enabled</span></label>
						<button class="nms-save-button" type="submit">Save rule</button>
					</form>
				<?php } ?>
				</div>
			</details>
		<?php } ?>
		</div>
	</section>
</main>
<?php require($config['base_path'] . '/plugins/nms/templates/app_footer.php'); ?>
