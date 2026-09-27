<?php
/** Configuration job admission and immutable preview evidence. */
require_once __DIR__.'/equipment.php';

function nms_config_target($host_id, $authorize=true)
{
    $host_id=nms_config_integer($host_id,1,16777215,'Device ID');
    if($authorize) { nms_require_management(); nms_require_device_access($host_id); }
    $host=db_fetch_row_prepared("SELECT h.id,h.description,h.hostname,h.site_id,h.poller_id,h.disabled,h.snmp_version,h.snmp_port,h.snmp_timeout,h.snmp_community,h.snmp_username,h.snmp_password,h.snmp_auth_protocol,h.snmp_priv_passphrase,h.snmp_priv_protocol,h.snmp_context,h.snmp_engine_id FROM host h JOIN poller p ON p.id=h.poller_id WHERE h.id=? AND h.deleted='' AND p.disabled=''",[$host_id]);
    if(!$host || $host['disabled']!=='') throw new RuntimeException('Device or its collector is missing or disabled.');
    $assignment=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]);
    if(!$assignment) throw new RuntimeException('Assign an equipment profile first.');
    $profile=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_profiles WHERE id=?',[$assignment['profile_id']]);
    if(!$profile) throw new RuntimeException('Equipment profile is unavailable.');
    $fields=nms_equipment_fields($profile['fields_json'],$profile['protocol']);
    $serial=null;
    if($profile['protocol']==='modbus_rtu') {
        $serial=db_fetch_row_prepared('SELECT d.device_address,d.revision AS device_revision,c.* FROM plugin_nms_serial_devices d JOIN plugin_nms_serial_connections c ON c.id=d.connection_id WHERE d.host_id=?',[$host_id]);
        if(!$serial || !$serial['enabled'] || (int)$serial['poller_id']!==(int)$host['poller_id']) throw new RuntimeException('Serial connection is missing, disabled or assigned to another collector.');
        $serial['settings']=json_decode($serial['settings_json'],true,32,JSON_THROW_ON_ERROR);
    } elseif(!(int)$host['snmp_version']) throw new RuntimeException('Native SNMP access is disabled.');
    $signature=hash('sha256',json_encode([$host,$assignment,$profile,$serial],JSON_THROW_ON_ERROR));
    return ['host'=>$host,'assignment'=>$assignment,'profile'=>$profile,'fields'=>array_column($fields,null,'key'),'serial'=>$serial,'signature'=>$signature];
}

function nms_config_job_enqueue($host_id, $field_key, $operation='read', $requested=null, $preview_id=0)
{
    if(!in_array($operation,['read','write'],true)) throw new InvalidArgumentException('Unsupported configuration operation.');
    return nms_serial_mutation(function() use($host_id,$field_key,$operation,$requested,$preview_id) {
        $target=nms_config_target($host_id);
        $field=$target['fields'][$field_key] ?? null;
        if(!$field) throw new InvalidArgumentException('Select a defined equipment field.');
        $user=nms_current_user_id();
        if($user<1) throw new RuntimeException('Sign in before requesting configuration.');
        if(db_fetch_cell_prepared("SELECT id FROM plugin_nms_config_jobs WHERE host_id=? AND status IN ('queued','running') LIMIT 1",[$host_id])) throw new RuntimeException('This device already has a pending configuration request.');
        $before=null;
        if($operation==='write') {
            if(!$field['writable']) throw new InvalidArgumentException('This field is read-only.');
            if($target['profile']['protocol']==='snmp' && $target['assignment']['credential_ref']==='') throw new RuntimeException('Assign an authorised write credential reference first.');
            $requested=nms_equipment_value($field,$requested);
            $preview=db_fetch_row_prepared("SELECT * FROM plugin_nms_config_jobs WHERE id=? AND host_id=? AND user_id=? AND operation='read' AND field_key=? AND status='complete' AND finished_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) FOR UPDATE",[nms_config_integer($preview_id,1,PHP_INT_MAX,'Preview ID'),$host_id,$user,$field_key]);
            if(!$preview || !hash_equals($target['signature'],$preview['signature'])) throw new RuntimeException('Read this setting again before applying a change.');
            $result=json_decode($preview['result_json'],true,32,JSON_THROW_ON_ERROR);
            if(!array_key_exists('value',$result)) throw new RuntimeException('Preview has no usable device value.');
            $before=nms_equipment_value($field,$result['value']);
            // A preview can authorise only one write, including after a worker failure.
            nms_category_execute("UPDATE plugin_nms_config_jobs SET status='consumed' WHERE id=?",[$preview['id']]);
        }
        nms_category_execute("INSERT INTO plugin_nms_config_jobs(host_id,poller_id,user_id,operation,field_key,signature,before_json,requested_json,result_json,status,requested_at) VALUES (?,?,?,?,?,?,?,?,?,'queued',NOW())",[$host_id,$target['host']['poller_id'],$user,$operation,$field_key,$target['signature'],json_encode($before,JSON_THROW_ON_ERROR),json_encode($requested,JSON_THROW_ON_ERROR),'{}']);
        return (int)db_fetch_cell('SELECT LAST_INSERT_ID()');
    });
}

function nms_config_job_authorize(array $job)
{
    $user=(int)$job['user_id'];
    if($user<1 || db_fetch_cell_prepared('SELECT enabled FROM user_auth WHERE id=?',[$user])!=='on'
        || !is_realm_allowed(3,$user) || !is_device_allowed((int)$job['host_id'],$user)) throw new RuntimeException('Configuration permission was revoked.');
    $session=$_SESSION ?? [];
    try {
        $_SESSION=['sess_user_id'=>$user];
        if(!api_user_realm_auth('devices.php')) throw new RuntimeException('NMS page access was revoked.');
    } finally { $_SESSION=$session; }
}

function nms_config_job_history($host_id)
{
    nms_require_device_access($host_id);
    return db_fetch_assoc_prepared('SELECT id,host_id,user_id,operation,field_key,status,before_json,requested_json,result_json,requested_at,finished_at FROM plugin_nms_config_jobs WHERE host_id=? ORDER BY id DESC LIMIT 50',[(int)$host_id]);
}
