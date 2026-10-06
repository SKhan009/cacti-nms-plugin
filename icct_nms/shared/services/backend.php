<?php
/** Load only this plugin's Inventory services and Cacti's native device APIs. */
if (!defined("ICCT_NMS_ROOT")) {
    define("ICCT_NMS_ROOT", dirname(__DIR__, 2));
}
foreach (
    [
        "api_device.php",
        "api_automation.php",
        "api_tree.php",
        "api_graph.php",
        "data_query.php",
        "template.php",
        "utility.php",
        "snmp.php",
    ]
    as $library
) {
    require_once $config["base_path"] . "/lib/" . $library;
}
require_once __DIR__ . "/../../presets/services/backend/categories.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/protocol_state.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_equipment.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_jobs.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_monitoring.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_runner.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_service.php";
require_once __DIR__ . "/../../configuration/services/backend/configuration_validation.php";
require_once __DIR__ . "/../../presets/services/backend/core_form_options.php";
require_once __DIR__ . "/backend/database.php";
require_once __DIR__ . "/../../inventory/services/backend/device_manager.php";
require_once __DIR__ . "/../../inventory/services/backend/device_metadata.php";
require_once __DIR__ . "/../../inventory/diagnostics/services/backend/diagnostic_bandwidth.php";
require_once __DIR__ . "/../../inventory/diagnostics/services/backend/diagnostics.php";
require_once __DIR__ . "/../../inventory/diagnostics/services/backend/diagnostics_queue.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/discovery.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/discovery_endpoints.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/discovery_identity.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/discovery_management_addresses.php";
require_once __DIR__ . "/../../protocols/shared/services/backend/discovery_neighbors.php";
require_once __DIR__ . "/../../protocols/snmp/services/backend/discovery_snmp.php";
require_once __DIR__ . "/backend/functions.php";
require_once __DIR__ . "/../../ports/services/backend/interface_rates.php";
require_once __DIR__ . "/../../inventory/services/backend/inventory.php";
require_once __DIR__ . "/../../protocols/ssh/services/backend/ssh.php";
require_once __DIR__ . "/../../protocols/ssh/services/backend/ssh_linux.php";
require_once __DIR__ . "/../../protocols/ssh/services/backend/ssh_platform.php";
require_once __DIR__ . "/../../protocols/ssh/services/backend/ssh_schema.php";
require_once __DIR__ . "/../../dashboard/topology/services/backend/topology_config.php";
require_once __DIR__ . "/../../protocols/ssh/services/backend/ssh_broker.php";

require_once __DIR__ . "/../../inventory/services/backend/identity.php";

require_once __DIR__ . "/../../ports/services/backend/ports.php";

require_once __DIR__ . '/../../fcaps/services/fcaps_service.php';

require_once __DIR__ . '/../../protocols/syslog/services/backend/syslog.php';
