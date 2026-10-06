<?php
/**
 * Protocol controller: dispatch authenticated saves and load existing backend assignments.
 */

require dirname(__DIR__, 3) . "/../../include/auth.php";
require_once $config["base_path"] . "/include/global_form.php";
require_once dirname(__DIR__, 3) . '/configuration/services/configuration_history.php';
require_once dirname(__DIR__, 3) . "/shared/services/bootstrap.php";
require_once dirname(__DIR__, 3) . "/inventory/services/inventory.php";
require_once dirname(__DIR__, 3) . "/shared/services/forms.php";
require_once dirname(__DIR__, 3) . "/graphs/services/graph_service.php";
require_once dirname(__DIR__, 3) . "/graphs/services/data_query_service.php";
require_once dirname(__DIR__, 3) . "/protocols/shared/services/protocol_service.php";
require_once dirname(__DIR__, 3) . '/protocols/shared/services/protocol_preset_service.php';
require_once dirname(__DIR__, 3) . "/inventory/services/device_service.php";
require_once dirname(__DIR__, 3) . "/protocols/serial/services/serial_service.php";
$error = "";
$notice = "";
try {
    icct_nms_backend();
    if (
        $_SERVER["REQUEST_METHOD"] === "POST" &&
        ($_POST["action"] ?? "") === "discover"
    ) {
        icct_nms_post();
        $count = 0;
        foreach (icct_nms_inventory() as $row) {
            $assignment = icct_nms_discovery_assignment((int) $row["id"]);
            if (
                $row["disabled"] !== "" ||
                empty($assignment["preset_id"]) ||
                empty($assignment["enabled"]) ||
                empty($assignment["collection_enabled"])
            ) {
                continue;
            }
            icct_backend_nd_test_queue((int) $row["id"], (int) $row["site_id"]);
            $count++;
        }
        $_SESSION["icct_nms_notice"] =
            $count . " device discovery collections queued.";
        icct_nms_redirect("inventory.php");
    }
    $id = icct_nms_id($_GET["id"] ?? 0);
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        icct_nms_redirect("device.php?id=" . $id . "&step=protocol");
    }
    $host = icct_nms_device($id);
    icct_backend_require_management(3);
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        icct_nms_post();
        try {
        if(!icct_nms_meta('configuration_latest_'.$id))icct_nms_configuration_record($id,'Initial baseline');
            switch ($_POST["action"] ?? "") {
                case "add_data_query":
                case "change_data_query":
                case "remove_data_query":
                case "reload_data_query":
                case "verbose_data_query":
                    $_SESSION["icct_nms_notice"] = icct_nms_save_data_query($id, $host, $_POST);
                    icct_nms_redirect("protocol.php?id=" . $id . "#data-query");
                    break;
                case "add_graph_template":
                case "remove_graph_template":
                    icct_nms_save_graph_association($id, $_POST);
                    $_SESSION["icct_nms_notice"] =
                        $_POST["action"] === "add_graph_template"
                            ? "Graph template associated."
                            : "Graph template association removed.";
                    icct_nms_redirect("protocol.php?id=" . $id . "#graphs");
                    break;
                case "toggle_protocol":
                    icct_nms_toggle_protocol($id, $host, $_POST);
                    break;
                case "remove_protocol":
                    icct_nms_remove_protocol($id, $host, $_POST);
                    icct_backend_protocol_state_write(
                        $id,
                        $_POST["protocol"],
                        true,
                    );
                    if ($_POST["protocol"] === "snmp") {
                        icct_backend_category_execute(
                            "DELETE FROM plugin_icct_nms_meta WHERE meta_key=?",
                            ["protocol_snmp_version_" . $id],
                        );
                    }
                    break;
                case "reindex":
                    icct_backend_device_reindex($id);
                    $_SESSION["icct_nms_notice"] =
                        "Device " . $id . " data queries re-indexed.";
                    icct_nms_redirect("inventory.php");
                    break;
                case "snmp":
                    if (!icct_backend_protocol_enabled($id, "snmp")) {
                        throw new RuntimeException(
                            "Enable the protocol before editing its settings.",
                        );
                    }
                    icct_nms_save_snmp($id, $host, $_POST);
                    break;
                case "discovery":
                    if (
                        isset($_POST["discovery_protocol"]) &&
                        !icct_backend_protocol_enabled(
                            $id,
                            $_POST["discovery_protocol"],
                        )
                    ) {
                        throw new RuntimeException(
                            "Enable the protocol before editing its settings.",
                        );
                    }
                    icct_nms_save_discovery($id, $_POST);
                    break;
                case "diagnostics":
                    icct_nms_save_diagnostics($id, $_POST);
                    break;
                case "ssh":
                    if (!icct_backend_protocol_enabled($id, "ssh")) {
                        throw new RuntimeException(
                            "Enable the protocol before editing its settings.",
                        );
                    }
                    icct_nms_save_ssh($id, $_POST);
                    break;
                case "serial":
                    if (!icct_backend_protocol_enabled($id, "serial")) {
                        throw new RuntimeException(
                            "Enable the protocol before editing its settings.",
                        );
                    }
                    icct_nms_save_serial($id, $_POST);
                    break;
                case "syslog":
                    if (!icct_backend_protocol_enabled($id, "syslog")) throw new RuntimeException("Enable Syslog before editing its settings.");
                    icct_nms_save_syslog($id, $host, $_POST);
                    break;
                default:
                    throw new InvalidArgumentException(
                        "Unknown inventory action.",
                    );
            }
            icct_nms_protocol_record_overrides($id,(string)($_POST['action'] ?? ''),$_POST);
            icct_nms_configuration_record($id,(string)($_POST['action'] ?? 'Protocol saved'));
            $deviceName = '“' . $host["description"] . '”';
            $settingNames = [
                "snmp" => "SNMP",
                "ssh" => "SSH",
                "serial" => "Serial communication",
                "syslog" => "Syslog",
                "diagnostics" => "Device diagnostics",
                "discovery" => "Discovery",
            ];
            $action = $_POST["action"];
            $protocolName = ($_POST["protocol"] ?? "") === "serial"
                ? "Serial communication"
                : strtoupper($_POST["protocol"] ?? "");
            if ($action === "toggle_protocol") {
                $_SESSION["icct_nms_notice"] = $protocolName .
                    ($_POST["enabled"] === "1" ? " enabled" : " disabled") .
                    " for " . $deviceName . ". Saved parameters are retained.";
            } elseif ($action === "remove_protocol") {
                $_SESSION["icct_nms_notice"] = $protocolName .
                    " removed from " . $deviceName . ".";
            } else {
                $_SESSION["icct_nms_notice"] = $settingNames[$action] .
                    " settings saved successfully for " . $deviceName . ".";
            }
            icct_nms_redirect("protocol.php?id=" . $id);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    $discovery = icct_nms_discovery_assignment($id);
    $diag = db_fetch_row_prepared(
        "SELECT p.* FROM plugin_icct_nms_diagnostic_devices d JOIN plugin_icct_nms_diagnostic_profiles p ON p.id=d.profile_id WHERE d.host_id=?",
        [$id],
    );
    $ssh = db_fetch_row_prepared(
        "SELECT p.*,d.monitoring FROM plugin_icct_nms_ssh_devices d JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE d.host_id=?",
        [$id],
    );
    $serial = icct_backend_serial_assignment($id);
    $connections = icct_backend_serial_connections((int) $host["poller_id"]);
    $notice = $_SESSION["icct_nms_notice"] ?? "";
    unset($_SESSION["icct_nms_notice"]);
    // Render the saved SNMP version even while native polling is disabled.
    if (!icct_backend_protocol_enabled($id, "snmp")) {
        $host["snmp_version"] = (int) icct_nms_meta(
            "protocol_snmp_version_" . $id,
        );
    }
    $graphAssociations = icct_nms_graph_associations($id);
    $availableGraphTemplates = icct_nms_available_graph_templates($id);
    $dataQueryAssociations = icct_nms_data_query_associations($id);
    $availableDataQueries = icct_nms_available_data_queries($id, $host);
    $dataQueryMethods = icct_nms_data_query_methods($host);
    $defaultDataQueryMethod = (int) read_config_option("reindex_method");
    if (!isset($dataQueryMethods[$defaultDataQueryMethod])) $defaultDataQueryMethod = 0;
    // Failed native POSTs must retain the selected panels even before an assignment exists.
    $protocolDraft = [];
    $failedProtocol = '';
    if ($error !== '') {
        $allowedProtocols = ['cdp','lldp','snmp','ssh','serial','syslog','netflow','ntp','tacacs'];
        $protocolDraft = array_values(array_intersect($allowedProtocols, (array)($_POST['draft_protocols'] ?? [])));
        $failedProtocol = (string)($_POST['action'] ?? '');
        if ($failedProtocol === 'discovery') $failedProtocol = (string)($_POST['discovery_protocol'] ?? '');
        if (in_array($failedProtocol, $allowedProtocols, true)) $protocolDraft[] = $failedProtocol;
    }
    $title = "Protocol Config";
    require dirname(__DIR__, 3) . "/shared/templates/header.php";
    require dirname(__DIR__, 3) . "/protocols/shared/templates/protocol.php";
    require dirname(__DIR__, 3) . "/shared/templates/footer.php";
} catch (Throwable $e) {
    icct_nms_failure($e);
}
