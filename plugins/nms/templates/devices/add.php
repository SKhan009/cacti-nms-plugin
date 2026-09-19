<?php
/**
 * @file add.php
 * Render the new-device form using Cacti defaults or explicit imported-simulator settings.
 * The devices.php controller validates and saves submitted values through the shared device helper.
 */
$device_form_is_edit = !empty($edit_device);
$device_form_is_prefilled =
	!$device_form_is_edit &&
	(!empty($snmpsim_import_id) ||
		isset_request_var("host_template_id") ||
		isset_request_var("snmp_community") ||
		isset_request_var("snmp_port"));
$device_values = $device_form_is_edit ? array_merge($cacti_device_defaults, $edit_device) : $cacti_device_defaults;
$selected_snmp_version = isset_request_var("snmp_version")
	? (int) get_filter_request_var("snmp_version")
	: (int) $device_values["snmp_version"];
$selected_auth_protocol = isset_request_var("snmp_auth_protocol")
	? get_nfilter_request_var("snmp_auth_protocol")
	: $device_values["snmp_auth_protocol"];
$selected_priv_protocol = isset_request_var("snmp_priv_protocol")
	? get_nfilter_request_var("snmp_priv_protocol")
	: $device_values["snmp_priv_protocol"];
$selected_availability = isset_request_var("availability_method")
	? (int) get_filter_request_var("availability_method")
	: (int) $device_values["availability_method"];
$selected_ping_method = isset_request_var("ping_method")
	? (int) get_filter_request_var("ping_method")
	: (int) $device_values["ping_method"];
$selected_template_id = isset_request_var("host_template_id")
	? (int) get_filter_request_var("host_template_id")
	: (int) ($device_values["host_template_id"] ?? 0);
$selected_site_id = isset_request_var("site_id")
	? (int) get_filter_request_var("site_id")
	: (int) ($device_form_is_edit
		? $device_values["site_id"] ?? 0
		: (nms_single_topology_site() ?:
		$device_values["site_id"] ?? 0));
$available_poller_ids = array_map(static fn($poller) => (int) $poller["id"], $pollers);
$default_poller_id = (int) ($device_values["poller_id"] ?? 0);
if (!in_array($default_poller_id, $available_poller_ids, true)) {
	$default_poller_id = $available_poller_ids ? $available_poller_ids[0] : 0;
}
$selected_poller_id = isset_request_var("poller_id")
	? (int) get_filter_request_var("poller_id")
	: $default_poller_id;
$selected_snmp_port = isset_request_var("snmp_port")
	? (int) get_filter_request_var("snmp_port")
	: (int) $device_values["snmp_port"];
$selected_snmp_timeout = isset_request_var("snmp_timeout")
	? (int) get_filter_request_var("snmp_timeout")
	: (int) $device_values["snmp_timeout"];
$selected_ping_port = isset_request_var("ping_port")
	? (int) get_filter_request_var("ping_port")
	: (int) $device_values["ping_port"];
$selected_ping_timeout = isset_request_var("ping_timeout")
	? (int) get_filter_request_var("ping_timeout")
	: (int) $device_values["ping_timeout"];
$selected_ping_retries = isset_request_var("ping_retries")
	? (int) get_filter_request_var("ping_retries")
	: (int) $device_values["ping_retries"];
$selected_max_oids = isset_request_var("max_oids")
	? (int) get_filter_request_var("max_oids")
	: (int) $device_values["max_oids"];
$selected_device_threads = isset_request_var("device_threads")
	? (int) get_filter_request_var("device_threads")
	: (int) $device_values["device_threads"];
$selected_category_id = isset_request_var("equipment_category_id")
	? (string) get_nfilter_request_var("equipment_category_id")
	: (string) ($device_classification["category_id"] ?? "0");
$selected_device_type = isset_request_var("device_type")
	? get_nfilter_request_var("device_type")
	: $device_classification["device_type"] ?? "";
require_once __DIR__ . "/../../includes/topology/appearance.php";
if ($selected_device_type === "") {
	$selected_template_name = (string) db_fetch_cell_prepared("SELECT name FROM host_template WHERE id=?", [
		$selected_template_id,
	]);
	$selected_device_type = nms_device_type_auto($device_values["description"] ?? "", $selected_template_name);
}
$manual_serial_value = isset_request_var("manual_serial_number")
	? get_nfilter_request_var("manual_serial_number")
	: ($device_form_is_edit
		? $serial_prefill["value"] ?? ($device_values["manual_serial_number"] ?? "")
		: "");
$identity_auto = $device_form_is_edit ? nms_identity_observation((int) $device_values["id"]) : [];
if (!empty($identity_auto) && empty($identity_auto["source"])) {
	$identity_auto["source"] = "Current SNMP discovery";
}
if (!$device_form_is_edit && !empty($snmpsim_import_id)) {
	try {
		$identity_auto = nms_identity_record_ports($snmpsim_import_id);
	} catch (Throwable $e) {
		$identity_auto = ["source" => "Port information unavailable from this recording."];
	}
}
$identity_manual = $device_form_is_edit
	? db_fetch_row_prepared(
		"SELECT chassis_id,mac_address,port_count FROM plugin_nms_device_metadata WHERE host_id=?",
		[$device_values["id"]],
	)
	: [];
$identity_physical_count = !empty($identity_auto["hardware"]["physical_ports"])
	? count($identity_auto["hardware"]["physical_ports"])
	: 0;
$identity_interface_count = !empty($identity_auto["interfaces"]) ? count($identity_auto["interfaces"]) : 0;
$identity_suggestions = [
	"chassis_id" => $identity_auto["chassis_id"] ?? "",
	"mac_address" => $identity_auto["mac"] ?? "",
	"port_count" =>
		$identity_physical_count || $identity_interface_count
			? (string) ($identity_physical_count ?: $identity_interface_count)
			: "",
];
$identity_port_fallback = !$identity_physical_count && $identity_interface_count > 0;
if (
	$manual_serial_value === "" &&
	!isset_request_var("manual_serial_number") &&
	!isset_request_var("serial_saved") &&
	!empty($identity_auto["serial"])
) {
	$manual_serial_value = $identity_auto["serial"];
}
$shared_endpoint_selected = isset_request_var("nms_action")
	? array_key_exists("proxy", $_POST)
	: ($device_form_is_edit
		? nms_shared_endpoint_get((int) $device_values["id"])
		: !empty($snmpsim_import_id));
$monitoring_disabled = isset_request_var("nms_action")
	? array_key_exists("disabled", $_POST)
	: !empty($device_values["disabled"]);
// Discrete choices belong to Cacti; ports, retries and timeouts remain editable numbers.
$max_oid_options = array_keys($fields_snmp_item_with_oids["max_oids"]["array"]);
$device_thread_options = array_keys($fields_host_edit["device_threads"]["array"]);
?>
<link rel="stylesheet" href="<?php print nms_h(nms_asset_url("css/nms-snmp-form.css")); ?>">
<section class="nms-panel nms-form-panel">
	<div class="nms-panel-head"><div><h2><?php print $device_form_is_edit
 	? "Edit " . nms_h($device_values["description"])
 	: "Add a Cacti device"; ?></h2><p><?php print $device_form_is_edit
	? "Live settings from Cacti core. Saving updates this device directly."
	: "Uses the same Cacti device API as the core console."; ?></p></div><?php if (
	$device_form_is_edit
) { ?><span class="nms-device-id">Device ID <?php print (int) $device_values["id"]; ?></span><?php } ?></div>
	<form id="nms-device-form" method="post" action="devices.php?tab=<?php print $device_form_is_edit
 	? "edit&id=" . (int) $device_values["id"]
 	: "add"; ?>" class="nms-device-form">
		<input type="hidden" name="__csrf_magic" value="<?php print nms_h($nms_csrf_token); ?>">
		<input type="hidden" name="nms_action" value="<?php print $device_form_is_edit ? "update_device" : "add_device"; ?>">
		<?php if (!$device_form_is_edit && !empty($snmpsim_import_id)) { ?>
		<input type="hidden" name="snmpsim_import_id" value="<?php print (int) $snmpsim_import_id; ?>">
		<p>Simulator address and port come from the shared server configuration; community and template come from this imported record. Keep these connection fields unchanged. Use normal Add device for real hardware.</p>
		<?php } ?>
		<?php if ($device_form_is_edit) { ?><input type="hidden" name="id" value="<?php print (int) $device_values[
	"id"
]; ?>"><?php } ?>
		<fieldset><legend>Device identity</legend><div class="nms-form-grid">
			<label><span>Device segment</span><select name="equipment_category_id" required><?php if (
   	!$device_form_is_edit
   ) { ?><option value="">Select a device segment</option><?php } ?><option value="0" <?php print $selected_category_id ===
"0"
	? "selected"
	: ""; ?>>Unclassified</option><?php
if (!$device_form_is_edit) { ?><option value="template" <?php print $selected_category_id === "template"
	? "selected"
	: ""; ?>>Use selected template's saved suggestion</option><?php }
foreach ($categories as $category) { ?><option value="<?php print (int) $category[
	"id"
]; ?>" <?php print $selected_category_id === (string) $category["id"] ? "selected" : ""; ?>><?php print nms_h(
	$category["name"],
); ?></option><?php }
?></select><small>NMS device classification. Select the segment that describes this device.</small></label>
			<label><span>Device type</span><input list="nms-device-types" name="device_type" maxlength="150" placeholder="UPS, switch, sensor" value="<?php print nms_h(
   	$selected_device_type,
   ); ?>"></label>
			<datalist id="nms-device-types"><?php
   foreach (nms_appearance_read()["types"] as $profile) { ?><option value="<?php print nms_h(
	$profile["name"],
); ?>"><?php print nms_h($profile["icon"]); ?></option><?php }
   foreach (
   	[
   		"Switch",
   		"Router",
   		"Server",
   		"PC / desktop",
   		"Laptop",
   		"Phone",
   		"Desk phone",
   		"UPS / battery",
   		"Sensor",
   		"Printer",
   		"Camera",
   		"Wireless AP",
   		"Firewall",
   		"Satellite / VSAT",
   		"Generic device",
   	]
   	as $default_type
   ) { ?><option value="<?php print nms_h($default_type); ?>"></option><?php }
   ?></datalist>
            <label><span>Serial number</span><input id="nmsManualSerial" name="manual_serial_number" maxlength="191" value="<?php print nms_h(
            	is_string($manual_serial_value) ? $manual_serial_value : "",
            ); ?>" placeholder="Serial printed on the device"><small>Optional NMS metadata. A current SNMP serial may be suggested; review before saving.</small><?php if (
	($serial_prefill["source"] ?? "") ===
	"snmp"
) { ?><small>Suggested from live SNMP OID <?php print nms_h($serial_prefill["oid"]); ?> at <?php print nms_h(
 	$serial_prefill["last_success"],
 ); ?>; review before saving.</small><?php } elseif (
	($serial_prefill["source"] ?? "") ===
	"saved"
) { ?><small>Saved manually in NMS.</small><?php } ?></label>
            <?php foreach (
            	[
            		"chassis_id" => "Chassis ID",
            		"mac_address" => "Device MAC address",
            		"port_count" => "Physical port count",
            	]
            	as $identity_key => $identity_label
            ) {

            	$identity_field = "manual_" . $identity_key;
            	$identity_value = isset_request_var($identity_field)
            		? get_nfilter_request_var($identity_field)
            		: $identity_manual[$identity_key] ?? "";
            	$identity_suggested = false;
            	if (
            		$identity_value === "" &&
            		!isset_request_var($identity_field) &&
            		!isset_request_var("serial_saved") &&
            		($identity_suggestions[$identity_key] ?? "") !== ""
            	) {
            		$identity_value = $identity_suggestions[$identity_key];
            		$identity_suggested = true;
            	}
            	?><label><span><?php print nms_h($identity_label); ?></span><input name="<?php print nms_h(
	$identity_field,
); ?>" value="<?php print nms_h(
	is_string($identity_value) ? $identity_value : "",
); ?>" maxlength="<?php print $identity_key === "port_count"
	? 5
	: ($identity_key === "mac_address"
		? 17
		: 191); ?>" <?php if ($identity_key === "port_count") {
	print 'inputmode="numeric" pattern="[0-9]{1,5}"';
} ?>><small><?php print $identity_suggested
	? (!empty($snmpsim_import_id)
		? "Suggested from the SNMP recording; review before saving."
		: "Suggested from current discovery; review before saving.")
	: "Optional manual entry. Automatic observations remain separate."; ?></small><?php if (
	$identity_key === "port_count"
) { ?>
<small><?php print nms_h($identity_auto["source"] ?? ""); ?></small><?php if (
	$identity_port_fallback
) { ?><small>Suggested from <?php print $identity_interface_count; ?> discovered interfaces because this device did not report ENTITY-MIB physical ports. Review the value against the chassis specification.</small><?php } ?>
<?php
$reported_ports = $identity_auto["hardware"]["physical_ports"] ?? [];
$reported_interfaces = $identity_auto["interfaces"] ?? [];
?>
<?php if ($reported_ports) { ?><details><summary><?php print count(
	$reported_ports,
); ?> detected physical ports</summary><?php foreach (
 	$reported_ports
 	as $port
 ) { ?><small style="display:block"><?php print nms_h(
	($port["name"] ?: "Unnamed port") .
		" — position " .
		(isset($port["position"]) && $port["position"] >= 0 ? $port["position"] : "not reported") .
		" (entity " .
		$port["entity_index"] .
		")",
); ?></small><?php } ?></details>
<?php } elseif ($reported_interfaces) { ?><details><summary><?php print count(
	$reported_interfaces,
); ?> detected interfaces</summary><small>Interfaces may include logical ports; they do not establish the physical port count.</small><?php foreach (
 	$reported_interfaces
 	as $port
 ) { ?><small style="display:block"><?php print nms_h(
	($port["name"] ?: $port["description"]) . " — ifIndex " . $port["index"],
); ?></small><?php } ?></details>
<?php } else { ?><small>Port numbers appear after collection, or from a selected SNMP recording containing port tables. MIB definitions alone contain no device port inventory.</small><?php } ?>
<?php } ?></label><?php
            } ?>

			<label><span>Device name</span><input required name="description" value="<?php print nms_h(
   	isset_request_var("description") ? get_nfilter_request_var("description") : $device_values["description"] ?? "",
   ); ?>" placeholder="Branch router 01"></label>
			<label><span>Short name</span><input name="short_name" id="nms-short-name" data-device-id="<?php print (int) ($device_values[
   	"id"
   ] ?? 0); ?>" maxlength="24" pattern="[A-Za-z0-9][A-Za-z0-9 _\-]*" value="<?php print nms_h(
	isset_request_var("short_name")
		? get_nfilter_request_var("short_name")
		: ($device_form_is_edit
			? nms_short_name_get($device_values["id"])
			: ""),
); ?>" placeholder="<?php print nms_h(
	nms_short_name_auto($device_values["description"] ?? "", $device_values["id"] ?? 0),
); ?>" data-nms-tip="Enter a short map label, for example CORE-SW or RTR-01. Use up to 24 letters, numbers, spaces, hyphens or underscores. Leave blank to follow the device name automatically."><small id="nms-short-name-help">Leave blank to generate from the device name automatically.</small></label>
            <label><span>Hostname or IP</span><input required name="hostname" value="<?php print nms_h(
            	isset_request_var("hostname") ? get_nfilter_request_var("hostname") : $device_values["hostname"] ?? "",
            ); ?>" placeholder="192.0.2.10"></label>
			<label><span>Cacti host template</span><select name="host_template_id"><option value="0">None</option><?php foreach (
   	$host_templates
   	as $template
   ) { ?><option value="<?php print (int) $template["id"]; ?>" <?php print $selected_template_id ===
(int) $template["id"]
	? "selected"
	: ""; ?>><?php print nms_h($template["name"]); ?></option><?php } ?></select></label>
			<label><span>Site</span><select name="site_id"><option value="0" <?php print $selected_site_id === 0
   	? "selected"
   	: ""; ?>>No site</option><?php foreach ($sites as $site) { ?><option value="<?php print (int) $site[
	"id"
]; ?>" <?php print $selected_site_id === (int) $site["id"] ? "selected" : ""; ?>><?php print nms_h(
	$site["name"],
); ?></option><?php } ?></select></label>
			<label><span>Data collector</span><select name="poller_id"><?php foreach (
   	$pollers
   	as $poller
   ) { ?><option value="<?php print (int) $poller["id"]; ?>" <?php print $selected_poller_id === (int) $poller["id"]
	? "selected"
	: ""; ?>><?php print nms_h($poller["name"]); ?></option><?php } ?></select></label>
			<label><span>Location</span><input name="location" value="<?php print nms_h(
   	isset_request_var("location") ? get_nfilter_request_var("location") : $device_values["location"] ?? "",
   ); ?>" placeholder="Building / rack"></label>
		</div></fieldset>
		<fieldset class="nms-snmp-fieldset"><legend>SNMP connection</legend>
			<p class="nms-snmp-mode-note" id="nmsSnmpModeNote" aria-live="polite"></p>
			<div class="nms-form-grid">
			<label><span>SNMP version</span><select id="nmsSnmpVersion" name="snmp_version"><?php foreach (
   	$snmp_versions
   	as $value => $label
   ) { ?><option value="<?php print nms_h($value); ?>" <?php print (string) $selected_snmp_version === (string) $value
	? "selected"
	: ""; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label class="nms-conditional-field" data-snmp-versions="1,2"><span>Community (v1/v2c)</span><input id="nmsSnmpCommunity" name="snmp_community" value="<?php print nms_h(
   	isset_request_var("snmp_community")
   		? get_nfilter_request_var("snmp_community")
   		: $device_values["snmp_community"],
   ); ?>" placeholder="Cacti default community"></label>
			<label><span>SNMP port</span><input required type="number" min="1" max="65535" name="snmp_port" value="<?php print $selected_snmp_port; ?>"></label>
			<label><span>Timeout (ms)</span><input type="number" name="snmp_timeout" min="1" step="1" value="<?php print (int) $selected_snmp_timeout; ?>"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 username</span><input id="nmsSnmpUsername" name="snmp_username" value="<?php print nms_h(
   	isset_request_var("snmp_username") ? get_nfilter_request_var("snmp_username") : $device_values["snmp_username"],
   ); ?>"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP v3 authentication</span><select id="nmsSnmpAuthProtocol" name="snmp_auth_protocol"><?php foreach (
   	$snmp_auth_protocols
   	as $value => $label
   ) { ?><option value="<?php print nms_h($value); ?>" <?php print (string) $selected_auth_protocol === (string) $value
	? "selected"
	: ""; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-required="1"><span>Authentication password</span><input id="nmsSnmpPassword" type="password" minlength="8" name="snmp_password" value="<?php print nms_h(
   	isset_request_var("snmp_password") ? get_nfilter_request_var("snmp_password") : $device_values["snmp_password"],
   ); ?>" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-auth-enabled="1"><span>SNMP v3 privacy</span><select id="nmsSnmpPrivProtocol" name="snmp_priv_protocol"><?php foreach (
   	$snmp_priv_protocols
   	as $value => $label
   ) { ?><option value="<?php print nms_h($value); ?>" <?php print (string) $selected_priv_protocol === (string) $value
	? "selected"
	: ""; ?>><?php print nms_h($label); ?></option><?php } ?></select></label>
			<label class="nms-conditional-field" data-snmp-versions="3" data-snmp-privacy-required="1"><span>Privacy passphrase</span><input id="nmsSnmpPrivPassphrase" type="password" minlength="8" name="snmp_priv_passphrase" value="<?php print nms_h(
   	isset_request_var("snmp_priv_passphrase")
   		? get_nfilter_request_var("snmp_priv_passphrase")
   		: $device_values["snmp_priv_passphrase"],
   ); ?>" autocomplete="new-password"><small>At least 8 characters</small></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP context</span><input id="nmsSnmpContext" name="snmp_context" value="<?php print nms_h(
   	isset_request_var("snmp_context")
   		? get_nfilter_request_var("snmp_context")
   		: $device_values["snmp_context"] ?? "",
   ); ?>"></label>
			<label class="nms-conditional-field" data-snmp-versions="3"><span>SNMP engine ID</span><input id="nmsSnmpEngineId" name="snmp_engine_id" value="<?php print nms_h(
   	isset_request_var("snmp_engine_id")
   		? get_nfilter_request_var("snmp_engine_id")
   		: $device_values["snmp_engine_id"] ?? "",
   ); ?>"></label>
		</div></fieldset>

        <?php require __DIR__ . "/discovery_fields.php"; ?>
		<fieldset class="nms-availability-fieldset"><legend>Availability and polling</legend><div class="nms-availability-grid">
			<section><h3>SNMP availability</h3><label><span>Availability method</span><select name="availability_method"><?php foreach (
   	$availability_options
   	as $value => $label
   ) { ?><option value="<?php print nms_h($value); ?>" <?php print (string) $selected_availability === (string) $value
	? "selected"
	: ""; ?>><?php print nms_h(
	$label,
); ?></option><?php } ?></select></label><small>Choose how Cacti confirms that this device is available through SNMP.</small></section>
			<section><h3>Ping availability</h3><label><span>Ping method</span><select name="ping_method"><?php foreach (
   	$ping_methods
   	as $value => $label
   ) { ?><option value="<?php print nms_h($value); ?>" <?php print (string) $selected_ping_method === (string) $value
	? "selected"
	: ""; ?>><?php print nms_h(
	$label,
); ?></option><?php } ?></select></label><small>Choose the network ping method used when Cacti performs an availability check.</small></section>
			<div class="nms-availability-options"><label><span>Ping port</span><input type="number" name="ping_port" min="0" max="65535" step="1" value="<?php print (int) $selected_ping_port; ?>"></label><label><span>Ping timeout (ms)</span><input type="number" name="ping_timeout" min="1" step="1" value="<?php print (int) $selected_ping_timeout; ?>"></label><label><span>Ping retries</span><input type="number" name="ping_retries" min="0" step="1" value="<?php print (int) $selected_ping_retries; ?>"></label></div>
			<div class="nms-availability-options"><label><span>Maximum OIDs per request</span><select name="max_oids"><?php foreach (
   	$max_oid_options
   	as $value
   ) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_max_oids === (int) $value
	? "selected"
	: ""; ?>><?php print (int) $value; ?> <?php print (int) $value === 1
 	? "OID"
 	: "OIDs"; ?></option><?php } ?></select></label><label><span>Device threads</span><select name="device_threads"><?php foreach (
	$device_thread_options
	as $value
) { ?><option value="<?php print (int) $value; ?>" <?php print $selected_device_threads === (int) $value
	? "selected"
	: ""; ?>><?php print (int) $value; ?> <?php print (int) $value === 1
 	? "thread"
 	: "threads"; ?></option><?php } ?></select></label><label><span>External ID</span><input name="external_id" value="<?php print nms_h(
	isset_request_var("external_id") ? get_nfilter_request_var("external_id") : $device_values["external_id"] ?? "",
); ?>"></label></div>
		</div></fieldset>
		<label class="nms-wide-field"><span>Notes</span><textarea name="notes" rows="3"><?php print nms_h(
  	isset_request_var("notes") ? get_nfilter_request_var("notes") : $device_values["notes"] ?? "",
  ); ?></textarea></label>
		<div class="nms-check-row"><label><input type="checkbox" name="proxy" value="1" <?php print $shared_endpoint_selected
  	? "checked"
  	: ""; ?>><span>Allow shared IP for proxy or SNMPSim communities</span></label><label><input type="checkbox" name="disabled" value="1" <?php print $monitoring_disabled
	? "checked"
	: ""; ?>><span><?php print $device_form_is_edit
	? "Disable device monitoring"
	: "Create with monitoring disabled"; ?></span></label></div>
		<div class="nms-form-actions"><button type="submit"><?php print $device_form_is_edit
  	? "Save device changes"
  	: ($device_form_is_prefilled
  		? "Save device"
  		: "Create device in Cacti"); ?></button><a class="nms-cancel-button" href="?tab=inventory">Cancel</a></div>
	</form>
</section>
<?php
// Discovery result panels are temporarily hidden from Device management; collection remains enabled.
?>
<?php if (!$device_form_is_edit) { ?>
<div class="nms-device-associations">
	<section class="nms-panel" id="graph-templates">
		<div class="nms-panel-head"><div><h2>Associated Graph Templates</h2><p>Available after this device has been saved in Cacti.</p></div><a class="nms-panel-action" href="#nms-device-form">Save device first</a></div>
		<p class="nms-association-guidance">Complete the device form and click Create device in Cacti (or Save device). You will then be taken to this device's graph-template associations, where you can review inherited templates, search for more templates, and create graphs.</p>
	</section>
	<section class="nms-panel" id="data-queries">
		<div class="nms-panel-head"><div><h2>Associated Data Queries</h2><p>A saved device is required for query associations and re-indexing.</p></div><a class="nms-panel-action" href="#nms-device-form">Save device first</a></div>
		<p class="nms-association-guidance">After saving, open Data queries to review inherited queries, search for additional Cacti queries, and choose their re-index method. Nothing is attached or polled before the device is created.</p>
	</section>
</div>
<?php } ?>
