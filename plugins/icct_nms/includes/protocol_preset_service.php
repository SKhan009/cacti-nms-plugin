<?php
/** Defaults are copied into new protocol drafts, never linked to device assignments. */
function icct_nms_protocol_preset_fields($protocol) {
    switch ($protocol) {
        case 'snmp': return ['snmp_version','snmp_port','snmp_timeout','max_oids','availability_method','ping_method','ping_timeout','ping_retries','snmp_username','snmp_auth_protocol','snmp_priv_protocol','snmp_context','snmp_security_level'];
        case 'cdp': case 'lldp': return ['interval_seconds','stale_seconds','refresh_seconds'];
        case 'ssh': return ['port','connect_timeout','auth_method','username','command_timeout','retries','keepalive','monitoring'];
        case 'serial': return ['serial_interface','baud_rate','data_bits','parity','stop_bits','flow_control','response_timeout','serial_retries','serial_interval','serial_protocol'];
        default: throw new InvalidArgumentException('Choose a supported protocol.');
    }
}
function icct_nms_protocol_presets() {
    $json=db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['protocol_defaults']);
    if (!$json) return [];
    $values=json_decode($json,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($values)) throw new RuntimeException('Invalid protocol defaults.');
    return $values;
}
function icct_nms_protocol_preset_validate($protocol,$input) {
    global $fields_host_edit;
    if ($protocol==='snmp') $input+=array_replace(icct_nms_defaults(),icct_nms_protocol_presets()['snmp'] ?? []);
    $out=[];
    foreach (icct_nms_protocol_preset_fields($protocol) as $field) {
        if (!isset($input[$field]) && $field!=='monitoring') throw new InvalidArgumentException('Missing field: '.$field);
        if (isset($input[$field]) && !is_scalar($input[$field])) throw new InvalidArgumentException('Invalid field: '.$field);
        $out[$field]=$input[$field] ?? '0';
    }
    if ($protocol==='snmp') {
        $out['snmp_version']=icct_backend_config_choice((string)$out['snmp_version'],['1','2','3'],'SNMP version');
        $out['snmp_security_level']=icct_backend_config_choice($out['snmp_security_level'],['noAuthNoPriv','authNoPriv','authPriv'],'SNMP security level');
        foreach ($out as $field=>$value) if (isset($fields_host_edit[$field])) $out[$field]=icct_backend_core_field_value($field,$fields_host_edit[$field],$value);
        foreach (icct_nms_native_ranges() as $field=>$range) $out[$field]=icct_backend_topology_integer($out[$field],$range[0],$range[1],$field);
        if ($out['snmp_security_level']==='noAuthNoPriv') $out['snmp_auth_protocol']='[None]';
        if ($out['snmp_security_level']!=='authPriv') $out['snmp_priv_protocol']='[None]';
    } elseif ($protocol==='cdp' || $protocol==='lldp') {
        $validated=icct_backend_nd_preset_validate($out+['name'=>'Protocol defaults','methods'=>[$protocol],'enabled'=>1]);
        $cadence=(int)read_config_option('poller_interval');
        if ($cadence<1 || $validated['interval_seconds']%$cadence!==0) throw new InvalidArgumentException('Collection interval must be a multiple of the poller interval.');
        foreach ($out as $field=>$value) $out[$field]=$validated[$field];
    } elseif ($protocol==='ssh') {
        $validated=icct_backend_ssh_preset_values($out+['name'=>'Protocol defaults','description'=>'','enabled'=>1]);
        foreach ($out as $field=>$value) if ($field!=='monitoring') $out[$field]=$validated[$field];
        $out['monitoring']=!empty($input['monitoring'])?'1':'0';
    } else {
        icct_nms_serial_settings([],$out,true);
        $out['serial_interval']=icct_backend_config_integer($out['serial_interval'],1,86400,'Polling interval');
    }
    return $out;
}
function icct_nms_save_protocol_preset($input) {
    $protocol=$input['preset_protocol'] ?? '';
    if (!is_string($protocol)) throw new InvalidArgumentException('Choose a supported protocol.');
    $values=icct_nms_protocol_preset_validate($protocol,$input);
    $lock='icct_protocol_defaults';
    if ((int)db_fetch_cell_prepared('SELECT GET_LOCK(?,5)',[$lock])!==1) throw new RuntimeException('Protocol defaults are busy. Please retry.');
    try {
        $presets=icct_nms_protocol_presets(); $presets[$protocol]=$values;
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['protocol_defaults',json_encode($presets,JSON_THROW_ON_ERROR)]);
    } finally { db_fetch_cell_prepared('SELECT RELEASE_LOCK(?)',[$lock]); }
    return strtoupper($protocol).' defaults saved. Existing devices keep their own settings.';
}
