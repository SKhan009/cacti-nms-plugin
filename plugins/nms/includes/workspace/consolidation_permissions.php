<?php
require_once __DIR__.'/admission.php';

/** Read current native ACL inputs; never change permissions as a side effect of consolidation. */
function nms_workspace_consolidation_permissions($keep,$source)
{
    $keep=(int)$keep;$source=(int)$source;
    if($keep<1||$source<1||$keep===$source)throw new InvalidArgumentException('Distinct devices are required for permission review.');
    $snapshot=[];$blockers=[];
    // Bound the complete input snapshot, including inherited policy and membership changes.
    $queries=[
        'users'=>'SELECT id,enabled,policy_graphs,policy_hosts,policy_graph_templates,policy_trees FROM user_auth ORDER BY id LIMIT 10001',
        'groups'=>'SELECT id,enabled,policy_graphs,policy_hosts,policy_graph_templates,policy_trees FROM user_auth_group ORDER BY id LIMIT 10001',
        'members'=>'SELECT group_id,user_id FROM user_auth_group_members ORDER BY group_id,user_id LIMIT 10001',
        'user_rules'=>'SELECT user_id,item_id,type FROM user_auth_perms ORDER BY user_id,type,item_id LIMIT 10001',
        'group_rules'=>'SELECT group_id,item_id,type FROM user_auth_group_perms ORDER BY group_id,type,item_id LIMIT 10001',
        'realms'=>'SELECT user_id,realm_id FROM user_auth_realm ORDER BY user_id,realm_id LIMIT 10001',
        'group_realms'=>'SELECT group_id,realm_id FROM user_auth_group_realm ORDER BY group_id,realm_id LIMIT 10001',
        'trees'=>'SELECT id,graph_tree_id,local_graph_id,host_id FROM graph_tree_items ORDER BY id LIMIT 10001',
        'modes'=>"SELECT name,value FROM settings WHERE name IN ('auth_method','graph_auth_method') ORDER BY name"
    ];
    $total=0;
    foreach($queries as $key=>$sql) {
        $rows=nms_workspace_admission_rows($sql);$total+=count($rows);
        if(count($rows)>10000||$total>30000)throw new RuntimeException('Permission inventory exceeds the automatic consolidation review limit.');
        $snapshot[$key]=$rows;
    }
    foreach(['user_rules'=>'user_id','group_rules'=>'group_id'] as $key=>$principal) {
        $rules=[$keep=>[],$source=>[]];
        foreach($snapshot[$key] as $row)if((int)$row['type']===3&&isset($rules[(int)$row['item_id']]))$rules[(int)$row['item_id']][]=(int)$row[$principal];
        foreach($rules as &$ids){$ids=array_values(array_unique($ids));sort($ids);}unset($ids);
        if($rules[$keep]!==$rules[$source])$blockers[$key]='The two records have different '.($key==='user_rules'?'user':'group').' device-permission exceptions. Review and align effective access before transfer.';
    }
    $trees=[$keep=>[],$source=>[]];
    foreach($snapshot['trees'] as $row)if(isset($trees[(int)$row['host_id']]))$trees[(int)$row['host_id']][]=(int)$row['graph_tree_id'];
    foreach($trees as &$ids){$ids=array_values(array_unique($ids));sort($ids);}unset($ids);
    if($trees[$keep]!==$trees[$source])$blockers['tree_membership']='The records belong to different device branches in Cacti trees. Review tree visibility and references before transfer.';
    $hosts=nms_workspace_admission_rows('SELECT id,host_template_id,site_id,disabled,deleted FROM host WHERE id IN (?,?) ORDER BY id',[$keep,$source]);
    if(count($hosts)!==2)throw new RuntimeException('A device disappeared during permission review.');
    foreach(['host_template_id','site_id','disabled','deleted'] as $field)if((string)$hosts[0][$field]!== (string)$hosts[1][$field])$blockers['device_context']='Device template, site or monitoring state differs. Effective device access requires separate review.';
    $snapshot['hosts']=$hosts;
    return ['status'=>$blockers?'blocked':'native_inputs_equivalent','blockers'=>$blockers,
        'fingerprint'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR)),
        'scope'=>'Native Cacti permission inputs only. External authorization plugins require separate review. No permissions are copied or broadened.'];
}
