<?php
require_once __DIR__ . '/../../fcaps/services/fcaps_service.php';
/** Device-owned, credential-free NMS configuration snapshots. Not running-config backups. */
function icct_nms_configuration_pick($row, $fields, $prefix, &$out) {
    foreach ($fields as $field) if (array_key_exists($field, $row) && is_scalar($row[$field])) $out[$prefix.'.'.$field]=(string)$row[$field];
}
function icct_nms_configuration_snapshot($id) {
    $host=icct_nms_device($id);$out=[];
    icct_nms_configuration_pick($host,['description','hostname','disabled','host_template_id','site_id','poller_id','device_threads','notes','snmp_version','snmp_port','snmp_timeout','max_oids','availability_method','ping_method','ping_timeout','ping_retries','snmp_auth_protocol','snmp_priv_protocol','snmp_security_level'],'device',$out);
    icct_nms_configuration_pick(icct_nms_metadata($id) ?: [],['short_name','serial_number','mac_address','chassis_id','cross_launch_url'],'identity',$out);
    icct_nms_configuration_pick(db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_device_classification WHERE host_id=?',[$id]) ?: [],['category_id','device_type'],'classification',$out);
    icct_nms_configuration_pick(db_fetch_row_prepared('SELECT * FROM plugin_icct_nms_rack_devices WHERE host_id=?',[$id]) ?: [],['rack_id','start_unit','unit_height'],'rack',$out);
    icct_nms_configuration_pick(db_fetch_row_prepared('SELECT p.*,d.monitoring FROM plugin_icct_nms_ssh_devices d JOIN plugin_icct_nms_ssh_presets p ON p.id=d.preset_id WHERE d.host_id=?',[$id]) ?: [],['port','connect_timeout','auth_method','command_timeout','retries','keepalive','monitoring'],'ssh',$out);
    icct_nms_configuration_pick(icct_nms_discovery_assignment($id) ?: [],['protocol','interval_seconds','stale_seconds','refresh_seconds'],'discovery',$out);
    $serial=icct_backend_serial_assignment($id) ?: [];
    icct_nms_configuration_pick($serial,['connection_id','device_address','protocol','poll_interval'],'serial',$out);
    icct_nms_configuration_pick(json_decode($serial['settings_json'] ?? '{}',true) ?: [],['baud_rate','data_bits','parity','stop_bits','flow_control','response_timeout','serial_retries','serial_interface'],'serial_settings',$out);
    icct_nms_configuration_pick(icct_backend_syslog_assignment($id) ?: [],['source_address','transport','max_severity','severity_codes','facility_codes','match_strings'],'syslog',$out);
    foreach(['snmp','ssh','serial','cdp','lldp','syslog'] as $protocol)$out['protocol_enabled.'.$protocol]=icct_backend_protocol_enabled($id,$protocol)?'1':'0';
    $out['graphs.template_ids']=json_encode(array_column(icct_nms_graph_associations($id),'id'));
    $out['ports.monitoring']=json_encode(icct_nms_port_monitoring($id),JSON_THROW_ON_ERROR);
    $out['fault.rules']=json_encode(icct_nms_fault_rules($id),JSON_THROW_ON_ERROR);
    $out['rack.peripheral_rack_id']=(string)icct_nms_meta('rack_peripheral_'.$id);
    $out['identity.short_name']=icct_nms_meta('device_short_name_'.$id);
    ksort($out);return $out;
}
function icct_nms_configuration_changes($before,$after) {
    $changes=[];foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $field)if(($before[$field] ?? null)!==($after[$field] ?? null))$changes[$field]=['before'=>$before[$field] ?? null,'after'=>$after[$field] ?? null];
    return $changes;
}
function icct_nms_configuration_history($id) {
    if(!$id)return [];icct_nms_device($id);
    $prefix='configuration_history_'.(int)$id.'_';
    $rows=db_fetch_assoc_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE LEFT(meta_key,?)=? ORDER BY meta_key DESC LIMIT 100',[strlen($prefix),$prefix]);
    $events=[];foreach($rows as $row){$event=json_decode($row['meta_value'],true);if(is_array($event))$events[]=$event;}return $events;
}
function icct_nms_configuration_record($id,$action,$manual=false) {
    icct_backend_require_management(3);icct_nms_device($id);
    $lock='icct_config_history_'.(int)$id;
    if((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',[$lock])!==1)throw new RuntimeException('Configuration history is busy. Retry the backup.');
    try {
        $snapshot=icct_nms_configuration_snapshot($id);
        $last=json_decode(icct_nms_meta('configuration_latest_'.$id),true) ?: [];
        $changes=icct_nms_configuration_changes($last['snapshot'] ?? [],$snapshot);
        if(!$manual && $last && !$changes)return;
        $actor=(int)($_SESSION['sess_user_id'] ?? 0);
        $event=['id'=>sprintf('%020d',(int)(microtime(true)*1000000)).'_'.bin2hex(random_bytes(4)),'time'=>date(DATE_ATOM),'user_id'=>$actor,'user'=>(string)db_fetch_cell_prepared('SELECT username FROM user_auth WHERE id=?',[$actor]),'action'=>$action,'snapshot'=>$snapshot,'changes'=>$changes];
        $json=json_encode($event,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW())',['configuration_history_'.$id.'_'.$event['id'],$json]);
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['configuration_latest_'.$id,$json]);
    } finally {db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]);}
}
