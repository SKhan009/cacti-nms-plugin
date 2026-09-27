<?php
/** Scheduled reads and evidence freshness, shared by device views and native graph inputs. */
require_once __DIR__.'/jobs.php';

function nms_config_store_reading(array $target, $key, array $result)
{
    $status=($result['status'] ?? '')==='read'?'complete':'failed';
    nms_category_execute('INSERT INTO plugin_nms_serial_readings(host_id,field_key,signature,value_json,status,error_text,observed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE signature=VALUES(signature),value_json=VALUES(value_json),status=VALUES(status),error_text=VALUES(error_text),observed_at=NOW()',[$target['host']['id'],$key,$target['signature'],json_encode($result['value'] ?? null,JSON_THROW_ON_ERROR),$status,substr($result['error'] ?? '',0,512)]);
}

/** Values from an obsolete connection or equipment map are never presented as current. */
function nms_config_reading($host_id,$key,$authorize=true)
{
    if($authorize) nms_require_device_access($host_id);
    try { $target=nms_config_target($host_id,false); }
    catch(Throwable $e) { return ['status'=>'unavailable','value'=>null,'observed_at'=>null]; }
    if(!isset($target['fields'][$key])) return ['status'=>'unavailable','value'=>null,'observed_at'=>null];
    $row=db_fetch_row_prepared('SELECT *,TIMESTAMPDIFF(SECOND,observed_at,NOW()) AS age_seconds FROM plugin_nms_serial_readings WHERE host_id=? AND field_key=?',[$host_id,$key]);
    if(!$row || !hash_equals($target['signature'],$row['signature'])) return ['status'=>'unavailable','value'=>null,'observed_at'=>null];
    $status=$row['status']==='complete'?'current':'failed';
    if((int)$row['age_seconds']<0 || (int)$row['age_seconds']>max(120,2*(int)$target['assignment']['interval_seconds'])) $status='stale';
    return ['status'=>$status,'value'=>$status==='current'?json_decode($row['value_json'],true):null,'observed_at'=>$row['observed_at']];
}

/** Called inside the collector worker lock. One due field per invocation bounds work. */
function nms_config_monitor_once($collector)
{
    if(PHP_SAPI!=='cli') throw new RuntimeException('Scheduled collection is CLI-only.');
    $devices=db_fetch_assoc_prepared("SELECT a.host_id,a.updated_by FROM plugin_nms_config_devices a JOIN host h ON h.id=a.host_id JOIN poller p ON p.id=h.poller_id LEFT JOIN plugin_nms_serial_readings r ON r.host_id=a.host_id WHERE h.poller_id=? AND h.deleted='' AND h.disabled='' AND p.disabled='' GROUP BY a.host_id,a.updated_by ORDER BY MIN(r.observed_at),a.host_id",[$collector]);
    $previous=$_SESSION ?? [];
    try {
        foreach($devices as $device) {
            try {
                // Monitoring retains the assigning operator's device scope; revoked access stops reads.
                nms_config_job_authorize(['host_id'=>$device['host_id'],'user_id'=>$device['updated_by']]);
                $_SESSION=['sess_user_id'=>(int)$device['updated_by']];
                $done=nms_serial_mutation(function() use($device,$collector) {
                    $target=nms_config_target($device['host_id']);
                    if((int)$target['host']['poller_id']!==(int)$collector) return false;
                    foreach($target['fields'] as $key=>$field) {
                        $row=db_fetch_row_prepared('SELECT signature,TIMESTAMPDIFF(SECOND,observed_at,NOW()) AS age_seconds FROM plugin_nms_serial_readings WHERE host_id=? AND field_key=?',[$device['host_id'],$key]);
                        if($row && hash_equals($target['signature'],$row['signature']) && (int)$row['age_seconds']>=0 && (int)$row['age_seconds']<(int)$target['assignment']['interval_seconds']) continue;
                        try { $result=$target['profile']['protocol']==='modbus_rtu'?nms_config_serial_execute($target,$field,'read'):nms_config_snmp_execute($target,$field,'read'); }
                        catch(Throwable $e) { $result=['status'=>'failed','error'=>'Collector read failed. Check connection settings and collector logs.']; }
                        nms_config_store_reading($target,$key,$result);
                        return true;
                    }
                    return false;
                });
                if($done) return true;
            } catch(Throwable $e) { /* Ineligible assignments remain unavailable; never fall back to another collector. */ }
        }
    } finally { $_SESSION=$previous; }
    return false;
}

/** Native Script/Command data input: unknown samples become RRD gaps, never zero. */
function nms_config_graph_value($host_id,$key,$collector,$model=null,$revision=null)
{
    if(PHP_SAPI!=='cli') throw new RuntimeException('Graph input is CLI-only.');
    try {
        $target=nms_config_target($host_id,false);
        if((int)$target['host']['poller_id']!==(int)$collector || !isset($target['fields'][$key]) || $target['fields'][$key]['type']==='string') return 'U';
        if($model!==null && ((int)$target['profile']['id']!==$model || (int)$target['profile']['revision']!==$revision)) return 'U';
        $sample=nms_config_reading($host_id,$key,false);
        return $sample['status']==='current' && is_numeric($sample['value']) ? (string)$sample['value'] : 'U';
    } catch(Throwable $e) { return 'U'; }
}

function nms_config_connection_status($host_id)
{
    nms_require_device_access($host_id);
    $serial=db_fetch_row_prepared('SELECT c.transport,c.endpoint,d.device_address FROM plugin_nms_serial_devices d LEFT JOIN plugin_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?',[$host_id]);
    if(!$serial) return null;
    $label=($serial['transport']==='direct'?'Serial port':'Serial gateway').' · '.$serial['endpoint'].' · Address '.$serial['device_address'];
    try {
        $target=nms_config_target($host_id,false); $states=[];
        foreach($target['fields'] as $key=>$field) $states[]=nms_config_reading($host_id,$key)['status'];
        $status=in_array('failed',$states,true)?'Read failed':(in_array('unavailable',$states,true)?'Unavailable':(in_array('stale',$states,true)?'Stale':'Responding'));
    } catch(Throwable $e) { $status='Unavailable'; }
    return ['connection'=>$label,'status'=>$status];
}
