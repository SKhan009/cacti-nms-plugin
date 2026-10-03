<?php
/** Load only this plugin's Inventory services and Cacti's native device APIs. */
if (!defined("ICCT_NMS_ROOT")) {
    define("ICCT_NMS_ROOT", dirname(__DIR__));
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
require_once __DIR__ . "/services/categories.php";
require_once __DIR__ . "/services/protocol_state.php";
require_once __DIR__ . "/services/configuration_equipment.php";
require_once __DIR__ . "/services/configuration_jobs.php";
require_once __DIR__ . "/services/configuration_monitoring.php";
require_once __DIR__ . "/services/configuration_runner.php";
require_once __DIR__ . "/services/configuration_service.php";
require_once __DIR__ . "/services/configuration_validation.php";
require_once __DIR__ . "/services/core_form_options.php";
require_once __DIR__ . "/services/database.php";
require_once __DIR__ . "/services/device_manager.php";
require_once __DIR__ . "/services/device_metadata.php";
require_once __DIR__ . "/services/diagnostic_bandwidth.php";
require_once __DIR__ . "/services/diagnostics.php";
require_once __DIR__ . "/services/diagnostics_queue.php";
require_once __DIR__ . "/services/discovery.php";
require_once __DIR__ . "/services/discovery_endpoints.php";
require_once __DIR__ . "/services/discovery_identity.php";
require_once __DIR__ . "/services/discovery_management_addresses.php";
require_once __DIR__ . "/services/discovery_neighbors.php";
require_once __DIR__ . "/services/discovery_snmp.php";
require_once __DIR__ . "/services/functions.php";
require_once __DIR__ . "/services/interface_rates.php";
require_once __DIR__ . "/services/inventory.php";
require_once __DIR__ . "/services/ssh.php";
require_once __DIR__ . "/services/ssh_linux.php";
require_once __DIR__ . "/services/ssh_platform.php";
require_once __DIR__ . "/services/ssh_schema.php";
require_once __DIR__ . "/services/topology_config.php";
require_once __DIR__ . "/services/ssh_broker.php";

require_once __DIR__ . "/services/identity.php";

require_once __DIR__."/services/ports.php";

require_once __DIR__.'/fcaps_service.php';
