<?php
/** Collector-only reviewed Cacti device creation. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../include/cli_check.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/diagnostics_queue.php';
require_once __DIR__.'/includes/inventory.php';
require_once __DIR__.'/includes/workspace/onboarding_worker.php';
try {
    nms_diag_worker_database();if(!nms_database_ready())exit(1);
    nms_workspace_onboarding_poll(nms_inventory_collector_id());
}catch(Throwable $e){cacti_log('NMS onboarding worker: '.$e->getMessage(),false,'NMS');exit(1);}
