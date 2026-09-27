<?php
require_once __DIR__.'/authorization.php';
require_once __DIR__.'/../topology/discovery.php';
require_once __DIR__.'/consolidation_preflight.php';
require_once __DIR__.'/consolidation_permissions.php';
require_once __DIR__.'/reviews.php';
require_once __DIR__.'/admission.php';

/** A consolidation plan is a review artifact, never a claim that assets were migrated. */
function nms_workspace_consolidation_plan($keep,$other)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Device workspace permission is required.');
    [$a,$b]=nms_identity_review_pair($keep,$other);$keep=(int)$keep;$other=(int)$other;
    nms_workspace_authorize_current($a);nms_workspace_authorize_current($b);
    $review=db_fetch_row_prepared('SELECT * FROM plugin_nms_identity_reviews WHERE host_a=? AND host_b=?',[$a,$b]);
    $discovery=nms_topology_discovery(null,true);$identities=nms_nd_device_identities($discovery['hosts'],$discovery['snapshots']);
    $evidence=nms_identity_review_hash($a,$b,$discovery['hosts'],$identities);
    if(!$review||$review['decision']!=='same'||!hash_equals($evidence,$review['evidence_hash']))throw new RuntimeException('Save a current Same device identity review before preparing consolidation.');
    // Schema identifiers originate only in native metadata and are validated before quoting.
    $columns=nms_workspace_admission_rows("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME='host_id' OR COLUMN_NAME LIKE '%\\_host_id' OR COLUMN_NAME='device_id' OR COLUMN_NAME LIKE '%\\_device_id') ORDER BY TABLE_NAME,COLUMN_NAME");
    $devices=[];
    foreach([$keep,$other] as $id) {
        $host=db_fetch_row_prepared("SELECT id,description,hostname,poller_id,site_id,host_template_id,disabled FROM host WHERE id=? AND deleted=''",[$id]);
        if(!$host)throw new RuntimeException('A selected device is unavailable.');
        $graphs=nms_workspace_admission_rows('SELECT id,graph_template_id,snmp_query_id,snmp_index FROM graph_local WHERE host_id=? ORDER BY id LIMIT 2001',[$id]);
        foreach($graphs as $graph)if(!is_graph_allowed((int)$graph['id']))throw new RuntimeException('Some dependent graphs are outside your permissions. An authorized reviewer must inspect this pair.');
        $data=nms_workspace_admission_rows('SELECT d.id,d.data_template_id,d.snmp_query_id,d.snmp_index,t.data_source_path,t.data_source_profile_id,t.rrd_step FROM data_local d LEFT JOIN data_template_data t ON t.local_data_id=d.id WHERE d.host_id=? ORDER BY d.id,t.id LIMIT 2001',[$id]);
        if(count($graphs)>2000||count($data)>2000)throw new RuntimeException('This pair exceeds the interactive review limit. Prepare an offline dependency review.');
        $refs=[];
        foreach($columns as $column) {
            $table=$column['TABLE_NAME'];$field=$column['COLUMN_NAME'];
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table)||!preg_match('/^[a-zA-Z0-9_]+$/D',$field))throw new RuntimeException('Unsupported dependency identifier.');
            // Own audit/review records should not invalidate a plan simply by saving it.
            if(in_array($table,['plugin_nms_workspace_audit','plugin_nms_consolidation_reviews'],true))continue;
            $row=nms_workspace_admission_rows("SELECT COUNT(*) AS total FROM `$table` WHERE `$field`=?",[$id]);
            if((int)$row[0]['total']>0) {
                $keys=nms_workspace_admission_rows("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table]);
                $keyNames=array_column($keys,'COLUMN_NAME');
                foreach($keyNames as $keyName)if(!preg_match('/^[a-zA-Z0-9_]+$/D',$keyName))throw new RuntimeException('Unsupported dependency key.');
                $fingerprint=null;
                if($keyNames) {
                    $quoted='`'.implode('`,`',$keyNames).'`';
                    $keyRows=nms_workspace_admission_rows("SELECT $quoted FROM `$table` WHERE `$field`=? ORDER BY $quoted LIMIT 5001",[$id]);
                    if(count($keyRows)>5000)throw new RuntimeException('A dependency exceeds the interactive review limit. Prepare an offline review.');
                    $fingerprint=hash('sha256',json_encode($keyRows,JSON_THROW_ON_ERROR));
                }
                $refs[$table.'.'.$field]=['count'=>(int)$row[0]['total'],'key_fingerprint'=>$fingerprint];
            }
        }
        $permissions=[];
        foreach(['user_auth_perms','user_auth_group_perms'] as $table) {
            // Keep ACL identities private; review records bind a hash and count of explicit rules.
            $rows=nms_workspace_admission_rows("SELECT * FROM `$table` WHERE (type=3 AND item_id=?) OR (type=1 AND item_id IN (SELECT id FROM graph_local WHERE host_id=?)) ORDER BY type,item_id,1",[$id,$id]);
            $permissions[$table]=['explicit_rules'=>count($rows),'fingerprint'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        }
        $devices[]=['host'=>$host,'graphs'=>$graphs,'data_sources'=>$data,'references'=>$refs,'permissions'=>$permissions];
    }
    // Bind graph-to-data edges too: ownership counts alone miss shared data sources.
    $links=nms_workspace_admission_rows('SELECT DISTINCT g.id AS graph_id,g.host_id AS graph_host_id,d.id AS data_id,d.host_id AS data_host_id FROM graph_templates_item i JOIN graph_local g ON g.id=i.local_graph_id JOIN data_template_rrd r ON r.id=i.task_item_id JOIN data_local d ON d.id=r.local_data_id WHERE g.host_id=? OR d.host_id=? ORDER BY g.id,d.id LIMIT 5001',[$other,$other]);
    if(count($links)>5000)throw new RuntimeException('Graph/data dependencies exceed the interactive transfer review limit.');
    $preflight=nms_workspace_consolidation_preflight($devices[0]['host'],$devices[1]['host'],$devices[1]['graphs'],$devices[1]['data_sources'],$links);
    $plan=['keep_id'=>$keep,'other_id'=>$other,'identity_revision'=>(int)$review['revision'],'evidence_hash'=>$evidence,'devices'=>$devices,
        'transfer_preflight'=>$preflight,'permission_preflight'=>nms_workspace_consolidation_permissions($keep,$other),'dependency_edges_hash'=>hash('sha256',json_encode($links,JSON_THROW_ON_ERROR)),
        'status'=>'migration_review_required','limitations'=>[
            'Cacti supports changing the device for regular graphs and data sources. Query graphs and shared dependencies need separate handling. Saving this plan does not execute a transfer.',
            'Graph and data-source IDs and RRD path metadata are inventoried. RRD file contents and historical continuity have not been verified or migrated.',
            'Explicit device/graph permission rules are fingerprinted. Inherited user/group/tree policies require separate review before access changes.',
            'Reference counts cover schema columns named host_id/device_id or ending in those names. Embedded JSON, textual references and external integrations require manual review.',
            'Choose an approved migration method and verify populated history, permissions and all references before retiring either record.'
        ]];
    $plan['revision']=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));return $plan;
}

/** Save exactly the reviewed impact assessment; no Cacti device/graph mutation occurs. */
function nms_workspace_consolidation_save($input)
{
    $keep=nms_workspace_integer($input['keep_id']??0,1,16777215,'preferred device');$other=nms_workspace_integer($input['other_id']??0,1,16777215,'other device');
    $plan=nms_workspace_consolidation_plan($keep,$other);$note=$input['note']??'';
    if(!is_string($note)||strlen($note)>1024||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$note))throw new InvalidArgumentException('Use a review note of at most 1024 bytes.');
    if(!is_string($input['revision']??null)||!hash_equals($plan['revision'],$input['revision']))throw new RuntimeException('Dependencies or identity changed. Rebuild the consolidation plan.');
    if(!db_execute('START TRANSACTION'))throw new RuntimeException('Could not begin consolidation review.');
    try {
        nms_category_execute('INSERT INTO plugin_nms_consolidation_reviews(keep_id,other_id,user_id,revision,plan_json,note,created_at) VALUES (?,?,?,?,?,?,NOW())',[$keep,$other,nms_current_user_id(),$plan['revision'],json_encode($plan,JSON_THROW_ON_ERROR),trim($note)]);
        $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        foreach([$keep,$other] as $host_id)nms_workspace_audit('consolidation_review_saved',['review_id'=>$id,'status'=>'migration_review_required'],$host_id);
        if(!db_execute('COMMIT'))throw new RuntimeException('Could not commit consolidation review.');return $id;
    }catch(Throwable $e){db_execute('ROLLBACK');throw $e;}
}
function nms_workspace_consolidation_history($host_id=0)
{
    nms_require_management(3);
    if(!api_user_realm_auth('devices.php'))throw new RuntimeException('Device workspace permission is required.');
    return nms_workspace_admission_rows("SELECT r.id,r.keep_id,r.other_id,r.user_id,r.note,r.created_at,a.description AS keep_name,b.description AS other_name FROM plugin_nms_consolidation_reviews r JOIN host a ON a.id=r.keep_id JOIN host b ON b.id=r.other_id WHERE a.deleted='' AND b.deleted='' AND ".nms_visible_host_sql('a.id').' AND '.nms_visible_host_sql('b.id').($host_id?' AND (a.id='.(int)$host_id.' OR b.id='.(int)$host_id.')':'').' ORDER BY r.id DESC LIMIT 50');
}
