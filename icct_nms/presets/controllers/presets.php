<?php

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 2) . '/presets/services/segment_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/device_type_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/connection_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/site_service.php';
require_once dirname(__DIR__, 2) . '/dashboard/widgets/services/lnms_service.php';
require_once dirname(__DIR__, 2) . '/presets/services/rack_preset_service.php';
require_once $config['base_path'] . '/include/global_form.php';
require_once dirname(__DIR__, 2) . '/shared/services/forms.php';
require_once dirname(__DIR__, 2) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 2) . '/inventory/services/device_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_service.php';
require_once dirname(__DIR__, 2) . '/protocols/serial/services/serial_service.php';
require_once dirname(__DIR__, 2) . '/protocols/shared/services/protocol_preset_service.php';
$presetTabs = [
    'rack-config' => 'Rack Config',
    'protocols' => 'Protocols',
    'site' => 'Site',
    'segment' => 'Segment',
    'device-type' => 'Device Type',
    'network-connections' => 'Network Connections',
];
$actionTabs = [
    'save_rack_profile' => 'rack-config',
    'delete_rack_profile' => 'rack-config',
    'save_protocol_defaults' => 'protocols',
    'save_gnss' => 'site',
    'save_site' => 'site',
    'save_lnms_sites' => 'site',
    'save_segment' => 'segment',
    'delete_segment' => 'segment',
    'save_type' => 'device-type',
    'delete_type' => 'device-type',
    'save_connection' => 'network-connections',
    'delete_connection' => 'network-connections',
];
$requestedTab = $_GET['tab'] ?? 'segment';
$activePreset =
    $actionTabs[is_string($_POST['action'] ?? null) ? $_POST['action'] : ''] ??
    (is_string($requestedTab) && isset($presetTabs[$requestedTab]) ? $requestedTab : 'segment');
$typeEditing = false;
$typeValues = [];
$connectionEditing = false;
$connectionValues = [];
$rackEditing = false;
$rackValues = [];
$siteEditing = false;
$siteValues = [];
$error = '';
$editing = false;
$segmentId = 0;
$segmentName = '';
try {
    icct_nms_backend();
    $management = is_realm_allowed(3);
    $canManageSyslogPort = $activePreset === 'protocols' && $management && is_realm_allowed(26);
    $syslogPortStatus = ['available' => false];
    if ($canManageSyslogPort) {
        require_once dirname(__DIR__, 2) . '/installation/services/package_jobs.php';
        $syslogPortStatus = icct_nms_package_status($config['base_path']);

    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $segmentName = is_string($_POST['segment_name'] ?? null) ? $_POST['segment_name'] : '';
        $editing = $activePreset === 'segment' && ($_POST['action'] ?? '') !== 'delete_segment';
        $typeEditing =
            $activePreset === 'device-type' && ($_POST['action'] ?? '') !== 'delete_type';
        $rackEditing =
            $activePreset === 'rack-config' && ($_POST['action'] ?? '') !== 'delete_rack_profile';
        $rackValues = $_POST;
        $siteEditing = $activePreset === 'site' && ($_POST['action'] ?? '') === 'save_site';
        $siteValues = $_POST;
        $typeValues = $_POST;
        $connectionValues = $_POST;
        $connectionEditing =
            $activePreset === 'network-connections' &&
            ($_POST['action'] ?? '') !== 'delete_connection';
        try {
            icct_nms_post();
            if (!in_array($_POST['action'] ?? '', array_keys($actionTabs), true)) {
                throw new InvalidArgumentException('Choose a valid preset action.');
            }
            if ($activePreset === 'segment') {
                $segmentId = icct_nms_segment_id($_POST['segment_id'] ?? 0);
            }
            $redirect = 'presets/controllers/presets.php?tab=' . $activePreset;
            if ($activePreset === 'rack-config') {
                $message = icct_nms_save_rack_preset($_POST);
            } elseif ($activePreset === 'protocols') {
                $port = null;
                $savingSyslog = ($_POST['preset_protocol'] ?? '') === 'syslog';
                if ($savingSyslog) {
                    $redirect .= '&protocol=syslog';
                    if (array_key_exists('receiver_port', $_POST)) {
                        if (!$canManageSyslogPort || !isset($syslogPortStatus['syslog_port'])) {
                            throw new RuntimeException('The offline runner is unavailable for Syslog port changes.');
                        }
                        $requestedPort = $_POST['receiver_port'];
                        if (!icct_nms_valid_syslog_port($requestedPort)) {
                            throw new InvalidArgumentException('Enter a receiver port from 1 to 65535.');
                        }
                        if ((int) $requestedPort !== $syslogPortStatus['syslog_port']) {
                            if (!$syslogPortStatus['available'] || ($syslogPortStatus['job']['status'] ?? '') === 'running') {
                                throw new RuntimeException('The offline runner is busy or unavailable. Try saving again shortly.');
                            }
                            $port = $requestedPort;
                        }
                    }
                }
                $message = icct_nms_save_protocol_preset($_POST);
                if ($port !== null) {
                    try {
                        $job = icct_nms_package_submit($config['base_path'], 'syslog-port', $port);
                        $redirect .= '&port_job=' . $job;
                        $message .= ' Receiver port change queued.';
                    } catch (Throwable $failure) {
                        throw new RuntimeException('Syslog presets saved. The port change could not be queued. ' . $failure->getMessage());
                    }
                }
            } elseif (($_POST['action'] ?? '') === 'save_gnss') {
                icct_nms_gnss_save($_POST);
                $message = 'GNSS source saved.';
            } elseif ($activePreset === 'site') {
                $message =
                    ($_POST['action'] ?? '') === 'save_lnms_sites'
                        ? icct_nms_lnms_save_sites($_POST)
                        : icct_nms_save_site($_POST);
            } elseif ($activePreset === 'device-type') {
                $message = icct_nms_save_device_type($_POST, $_FILES['device_image'] ?? null);
            } elseif ($activePreset === 'network-connections') {
                $message = icct_nms_save_connection($_POST);
            } else {
                $message = icct_nms_save_segment($_POST);
            }
            $_SESSION['icct_nms_notice'] = $message;
            icct_nms_redirect($redirect);
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }
    }
    $rackProfiles = icct_nms_rack_presets();
    $segments = db_fetch_assoc(
        'SELECT id,name FROM plugin_icct_nms_categories ORDER BY sort_order,name,id',
    );
    $deviceTypes = icct_nms_device_types();
    $connections = icct_nms_connections();
    $sites = db_fetch_assoc(
        'SELECT s.*, (SELECT COUNT(*) FROM host h WHERE h.site_id=s.id AND h.deleted="") AS devices FROM sites s ORDER BY s.name,s.id',
    );
} catch (Throwable $failure) {
    icct_nms_failure($failure);
}
$title = 'Presets';
$notice = $_SESSION['icct_nms_notice'] ?? '';
unset($_SESSION['icct_nms_notice']);
require dirname(__DIR__, 2) . '/shared/templates/header.php';
require dirname(__DIR__, 2) . '/presets/templates/presets.php';
$presetsPage = true;
require dirname(__DIR__, 2) . '/shared/templates/footer.php';
