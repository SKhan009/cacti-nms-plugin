<?php
$device_form_is_edit = !empty($edit_device);
$device_form_is_prefilled = !$device_form_is_edit && (isset_request_var('host_template_id') || isset_request_var('snmp_community') || isset_request_var('snmp_port'));
$device_values = $device_form_is_edit ? array_merge($cacti_device_defaults, $edit_device) : $cacti_device_defaults;
$selected_snmp_version = isset_request_var('snmp_version') ? (int) get_filter_request_var('snmp_version') : (int) $device_values['snmp_version'];
$selected_auth_protocol = isset_request_var('snmp_auth_protocol') ? get_nfilter_request_var('snmp_auth_protocol') : $device_values['snmp_auth_protocol'];
$selected_priv_protocol = isset_request_var('snmp_priv_protocol') ? get_nfilter_request_var('snmp_priv_protocol') : $device_values['snmp_priv_protocol'];
$selected_availability = isset_request_var('availability_method') ? (int) get_filter_request_var('availability_method') : (int) $device_values['availability_method'];
$selected_ping_method = isset_request_var('ping_method') ? (int) get_filter_request_var('ping_method') : (int) $device_values['ping_method'];
$selected_template_id = isset_request_var('host_template_id') ? (int) get_filter_request_var('host_template_id') : (int) ($device_values['host_template_id'] ?? 0);
$selected_site_id = isset_request_var('site_id') ? (int) get_filter_request_var('site_id') : (int) ($device_values['site_id'] ?? 0);
$selected_poller_id = isset_request_var('poller_id') ? (int) get_filter_request_var('poller_id') : (int) ($device_values['poller_id'] ?? 1);
$selected_snmp_port = isset_request_var('snmp_port') ? (int) get_filter_request_var('snmp_port') : (int) $device_values['snmp_port'];
$selected_snmp_timeout = isset_request_var('snmp_timeout') ? (int) get_filter_request_var('snmp_timeout') : (int) $device_values['snmp_timeout'];
$selected_ping_port = isset_request_var('ping_port') ? (int) get_filter_request_var('ping_port') : (int) $device_values['ping_port'];
$selected_ping_timeout = isset_request_var('ping_timeout') ? (int) get_filter_request_var('ping_timeout') : (int) $device_values['ping_timeout'];
$selected_ping_retries = isset_request_var('ping_retries') ? (int) get_filter_request_var('ping_retries') : (int) $device_values['ping_retries'];
$selected_max_oids = isset_request_var('max_oids') ? (int) get_filter_request_var('max_oids') : (int) $device_values['max_oids'];
$selected_device_threads = isset_request_var('device_threads') ? (int) get_filter_request_var('device_threads') : (int) $device_values['device_threads'];

$snmp_port_options = array(161 => '161 — Standard SNMP', 1161 => '1161 — SNMPSim', 10161 => '10161 — Alternate SNMP');
$ping_port_options = array(0 => '0 — Not used / ICMP', 22 => '22 — SSH', 23 => '23 — Telnet', 53 => '53 — DNS', 80 => '80 — HTTP', 161 => '161 — SNMP', 443 => '443 — HTTPS', 1161 => '1161 — SNMPSim');
$timeout_options = array(100, 200, 250, 400, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000);
$max_oid_options = array(1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60);
$device_thread_options = range(1, 10);

if (!array_key_exists($selected_snmp_port, $snmp_port_options)) {
	$snmp_port_options[$selected_snmp_port] = $selected_snmp_port . ' — Current custom value';
}
if (!array_key_exists($selected_ping_port, $ping_port_options)) {
	$ping_port_options[$selected_ping_port] = $selected_ping_port . ' — Current custom value';
}
foreach (array($selected_snmp_timeout, $selected_ping_timeout) as $current_timeout) {
	if (!in_array($current_timeout, $timeout_options, true)) {
		$timeout_options[] = $current_timeout;
	}
}
if (!in_array($selected_max_oids, $max_oid_options, true)) {
	$max_oid_options[] = $selected_max_oids;
}
if (!in_array($selected_device_threads, $device_thread_options, true)) {
	$device_thread_options[] = $selected_device_threads;
}
ksort($snmp_port_options, SORT_NUMERIC);
ksort($ping_port_options, SORT_NUMERIC);
sort($timeout_options, SORT_NUMERIC);
sort($max_oid_options, SORT_NUMERIC);
sort($device_thread_options, SORT_NUMERIC);
?>
<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-snmp-form.css?v=1.9.18'); ?>">
<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2><?php print $device_form_is_edit ? 'Edit ' . nms_h($device_values['description']) : 'Add a Cacti device'; ?></h2><p><?php print $device_form_is_edit ? 'Live settings from Cacti core. Saving updates this device directly.' : 'Uses the same Cacti device API as the core console.'; ?></p></div><?php if ($device_form_is_edit) { ?><span class="nms-device-id">Device ID <?php print (int) $device_values['id']; ?></span><?php } ?></div>
	<form method="post" action="devices.php?tab=<?php print $device_form_is_edit ? 'edit&id=' . (int) $device_values['id'] : 'add'; ?>" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="<?php print $device_form_is_edit ? 'update_device' : 'add_device'; ?>">
		<?php if ($device_form_is_edit) { ?><input type="hidden" name="id" value="<?php print (int) $device_values['id']; ?>"><?php } ?>
		<fieldset><legend>Device identity</legend><div class="nms-form-grid">
			<label><span>Device name</span><input required name="description" value="<?php print nms_h(isset_request_var('description') ? get_nfilter_request_var('description') : ($device_values['description'] ?? '')); ?>" placeholder="Branch router 01"></label>
			<label><span>Hostname or IP</span><input required name="hostname" value="<?php print nms_h(isset_request_var('hostname') ? get_nfilter_request_var('hostname') : ($device_values['hostname'] ?? '127.0.0.1')); ?>" placeholder="192.0.2.10"></label>
			<label><span>Cacti host template</span><select required name="host_template_id"><option value="">Select template</option><?php foreach ($host_templates as $template) { ?><option value="<?php print (int) $template['id']; ?>" <?php print $selected_template_id === (int) $template['id'] ? 'selected' : ''; ?>><?php print nms_h($template['name']); ?></option><?php } ?></select></label>
			<label><span>Site</span><select name="site_id"><option value="0" <?php print $selected_site_id === 0 ? 'selected' : ''; ?>>No site</option><?php foreach ($sites as $site) { ?><option value="<?php print (int) $site['id']; ?>" <?php print $selected_site_id === (int) $site['id'] ? 'selected' : ''; ?>><?php print nms_h($site['name']); ?></option><?php } ?></select></label>
			<label><span>Data collector</span><select name="poller_id"><?php foreach ($pollers as $poller) { ?><option value="<?php print (int) $poller['id']; ?>" <?php print $selected_poller_id === (int) $poller['id'] ? 'selected' : ''; ?>><?php print nms_h($poller['name']); ?></option><?php } ?></select></label>
			<label><span>Location</span><input name="location" value="<?php print nms_h(isset_request_var('location') ? get_nfilter_request_var('location') : ($device_values['location'] ?? '')); ?>" placeholder="Building / rack"></label>
		</div></fieldset>

		<fieldset class="nms-snmp-fieldset"><legend>SNMP connection</legend>
			<p class="nms-snmp-mode-note" id="nmsSnmpModeNote" aria-live="polite"></p>
			<div class="nms-form-grid">
			<label><span>SNMP version</span><select id="nmsSnmpVersion" name="snmp_version"><option value="2" <?php print $selected_snmp_version === 2 ? 'selected' : ''; ?>>Version 2c</option><option value="1" <?php print $selected_snmp_version === 1 ? 'selected' : ''; ?>>Version 1</option><option value="3" <?php print $selected_snmp_version === 3 ? 'selected' : ''; ?>>Version 3</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="1,2"><span>Community (v1/v2c)</span><input id="nmsSnmpCommunity" name="snmp_community" value="<?php print nms_h(isset_request_var('snmp_community') ? get_nfilter_request_var('snmp_community') : $device_values['snmp_community']); ?>" placeholder="Cacti default community"></label>
			<label><span>SNMP port</span><select name="snmp_port"><?php foreach ($snmp_port_options as $value => $label) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_snmp_port === (int) $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label><span>Timeout (ms)</span><select name="snmp_timeout"><?php foreach ($timeout_options as $value) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_snmp_timeout === (int) $value ? 'selected' : ''; ?>><?php print (int) $value; ?> ms</option><?php } ?></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 username</span><input id="nmsSnmpUsername" name="snmp_username" value="<?php print nms_h(isset_request_var('snmp_username') ? get_nfilter_request_var('snmp_username') : $device_values['snmp_username']); ?>"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 authentication</span><select id="nmsSnmpAuthProtocol" name="snmp_auth_protocol"><option value="[None]" <?php print $selected_auth_protocol === '[None]' ? 'selected' : ''; ?>>None (no authentication)</option><option value="MD5" <?php print $selected_auth_protocol === 'MD5' ? 'selected' : ''; ?>>MD5</option><option value="SHA" <?php print $selected_auth_protocol === 'SHA' ? 'selected' : ''; ?>>SHA</option><option value="SHA224" <?php print $selected_auth_protocol === 'SHA224' ? 'selected' : ''; ?>>SHA224</option><option value="SHA256" <?php print $selected_auth_protocol === 'SHA256' ? 'selected' : ''; ?>>SHA256</option><option value="SHA392" <?php print $selected_auth_protocol === 'SHA392' ? 'selected' : ''; ?>>SHA392</option><option value="SHA512" <?php print $selected_auth_protocol === 'SHA512' ? 'selected' : ''; ?>>SHA512</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-required="1"><span>Authentication password</span><input id="nmsSnmpPassword" type="password" minlength="8" name="snmp_password" value="<?php print nms_h(isset_request_var('snmp_password') ? get_nfilter_request_var('snmp_password') : $device_values['snmp_password']); ?>" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-enabled="1"><span>SNMP v3 privacy</span><select id="nmsSnmpPrivProtocol" name="snmp_priv_protocol"><option value="[None]" <?php print $selected_priv_protocol === '[None]' ? 'selected' : ''; ?>>None (no encryption)</option><option value="DES" <?php print $selected_priv_protocol === 'DES' ? 'selected' : ''; ?>>DES</option><option value="AES" <?php print $selected_priv_protocol === 'AES' ? 'selected' : ''; ?>>AES</option><option value="AES128" <?php print $selected_priv_protocol === 'AES128' ? 'selected' : ''; ?>>AES128</option><option value="AES192" <?php print $selected_priv_protocol === 'AES192' ? 'selected' : ''; ?>>AES192</option><option value="AES192C" <?php print $selected_priv_protocol === 'AES192C' ? 'selected' : ''; ?>>AES192C</option><option value="AES256" <?php print $selected_priv_protocol === 'AES256' ? 'selected' : ''; ?>>AES256</option><option value="AES256C" <?php print $selected_priv_protocol === 'AES256C' ? 'selected' : ''; ?>>AES256C</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-privacy-required="1"><span>Privacy passphrase</span><input id="nmsSnmpPrivPassphrase" type="password" minlength="8" name="snmp_priv_passphrase" value="<?php print nms_h(isset_request_var('snmp_priv_passphrase') ? get_nfilter_request_var('snmp_priv_passphrase') : $device_values['snmp_priv_passphrase']); ?>" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP context</span><input id="nmsSnmpContext" name="snmp_context" value="<?php print nms_h(isset_request_var('snmp_context') ? get_nfilter_request_var('snmp_context') : ($device_values['snmp_context'] ?? '')); ?>"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP engine ID</span><input id="nmsSnmpEngineId" name="snmp_engine_id" value="<?php print nms_h(isset_request_var('snmp_engine_id') ? get_nfilter_request_var('snmp_engine_id') : ($device_values['snmp_engine_id'] ?? '')); ?>"></label>
		</div></fieldset>

		<fieldset><legend>Availability and polling</legend><div class="nms-form-grid">
			<label><span>Availability method</span><select name="availability_method"><option value="2" <?php print $selected_availability === 2 ? 'selected' : ''; ?>>SNMP</option><option value="1" <?php print $selected_availability === 1 ? 'selected' : ''; ?>>SNMP and ping</option><option value="4" <?php print $selected_availability === 4 ? 'selected' : ''; ?>>SNMP or ping</option><option value="3" <?php print $selected_availability === 3 ? 'selected' : ''; ?>>Ping only</option><option value="0" <?php print $selected_availability === 0 ? 'selected' : ''; ?>>None</option></select></label>
			<label><span>Ping method</span><select name="ping_method"><option value="1" <?php print $selected_ping_method === 1 ? 'selected' : ''; ?>>ICMP</option><option value="3" <?php print $selected_ping_method === 3 ? 'selected' : ''; ?>>TCP</option><option value="2" <?php print $selected_ping_method === 2 ? 'selected' : ''; ?>>UDP</option></select></label>
			<label><span>Ping port</span><select name="ping_port"><?php foreach ($ping_port_options as $value => $label) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_ping_port === (int) $value ? 'selected' : ''; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label><span>Ping timeout (ms)</span><select name="ping_timeout"><?php foreach ($timeout_options as $value) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_ping_timeout === (int) $value ? 'selected' : ''; ?>><?php print (int) $value; ?> ms</option><?php } ?></select></label>
			<label><span>Ping retries</span><select name="ping_retries"><?php foreach (range(0, 10) as $value) { ?><option value="<?php print $value; ?>" <?php print $selected_ping_retries === $value ? 'selected' : ''; ?>><?php print $value === 0 ? 'No retries' : $value . ($value === 1 ? ' retry' : ' retries'); ?></option><?php } ?></select></label>
			<label><span>Maximum OIDs per request</span><select name="max_oids"><?php foreach ($max_oid_options as $value) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_max_oids === (int) $value ? 'selected' : ''; ?>><?php print (int) $value; ?> <?php print (int) $value === 1 ? 'OID' : 'OIDs'; ?></option><?php } ?></select></label>
			<label><span>Device threads</span><select name="device_threads"><?php foreach ($device_thread_options as $value) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_device_threads === (int) $value ? 'selected' : ''; ?>><?php print (int) $value; ?> <?php print (int) $value === 1 ? 'thread' : 'threads'; ?></option><?php } ?></select></label>
			<label><span>External ID</span><input name="external_id" value="<?php print nms_h(isset_request_var('external_id') ? get_nfilter_request_var('external_id') : ($device_values['external_id'] ?? '')); ?>"></label>
		</div></fieldset>
		<label class="nms-wide-field"><span>Notes</span><textarea name="notes" rows="3"><?php print nms_h(isset_request_var('notes') ? get_nfilter_request_var('notes') : ($device_values['notes'] ?? '')); ?></textarea></label>
		<div class="nms-check-row"><label><input type="checkbox" name="proxy" value="1"><span>Allow shared IP for proxy or SNMPSim communities</span></label><label><input type="checkbox" name="disabled" value="1" <?php print ((isset_request_var('disabled')) || (!isset_request_var('nms_action') && ($device_values['disabled'] ?? '') === 'on')) ? 'checked' : ''; ?>><span><?php print $device_form_is_edit ? 'Disable device monitoring' : 'Create with monitoring disabled'; ?></span></label></div>
		<div class="nms-form-actions"><button type="submit"><?php print $device_form_is_edit ? 'Save device changes' : ($device_form_is_prefilled ? 'Save device' : 'Create device in Cacti'); ?></button><a href="?tab=inventory">Cancel</a></div>
	</form>
</section>
