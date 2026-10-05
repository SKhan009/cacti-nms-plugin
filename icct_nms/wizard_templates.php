<?php
/** Read-only template associations for an unsaved device wizard. */
require __DIR__ . '/../../include/auth.php';
require_once $config['base_path'] . '/include/global_form.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/graph_service.php';
require_once __DIR__ . '/includes/data_query_service.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    icct_nms_backend();
    icct_backend_require_management(3);
    $templateId = icct_nms_id($_GET['template_id'] ?? 0);
    if ($templateId && !db_fetch_cell_prepared('SELECT id FROM host_template WHERE id=?', [$templateId])) throw new InvalidArgumentException('Device template not found.');
    $id = 0;
    $host = icct_nms_defaults();
    $graphAssociations = db_fetch_assoc_prepared('SELECT gt.id,gt.name,ht.name AS device_template_name FROM host_template_graph htg JOIN graph_templates gt ON gt.id=htg.graph_template_id JOIN host_template ht ON ht.id=htg.host_template_id WHERE htg.host_template_id=? ORDER BY gt.name', [$templateId]);
    foreach ($graphAssociations as &$template) {
        $template['graphs'] = [];
        $template['details'] = [];
        $template['parameters'] = icct_nms_graph_parameters((int)$template['id'], 0);
    }
    unset($template);
    $availableGraphTemplates = [];
    $dataQueryAssociations = db_fetch_assoc_prepared('SELECT sq.id,sq.name,0 AS reindex_method,0 AS item_count,0 AS row_count FROM host_template_snmp_query htq JOIN snmp_query sq ON sq.id=htq.snmp_query_id WHERE htq.host_template_id=? ORDER BY sq.name', [$templateId]);
    $availableDataQueries = [];
    $dataQueryMethods = icct_nms_data_query_methods($host);
    $defaultDataQueryMethod = 0;
    ob_start();require __DIR__.'/templates/graphs.php';$graphs = ob_get_clean();
    ob_start();require __DIR__.'/templates/data_queries.php';$queries = ob_get_clean();
    echo json_encode(['ok'=>true,'graphs'=>$graphs,'queries'=>$queries], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
