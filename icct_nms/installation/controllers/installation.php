<?php

require dirname(__DIR__, 2) . '/../../include/auth.php';
require_once dirname(__DIR__, 2) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__) . '/services/checks.php';
try {
    if (!is_realm_allowed(26)) {
        http_response_code(403);
        exit('Cacti Installation/Upgrades permission is required.');
    }
    icct_nms_backend();
    if (($_GET['guide'] ?? '') === 'ssh') {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile(dirname(__DIR__,2).'/protocols/ssh/README.md');
        exit();
    }
    $checks = icct_nms_installation_checks();
    $title = 'Installation Checks';
    $command =
        'sudo python3 ' .
        escapeshellarg(dirname(__DIR__) . '/cli/setup.py') .
        ' install --cacti-root ' .
        escapeshellarg($config['base_path']);
    require dirname(__DIR__, 2) . '/shared/templates/header.php';
    require dirname(__DIR__) . '/templates/installation.php';
    require dirname(__DIR__, 2) . '/shared/templates/footer.php';
} catch (Throwable $error) {
    icct_nms_failure($error);
}
