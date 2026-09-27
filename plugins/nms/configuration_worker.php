<?php
/** One isolated configuration request on this Cacti collector. */
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../../include/cli_check.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/diagnostics_queue.php';
require_once __DIR__.'/includes/configuration/runner.php';
require_once $config['base_path'].'/lib/auth.php';
try {
    nms_diag_worker_database();
    if(!nms_database_ready() || (int)db_fetch_cell_prepared('SELECT status FROM plugin_config WHERE directory=?',['nms'])!==1) exit(1);
    require_once __DIR__.'/includes/configuration/lifecycle.php';
    nms_config_reconcile();
    nms_config_worker_once(nms_inventory_collector_id());
} catch(Throwable $e) {
    cacti_log('NMS configuration worker failed ('.get_class($e).'). Check collector installation and plugin schema.',false,'NMS');
    exit(1);
}
