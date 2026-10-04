<?php
/** Parse the bounded, numeric MTR report produced by the diagnostic runner. */
function icct_nms_mtr_hops($output) {
    $hops=[];
    foreach(explode("\n",substr((string)$output,0,32768)) as $line) {
        if(!preg_match('/^\s*(\d+)\.\|--\s+(\S+)\s+([\d.]+)%\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s*$/',$line,$m))continue;
        $loss=(float)$m[3];if($loss<0||$loss>100||(int)$m[1]<1)continue;
        $hop=['hop'=>(int)$m[1],'address'=>$m[2],'loss_percent'=>$loss,'sent'=>(int)$m[4]];
        foreach(['last_ms','avg_ms','best_ms','worst_ms','stdev_ms'] as $i=>$key) {
            $v=$m[$i+5];$hop[$key]=$m[2]!=='???'&&$loss<100&&is_numeric($v)&&is_finite((float)$v)&&(float)$v>=0?(float)$v:null;
        }
        $hops[]=$hop;if(count($hops)>=30)break;
    }
    return $hops;
}
/** Read this account's saved reports only; opening a link never starts a probe. */
function icct_nms_link_mtr($id) {
    icct_backend_require_device_access($id);$reports=[];
    foreach(['mtr_icmp','mtr_tcp'] as $tool) {
        try {$assignment=icct_backend_diag_assignment($id,$tool);}catch(Throwable $e){continue;}
        $job=db_fetch_row_prepared("SELECT status,finished_at,result_json FROM plugin_icct_nms_diagnostic_jobs WHERE host_id=? AND user_id=? AND poller_id=? AND tool=? AND config_hash=? AND status IN ('complete','failed') ORDER BY id DESC LIMIT 1",[$id,icct_backend_current_user_id(),(int)$assignment['poller_id'],$tool,icct_backend_diag_signature($assignment)]);
        if(!$job)continue;
        $result=json_decode($job['result_json']??'',true);
        if(!is_array($result)||($result['target']??'')!==$assignment['hostname'])continue;
        $output=substr((string)($result['output']??''),0,32768);
        $reports[]=['method'=>$tool==='mtr_tcp'?'MTR TCP (443)':'MTR ICMP','status'=>$job['status'],'collected'=>$job['finished_at'],'collector'=>$result['execution_host']??'Collector','target'=>$result['target'],'hops'=>icct_nms_mtr_hops($output),'output'=>$output,'timed_out'=>!empty($result['timed_out']),'truncated'=>!empty($result['truncated'])];
    }
    return $reports;
}
