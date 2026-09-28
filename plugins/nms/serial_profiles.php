<?php
/** Serial profile editor; native authentication and CSRF protect every mutation. */
require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/configuration/service.php';
nms_require_database();
try { nms_require_management(); } catch (Throwable $e) { http_response_code(403); die(nms_h($e->getMessage())); }
$error = '';
$values = ['id'=>0,'revision'=>0,'name'=>'','description'=>'','manufacturer'=>'','model'=>'',
    'interface'=>'unspecified','custom_baud_rate'=>'','protocol'=>'modbus_rtu','baud_rate'=>9600,'data_bits'=>8,'parity'=>'even','stop_bits'=>1,
    'flow_control'=>'none','timeout_ms'=>1000,'retries'=>1];
$editing = isset($_GET['new']) || isset($_GET['id']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check_tokens($_POST['__csrf_magic'] ?? '')) throw new RuntimeException('Invalid request token.');
        if (($_POST['action'] ?? '') !== 'save') throw new InvalidArgumentException('Unsupported action.');
        $id = nms_serial_profile_save($_POST);
        header('Location: serial_profiles.php?id='.$id.'&saved=1', true, 303); exit;
    }
    if (isset($_GET['id'])) {
        $profile = nms_serial_profile_get($_GET['id']);
        $values = array_replace($values, $profile, $profile['settings']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $editing = true;
        foreach ($values as $key=>$value) if (isset($_POST[$key]) && is_scalar($_POST[$key])) $values[$key] = $_POST[$key];
    } else $editing = false;
}
$profiles = nms_serial_profiles();
// Only count references here: endpoint identities belong to the device-scoped connection UI.
$references = (int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_nms_serial_connections WHERE profile_id=?', [(int)$values['id']]);
nms_prepare_page('presets', 'NMS · Serial profiles', 'css/nms-devices.css,css/nms-nodes.css', '');
require __DIR__.'/templates/app_header.php';
require __DIR__.'/templates/serial_profiles.php';
require __DIR__.'/templates/app_footer.php';
