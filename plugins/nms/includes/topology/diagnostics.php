<?php
/** Sanitized diagnostic measurements for devices visible in topology. */
require_once __DIR__.'/../workspace/evidence.php';

function nms_topology_measurement($host,$job,$now)
{
    $empty=['state'=>'Not measured','packet_loss'=>null,'latency_ms'=>null,'method'=>null,'target'=>null,'collected_at'=>null,'collector_id'=>null];
    if(!$job)return $empty;
    // Parse historical evidence without presenting it as a current measurement.
    $evidence=nms_workspace_probe_evidence($host,$job,$now,PHP_INT_MAX);
    if(!$evidence)return array_replace($empty,['state'=>'No usable measurement']);
    $fresh=$now-strtotime($evidence['collected_at'])<=900;
    return array_replace($empty,$evidence,['state'=>$fresh?'Current':'Stale','collector_id'=>(int)$job['poller_id'],
        'packet_loss'=>$fresh?$evidence['packet_loss']:null,'latency_ms'=>$fresh?$evidence['latency_ms']:null]);
}

/** Latest terminal ping per visible host; no raw output or credentials leave this adapter. */
function nms_topology_measurements($hosts)
{
    if(!$hosts)return [];
    $ids=array_values(array_unique(array_map('intval',array_column($hosts,'id'))));
    $idSql=implode(',',$ids);$visible=nms_visible_host_sql('h.id');
    $jobs=db_fetch_assoc("SELECT j.id,j.host_id,j.poller_id,j.tool,j.status,j.finished_at,j.result_json
        FROM plugin_nms_diagnostic_jobs j JOIN host h ON h.id=j.host_id
        JOIN (SELECT host_id,MAX(id) AS id FROM plugin_nms_diagnostic_jobs
            WHERE host_id IN ($idSql) AND tool='ping' AND status IN ('complete','failed') GROUP BY host_id) latest ON latest.id=j.id
        WHERE h.deleted = '' AND $visible");
    $byHost=[];foreach($jobs as $job)$byHost[(int)$job['host_id']]=$job;
    $result=[];$now=time();
    foreach($hosts as $host)$result[(int)$host['id']]=nms_topology_measurement($host,$byHost[(int)$host['id']]??null,$now);
    return $result;
}
