<?php
/** Resolve file usage from Cacti associations, never from a matching device name/type. */
function icct_mib_repository_usage($bundles){
    if(!$bundles)return [];
    $usage=[];$flat=[];$owners=[];$exact=[];$indexed=[];$graphs=[];$templates=[];
    foreach($bundles as $bundle){
        $id=$bundle['id'];$usage[$id]=['data_sources'=>0,'devices'=>[]];
        try{$objects=icct_mib_objects($bundle);}catch(Throwable $e){$objects=[];}
        foreach($objects as $record){
            $owners[count($flat)]=$id;$flat[]=$record;
            if(empty($record['numeric'])||empty($record['base_oid']))continue;
            if(!empty($record['table']))$indexed[$record['base_oid']][$id]=true;
            else $exact[$record['base_oid'].'.0'][$id]=true;
        }
        foreach($bundle['rows'] as $row)if(!empty($row['graph_template_id']))$graphs[(int)$row['graph_template_id']][$id]=true;
        if(!empty($bundle['host_template_id']))$templates[(int)$bundle['host_template_id']][$id]=true;
    }
    $created=[];
    foreach(icct_mib_existing_source_indexes($flat) as $index=>$templateId)$created[$owners[$index]][$flat[$index]['base_oid']]=true;
    foreach($created as $id=>$oids)$usage[$id]['data_sources']=count($oids);
    $hosts=[];
    foreach(db_fetch_assoc("SELECT h.id,h.description,COALESCE(ht.id,0) AS host_template_id FROM host h LEFT JOIN host_template ht ON ht.id=h.host_template_id WHERE h.deleted='' ORDER BY h.description,h.id")?:[] as $host){
        $id=(int)$host['id'];if(!is_device_allowed($id,icct_backend_current_user_id()))continue;
        $hosts[$id]=$host;
        foreach($templates[(int)$host['host_template_id']]??[] as $bundleId=>$unused)$usage[$bundleId]['devices'][$id]=$host;
    }
    $associations=db_fetch_assoc('SELECT hg.host_id,hg.graph_template_id FROM host_graph hg JOIN graph_templates gt ON gt.id=hg.graph_template_id UNION SELECT gl.host_id,gl.graph_template_id FROM graph_local gl JOIN graph_templates gt ON gt.id=gl.graph_template_id WHERE gl.host_id>0')?:[];
    foreach($associations as $association){$id=(int)$association['host_id'];if(!isset($hosts[$id]))continue;foreach($graphs[(int)$association['graph_template_id']]??[] as $bundleId=>$unused)$usage[$bundleId]['devices'][$id]=$hosts[$id];}
    $sources=db_fetch_assoc("SELECT DISTINCT dl.host_id,did.value AS oid FROM data_local dl JOIN data_template dt ON dt.id=dl.data_template_id JOIN data_template_data dtd ON dtd.local_data_id=dl.id JOIN data_input_data did ON did.data_template_data_id=dtd.id JOIN data_input_fields f ON f.id=did.data_input_field_id WHERE dl.host_id>0 AND f.data_name='oid' AND did.value<>'' UNION SELECT pi.host_id,pi.arg1 AS oid FROM poller_item pi JOIN data_local dl ON dl.id=pi.local_data_id AND dl.host_id=pi.host_id JOIN data_template dt ON dt.id=dl.data_template_id WHERE pi.action=0 AND pi.host_id>0")?:[];
    foreach($sources as $source){
        $hostId=(int)$source['host_id'];if(!isset($hosts[$hostId]))continue;
        $oid=ltrim(trim((string)$source['oid']),'.');if(!preg_match('/^[0-9]+(?:\.[0-9]+)*$/D',$oid))continue;
        $matches=($exact[$oid]??[])+($indexed[$oid]??[]);$prefix=$oid;
        while(($dot=strrpos($prefix,'.'))!==false){$prefix=substr($prefix,0,$dot);$matches+=($indexed[$prefix]??[]);}
        foreach($matches as $bundleId=>$unused)$usage[$bundleId]['devices'][$hostId]=$hosts[$hostId];
    }
    foreach($usage as &$row){uasort($row['devices'],fn($a,$b)=>strnatcasecmp($a['description'],$b['description']));$row['devices']=array_values($row['devices']);}unset($row);
    return $usage;
}
