<?php
/** Explicit wizard saves only; step navigation never calls this endpoint. */
require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once $config['base_path'] . '/include/global_form.php';
require_once dirname(__DIR__, 2) . '/configuration/services/configuration_history.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 2) . '/shared/services/forms.php';
require_once dirname(__DIR__, 2) . '/inventory/services/device_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_preset_service.php';
require_once dirname(__DIR__, 2) . '/protocols/serial/services/serial_service.php';
require_once dirname(__DIR__, 2) . '/graphs/services/graph_service.php';
require_once dirname(__DIR__, 2) . '/graphs/services/data_query_service.php';
require_once dirname(__DIR__, 2) . '/fcaps/services/fcaps_service.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    icct_nms_backend();
    icct_nms_post();
    $id = icct_nms_id($_POST['host_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'basic') {
        $old = $id ? icct_nms_device($id) : icct_nms_defaults();
        $id = icct_nms_save_device($id, $old, $_POST);
    } else {
        icct_backend_require_management(3);
        $host = icct_nms_device($id);
        if(!icct_nms_meta('configuration_latest_'.$id))icct_nms_configuration_record($id,'Initial baseline');
        switch ($action) {
            case 'configuration_backup': icct_nms_configuration_record($id,'Manual backup',true); break;
            case 'ports': icct_nms_save_port_monitoring($id,$_POST); break;
            case 'faults': icct_nms_save_faults($id,$_POST); break;
            case 'snmp': icct_nms_save_snmp($id, $host, $_POST); break;
            case 'ssh': icct_nms_save_ssh($id, $_POST); break;
            case 'serial': icct_nms_save_serial($id, $_POST); break;
            case 'syslog': icct_nms_save_syslog($id, $host, $_POST); break;
            case 'discovery': icct_nms_save_discovery($id, $_POST); break;
            case 'diagnostics': icct_nms_save_diagnostics($id, $_POST); break;
            case 'toggle_protocol': icct_nms_toggle_protocol($id, $host, $_POST); break;
            case 'remove_protocol':
                icct_nms_remove_protocol($id, $host, $_POST);
                icct_backend_protocol_state_write($id, $_POST['protocol'], true);
                if ($_POST['protocol'] === 'snmp') icct_backend_category_execute('DELETE FROM plugin_icct_nms_meta WHERE meta_key=?', ['protocol_snmp_version_'.$id]);
                break;
            case 'add_graph_template':
            case 'remove_graph_template': icct_nms_save_graph_association($id, $_POST); break;
            case 'add_data_query':
            case 'change_data_query':
            case 'remove_data_query':
            case 'reload_data_query':
            case 'verbose_data_query': $message = icct_nms_save_data_query($id, $host, $_POST); break;
            default: throw new InvalidArgumentException('Unknown wizard save action.');
        }
    }
    icct_nms_protocol_record_overrides($id,$action,$_POST);
    if($action!=='basic' && $action!=='configuration_backup')icct_nms_configuration_record($id,$action);
    echo json_encode(['ok'=>true,'id'=>$id,'message'=>$message ?? 'Changes saved.', 'backups'=>$action==='configuration_backup'?icct_nms_configuration_history($id):null], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
