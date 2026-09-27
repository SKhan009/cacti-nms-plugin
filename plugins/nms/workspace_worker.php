<?php
/** Short CLI-only scan worker on the assigned collector's authoritative Cacti database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../include/cli_check.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/workspace/scan_worker.php';
require_once __DIR__.'/includes/inventory.php';
try {
    nms_diag_worker_database();if(!nms_database_ready())exit(1);
    nms_scan_poll(nms_inventory_collector_id());
} catch(Throwable $e){cacti_log('NMS workspace worker: '.$e->getMessage(),false,'NMS');exit(1);}
