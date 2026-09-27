<?php
/** Model-specific field definitions; no built-in manufacturer register guesses. */
require_once __DIR__.'/service.php';

function nms_equipment_fields($json, $protocol)
{
    if (!is_string($json) || strlen($json)>32768) throw new InvalidArgumentException('Field definitions must be JSON, at most 32 KB.');
    try { $fields=json_decode($json,true,16,JSON_THROW_ON_ERROR); }
    catch(Throwable $e) { throw new InvalidArgumentException('Invalid field definition JSON.'); }
    if (!is_array($fields) || !$fields || count($fields)>16 || array_keys($fields)!==range(0,count($fields)-1)) throw new InvalidArgumentException('Define a list of 1–16 fields.');
    $keys=[]; $result=[];
    foreach($fields as $field) {
        if(!is_array($field)) throw new InvalidArgumentException('Each field must be an object.');
        $key=nms_config_text($field['key'] ?? '',32,'Field key');
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$key) || isset($keys[$key])) throw new InvalidArgumentException('Field keys must be unique lowercase identifiers.');
        $keys[$key]=true;
        if(!is_bool($field['writable'] ?? null)) throw new InvalidArgumentException('Each field needs an explicit writable boolean.');
        $item=['key'=>$key,'label'=>nms_config_text($field['label'] ?? '',100,'Field label'),
            'unit'=>nms_config_text($field['unit'] ?? '',30,'Unit',false),'writable'=>$field['writable']];
        if($protocol==='modbus_rtu') {
            $item['offset']=nms_config_integer($field['offset'] ?? '',0,65535,'Zero-based register offset');
            $item['function']=nms_config_integer($field['function'] ?? '',3,4,'Read function');
            $item['type']=nms_config_choice($field['type'] ?? '',['uint16','int16'],'register type');
            if($item['writable'] && $item['function']!==3) throw new InvalidArgumentException('Input registers are read-only.');
        } elseif($protocol==='snmp') {
            $item['oid']=nms_config_text($field['oid'] ?? '',200,'Numeric OID');
            if(!preg_match('/^\.?[0-2](?:\.[0-9]+){2,}$/D',$item['oid'])) throw new InvalidArgumentException('Use a complete numeric scalar or indexed OID.');
            $item['type']=nms_config_choice($field['type'] ?? '',['integer','unsigned','string'],'SNMP type');
        } else throw new InvalidArgumentException('Unsupported equipment protocol.');
        if($item['type']==='string') {
            $item['max_length']=nms_config_integer($field['max_length'] ?? '',1,255,'String maximum length');
        } else {
            $low=in_array($item['type'],['int16','integer'],true) ? ($item['type']==='int16'?-32768:-2147483648) : 0;
            $high=in_array($item['type'],['int16','uint16'],true) ? ($item['type']==='int16'?32767:65535) : ($item['type']==='integer'?2147483647:4294967295);
            foreach(['min','max'] as $bound) {
                $v=$field[$bound] ?? null;
                if(!is_int($v) || $v<$low || $v>$high) throw new InvalidArgumentException('Provide integer min/max within the field type range.');
                $item[$bound]=$v;
            }
            if($item['min']>$item['max']) throw new InvalidArgumentException('Field minimum exceeds maximum.');
        }
        $result[]=$item;
    }
    return $result;
}

function nms_equipment_value(array $field, $value)
{
    if($field['type']==='string') return nms_config_text($value,$field['max_length'],'Setting value',false);
    if((!is_string($value) && !is_int($value)) || !preg_match('/^-?[0-9]+$/D',(string)$value)
        || (float)$value<$field['min'] || (float)$value>$field['max']) throw new InvalidArgumentException('Setting must be an integer within its documented range.');
    return (int)$value;
}

function nms_equipment_profile_save(array $input)
{
    nms_require_management();
    $id=nms_config_integer($input['id'] ?? 0,0,2147483647,'Equipment profile ID');
    $revision=nms_config_integer($input['revision'] ?? 0,0,2147483647,'Revision');
    $protocol=nms_config_choice($input['protocol'] ?? '',['modbus_rtu','snmp'],'equipment protocol');
    $fields=nms_equipment_fields($input['fields_json'] ?? '',$protocol);
    $values=[];
    foreach(['name'=>150,'manufacturer'=>120,'model'=>120,'manual_reference'=>512] as $key=>$max) $values[]=nms_config_text($input[$key] ?? '',$max,ucwords(str_replace('_',' ',$key)));
    return nms_serial_mutation(function() use($id,$revision,$protocol,$fields,$values) {
        if($id) {
            $old=db_fetch_row_prepared('SELECT revision FROM plugin_nms_config_profiles WHERE id=? FOR UPDATE',[$id]);
            if(!$old || (int)$old['revision']!==$revision) throw new RuntimeException('Equipment profile changed. Reload before saving.');
            if(db_fetch_cell_prepared('SELECT host_id FROM plugin_nms_config_devices WHERE profile_id=? LIMIT 1',[$id])) throw new RuntimeException('Assigned equipment profiles are immutable. Create a new profile and explicitly reassign devices.');
        }
        if(db_fetch_cell_prepared('SELECT id FROM plugin_nms_config_profiles WHERE name=? AND id<>?',[$values[0],$id])) throw new InvalidArgumentException('Equipment profile name is already used.');
        $args=array_merge($values,[$protocol,json_encode($fields,JSON_THROW_ON_ERROR),nms_current_user_id()]);
        if($id) {
            $args[]=$id;
            nms_category_execute('UPDATE plugin_nms_config_profiles SET name=?,manufacturer=?,model=?,manual_reference=?,protocol=?,fields_json=?,updated_by=?,revision=revision+1,updated_at=NOW() WHERE id=?',$args);
        } else {
            nms_category_execute('INSERT INTO plugin_nms_config_profiles(name,manufacturer,model,manual_reference,protocol,fields_json,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,NOW())',$args);
            $id=(int)db_fetch_cell('SELECT LAST_INSERT_ID()');
        }
        return $id;
    });
}

function nms_equipment_assign($host_id,array $input)
{
    return nms_serial_mutation(function() use($host_id,$input) {
        return nms_equipment_assign_locked($host_id,$input);
    });
}

/** Caller holds the serial transaction; also used during native device creation. */
function nms_equipment_assign_locked($host_id,array $input)
{
    $host_id=nms_config_integer($host_id,1,16777215,'Device ID');
    $profile_id=nms_config_integer($input['profile_id'] ?? '',0,2147483647,'Equipment profile ID');
    $revision=nms_config_integer($input['revision'] ?? '',0,2147483647,'Assignment revision');
    $credential=nms_config_text($input['credential_ref'] ?? '',100,'Write credential reference',false);
    if($credential!=='' && !preg_match('/^[A-Za-z0-9_-]+$/D',$credential)) throw new InvalidArgumentException('Invalid credential reference.');
    $interval=nms_config_integer($input['interval_seconds'] ?? 300,60,86400,'Collection interval');
        nms_require_device_access($host_id);
        $host=db_fetch_row_prepared("SELECT id,poller_id,snmp_version FROM host WHERE id=? AND deleted='' FOR UPDATE",[$host_id]);
        if(!$host) throw new InvalidArgumentException('Device no longer exists.');
        $old=db_fetch_row_prepared('SELECT revision FROM plugin_nms_config_devices WHERE host_id=? FOR UPDATE',[$host_id]);
        if((int)($old['revision'] ?? 0)!==$revision) throw new RuntimeException('Equipment assignment changed. Reload first.');
        if(!$profile_id) {
            nms_category_execute('DELETE FROM plugin_nms_config_devices WHERE host_id=?',[$host_id]);
            nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=?',[$host_id]);
            return;
        }
        $profile=db_fetch_row_prepared('SELECT * FROM plugin_nms_config_profiles WHERE id=?',[$profile_id]);
        if(!$profile) throw new InvalidArgumentException('Equipment profile not found.');
        if($profile['protocol']==='modbus_rtu') {
            $serial=nms_serial_assignment($host_id);
            if(!$serial || (int)$serial['poller_id']!==(int)$host['poller_id']) throw new InvalidArgumentException('Assign a serial connection on the device collector first.');
            $connection=nms_serial_connection_get($serial['connection_id']);
            // A bus can carry different models. The equipment map belongs to each device;
            // the connection snapshot supplies the shared physical/protocol settings.
            if(!$connection['enabled'] || ($connection['settings']['protocol'] ?? '')!==$profile['protocol']) throw new InvalidArgumentException('Equipment protocol must match an enabled serial connection.');
        } elseif(!(int)$host['snmp_version']) throw new InvalidArgumentException('Configure native Cacti SNMP access first.');
        nms_category_execute('INSERT INTO plugin_nms_config_devices(host_id,profile_id,credential_ref,interval_seconds,revision,updated_by,updated_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),credential_ref=VALUES(credential_ref),interval_seconds=VALUES(interval_seconds),revision=VALUES(revision),updated_by=VALUES(updated_by),updated_at=NOW()',[$host_id,$profile_id,$credential,$interval,$revision+1,nms_current_user_id()]);
        nms_category_execute('DELETE FROM plugin_nms_serial_readings WHERE host_id=?',[$host_id]);
}
