<?php

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__) . '/services/checks.php';
require_once dirname(__DIR__) . '/services/package_jobs.php';

$isApi = ($_GET['view'] ?? '') === 'package-job';
try {
    if (!is_realm_allowed(26)) {
        http_response_code(403);
        exit('Cacti Installation/Upgrades permission is required.');
    }
    icct_nms_backend();
    if ($isApi) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            icct_nms_post();
            $id = icct_nms_package_submit(
                $config['base_path'],
                $_POST['action'] ?? '',
                $_POST['target'] ?? '',
                $_POST['preview'] ?? '',
            );
            http_response_code(202);
            echo json_encode(['id' => $id], JSON_THROW_ON_ERROR);
        } else {
            echo json_encode(icct_nms_package_status($config['base_path']), JSON_THROW_ON_ERROR);
        }
        exit();
    }
    if (($_GET['guide'] ?? '') === 'ssh') {
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        readfile(dirname(__DIR__, 2) . '/protocols/ssh/README.md');
        exit();
    }

    $installationPage = true;
    $title = 'Installation';
    $packagesTab = ($_GET['tab'] ?? '') === 'packages';
    if ($packagesTab) {
        $manifest = icct_nms_installation_manifest();
        $packages = [
            'base' => ['label' => 'Core and collector tools', 'packages' => $manifest['packages']],
        ];
        foreach ($manifest['rhel_package_groups'] ?? [] as $id => $group) {
            $packages[$id] = [
                'label' => $group['label'],
                'packages' => ['rhel' => $group['packages']],
                'detail' => $group['detail'],
                'version_profile' => 'base',
            ];
        }
        $packages += $manifest['optional_components'];
        $status = icct_nms_package_status($config['base_path']);
    } else {
        $checks = icct_nms_installation_checks();
    }
    require dirname(__DIR__, 2) . '/shared/templates/header.php';
    require dirname(__DIR__) . '/templates/installation.php';
    require dirname(__DIR__, 2) . '/shared/templates/footer.php';
} catch (Throwable $error) {
    if ($isApi) {
        http_response_code(400);
        echo json_encode(['error' => $error->getMessage()]);
        exit();
    }
    icct_nms_failure($error);
}
