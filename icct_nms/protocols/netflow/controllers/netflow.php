<?php

require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
try {
    icct_nms_backend();
    $id = icct_nms_id($_GET['id'] ?? 0);
    icct_backend_require_device_access($id);
    if (isset($_GET['export'])) {
        $filter = icct_nms_netflow_filter($id, $_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="netflow-device-' . $id . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        $columns = [
            'first_seen',
            'last_seen',
            'version',
            'src_addr',
            'src_port',
            'dst_addr',
            'dst_port',
            'protocol',
            'bytes',
            'packets',
            'traffic_scope',
            'application',
            'application_basis',
            'sampled',
        ];
        fputcsv($out, $columns);
        $offset = 0;
        do {
            $rows = db_fetch_assoc_prepared(
                'SELECT ' .
                    implode(',', $columns) .
                    ' FROM plugin_icct_nms_netflow_flows WHERE ' .
                    $filter['where'] .
                    ' ORDER BY last_seen DESC,id DESC LIMIT 1000 OFFSET ' .
                    $offset,
                $filter['args'],
            );
            foreach ($rows as $row) {
                fputcsv(
                    $out,
                    array_map(
                        static fn ($v) => preg_match('/^[=+@-]/', (string) $v) ? "'" . $v : $v,
                        array_values($row),
                    ),
                );
            }
            $offset += count($rows);
        } while (count($rows) === 1000 && !connection_aborted());
        fclose($out);
        exit();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(icct_nms_netflow_read($id, $_GET), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
