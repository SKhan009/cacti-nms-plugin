<?php
require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/presets/services/segment_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/device_type_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/connection_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/site_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/rack_preset_service.php';
require_once $config['base_path'].'/include/global_form.php';
require_once dirname(__DIR__, 2) . '/shared/services/forms.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 2) . '/inventory/services/device_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_service.php';
require_once dirname(__DIR__, 2) . '/protocols/serial/services/serial_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_preset_service.php';
$presetTabs = ['rack-config'=>'Rack Config','protocols'=>'Protocols','site'=>'Site','segment'=>'Segment','device-type'=>'Device Type','network-connections'=>'Network Connections'];
$actionTabs = ['save_rack_profile'=>'rack-config','delete_rack_profile'=>'rack-config','save_protocol_defaults'=>'protocols','save_site'=>'site','save_segment'=>'segment','delete_segment'=>'segment','save_type'=>'device-type','delete_type'=>'device-type','save_connection'=>'network-connections','delete_connection'=>'network-connections'];
$requestedTab = $_GET['tab'] ?? 'segment';
$activePreset = $actionTabs[is_string($_POST['action'] ?? null) ? $_POST['action'] : ''] ?? (is_string($requestedTab) && isset($presetTabs[$requestedTab]) ? $requestedTab : 'segment');
$typeEditing = false; $typeValues = []; $connectionEditing = false; $connectionValues = [];
$rackEditing=false;$rackValues=[];
$siteEditing=false; $siteValues=[];
$error = ''; $editing = false; $segmentId = 0; $segmentName = '';
try {
    icct_nms_backend();
    $management = is_realm_allowed(3);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $segmentName = is_string($_POST['segment_name'] ?? '') ? $_POST['segment_name'] : '';
        $editing = $activePreset === 'segment' && ($_POST['action'] ?? '') !== 'delete_segment';
        $typeEditing = $activePreset === 'device-type' && ($_POST['action'] ?? '') !== 'delete_type';
        $rackEditing=$activePreset==='rack-config' && ($_POST['action'] ?? '')!=='delete_rack_profile';$rackValues=$_POST;
        $siteEditing=$activePreset==='site'; $siteValues=$_POST;
        $typeValues = $_POST;
        $connectionValues = $_POST;
        $connectionEditing = $activePreset === 'network-connections' && ($_POST['action'] ?? '') !== 'delete_connection';
        try {
            icct_nms_post();
            if (!in_array($_POST['action'] ?? '', array_keys($actionTabs),true)) throw new InvalidArgumentException('Choose a valid preset action.');
            if ($activePreset === 'segment') $segmentId = icct_nms_segment_id($_POST['segment_id'] ?? 0);
            if ($activePreset === 'rack-config') $message=icct_nms_save_rack_preset($_POST);
            elseif ($activePreset === 'protocols') $message=icct_nms_save_protocol_preset($_POST);
            elseif ($activePreset === 'site') $message=icct_nms_save_site($_POST);
            elseif ($activePreset === 'device-type') $message = icct_nms_save_device_type($_POST, $_FILES['device_image'] ?? null);
            elseif ($activePreset === 'network-connections') $message = icct_nms_save_connection($_POST);
            else $message = icct_nms_save_segment($_POST);
            $_SESSION['icct_nms_notice'] = $message;
            icct_nms_redirect('presets/controllers/presets.php?tab='.$activePreset);

        } catch (Throwable $failure) { $error = $failure->getMessage(); }
    }
    $rackProfiles=icct_nms_rack_presets();
    $segments = db_fetch_assoc('SELECT id,name FROM plugin_icct_nms_categories ORDER BY sort_order,name,id');
    $deviceTypes = icct_nms_device_types();
    $connections = icct_nms_connections();
    $sites=db_fetch_assoc('SELECT s.*, (SELECT COUNT(*) FROM host h WHERE h.site_id=s.id AND h.deleted="") AS devices FROM sites s ORDER BY s.name,s.id');
} catch (Throwable $failure) { icct_nms_failure($failure); }
$title = 'Presets';
$notice = $_SESSION['icct_nms_notice'] ?? ''; unset($_SESSION['icct_nms_notice']);
require dirname(__DIR__, 2) . '/shared/templates/header.php';
require dirname(__DIR__, 2) . '/presets/templates/presets.php';
$presetsPage = true;
require dirname(__DIR__, 2) . '/shared/templates/footer.php';
