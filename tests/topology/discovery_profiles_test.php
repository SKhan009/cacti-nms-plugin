<?php
/** Mutations are restricted to a disposable Cacti database clone. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$o=getopt('',array('cacti-root:','database:'));
if(empty($o['cacti-root'])||!preg_match('/^topology_profiles_qa_[a-z0-9_]+$/D',$o['database']??''))exit(2);
require($o['cacti-root'].'/include/cli_check.php');
$database_hostname='localhost';$database_port='3306';$database_default=$o['database'];
if(!db_connect_real('localhost','root','',$database_default,'mysql','3306',1))exit(2);
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/discovery_profiles.php');
try {
    if(db_fetch_cell('SELECT DATABASE()')!==$o['database'])throw new RuntimeException('Wrong QA database.');
    $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
    function dcheck($v,$s){if(!$v)throw new RuntimeException($s);print "PASS: $s\n";}
    function dreject($fn,$s){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){print "PASS: $s\n";return;}throw new RuntimeException('Accepted: '.$s);}
    $core=hash('sha256',json_encode(db_fetch_assoc('SELECT * FROM host ORDER BY id')));
    $evidence=db_fetch_assoc('SELECT * FROM plugin_topology_snapshots ORDER BY host_id,protocol');
    tp_schema();tp_schema();
    dcheck($evidence===db_fetch_assoc('SELECT * FROM plugin_topology_snapshots ORDER BY host_id,protocol'),'repeatable upgrade preserves existing discovery evidence');
    $id=tp_discovery_profile_save(0,'QA both','Operator-defined cadence','both',1,180,540,1);
    dcheck($id>0,'profile saved');
    dreject(function(){tp_discovery_profile_save(0,'QA invalid','','auto',1,180,540,1);},'automatic protocol fallback rejected');
    dreject(function(){tp_discovery_profile_save(0,'QA interval','','lldp',1,10,540,1);},'unsafe interval rejected');
    dreject(function(){tp_discovery_profile_save(0,'QA stale','','lldp',1,180,200,1);},'stale age shorter than two intervals rejected');
    dreject(function(){tp_discovery_profile_save(0,'QA both','','cdp',1,180,540,1);},'duplicate profile rejected');
    $protocols=db_fetch_assoc('SELECT host_id,protocol FROM plugin_topology_devices WHERE unit_id=3 ORDER BY host_id');
    tp_discovery_profile_apply(3,$id,'keep');
    dcheck($protocols===db_fetch_assoc('SELECT host_id,protocol FROM plugin_topology_devices WHERE unit_id=3 ORDER BY host_id'),'timing-only application preserves per-device protocols');
    $p=db_fetch_row('SELECT * FROM plugin_topology_discovery_sites WHERE unit_id=3');
    dcheck((int)$p['interval_seconds']===180&&(int)$p['stale_seconds']===540&&(int)$p['enabled']===1,'explicit profile timing reaches node policy');
    tp_discovery_profile_save($id,'QA both','Edited library settings','cdp',0,240,720,1);
    dcheck($p===db_fetch_row('SELECT * FROM plugin_topology_discovery_sites WHERE unit_id=3'),'editing library does not silently change a configured node');
    tp_discovery_profile_apply(3,$id,'all');
    dcheck((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_topology_devices WHERE unit_id=3 AND protocol="cdp"')===2,'apply-all writes only the selected node protocols');
    dcheck((int)db_fetch_cell('SELECT COUNT(*) FROM plugin_topology_snapshots WHERE host_id IN (9,10) AND status="success"')===0,'protocol change invalidates old evidence');
    tp_device_protocol_save(3,9,'lldp');
    dcheck(db_fetch_cell('SELECT protocol FROM plugin_topology_devices WHERE host_id=9')==='lldp'&&db_fetch_cell('SELECT protocol FROM plugin_topology_devices WHERE host_id=10')==='cdp','device override is independent');
    dreject(function(){tp_device_protocol_save(3,2,'lldp');},'foreign node device rejected');
    tp_discovery_profile_save($id,'QA both','','cdp',0,240,720,0);
    dreject(function()use($id){tp_discovery_profile_apply(3,$id,'all');},'archived profile cannot be applied');
    dcheck($core===hash('sha256',json_encode(db_fetch_assoc('SELECT * FROM host ORDER BY id'))),'core device and SNMP credential rows unchanged');
    $cols=array_column(db_fetch_assoc('SHOW COLUMNS FROM plugin_topology_discovery_profiles'),'Field');
    dcheck(!preg_grep('/password|community|passphrase|username/',$cols),'profile table contains no credentials');
    $_SESSION=array('sess_user_id'=>3);
    dreject(function(){tp_discovery_profile_save(0,'Unauthorized','','lldp',1,180,540,1);},'read-only account cannot save discovery profiles');
    print "DISCOVERY PROFILE CHECKS COMPLETE\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
