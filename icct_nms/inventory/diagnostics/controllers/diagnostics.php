<?php

/** Authenticated diagnostics: immediate Ping/Trace Route and saved-profile tools. */
require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
require_once dirname(__DIR__, 3) . '/inventory/services/inventory.php';
require_once dirname(__DIR__, 3) . '/shared/services/forms.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'instant') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        icct_nms_backend();
        icct_nms_post();
        $id = icct_nms_id($_POST['host_id'] ?? 0);
        $tool = (string) ($_POST['tool'] ?? '');
        $result = icct_backend_diag_run_instant($id, $tool);
        echo json_encode(
            ['ok' => true, 'result' => $result],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode(
            ['ok' => false, 'error' => $exception->getMessage()],
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    exit();
}
if (($_GET['action'] ?? '') === 'options') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        icct_nms_backend();
        icct_backend_require_management(3);
        $id = icct_nms_id($_GET['host_id'] ?? 0);
        icct_backend_require_device_access($id);
        $host = icct_nms_device($id);
        $labels = icct_backend_diag_selected_labels($id);
        $tool = (string) ($_GET['tool'] ?? (array_key_first($labels) ?? ''));
        if ($tool !== '' && !isset($labels[$tool])) {
            throw new InvalidArgumentException('This diagnostic is not selected for the device.');
        }
        echo json_encode(
            ['ok' => true, 'name' => $host['description'], 'tools' => $labels, 'tool' => $tool],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode(
            ['ok' => false, 'error' => $exception->getMessage()],
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
    exit();
}
if (($_GET['action'] ?? '') === 'status' || ($_POST['action'] ?? '') === 'queue') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        icct_nms_backend();
        icct_backend_require_management(3);
        $id = icct_nms_id($_GET['host_id'] ?? ($_POST['host_id'] ?? 0));
        icct_backend_require_device_access($id);
        if (($_POST['action'] ?? '') === 'queue') {
            icct_nms_post();
            $jobId = icct_backend_diag_run($id, (string) ($_POST['tool'] ?? ''));
        } else {
            $jobId = icct_nms_id($_GET['job_id'] ?? 0);
        }
        $job = icct_backend_diag_job($jobId);
        if ((int) $job['host_id'] !== $id) {
            throw new RuntimeException('The result belongs to another device.');
        }
        $result = $job['result_json'] !== '' ? json_decode($job['result_json'], true) : null;
        $summary = '';
        if (isset($result['output'])) {
            require_once __DIR__ . '/../services/diagnostic_reading.php';
            $run = $job;
            $labels = icct_backend_diag_labels();
            $display = static fn($value) => icct_nms_h(trim((string) $value) !== '' ? $value : '—');
            ob_start();
            try {
                require __DIR__ . '/../../templates/device_diagnostic_summary.php';
                $summary = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        }
        echo json_encode(
            [
                'ok' => true,
                'job_id' => (int) $job['id'],
                'status' => $job['status'],
                'tool' => $job['tool'],
                'output' => $result['output'] ?? '',
                'summary_html' => $summary,
            ],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        echo json_encode(
            ['ok' => false, 'error' => $exception->getMessage()],
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
    exit();
}
try {
    icct_nms_backend();
    icct_backend_require_management(3);
    $id = icct_nms_id($_GET['host_id'] ?? 0);
    icct_nms_device($id);
    $labels = icct_backend_diag_selected_labels($id);
    $tool = (string) ($_POST['tool'] ?? ($_GET['tool'] ?? (array_key_first($labels) ?? '')));
    if ($tool !== '' && !isset($labels[$tool])) {
        throw new InvalidArgumentException(
            'This diagnostic is not selected for the device. Select it in Device Diagnostics first.',
        );
    }
    $jobId = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        icct_nms_post();
        $jobId = icct_backend_diag_run($id, $tool);
    } elseif (isset($_GET['job_id'])) {
        $jobId = icct_nms_id($_GET['job_id']);
        $job = icct_backend_diag_job($jobId);
        if ((int) $job['host_id'] !== $id) {
            throw new RuntimeException('The result belongs to another device.');
        }
    }
    $params = ['id' => $id, 'view' => 1, 'open_diagnostic' => 1, 'tool' => $tool];
    if ($jobId) {
        $params['job_id'] = $jobId;
    }
    icct_nms_redirect('inventory/controllers/device.php?' . http_build_query($params));
} catch (Throwable $exception) {
    icct_nms_failure($exception);
}
