<?php
/**
 * Basic Information controller: load saved values and handle native device creation or editing.
 */

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='fault_parameter_read')$auth_json=true;
require __DIR__ . "/../../include/auth.php";
require_once $config["base_path"] . "/include/global_form.php";
require_once __DIR__ . '/configuration/services/configuration_history.php';
require_once __DIR__ . "/shared/services/bootstrap.php";
require_once __DIR__ . "/inventory/services/inventory.php";
require_once __DIR__ . "/shared/services/forms.php";
require_once __DIR__ . "/inventory/services/device_service.php";
require_once __DIR__ . "/graphs/services/graph_service.php";
require_once __DIR__ . "/graphs/services/data_query_service.php";
require_once __DIR__ . "/fcaps/services/fcaps_service.php";
require_once __DIR__ . "/protocols/shared/services/protocol_service.php";
require_once __DIR__ . "/protocols/serial/services/serial_service.php";
require_once __DIR__ . "/protocols/shared/services/protocol_preset_service.php";
// Read-only preview uses the saved device protocol credentials; never exposes them to JS.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='fault_parameter_read') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        icct_nms_backend();icct_nms_post();
        $host=icct_nms_device(icct_nms_id($_GET['id']??0));
        if($host['disabled']!==''||(int)$host['snmp_version']<1||!icct_backend_protocol_enabled((int)$host['id'],'snmp'))throw new InvalidArgumentException('Enable SNMP in Protocol Config and save the device first.');
        $oid=icct_nms_fault_oid($_POST['oid']??'');
        require_once $config['base_path'].'/lib/snmp.php';
        $value=icct_nms_fault_snmp_read($host,$oid);
        echo json_encode(['value'=>$value]);
    } catch(Throwable $e) { http_response_code(400);echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}
$error = "";
$notice = "";
try {
    icct_nms_backend();
    $id = icct_nms_id($_GET["id"] ?? 0);
    $readonly = isset($_GET["view"]) || !is_realm_allowed(3);
    if (!$id) {
        icct_backend_require_management(3);
    }
    $old = $id ? icct_nms_device($id) : icct_nms_defaults();
    $values = $old;
    $meta = $id ? icct_nms_metadata($id) : [];
    $classification = $id
        ? db_fetch_row_prepared(
            "SELECT * FROM plugin_icct_nms_device_classification WHERE host_id=?",
            [$id],
        )
        : [];
    $rack = $id
        ? db_fetch_row_prepared(
            "SELECT * FROM plugin_icct_nms_rack_devices WHERE host_id=?",
            [$id],
        )
        : [];
    $values += ["description" => "", "hostname" => "", "notes" => ""];
    $values["enabled"] = ($old["disabled"] ?? "") !== "on";
    $values["short_name"] = $id ? icct_backend_short_name_get($id) : "";
    $shortNameAuto = $values["short_name"] === "";
    $values["serial_number"] = $meta["serial_number"] ?? "";
    $values["mac_address"] = $meta["mac_address"] ?? "";
    $values["chassis_id"] = $meta["chassis_id"] ?? "";
    $observedIdentity = $id ? icct_backend_identity_observed($old) : [];
    foreach (["mac_address", "serial_number", "chassis_id"] as $field) {
        if (
            trim((string) $values[$field]) === "" &&
            !empty($observedIdentity[$field])
        ) {
            $values[$field] = $observedIdentity[$field];
        }
    }
    $values["category_id"] = $classification["category_id"] ?? 0;
    $values["device_type"] = $classification["device_type"] ?? "";
    $peripheralRack=$id ? (int)icct_nms_meta('rack_peripheral_'.$id) : 0;
    $values["rack_id"] = $rack["rack_id"] ?? $peripheralRack;
    $values["rack_position"] = $rack
        ? $rack["start_unit"] . ":" . $rack["unit_height"]
        : ($peripheralRack ? "peripheral" : "");
    $values["cross_launch_url"] = $id
        ? icct_nms_meta("icct_cross_launch_" . $id)
        : "";
    // Clone only visible, non-secret settings into an unsaved Add Device form.
    // Require a new endpoint and identity; never copy credentials, rack placement or observations.
    $cloneId = icct_nms_id($_GET["clone_id"] ?? 0);
    if ($cloneId && !$id) {
        $source = icct_nms_device($cloneId);
        foreach (
            [
                "description",
                "host_template_id",
                "site_id",
                "poller_id",
                "device_threads",
                "notes",
            ]
            as $field
        ) {
            $values[$field] = $source[$field];
        }
        $values["description"] .= " (Copy)";
        $values["hostname"] = "";
        $sourceClass = db_fetch_row_prepared(
            "SELECT category_id FROM plugin_icct_nms_device_classification WHERE host_id=?",
            [$cloneId],
        );
        $values["category_id"] = $sourceClass["category_id"] ?? 0;
        $values["device_type"] = icct_backend_category_device_type(
            $values["category_id"],
            $cloneId,
        );
    }
    if ($shortNameAuto && $values["description"] !== "") {
        $values["short_name"] = icct_backend_short_name_generate(
            $values["description"], $values["device_type"], $id,
        );
    }
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        icct_nms_post();
        if ($readonly) {
            throw new RuntimeException("This device is read only.");
        }
        try {
            $saved = icct_nms_save_device($id, $old, $_POST);
            $_SESSION["icct_nms_notice"] = $cloneId
                ? "Device “" . trim($_POST["description"]) . "” cloned successfully and added to Inventory."
                : "Device “" . trim($_POST["description"]) . "” saved successfully.";
            icct_nms_redirect("inventory.php");
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $values = array_replace($values, array_filter($_POST, "is_scalar"));
            $values["enabled"] = isset($_POST["enabled"]);
            $shortNameAuto =
                trim((string) ($values["short_name"] ?? "")) === "";
            if ($shortNameAuto) {
                $values["short_name"] = icct_backend_short_name_generate(
                    $values["description"], $values["device_type"], $id,
                );
            }
        }
    }
    $notice = $_SESSION["icct_nms_notice"] ?? "";
    unset($_SESSION["icct_nms_notice"]);
    $title = $readonly
        ? "Device Details"
        : ($id
            ? "Edit Device"
            : ($cloneId
                ? "Clone Device"
                : "Add Device"));
    require __DIR__ . "/shared/templates/header.php";
    $treeView = $readonly && $id && isset($_GET['tree']);
    if ($treeView) {
        $devices = icct_nms_inventory();
        $management = is_realm_allowed(3);
        require __DIR__ . '/inventory/templates/inventory_tree.php';
    }
    $wizard = !$readonly;
    if ($wizard) {
        $host = $old;
        echo '<div id="device-wizard" data-device-id="' . (int)$id . '" data-initial-step="' . icct_nms_h($_GET['step'] ?? 'basic') . '">';
    }
    require __DIR__ . ($readonly ? "/inventory/templates/device_view.php" : "/inventory/templates/device.php");
    if ($treeView) echo '</div></div>';
    if ($wizard) {
        require __DIR__ . "/inventory/services/wizard_view.php";
        echo '<script type="application/json" id="protocol-default-values">'.json_encode(icct_nms_protocol_presets(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
        echo '<script type="application/json" id="protocol-device-overrides">'.json_encode(icct_nms_protocol_device_overrides($id),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
        echo '<div id="wizard-panels" hidden>';
        require __DIR__ . "/protocols/shared/templates/protocol.php";
        require __DIR__ . '/ports/templates/ports.php';
        require __DIR__ . '/fcaps/templates/fcaps.php';
        echo '</div><footer class="form-footer wizard-footer"><button type="button" class="button" id="wizard-previous">Previous ←</button><button type="button" class="button" id="wizard-next">Next →</button></footer></div>';
    }
    require __DIR__ . "/shared/templates/footer.php";
} catch (Throwable $e) {
    icct_nms_failure($e);
}
