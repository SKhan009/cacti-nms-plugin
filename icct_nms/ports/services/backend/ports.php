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
    return ['up'=>1,'down'=>2,'testing'=>3,'unknown'=>4,'dormant'=>5,'notpresent'=>6,'lowerlayerdown'=>7,'true'=>1,'false'=>2,'ethernetcsmacd'=>6,'softwareloopback'=>24,'propvirtual'=>53,'l2vlan'=>135,'l3ipvlan'=>136,'tunnel'=>131][strtolower($value)] ?? null;
}
function icct_backend_ports_ticks($value) {
    $value=trim((string)$value);
    if(preg_match('/^\d+$/D',$value))return (int)$value;
    if(preg_match('/\((\d+)\)/',$value,$match))return (int)$match[1];
    if(preg_match('/^(\d+):(\d+):(\d+):(\d+)(?:\.(\d+))?$/D',$value,$match))return ((int)$match[1]*86400+(int)$match[2]*3600+(int)$match[3]*60+(int)$match[4])*100+(int)str_pad(substr($match[5]??'',0,2),2,'0');
    if(preg_match('/^(?:(\d+)\s+days?,?\s*)?(\d+):(\d+):(\d+)(?:\.(\d+))?$/i',$value,$match))return ((int)($match[1]??0)*86400+(int)$match[2]*3600+(int)$match[3]*60+(int)$match[4])*100+(int)str_pad(substr($match[5]??'',0,2),2,'0');
    return null;
}
function icct_backend_ports_parse($columns) {
    $ports=[];
    foreach ($columns['index'] ?? [] as $index=>$value) {
        if (!ctype_digit((string)$index) || (int)$index!==icct_backend_ports_integer($value)) continue;
        $port=['index'=>(int)$index];
        foreach (['name','description','alias'] as $field) $port[$field]=trim((string)($columns[$field][$index] ?? '')," \t\r\n\"");
        foreach (['admin','oper','connector','type','speed_bps','high_speed_mbps'] as $field) $port[$field]=icct_backend_ports_integer($columns[$field][$index] ?? '');
        $port['last_change_ticks']=icct_backend_ports_ticks($columns['last_change'][$index] ?? '');
        $port['bridge_port']=null;$port['vlan_id']=null;
        foreach($columns['bridge_ifindex'] ?? [] as $bridge=>$mapped)if(icct_backend_ports_integer($mapped)===(int)$index){$port['bridge_port']=(int)$bridge;$port['vlan_id']=icct_backend_ports_integer($columns['pvid'][$bridge] ?? '');break;}
        if (!$port['name']) $port['name']=$port['description'] ?: 'ifIndex '.$index;
        $ports[]=$port;
    }
    usort($ports,static fn($a,$b)=>$a['index']<=>$b['index']);
    return $ports;
}
/** Parse Linux iproute2 observations from an authenticated SSH session. */
function icct_backend_ports_linux_parse($json) {
    $rows=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($rows)||count($rows)>4096)throw new RuntimeException('Invalid Linux interface response.');
    $ports=[];
    foreach($rows as $row){
        if(!is_array($row)||!is_int($row['ifindex']??null)||$row['ifindex']<1||!is_string($row['ifname']??null))continue;
        $flags=$row['flags']??[];$state=strtoupper($row['operstate']??'UNKNOWN');
        $ports[]=['index'=>$row['ifindex'],'name'=>$row['ifname'],'description'=>$row['ifname'],'alias'=>$row['ifalias']??'','admin'=>in_array('UP',$flags,true)?1:2,'oper'=>['UP'=>1,'DOWN'=>2,'DORMANT'=>5,'LOWERLAYERDOWN'=>7][$state]??4,'connector'=>($row['link_type']??'')==='loopback'?2:null,'type'=>($row['link_type']??'')==='loopback'?24:6,'speed_bps'=>null,'high_speed_mbps'=>null,'last_change_ticks'=>null];
    }
    return $ports;
}
function icct_backend_ports_local() {
    $ports=[];
    foreach(glob('/sys/class/net/*')?:[] as $path){
        if(!is_readable($path.'/ifindex'))continue;$read=static function($field)use($path){return is_readable($path.'/'.$field)?trim(file_get_contents($path.'/'.$field)):'';};
        $index=(int)$read('ifindex');if($index<1)continue;$name=basename($path);$flags=hexdec($read('flags'));
        $port=icct_backend_ports_linux_parse(json_encode([['ifindex'=>$index,'ifname'=>$name,'flags'=>($flags&1)?['UP']:[],'operstate'=>$read('operstate'),'link_type'=>$read('type')==='772'?'loopback':'ether','ifalias'=>$read('ifalias')]]))[0];
        $port['connector']=is_dir($path.'/device')?1:($port['type']===24?2:null);
        $ports[]=$port;
    }
    usort($ports,static fn($a,$b)=>$a['index']<=>$b['index']);return $ports;
}
function icct_backend_ports_store($host,$ports,$source,$key='ports_snapshot_',$extra=[]) {
    $snapshot=['signature'=>icct_backend_ports_signature($host),'time'=>time(),'source'=>$source,'ports'=>$ports,'error'=>'']+$extra;
    icct_backend_category_execute('INSERT INTO plugin_icct_nms_meta(meta_key,meta_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=NOW()',[$key.$host['id'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);
}
function icct_backend_collect_ports() {
    global $config;
    $collector=icct_backend_inventory_collector_id();
    $hosts=db_fetch_assoc_prepared("SELECT * FROM host WHERE deleted='' AND disabled='' AND poller_id=?",[$collector]);
    foreach ($hosts as $host) {
        if (icct_backend_identity_local_endpoint($host['hostname']) && ((int)$host['snmp_version']<1 || !icct_backend_protocol_enabled($host['id'],'snmp'))) {icct_backend_ports_store($host,icct_backend_ports_local(),'Local Linux interfaces');continue;}
        if ((int)$host['snmp_version']<1 || !icct_backend_protocol_enabled($host['id'],'snmp')) continue;
        $snapshot=['signature'=>icct_backend_ports_signature($host),'time'=>time(),'source'=>'SNMP IF-MIB / IF-X-MIB','ports'=>[],'error'=>''];
        try {
            $columns=[];
            foreach (['index'=>'1.3.6.1.2.1.2.2.1.1','description'=>'1.3.6.1.2.1.2.2.1.2','type'=>'1.3.6.1.2.1.2.2.1.3','speed_bps'=>'1.3.6.1.2.1.2.2.1.5','high_speed_mbps'=>'1.3.6.1.2.1.31.1.1.1.15','admin'=>'1.3.6.1.2.1.2.2.1.7','oper'=>'1.3.6.1.2.1.2.2.1.8','name'=>'1.3.6.1.2.1.31.1.1.1.1','connector'=>'1.3.6.1.2.1.31.1.1.1.17','alias'=>'1.3.6.1.2.1.31.1.1.1.18','last_change'=>'1.3.6.1.2.1.2.2.1.9','uptime'=>'1.3.6.1.2.1.1.3','bridge_ifindex'=>'1.3.6.1.2.1.17.1.4.1.2','pvid'=>'1.3.6.1.2.1.17.7.1.4.5.1.1'] as $field=>$oid) {
                $rows=cacti_snmp_walk($host['hostname'],$host['snmp_community'],'.'.$oid,$host['snmp_version'],$host['snmp_username'],$host['snmp_password'],$host['snmp_auth_protocol'],$host['snmp_priv_passphrase'],$host['snmp_priv_protocol'],$host['snmp_context'],$host['snmp_port'],$host['snmp_timeout'],(int)read_config_option('snmp_retries'),(int)$host['max_oids'],'ICCT Ports',$host['snmp_engine_id']);
                foreach (is_array($rows)?$rows:[] as $row) {
                    $returned=ltrim((string)($row['oid'] ?? ''),'.'); $prefix=$oid.'.';
                    if (str_starts_with($returned,$prefix)) $columns[$field][substr($returned,strlen($prefix))]=$row['value'] ?? '';
                }
                if ($field==='index' && empty($columns['index'])) throw new RuntimeException('No interfaces reported. Check saved SNMP settings and IF-MIB access.');
            }
            $snapshot['uptime_ticks']=icct_backend_ports_ticks($columns['uptime']['0'] ?? '');
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
/** Display observed physical connectors; preset capacity is not interface inventory. */
function icct_nms_port_slots($ports,$count) {
    $physical=array_values(array_filter($ports,static fn($p)=>($p['type']??null)!==24 && ($p['connector']??null)!==2 && (($p['connector']??null)===1||($p['type']??null)===6)));
    $candidates=[];$mapped=[];
    foreach($physical as $port){
        $number=null;
        if(preg_match('/^(?:Gi|GigabitEthernet|Te|TenGigabitEthernet|Fa|FastEthernet|Eth|Ethernet|ge-|xe-)[\d\/.-]*[\/](\d+)$/i',$port['name']??'',$match))$number=(int)$match[1];
        elseif(preg_match('/^(?:B|Port|Ethernet)(\d+)$/i',$port['name']??'',$match))$number=(int)$match[1];
        if($number>0)$candidates[$number][]=$port['index'];
    }
    foreach($candidates as $number=>$indices)if(count($indices)===1)$mapped[$indices[0]]=$number;
    foreach($ports as &$port)if(isset($mapped[$port['index']]))$port['panel_port']=$mapped[$port['index']];unset($port);
    foreach($physical as &$port)if(isset($mapped[$port['index']]))$port['panel_port']=$mapped[$port['index']];unset($port);
    // A preset supplies capacity only. Unmapped slots have no measured identity or status.
    $capacity=max(0,(int)$count);$chassis=[];$assigned=[];
    for($number=1;$number<=$capacity;$number++){
        $match=null;
        foreach($physical as $port)if(($port['panel_port']??null)===$number){$match=$port;break;}
        if($match){$chassis[]=$match;$assigned[$match['index']]=true;}
        else $chassis[]=['index'=>-$number,'name'=>'Port '.$number,'panel_port'=>$number,'placeholder'=>true,'status'=>'Not discovered'];
    }
    foreach($physical as $port)if(!isset($assigned[$port['index']]))$chassis[]=$port;
    return ['chassis'=>$chassis,'ports'=>$ports,'capacity'=>$capacity,'physical_count'=>count($physical)];
}
function icct_backend_ports_view($host) {
    // Dashboard inventory summaries omit SNMP settings required for permissions
    // and the snapshot signature. Resolve the native record before either check.
    if (!array_key_exists('snmp_version', $host)) {
        $host = db_fetch_row_prepared("SELECT * FROM host WHERE id=? AND deleted=''", [(int)($host['id'] ?? 0)]);
        if (!$host || !array_key_exists('snmp_version', $host)) {
            return ['ports'=>[], 'fresh'=>false, 'collected'=>0, 'uptime_ticks'=>null,
                'source'=>'SNMP IF-MIB / IF-X-MIB', 'message'=>'Device configuration is unavailable.'];
        }
    }
    $snapshot=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['ports_snapshot_'.$host['id']]),true);
    if(!is_array($snapshot)||($snapshot['signature']??'')!==icct_backend_ports_signature($host)||!empty($snapshot['error'])||time()-(int)($snapshot['time']??0)>2*max(60,(int)read_config_option('poller_interval'))||(($snapshot['source']??'SNMP IF-MIB / IF-X-MIB')==='SNMP IF-MIB / IF-X-MIB'&&((int)$host['snmp_version']<1||!icct_backend_protocol_enabled($host['id'],'snmp')))){
        $ssh=json_decode((string)db_fetch_cell_prepared('SELECT meta_value FROM plugin_icct_nms_meta WHERE meta_key=?',['ports_ssh_snapshot_'.$host['id']]),true);
        if(is_array($ssh)&&($ssh['signature']??'')===icct_backend_ports_signature($host))$snapshot=$ssh;
    }
    $source=$snapshot['source']??'SNMP IF-MIB / IF-X-MIB';
    $permitted=(int)$host['snmp_version']>0&&icct_backend_protocol_enabled($host['id'],'snmp');
    if($source==='Local Linux interfaces')$permitted=icct_backend_identity_local_endpoint($host['hostname']);
    if($source==='SSH Linux interfaces'){
        $permitted=false;
        try{$d=icct_backend_ssh_device((int)$host['id']);$permitted=!empty($d['monitoring'])&&($snapshot['device_revision']??null)===$d['device_revision']&&($snapshot['preset_revision']??null)===$d['preset_revision']&&($snapshot['endpoint']??null)===$d['endpoint']&&icct_backend_protocol_enabled($host['id'],'ssh');}catch(Throwable $e){}
    }
    $age=time()-(int)($snapshot['time'] ?? 0);
    $fresh=is_array($snapshot) && ($snapshot['signature'] ?? '')===icct_backend_ports_signature($host) && $age>=0 && $age<=2*max(60,(int)read_config_option('poller_interval')) && empty($snapshot['error']) && $host['disabled']==='' && $permitted;
    $same=is_array($snapshot) && ($snapshot['signature'] ?? '')===icct_backend_ports_signature($host);
    $ports=$same && is_array($snapshot['ports'] ?? null)?$snapshot['ports']:[];
    foreach ($ports as &$port) $port['status']=icct_backend_ports_status($port,$fresh);
    unset($port);
    return ['ports'=>$ports,'fresh'=>$fresh,'collected'=>$same?(int)($snapshot['time'] ?? 0):0,'uptime_ticks'=>$same?($snapshot['uptime_ticks'] ?? null):null,'source'=>$source,'message'=>$fresh?'Port status from the latest collector observation.':($same && !empty($snapshot['error'])?$snapshot['error']:'Waiting for a current observation. Save SNMP settings; the assigned Cacti poller discovers interfaces automatically.')];
}

/** Current neighbour reports from this authorized device, matched by ifIndex or name. */
function icct_nms_port_connections($id,$ports) {
    require_once __DIR__ . '/../../../dashboard/topology/services/topology_configuration_service.php';
    $hosts=array_column(icct_backend_nd_hosts(),null,'id');$host=$hosts[$id]??null;$connections=[];
    foreach(db_fetch_assoc_prepared("SELECT protocol,status,succeeded_at,config_hash,data_json FROM plugin_icct_nms_discovery_snapshots WHERE host_id=? AND protocol IN ('lldp','cdp')",[$id]) as $snapshot){
        if(!icct_nms_discovery_current($snapshot,$host))continue;
        $payload=json_decode($snapshot['data_json'],true)??[];
        foreach($payload['neighbors']??[] as $peer){
            if(isset($peer['present'])&&!$peer['present'])continue;
            foreach($ports as $port){
                if(!empty($peer['local_ifindex'])?(int)$peer['local_ifindex']!==(int)$port['index']:strcasecmp(trim($peer['local_port']??''),$port['name'])!==0)continue;
                $connections[$port['index']]['names'][]=$peer['remote_name']??($peer['peer_label']??'');
                foreach($peer['management_addresses']??[] as $address)if(is_string($address))$connections[$port['index']]['addresses'][]=$address;
            }
        }
    }
    return $connections;
}
