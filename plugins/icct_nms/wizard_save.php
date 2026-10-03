<?php
/** Explicit wizard saves only; step navigation never calls this endpoint. */
require __DIR__ . '/../../include/auth.php';
require_once $config['base_path'] . '/include/global_form.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/device_service.php';
require_once __DIR__ . '/includes/protocol_service.php';
require_once __DIR__ . '/includes/serial_service.php';
require_once __DIR__ . '/includes/graph_service.php';
require_once __DIR__ . '/includes/data_query_service.php';
require_once __DIR__.'/includes/fcaps_service.php';
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
        switch ($action) {
            case 'faults': icct_nms_save_faults($id,$_POST); break;
            case 'snmp': icct_nms_save_snmp($id, $host, $_POST); break;
            case 'ssh': icct_nms_save_ssh($id, $_POST); break;
            case 'serial': icct_nms_save_serial($id, $_POST); break;
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
    echo json_encode(['ok'=>true,'id'=>$id,'message'=>$message ?? 'Changes saved.'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
