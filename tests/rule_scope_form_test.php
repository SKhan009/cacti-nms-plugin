<?php
/** Render applicability controls with native-option fixtures; this is not a live browser/backend round trip. */
require_once(__DIR__ . '/../includes/functions.php');

/** Mirror Cacti HTML escaping for fixture-rendered user text. */
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
/** Read controlled submitted values without involving a Cacti web session. */
function isset_request_var($key) { return isset($_POST[$key]); }
/** Return raw fixture values so the template's scalar guard is exercised. */
function get_nfilter_request_var($key) { return $_POST[$key] ?? ''; }
/** Supply the selected fixture rule ID only. */
function get_filter_request_var($key) { return (int) ($_POST[$key] ?? 0); }
/** Fail independently of PHP assertion configuration. */
function expect_scope_form($ok, $message) { if (!$ok) throw new RuntimeException($message); }

$rule = array('id' => 7, 'name' => '<Sensor> & power') + nms_rule_scope_defaults();
$category_id = 5;
$nms_csrf_token = 'fixture-token';
$page_error = '';
$templates = array(array('id' => 4, 'name' => 'Native <template>'));
$scope_devices = array(array('id' => 9, 'name' => 'Native device'));
$scope_inputs = array(array('id' => 20, 'name' => 'Native input method'));
$scope_queries = array(array('id' => 5, 'name' => 'Native query'));
$scope_sources = array(array('id' => 12, 'name' => 'Native source'));
$scope_model_ids = array(array('snmp_sysObjectID' => '1.3.6.1.4.1.9.1.1'));
$snmp_versions = array(0 => 'Disabled', 3 => 'Installed SNMPv3');
$_POST = array();
ob_start(); require(__DIR__ . '/../templates/fault_rule_scope.php'); $html = ob_get_clean();
foreach (array_keys(nms_rule_scope_defaults()) as $field) {
	expect_scope_form(substr_count($html, 'name="' . $field . '"') === 1, 'Missing or duplicated scope field ' . $field);
}
expect_scope_form(strpos($html, 'name="__csrf_magic" value="fixture-token"') !== false, 'Native CSRF field missing');
expect_scope_form(strpos($html, '&lt;Sensor&gt; &amp; power') !== false && strpos($html, 'Native &lt;template&gt;') !== false, 'Rule/native labels were not escaped');
expect_scope_form(strpos($html, 'Installed SNMPv3') !== false && strpos($html, '>Version 2<') === false, 'Template used a copied protocol catalogue');
$page_error = 'Invalid input';
$_POST = array('nms_action' => 'save_rule_scope', 'rule_id' => 7,
	'scope_kind' => 'not-supported', 'scope_host_id' => '999', 'scope_snmp_version' => '99', 'scope_device_type' => '<script>');
ob_start(); require(__DIR__ . '/../templates/fault_rule_scope.php'); $html = ob_get_clean();
expect_scope_form(strpos($html, 'Unavailable scope — not-supported') !== false && strpos($html, 'Unavailable version — 99') !== false,
	'Invalid saved/submitted filters silently selected Any');
expect_scope_form(strpos($html, 'Unavailable selection — 999') !== false && strpos($html, 'value="&lt;script&gt;"') !== false, 'Attempted selection lost or unescaped');
expect_scope_form($rule['scope_kind'] === 'all', 'Rendering mutated persisted policy');
echo "Rule applicability form contracts passed: native options, all fields, CSRF, escaping and unavailable-value preservation.\n";
