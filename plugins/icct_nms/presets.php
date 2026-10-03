<?php
require __DIR__ . '/../../include/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/segment_service.php';
require_once __DIR__ . '/includes/device_type_service.php';
require_once __DIR__.'/includes/connection_service.php';
require_once __DIR__.'/includes/site_service.php';
require_once __DIR__.'/includes/node_service.php';
require_once __DIR__.'/includes/rack_preset_service.php';
require_once $config['base_path'].'/include/global_form.php';
require_once __DIR__.'/includes/forms.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/device_service.php';
require_once __DIR__.'/includes/protocol_service.php';
require_once __DIR__.'/includes/serial_service.php';
require_once __DIR__.'/includes/protocol_preset_service.php';
$presetTabs = ['rack-config'=>'Rack Config','node'=>'Node','protocols'=>'Protocols','site'=>'Site','segment'=>'Segment','device-type'=>'Device Type','network-connections'=>'Network Connections'];
$actionTabs = ['save_rack_profile'=>'rack-config','save_node'=>'node','delete_node'=>'node','save_protocol_defaults'=>'protocols','save_site'=>'site','save_segment'=>'segment','delete_segment'=>'segment','save_type'=>'device-type','delete_type'=>'device-type','save_connection'=>'network-connections','delete_connection'=>'network-connections'];
$requestedTab = $_GET['tab'] ?? 'segment';
$activePreset = $actionTabs[is_string($_POST['action'] ?? null) ? $_POST['action'] : ''] ?? (is_string($requestedTab) && isset($presetTabs[$requestedTab]) ? $requestedTab : 'segment');
$typeEditing = false; $typeValues = []; $connectionEditing = false; $connectionValues = [];
$rackEditing=false;$rackValues=[];
$siteEditing=false; $siteValues=[]; $nodeEditing=false; $nodeValues=[];
$error = ''; $editing = false; $segmentId = 0; $segmentName = '';
try {
    icct_nms_backend();
    $management = is_realm_allowed(3);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $segmentId = icct_nms_id($_POST['segment_id'] ?? 0);
        $segmentName = is_string($_POST['segment_name'] ?? '') ? $_POST['segment_name'] : '';
        $editing = $activePreset === 'segment' && ($_POST['action'] ?? '') !== 'delete_segment';
        $typeEditing = $activePreset === 'device-type' && ($_POST['action'] ?? '') !== 'delete_type';
        $rackEditing=$activePreset==='rack-config';$rackValues=$_POST;
        $nodeEditing=$activePreset==='node' && ($_POST['action'] ?? '')!=='delete_node'; $nodeValues=$_POST;
        $siteEditing=$activePreset==='site'; $siteValues=$_POST;
        $typeValues = $_POST;
        $connectionValues = $_POST;
        $connectionEditing = $activePreset === 'network-connections' && ($_POST['action'] ?? '') !== 'delete_connection';
        try {
            icct_nms_post();
            if (!in_array($_POST['action'] ?? '', array_keys($actionTabs),true)) throw new InvalidArgumentException('Choose a valid preset action.');
            if ($activePreset === 'rack-config') $message=icct_nms_save_rack_preset($_POST);
            elseif ($activePreset === 'node') $message=icct_nms_save_node($_POST);
            elseif ($activePreset === 'protocols') $message=icct_nms_save_protocol_preset($_POST);
            elseif ($activePreset === 'site') $message=icct_nms_save_site($_POST);
            elseif ($activePreset === 'device-type') $message = icct_nms_save_device_type($_POST, $_FILES['device_image'] ?? null);
            elseif ($activePreset === 'network-connections') $message = icct_nms_save_connection($_POST);
            else $message = icct_nms_save_segment($_POST);
            $_SESSION['icct_nms_notice'] = $message;
            icct_nms_redirect('presets.php?tab='.$activePreset);

        } catch (Throwable $failure) { $error = $failure->getMessage(); }
    }
    $rackProfiles=icct_nms_rack_presets();
    $nodes=icct_nms_nodes();
    $segments = db_fetch_assoc('SELECT id,name FROM plugin_icct_nms_categories ORDER BY sort_order,name,id');
    $deviceTypes = icct_nms_device_types();
    $connections = icct_nms_connections();
    $sites=db_fetch_assoc('SELECT s.*, (SELECT COUNT(*) FROM host h WHERE h.site_id=s.id AND h.deleted="") AS devices FROM sites s ORDER BY s.name,s.id');
} catch (Throwable $failure) { icct_nms_failure($failure); }
$title = 'Presets';
$notice = $_SESSION['icct_nms_notice'] ?? ''; unset($_SESSION['icct_nms_notice']);
require __DIR__.'/templates/header.php';
require __DIR__.'/templates/presets.php';
$presetsPage = true;
require __DIR__.'/templates/footer.php';
