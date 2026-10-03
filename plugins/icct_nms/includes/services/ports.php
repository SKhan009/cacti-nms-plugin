<?php
/** Read-only IF-MIB/IF-X-MIB observations; preset capacity never creates observed ports. */
function icct_backend_ports_signature($host) {
    $fields=['id','hostname','poller_id','disabled','snmp_version','snmp_port','snmp_context','snmp_engine_id','snmp_username','snmp_community','snmp_password','snmp_auth_protocol','snmp_priv_passphrase','snmp_priv_protocol','snmp_timeout','max_oids'];
    $values=[];
    foreach ($fields as $field) $values[$field]=(string)($host[$field] ?? '');
    return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
}
function icct_backend_ports_integer($value) {
    $value=trim((string)$value);
    if (preg_match('/^\d+$/D',$value)) return (int)$value;
    if (preg_match('/\((\d+)\)$/D',$value,$match)) return (int)$match[1];
    // Cacti strips the numeric suffix from named Net-SNMP enumerations.
    return ['up'=>1,'down'=>2,'testing'=>3,'unknown'=>4,'dormant'=>5,'notpresent'=>6,'lowerlayerdown'=>7,'true'=>1,'false'=>2][strtolower($value)] ?? null;
}
function icct_backend_ports_parse($columns) {
    $ports=[];
    foreach ($columns['index'] ?? [] as $index=>$value) {
        if (!ctype_digit((string)$index) || (int)$index!==icct_backend_ports_integer($value)) continue;
        $port=['index'=>(int)$index];
        foreach (['name','description','alias'] as $field) $port[$field]=trim((string)($columns[$field][$index] ?? '')," \t\r\n\"");
        foreach (['admin','oper','connector','type'] as $field) $port[$field]=icct_backend_ports_integer($columns[$field][$index] ?? '');
        if (!$port['name']) $port['name']=$port['description'] ?: 'ifIndex '.$index;
        $ports[]=$port;
    }
    usort($ports,static fn($a,$b)=>$a['index']<=>$b['index']);
    return $ports;
}
function icct_backend_collect_ports() {
    global $config;
    $collector=icct_backend_inventory_collector_id();
    $hosts=db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND disabled='' AND snmp_version>0 AND poller_id=?",[$collector]);
    foreach ($hosts as $host) {
        if (!icct_backend_protocol_enabled($host['id'],'snmp')) continue;
        $snapshot=['signature'=>icct_backend_ports_signature($host),'time'=>time(),'source'=>'SNMP IF-MIB / IF-X-MIB','ports'=>[],'error'=>''];
        try {
            $columns=[];
            foreach (['index'=>'1.3.6.1.2.1.2.2.1.1','description'=>'1.3.6.1.2.1.2.2.1.2','type'=>'1.3.6.1.2.1.2.2.1.3','admin'=>'1.3.6.1.2.1.2.2.1.7','oper'=>'1.3.6.1.2.1.2.2.1.8','name'=>'1.3.6.1.2.1.31.1.1.1.1','connector'=>'1.3.6.1.2.1.31.1.1.1.17','alias'=>'1.3.6.1.2.1.31.1.1.1.18'] as $field=>$oid) {
                $rows=cacti_snmp_walk($host['hostname'],$host['snmp_community'],'.'.$oid,$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],$host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],$host['snmp_context'],$host['snmp_port'],$host['snmp_timeout'],(int)read_config_option('snmp_retries'),(int)$host['max_oids'],'ICCT Ports',$host['snmp_engine_id']);
                foreach (is_array($rows)?$rows:[] as $row) {
                    $returned=ltrim((string)($row['oid'] ?? ''),'.'); $prefix=$oid.'.';
                    if (str_starts_with($returned,$prefix)) $columns[$field][substr($returned,strlen($prefix))]=$row['value'] ?? '';
                }
                if ($field==='index' && empty($columns['index'])) throw new RuntimeException('No interfaces reported. Check saved SNMP settings and IF-MIB access.');
            }
            $snapshot['ports']=icct_backend_ports_parse($columns);
            if (!$snapshot['ports']) throw new RuntimeException('The device did not report valid interface indexes.');
        } catch (Throwable $error) { $snapshot['error']='Interface discovery failed. Check saved SNMP settings and device IF-MIB access.'; }
        $snapshot['time']=time();
        icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',['ports_snapshot_'.$host['id'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);
    }
}
function icct_backend_ports_status($port,$fresh) {
    if (!$fresh) return 'Unknown';
    if (($port['admin'] ?? null)===2) return 'Disabled';
    if (($port['oper'] ?? null)===1) return 'In use';
    if (($port['admin'] ?? null)===1 && ($port['oper'] ?? null)===2) return 'Available (link down)';
    return 'Unknown';
}
function icct_backend_ports_view($host) {
    $snapshot=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['ports_snapshot_'.$host['id']]),true);
    $age=time()-(int)($snapshot['time'] ?? 0);
    $fresh=is_array($snapshot) && ($snapshot['signature'] ?? '')===icct_backend_ports_signature($host) && $age>=0 && $age<=2*max(60,(int)read_config_option('poller_interval')) && empty($snapshot['error']) && $host['disabled']==='' && (int)$host['snmp_version']>0 && icct_backend_protocol_enabled($host['id'],'snmp');
    $same=is_array($snapshot) && ($snapshot['signature'] ?? '')===icct_backend_ports_signature($host);
    $ports=$same && is_array($snapshot['ports'] ?? null)?$snapshot['ports']:[];
    foreach ($ports as &$port) $port['status']=icct_backend_ports_status($port,$fresh);
    unset($port);
    return ['ports'=>$ports,'fresh'=>$fresh,'collected'=>$same?(int)($snapshot['time'] ?? 0):0,'source'=>'SNMP IF-MIB / IF-X-MIB','message'=>$fresh?'Port status from the latest collector observation.':($same && !empty($snapshot['error'])?$snapshot['error']:'Waiting for a current observation. Save SNMP settings; the assigned Cacti poller discovers interfaces automatically.')];
}
