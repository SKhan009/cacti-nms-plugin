<?php
/** Transaction regression, restricted to a disposable cloned Cacti database. */
if(PHP_SAPI!=='cli')exit(2);
$o=getopt('',array('cacti-root:','database:'));
if(empty($o['cacti-root'])||!preg_match('/^topology_discform_qa_[a-z0-9_]+$/D',$o['database']??''))exit(2);
require($o['cacti-root'].'/include/cli_check.php');
$database_hostname='localhost';$database_port='3306';$database_default=$o['database'];
if(!db_connect_real('localhost','root','',$database_default,'mysql','3306',1))exit(2);
require_once(dirname(__DIR__, 2).'/plugins/topology/includes/discovery_profiles.php');
try{
 function fcheck($v,$s){if(!$v)throw new RuntimeException($s);print "PASS: $s\n";}
 function freject($fn,$s){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){print "PASS: $s\n";return;}throw new RuntimeException('Accepted: '.$s);}
 function fstate(){return hash('sha256',json_encode(array(db_fetch_assoc('SELECT * FROM plugin_topology_devices ORDER BY host_id'),db_fetch_assoc('SELECT * FROM plugin_topology_discovery_sites ORDER BY unit_id'),db_fetch_assoc('SELECT * FROM plugin_topology_snapshots ORDER BY host_id,protocol'))));}
 fcheck(db_fetch_cell('SELECT DATABASE()')===$o['database'],'isolated database');
 $_SESSION=array('sess_user_id'=>(int)read_config_option('admin_user'));
 $core=hash('sha256',json_encode(db_fetch_assoc('SELECT * FROM host ORDER BY id')));
 $protocols=array();foreach(tp_discovery_hosts(tp_scope_id()) as $h)$protocols[$h['id']]=$h['protocol'];
 $before=fstate();$invalid=$protocols;$invalid[array_key_first($invalid)]='invalid';
 freject(function()use($invalid){tp_discovery_config_save(1,600,1800,$invalid);},'invalid protocol rejected');fcheck($before===fstate(),'invalid protocol changes no settings');
 freject(function()use($protocols){tp_discovery_config_save(1,600,100,$protocols);},'invalid timing rejected');fcheck($before===fstate(),'invalid timing changes no settings');
 $missing=$protocols;array_pop($missing);freject(function()use($missing){tp_discovery_config_save(1,300,900,$missing);},'missing device rejects stale form');
 tp_discovery_config_save(1,300,900,$protocols);
 fcheck($core===hash('sha256',json_encode(db_fetch_assoc('SELECT * FROM host ORDER BY id'))),'save preserves native SNMP and device fields');
 fcheck((int)db_fetch_cell('SELECT interval_seconds FROM plugin_topology_discovery_sites WHERE unit_id=2147483647')===300,'combined form saves collection settings');
 $_SESSION=array('sess_user_id'=>3);freject(function()use($protocols){tp_discovery_config_save(1,300,900,$protocols);},'read-only user cannot save');
 print "DISCOVERY FORM CHECKS COMPLETE\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
