<?php
/** Manual node selection, per-device evidence and independent configuration outcomes. */
require_once __DIR__.'/monitoring.php';
require_once dirname(__DIR__).'/nodes/service.php';

function nms_config_node_selection($node_id,$ids)
{
    nms_require_management();
    $node=nms_node_get($node_id);
    if(!is_array($ids) || !$ids || count($ids)>50) throw new InvalidArgumentException('Select 1–50 member devices.');
    $result=[];
    foreach($ids as $id) {
        $id=nms_config_integer($id,1,16777215,'Device ID');
        nms_require_device_access($id);
        if(!db_fetch_cell_prepared("SELECT h.id FROM host h JOIN plugin_nms_node_devices d ON d.host_id=h.id WHERE d.node_id=? AND h.id=? AND h.site_id=? AND h.deleted=''",[$node_id,$id,$node['site_id']])) throw new InvalidArgumentException('A selected device is no longer a member at this node’s site.');
        $result[$id]=$id;
    }
    return array_values($result);
}

function nms_config_node_reads($node_id,$ids,$key)
{
    $lock=nms_nodes_lock();
    try {
        $ids=nms_config_node_selection($node_id,$ids); $results=[];
        foreach($ids as $id) {
            try { $results[$id]=['job_id'=>nms_config_job_enqueue($id,$key),'status'=>'queued']; }
            catch(Throwable $e) { $results[$id]=['status'=>'failed','error'=>$e->getMessage()]; }
        }
        return $results;
    } finally { nms_nodes_unlock($lock); }
}

function nms_config_node_preview($node_id,$ids,$key,$requested)
{
    $ids=nms_config_node_selection($node_id,$ids); $rows=[]; $compatibility=null;
    foreach($ids as $id) {
        $target=nms_config_target($id); $field=$target['fields'][$key] ?? null;
        if(!$field || !$field['writable']) throw new InvalidArgumentException('Device '.$id.' does not support this writable field.');
        // Register offsets may differ by model; meaning, units and representation must agree.
        $meaning=[$target['profile']['protocol'],$field['key'],$field['label'],$field['unit'],$field['type']];
        if($compatibility!==null && $compatibility!==$meaning) throw new InvalidArgumentException('Selected fields are not compatible. Configure these devices separately.');
        $compatibility=$meaning;
        $value=nms_equipment_value($field,$requested);
        $read=db_fetch_row_prepared("SELECT id,result_json,signature FROM plugin_nms_config_jobs WHERE host_id=? AND field_key=? AND user_id=? AND operation='read' AND status='complete' AND finished_at>DATE_SUB(NOW(),INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1",[$id,$key,nms_current_user_id()]);
        if(!$read || !hash_equals($target['signature'],$read['signature'])) throw new RuntimeException('Read the current value for device '.$id.' before reviewing the batch.');
        $result=json_decode($read['result_json'],true,32,JSON_THROW_ON_ERROR);
        $rows[$id]=['name'=>$target['host']['description'],'read_id'=>(int)$read['id'],'before'=>nms_equipment_value($field,$result['value'] ?? null),'requested'=>$value,'signature'=>$target['signature']];
    }
    return ['node_id'=>(int)$node_id,'field_key'=>$key,'rows'=>$rows,'user_id'=>nms_current_user_id(),'expires'=>time()+300];
}

function nms_config_node_apply(array $preview)
{
    nms_require_management();
    if($preview['user_id']!==nms_current_user_id() || $preview['expires']<time()) throw new RuntimeException('Batch preview expired. Read and review again.');
    $lock=nms_nodes_lock(); $results=[];
    try {
        foreach($preview['rows'] as $id=>$row) {
            try {
                nms_config_node_selection($preview['node_id'],[$id]);
                $target=nms_config_target($id);
                if(!hash_equals($row['signature'],$target['signature'])) throw new RuntimeException('Device settings changed after review.');
                $results[$id]=['job_id'=>nms_config_job_enqueue($id,$preview['field_key'],'write',$row['requested'],$row['read_id']),'status'=>'queued'];
            } catch(Throwable $e) { $results[$id]=['status'=>'failed','error'=>$e->getMessage()]; }
        }
    } finally { nms_nodes_unlock($lock); }
    return $results;
}

