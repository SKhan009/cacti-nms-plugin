<?php
require_once __DIR__.'/../diagnostics_queue.php';
require_once __DIR__.'/audit.php';

/** Refresh reachability through the assigned profile, then combine it with saved device evidence. */
function nms_workspace_diagnose_device($host_id)
{
    // nms_diag_run enforces device, profile, tool, collector and current runner permissions.
    // Keep admission and audit atomic so a failed audit cannot leave an untracked request.
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin diagnosis request.');
    try {
        $id=nms_diag_run($host_id,'ping');
        nms_workspace_audit('device_diagnosis_requested',['job_id'=>$id,'refresh'=>'ping','other_evidence'=>'latest saved Cacti and discovery observations'],$host_id);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit diagnosis request.');
        return $id;
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
