<?php

require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
require_once __DIR__ . '/../services/console_service.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    icct_nms_backend();
    icct_nms_post();
    $result = icct_nms_ssh_console_operation(icct_nms_id($_POST['host_id'] ?? 0), $_POST);
    echo json_encode(
        ['ok' => true, 'result' => $result],
        JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
    );
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(
        [
            'ok' => false,
            'error' =>
                $error instanceof RuntimeException || $error instanceof InvalidArgumentException
                    ? $error->getMessage()
                    : 'SSH console request failed. Check the service configuration.',
        ],
        JSON_INVALID_UTF8_SUBSTITUTE,
    );
}
