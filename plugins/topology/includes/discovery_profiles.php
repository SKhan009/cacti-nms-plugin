<?php
/** Reusable discovery settings; credentials remain exclusively in native Cacti devices. */
require_once(__DIR__.'/discovery.php');
require_once(__DIR__.'/physical.php');
function tp_discovery_profiles_schema() {
    tp_exec('CREATE TABLE IF NOT EXISTS plugin_topology_discovery_profiles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,description VARCHAR(255) NOT NULL,protocol VARCHAR(8) NOT NULL,enabled TINYINT NOT NULL,interval_seconds INT UNSIGNED NOT NULL,stale_seconds INT UNSIGNED NOT NULL,active TINYINT NOT NULL DEFAULT 1,updated_by INT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function tp_protocol_options($none=true) {return ($none?array('none'=>'None — no neighbor collection'):array())+array('lldp'=>'LLDP only','cdp'=>'CDP only','both'=>'LLDP and CDP');}
function tp_discovery_values($enabled,$interval,$stale) {
    $interval=tp_id($interval);$stale=tp_id($stale);
    if(!in_array((string)$enabled,array('0','1'),true)||$interval<60||$interval>86400||$stale<$interval*2||$stale>604800)throw new InvalidArgumentException('Use an interval of 60–86400 seconds and a stale age between two intervals and 604800 seconds.');
    return array((int)$enabled,$interval,$stale);
}
function tp_discovery_profile_save($id,$name,$description,$protocol,$enabled,$interval,$stale,$active) {
    tp_require_core('topo_discovery.php');$id=tp_id($id,true);$name=tp_text($name);$description=tp_text($description,255,false);
    if(!isset(tp_protocol_options(false)[$protocol])||!in_array((string)$active,array('0','1'),true))throw new InvalidArgumentException('Choose a supported protocol and profile state.');
    list($enabled,$interval,$stale)=tp_discovery_values($enabled,$interval,$stale);
    if($id&&!db_fetch_cell_prepared('SELECT id FROM plugin_topology_discovery_profiles WHERE id=?',array($id)))throw new InvalidArgumentException('Profile no longer exists.');
    if(db_fetch_cell_prepared('SELECT id FROM plugin_topology_discovery_profiles WHERE name=? AND id<>?',array($name,$id)))throw new InvalidArgumentException('This discovery profile name already exists.');
    $id=tp_core_save(array('id'=>$id,'name'=>$name,'description'=>$description,'protocol'=>$protocol,'enabled'=>$enabled,'interval_seconds'=>$interval,'stale_seconds'=>$stale,'active'=>(int)$active,'updated_by'=>(int)$_SESSION['sess_user_id'],'updated_at'=>date('Y-m-d H:i:s')),'plugin_topology_discovery_profiles');
    tp_audit('save_discovery_profile',$id);return $id;
}
/** Called inside the topology write transaction; excludes all credential fields. */
function tp_protocol_write($unit,$host,$protocol) {
    tp_physical_host($host,$unit);
    if(!isset(tp_protocol_options()[$protocol]))throw new InvalidArgumentException('Choose a supported discovery protocol.');
    $old=db_fetch_cell_prepared('SELECT protocol FROM plugin_topology_devices WHERE host_id=? AND unit_id=?',array($host,$unit));
    tp_exec('UPDATE plugin_topology_devices SET protocol=?,updated_by=?,updated_at=NOW() WHERE host_id=? AND unit_id=?',array($protocol,(int)$_SESSION['sess_user_id'],$host,$unit));
    if($old!==$protocol)tp_exec('UPDATE plugin_topology_snapshots SET status="failed",config_hash="",error="Protocol selection changed; run discovery again." WHERE host_id=?',array($host));
    tp_audit('set_discovery_protocol',$host);
}
function tp_device_protocol_save($unit,$host,$protocol) {tp_require_core('topo_discovery.php');return tp_physical_write(function()use($unit,$host,$protocol){tp_protocol_write(tp_id($unit),tp_id($host),$protocol);});}
/** Apply an explicit copy. Profile edits never silently reconfigure existing nodes. */
function tp_discovery_profile_apply($unit,$id,$mode) {
    tp_require_core('topo_discovery.php');
    return tp_physical_write(function()use($unit,$id,$mode){
        $node=tp_unit($unit);$unit=(int)$node['id'];$id=tp_id($id);
        if(!in_array($mode,array('keep','all'),true))throw new InvalidArgumentException('Choose how to apply protocol selections.');
        $p=db_fetch_row_prepared('SELECT * FROM plugin_topology_discovery_profiles WHERE id=? AND active=1',array($id));if(!$p)throw new InvalidArgumentException('Choose an active discovery profile.');
        $hosts=tp_physical_hosts($unit);
        // A node-wide write must not silently change policy for hidden members.
        $total=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM host h JOIN plugin_topology_devices d ON d.host_id=h.id WHERE d.unit_id=? AND h.deleted=""',array($unit));
        if($total!==count($hosts))throw new RuntimeException('Access to every assigned device is required to apply a discovery profile.');
        tp_discovery_save($unit,$p['enabled'],$p['interval_seconds'],$p['stale_seconds']);
        if($mode==='all')foreach($hosts as $h)tp_protocol_write($unit,(int)$h['id'],$p['protocol']);
        tp_audit('apply_discovery_profile',$unit);
    });
}
/** Configuration check only: no request is sent and no successful connection is implied. */
function tp_snmp_configuration_note($h) {
    global $config;
    if($h['disabled']!=='')return 'Device disabled in Cacti';
    if((int)$h['poller_id']!==(int)$config['poller_id'])return 'Assigned to another Cacti collector';
    if(!in_array((string)$h['snmp_version'],array('2','3'),true))return 'Select SNMPv2c or SNMPv3 in Cacti';
    if($h['snmp_engine_id']!=='')return 'Explicit SNMP engine ID is unsupported by this collector';
    if((int)$h['snmp_timeout']<1||(int)$h['snmp_timeout']>5000)return 'Native timeout must be 1–5000 ms';
    if(!preg_match('/^[0-3]$/D',(string)read_config_option('snmp_retries')))return 'Native retries must be 0–3';
    if((string)$h['snmp_version']==='2'&&$h['snmp_community']==='')return 'SNMPv2c community is missing in Cacti';
    try{return 'Configured: '.((string)$h['snmp_version']==='3'?'SNMPv3 / ':'').tp_snmp_security($h).' — run discovery to verify';}catch(Throwable $e){return $e->getMessage();}
}

/** Save one complete discovery form atomically; core SNMP settings are never written. */
function tp_discovery_config_save($enabled,$interval,$stale,$protocols) {
    tp_require_core('topo_discovery.php');
    return tp_physical_write(function()use($enabled,$interval,$stale,$protocols){
        if(!is_array($protocols)||count($protocols)>500)throw new InvalidArgumentException('Invalid device protocol list.');
        $hosts=tp_physical_hosts(tp_scope_id());$submitted=array();
        foreach($protocols as $id=>$protocol){$id=tp_id($id);if(!is_string($protocol)||!isset(tp_protocol_options()[$protocol]))throw new InvalidArgumentException('Choose an explicit protocol for every device.');$submitted[$id]=$protocol;}
        $expected=array_keys($hosts);$actual=array_keys($submitted);sort($expected);sort($actual);
        if($expected!==$actual)throw new RuntimeException('The assigned device list changed. Reload Discovery before saving.');
        tp_discovery_save(tp_scope_id(),$enabled,$interval,$stale);
        foreach($submitted as $id=>$protocol)tp_protocol_write(tp_scope_id(),$id,$protocol);
    });
}
