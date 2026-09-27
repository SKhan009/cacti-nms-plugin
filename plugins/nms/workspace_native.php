<?php
/** CLI-only native scan process supervisor, dispatched by the assigned collector. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../../include/cli_check.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/workspace/scan_worker.php';
require_once __DIR__.'/includes/inventory.php';
try {
    nms_diag_worker_database();if(!nms_database_ready())exit(1);
    $id=0;foreach($argv as $arg)if(preg_match('/^--run=([1-9][0-9]*)$/D',$arg,$m))$id=(int)$m[1];
    if(!$id)throw new InvalidArgumentException('Explicit run ID required.');
    nms_scan_native_execute($id,nms_inventory_collector_id());
} catch(Throwable $e){cacti_log('NMS native scan worker: '.$e->getMessage(),false,'NMS');exit(1);}
