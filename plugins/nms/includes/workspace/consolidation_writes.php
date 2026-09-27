<?php
/** Observe native pending writers before transfer and before accepting file evidence.
 * This is not an RRD lock: disabled hosts and unchanged pre/post file proofs are also required.
 */
function nms_workspace_consolidation_pending_writes($dataIds,$collector)
{
    if(nms_workspace_admission_rows("SELECT id FROM poller_time WHERE poller_id=? AND end_time='0000-00-00 00:00:00' AND start_time>DATE_SUB(NOW(),INTERVAL 15 MINUTE) LIMIT 1",[$collector]))throw new RuntimeException('Native polling is active. Retry the reviewed transfer after it finishes.');
    if(nms_workspace_admission_rows("SELECT id FROM processes WHERE tasktype='boost' AND TIMESTAMPDIFF(SECOND,started,NOW())<=COALESCE(timeout,300) LIMIT 1"))throw new RuntimeException('A native Boost writer is active. Wait for its RRD updates to finish.');
    $ids=array_values(array_unique(array_map('intval',$dataIds)));
    if(!$ids)return;
    if(count($ids)>4000 || min($ids)<1)throw new RuntimeException('Invalid pending-write inventory.');
    $tables=nms_workspace_admission_rows("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'poller_output%' ORDER BY TABLE_NAME LIMIT 129");
    if(count($tables)>128)throw new RuntimeException('Too many native output tables to verify pending writes.');
    foreach($tables as $table) {
        $name=$table['name'];
        if(!preg_match('/^poller_output(?:_realtime|_boost(?:_local_data_ids|_arch_[a-zA-Z0-9_]+)?)?$/D',$name))continue;
        if(nms_workspace_admission_rows('SELECT local_data_id FROM `'.$name.'` WHERE local_data_id IN ('.implode(',',$ids).') LIMIT 1'))throw new RuntimeException('Pending native RRD updates must drain before transfer or recovery verification.');
    }
}
