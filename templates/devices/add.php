<link rel="stylesheet" href="<?php print nms_h($nms_asset_base . 'css/nms-snmp-form.css?v=1.0.0'); ?>">
<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2>Add a Cacti device</h2><p>Uses the same Cacti device API as the core console.</p></div></div>
	<form method="post" action="devices.php?tab=add" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="add_device">
		<fieldset><legend>Device identity</legend><div class="nms-form-grid">
			<label><span>Device name</span><input required name="description" value="<?php print isset_request_var('description') ? nms_h(get_nfilter_request_var('description')) : ''; ?>" placeholder="Branch router 01"></label>
			<label><span>Hostname or IP</span><input required name="hostname" value="<?php print isset_request_var('hostname') ? nms_h(get_nfilter_request_var('hostname')) : '127.0.0.1'; ?>" placeholder="192.0.2.10"></label>
			<label><span>Cacti host template</span><select required name="host_template_id"><option value="">Select template</option><?php foreach ($host_templates as $template) { ?><option value="<?php print (int) $template['id']; ?>" <?php print isset_request_var('host_template_id') && get_filter_request_var('host_template_id') == $template['id'] ? 'selected' : ''; ?>><?php print nms_h($template['name']); ?></option><?php } ?></select></label>
			<label><span>Site</span><select name="site_id"><option value="0">No site</option><?php foreach ($sites as $site) { ?><option value="<?php print (int) $site['id']; ?>"><?php print nms_h($site['name']); ?></option><?php } ?></select></label>
			<label><span>Data collector</span><select name="poller_id"><?php foreach ($pollers as $poller) { ?><option value="<?php print (int) $poller['id']; ?>"><?php print nms_h($poller['name']); ?></option><?php } ?></select></label>
			<label><span>Location</span><input name="location" placeholder="Building / rack"></label>
		</div></fieldset>

		<?php $selected_snmp_version = isset_request_var('snmp_version') ? (int) get_filter_request_var('snmp_version') : 2; ?>
		<fieldset class="nms-snmp-fieldset"><legend>SNMP connection</legend>
			<p class="nms-snmp-mode-note" id="nmsSnmpModeNote" aria-live="polite"></p>
			<div class="nms-form-grid">
			<label><span>SNMP version</span><select id="nmsSnmpVersion" name="snmp_version"><option value="2" <?php print $selected_snmp_version === 2 ? 'selected' : ''; ?>>Version 2c</option><option value="1" <?php print $selected_snmp_version === 1 ? 'selected' : ''; ?>>Version 1</option><option value="3" <?php print $selected_snmp_version === 3 ? 'selected' : ''; ?>>Version 3</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="1,2"><span>Community (v1/v2c)</span><input id="nmsSnmpCommunity" name="snmp_community" value="<?php print isset_request_var('snmp_community') ? nms_h(get_nfilter_request_var('snmp_community')) : ''; ?>" placeholder="sim-router"></label>
			<label><span>SNMP port</span><input type="number" min="1" max="65535" name="snmp_port" value="<?php print isset_request_var('snmp_port') ? (int) get_filter_request_var('snmp_port') : 161; ?>"></label>
			<label><span>Timeout (ms)</span><input type="number" min="100" max="10000" name="snmp_timeout" value="1000"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 username</span><input id="nmsSnmpUsername" name="snmp_username"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 authentication</span><select id="nmsSnmpAuthProtocol" name="snmp_auth_protocol"><option value="[None]">None (no authentication)</option><option value="MD5">MD5</option><option value="SHA">SHA</option><option value="SHA224">SHA224</option><option value="SHA256">SHA256</option><option value="SHA392">SHA392</option><option value="SHA512">SHA512</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-required="1"><span>Authentication password</span><input id="nmsSnmpPassword" type="password" minlength="8" name="snmp_password" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-enabled="1"><span>SNMP v3 privacy</span><select id="nmsSnmpPrivProtocol" name="snmp_priv_protocol"><option value="[None]">None (no encryption)</option><option value="DES">DES</option><option value="AES">AES</option><option value="AES128">AES128</option><option value="AES192">AES192</option><option value="AES192C">AES192C</option><option value="AES256">AES256</option><option value="AES256C">AES256C</option></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-privacy-required="1"><span>Privacy passphrase</span><input id="nmsSnmpPrivPassphrase" type="password" minlength="8" name="snmp_priv_passphrase" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP context</span><input id="nmsSnmpContext" name="snmp_context"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP engine ID</span><input id="nmsSnmpEngineId" name="snmp_engine_id"></label>
		</div></fieldset>

		<fieldset><legend>Availability and polling</legend><div class="nms-form-grid">
			<label><span>Availability method</span><select name="availability_method"><option value="2">SNMP</option><option value="1">SNMP and ping</option><option value="4">SNMP or ping</option><option value="3">Ping only</option><option value="0">None</option></select></label>
			<label><span>Ping method</span><select name="ping_method"><option value="1">ICMP</option><option value="3">TCP</option><option value="2">UDP</option></select></label>
			<label><span>Ping port</span><input type="number" min="0" max="65535" name="ping_port" value="0"></label>
			<label><span>Ping timeout (ms)</span><input type="number" min="1" name="ping_timeout" value="400"></label>
			<label><span>Ping retries</span><input type="number" min="0" max="10" name="ping_retries" value="2"></label>
			<label><span>Maximum OIDs per request</span><input type="number" min="1" max="60" name="max_oids" value="10"></label>
			<label><span>Device threads</span><input type="number" min="1" max="50" name="device_threads" value="1"></label>
			<label><span>External ID</span><input name="external_id"></label>
		</div></fieldset>
		<label class="nms-wide-field"><span>Notes</span><textarea name="notes" rows="3"></textarea></label>
		<div class="nms-check-row"><label><input type="checkbox" name="proxy" value="1"><span>Allow shared IP for proxy or SNMPSim communities</span></label><label><input type="checkbox" name="disabled" value="1"><span>Create with monitoring disabled</span></label></div>
		<div class="nms-form-actions"><button type="submit">Create device in Cacti</button><a href="?tab=inventory">Cancel</a></div>
	</form>
</section>
