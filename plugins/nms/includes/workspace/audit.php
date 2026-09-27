<?php
/** Append explicit metadata only; callers never pass credentials, raw SNMP settings or tokens. */
function nms_workspace_audit($action,$detail=[],$host_id=0,$network_id=0,$user_id=null)
{
    if(!preg_match('/^[a-z_]{1,48}$/D',$action))throw new InvalidArgumentException('Invalid audit action.');
    $json=json_encode($detail,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    if(strlen($json)>16000)throw new InvalidArgumentException('Audit metadata too large.');
    nms_category_execute('INSERT INTO plugin_nms_workspace_audit(user_id,host_id,network_id,action,detail_json,created_at) VALUES (?,?,?,?,?,NOW())',[$user_id??nms_current_user_id(),$host_id,$network_id,$action,$json]);
}

/** Scope historical mutations to current host ACLs and Automation access. */
function nms_workspace_audit_history($host_id,$page,$allow_networks)
{
    $page=max(1,min(100000,(int)$page));$params=[];
    $where='(a.host_id>0 AND h.id IS NOT NULL AND h.deleted=\'\' AND '.nms_visible_host_sql('h.id').')';
    if($allow_networks)$where='('.$where.' OR (a.host_id=0 AND a.network_id>0))';
    if($host_id){$where.=' AND a.host_id=?';$params[]=(int)$host_id;}
    $base=' FROM plugin_nms_workspace_audit a LEFT JOIN host h ON h.id=a.host_id WHERE '.$where;
    $total=(int)db_fetch_cell_prepared('SELECT COUNT(*)'.$base,$params);$pages=max(1,(int)ceil($total/25));$page=min($page,$pages);
    $rows=db_fetch_assoc_prepared('SELECT a.*,h.description'.$base.' ORDER BY a.id DESC LIMIT 25 OFFSET '.(($page-1)*25),$params);
    return compact('rows','total','page','pages');
}
