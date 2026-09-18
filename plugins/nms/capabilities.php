<?php
/** Per-device FCAPS capability evidence, derived from live Cacti configuration and collection state. */
require __DIR__ . "/../../include/auth.php";
require_once $config["base_path"] . "/include/global_form.php";
require_once $config["base_path"] . "/plugins/nms/includes/functions.php";
require_once $config["base_path"] . "/plugins/nms/includes/database.php";
require_once $config["base_path"] . "/plugins/nms/includes/capabilities.php";

nms_require_database();
$visible_hosts = nms_visible_host_sql();
$capability_devices = db_fetch_assoc("SELECT h.id, h.description, h.hostname, h.status, h.disabled,
	h.last_updated, h.availability, h.snmp_sysName
	FROM host AS h WHERE h.deleted = '' AND $visible_hosts ORDER BY h.description");
$allowed_device_ids = array_map("intval", array_column($capability_devices, "id"));
$device_id = isset_request_var("host_id") ? (int) get_filter_request_var("host_id") : $allowed_device_ids[0] ?? 0;
if ($device_id > 0 && !in_array($device_id, $allowed_device_ids, true)) {
	http_response_code(403);
	die("This Cacti device is not available.");
}
$capability_device = [];
foreach ($capability_devices as $candidate) {
	if ((int) $candidate["id"] === $device_id) {
		$capability_device = $candidate;
		break;
	}
}
$device_capabilities = $capability_device
	? nms_device_capabilities($capability_device)
	: ["capabilities" => [], "methods" => []];

nms_prepare_page("capabilities", "NMS · FCAPS Capabilities", "css/nms-capabilities.css");
require $config["base_path"] . "/plugins/nms/templates/app_header.php";
require $config["base_path"] . "/plugins/nms/templates/monitoring/capabilities.php";
require $config["base_path"] . "/plugins/nms/templates/app_footer.php";
