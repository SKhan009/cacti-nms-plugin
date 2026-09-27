<?php
/** Structural eligibility only. Passing does not authorize a transfer or prove RRD/ACL preservation. */
function nms_workspace_consolidation_preflight($keep,$source,$graphs,$data,$links)
{
    $issues=[];$add=static function($code,$message)use(&$issues){$issues[$code]=$message;};
    $sourceId=(int)$source['id'];$keepId=(int)$keep['id'];
    if($sourceId<1||$keepId<1||$sourceId===$keepId)$add('invalid_pair','Choose two distinct existing devices.');
    if((int)$keep['poller_id']!==(int)$source['poller_id'])$add('different_collectors','Moving assets across collectors requires RRD synchronization and is not yet supported.');
    if((int)$source['poller_id']!==1)$add('remote_collector','Remote-collector transfer and historical storage have not been verified.');
    if(($keep['disabled']??'')!=='on'||($source['disabled']??'')!=='on')$add('polling_enabled','Disable polling on both records during the reviewed transfer to prevent concurrent writes.');
    $graphIds=[];$dataIds=[];
    foreach($graphs as $graph) {
        $graphIds[]=(int)$graph['id'];
        if((int)$graph['snmp_query_id']!==0)$add('query_graph','Cacti does not support Change Device for data-query graphs. Interface/index mapping needs a separate migration method.');
    }
    foreach($data as $row) {
        $dataIds[]=(int)$row['id'];
        if((int)($row['snmp_query_id']??0)!==0)$add('query_data_source','Data-query sources require verified index mapping before transfer.');
        if(trim((string)($row['data_source_path']??''))==='')$add('missing_rrd_path','A data source has no recorded RRD path; resolve its storage before transfer.');
    }
    $graphIds=array_values(array_unique($graphIds));$dataIds=array_values(array_unique($dataIds));sort($graphIds);sort($dataIds);
    foreach($links as $link) {
        if((int)$link['graph_host_id']===$sourceId && (int)$link['data_host_id']!==$sourceId)$add('foreign_data_source','A source graph uses data owned by another device. Native graph transfer would also move that data source.');
        if((int)$link['data_host_id']===$sourceId && (int)$link['graph_host_id']!==$sourceId)$add('shared_data_source','A source data source is referenced by a graph outside the source record. Review those dependencies before transfer.');
        if((int)$link['graph_host_id']===$sourceId&&!in_array((int)$link['graph_id'],$graphIds,true))$add('changed_graph_inventory','Graph dependencies differ from the reviewed inventory. Rebuild the plan.');
        if((int)$link['data_host_id']===$sourceId&&!in_array((int)$link['data_id'],$dataIds,true))$add('changed_data_inventory','Data-source dependencies differ from the reviewed inventory. Rebuild the plan.');
    }
    if(!$graphIds&&!$dataIds)$add('no_assets','The source record has no graphs or data sources to transfer.');
    return ['status'=>$issues?'blocked':'structurally_eligible','blockers'=>$issues,'graph_ids'=>$graphIds,'data_ids'=>$dataIds,
        'remaining_checks'=>['Verify effective permissions for all affected users and groups.','Verify populated RRD history and storage on the assigned collector.','Resolve device references and preserve both records until retirement is separately reviewed.']];
}
