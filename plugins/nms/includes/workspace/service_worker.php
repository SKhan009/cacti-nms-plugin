<?php
require_once __DIR__.'/services.php';
/** One short service check; abandoned running jobs fail without repeating requests. */
function nms_workspace_service_poll($collector)
{
    if(PHP_SAPI!=='cli')return;
    $lock='nms_service_worker_'.(int)$collector;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,0)',[$lock])!==1)return;
    $session=$_SESSION??[];
    try {
        $job=db_fetch_row_prepared("SELECT * FROM plugin_nms_service_jobs WHERE poller_id=? AND status IN ('queued','running') ORDER BY id LIMIT 1",[$collector]);
        if(!$job)return;
        try {
            if($job['status']==='running')throw new RuntimeException('Previous service worker stopped. The request was not repeated.');
            if(time()-strtotime($job['requested_at'])>120)throw new RuntimeException('Service request expired before execution.');
            $_SESSION=['sess_user_id'=>(int)$job['user_id']];
            $host=nms_workspace_service_context((int)$job['host_id']);$spec=nms_workspace_service_spec(json_decode($job['spec_json'],true,512,JSON_THROW_ON_ERROR));
            if((int)$host['poller_id']!==(int)$collector||!hash_equals($job['config_hash'],nms_workspace_service_revision($host,$spec)))throw new RuntimeException('Device target or collector settings changed. Submit a new service check.');
            // Serialize the start transition with cancellation. A cancelled queued request never starts.
            if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin service check.');
            try {
                $current=db_fetch_cell_prepared('SELECT status FROM plugin_nms_service_jobs WHERE id=? FOR UPDATE',[$job['id']]);
                if($current!=='queued'){db_execute('ROLLBACK');return;}
                nms_category_execute("UPDATE plugin_nms_service_jobs SET status='running',started_at=NOW() WHERE id=?",[$job['id']]);
                if(!db_execute('COMMIT'))throw new RuntimeException('Could not start service check.');
            }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
            $result=nms_workspace_service_probe($host,$spec);$result['collector_id']=(int)$collector;
            $current=nms_workspace_service_context((int)$job['host_id']);
            if(!hash_equals($job['config_hash'],nms_workspace_service_revision($current,$spec)))throw new RuntimeException('Device settings changed during the check; result discarded.');
            nms_workspace_service_finish($job,'complete',$result);
        }catch(Throwable $e){nms_workspace_service_finish($job,'failed',['passed'=>false,'category'=>'execution_rejected','message'=>$e->getMessage()]);}
    }finally{$_SESSION=$session;db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
