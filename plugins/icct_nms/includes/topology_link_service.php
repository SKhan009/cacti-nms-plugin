<?php
/** Match only an explicit interface index or a unique exact port name. */
function icct_nms_link_port($ports, $label, $index=0) {
    if($index>0)return $ports[$index]??null;
    $matches=[];
    foreach($ports as $key=>$port) {
        if($label!=='' && (in_array($label,[$port['name']??'', $port['description']??''],true) || $label==='ifIndex '.$key))$matches[$key]=$port;
    }
    return count($matches)===1?reset($matches):null;
}
function icct_nms_link_reading($id,$label,$index=0) {
    $empty=['port'=>$label?:'Not specified','available'=>false];
    $hosts=array_column(icct_backend_nd_hosts(),null,'id');$host=$hosts[$id]??null;
    if(!$host || empty($host['enabled']) || empty($host['collection_enabled']))return $empty;
    $snapshot=db_fetch_row_prepared("SELECT status,succeeded_at,config_hash,data_json FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol='identity'",[$id]);
    $age=time()-strtotime($snapshot['succeeded_at']??'');
    if(!$snapshot || $snapshot['status']!=='success' || $age<0 || $age>(int)$host['stale_seconds'] || !hash_equals(icct_backend_nd_hash($host),(string)$snapshot['config_hash']))return $empty;
    $payload=json_decode($snapshot['data_json'],true)??[];
    $port=icct_nms_link_port($payload['interfaces']??[],$label,$index);
    if(!$port)return $empty;
    $speed=(float)($port['high_speed_mbps']??0)*1000000;
    if($speed<=0)$speed=(float)($port['speed_bps']??0);
    $result=['port'=>($port['name']??'')?:$label,'available'=>true,'capacity_bps'=>$speed>0?$speed:null,'status'=>(int)($port['admin']??0)===2?'Disabled':((int)($port['oper']??0)===1?'Up':((int)($port['oper']??0)===2?'Down':'Unknown')),'collected'=>$snapshot['succeeded_at'],'sample_seconds'=>$port['sample_seconds']??null];
    foreach(['in','out'] as $direction){$value=$port[$direction.'_bps']??null;$result[$direction.'_bps']=is_numeric($value)&&is_finite((float)$value)&&$value>=0?(float)$value:null;}
    return $result;
}
